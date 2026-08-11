<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\SubscriptionService;

/**
 * The sidebar must match what the workspace actually bought.
 *
 * Without this, a Free workspace sees Telephony, Channels, Messages and Flow
 * Builder in the menu and only discovers they aren't included by clicking and
 * hitting the 402 upsell — a menu full of dead ends.
 *
 * These assertions are made against ROUTE URLS rather than link text, so they
 * don't break when someone rewords a sidebar label.
 */
class SidebarPlanVisibilityTest extends BillingTestCase
{
    /** Render any page that uses layouts.master (and therefore the sidebar). */
    private function sidebarFor($client, $user): string
    {
        // project-profile only reads the master `projects` table — the dashboard
        // queries the tenant DB, which this suite doesn't provision.
        return $this->actingAs($user)
            ->get(route('project-profile.index', ['client' => $client->slug]))
            ->assertOk()
            ->getContent();
    }

    public function test_a_free_workspace_sees_only_the_sections_its_plan_includes(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $html = $this->sidebarFor($client, $user);
        $slug = ['client' => $client->slug];

        // Not in the Free plan → must not appear in the menu at all.
        foreach ([
            'telephony.index'       => 'Telephony',
            'channels.index'        => 'Channels',
            'chat.index'            => 'Messages (shared inbox)',
            'flows.index'           => 'Flow Builder',
            'roles.index'           => 'Team & Roles',
            'voices.index'          => 'Voices (cloning)',
        ] as $route => $label) {
            $this->assertStringNotContainsString(
                route($route, $slug),
                $html,
                "{$label} is not in the Free plan and must be hidden from the sidebar"
            );
        }

        // Included on Free → must still be there.
        foreach ([
            'dashboard'          => 'Dashboard',
            'leads.index'        => 'Leads',
            'data-sources.index' => 'Data Sources',
            'sessions.index'     => 'Conversations',
            'widget-settings.index' => 'Widget',
        ] as $route => $label) {
            $this->assertStringContainsString(
                route($route, $slug),
                $html,
                "{$label} IS in the Free plan and must stay visible"
            );
        }
    }

    public function test_upgrading_to_growth_reveals_the_gated_sections(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $before = $this->sidebarFor($client, $user);
        $this->assertStringNotContainsString(route('telephony.index', ['client' => $client->slug]), $before);

        // Pay for Growth.
        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', 'growth')
        )->assertOk();

        $after = $this->sidebarFor($client->fresh(), $user);
        $slug  = ['client' => $client->slug];

        foreach (['telephony.index', 'channels.index', 'chat.index', 'flows.index', 'roles.index'] as $route) {
            $this->assertStringContainsString(
                route($route, $slug),
                $after,
                "{$route} is included in Growth and must appear after upgrading"
            );
        }
    }

    public function test_the_sidebar_and_the_route_gate_never_disagree(): void
    {
        // The bug this prevents: a link visible in the menu that 402s on click,
        // or a section hidden from the menu that is actually reachable.
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $html     = $this->sidebarFor($client, $user);
        $features = app(\App\Services\Billing\PlanFeatureService::class);

        $checks = [
            'telephony'     => 'telephony.index',
            'channels'      => 'channels.index',
            'messages'      => 'chat.index',
            'flows'         => 'flows.index',
            'leads'         => 'leads.index',
            'data_sources'  => 'data-sources.index',
        ];

        foreach ($checks as $module => $route) {
            $allowedByPlan = $features->clientHasModule($client, $module);
            $inSidebar     = str_contains($html, route($route, ['client' => $client->slug]));

            $this->assertSame(
                $allowedByPlan,
                $inSidebar,
                "sidebar visibility for [{$module}] disagrees with the plan gate"
            );

            // And the route itself agrees with both.
            $status = $this->actingAs($user)
                ->get(route($route, ['client' => $client->slug]))
                ->getStatusCode();

            $allowedByPlan
                ? $this->assertNotSame(402, $status, "[{$module}] is in the plan but the route refused it")
                : $this->assertSame(402, $status, "[{$module}] is not in the plan but the route allowed it");
        }
    }

    public function test_locked_sections_can_be_left_visible_by_config(): void
    {
        // Some teams prefer the upsell page to do the selling rather than
        // hiding the feature entirely.
        config(['billing.settings.hide_locked_modules' => false]);

        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $html = $this->sidebarFor($client, $user);

        $this->assertStringContainsString(route('telephony.index', ['client' => $client->slug]), $html);

        // Visibility only — the route gate is unchanged.
        $this->actingAs($user)
             ->get(route('telephony.index', ['client' => $client->slug]))
             ->assertStatus(402);
    }

    public function test_a_grandfathered_workspace_keeps_its_whole_menu(): void
    {
        // Pre-billing workspaces have plan_id = NULL. Nothing they could see
        // yesterday may disappear because billing shipped.
        [$client, $user] = $this->makeWorkspace();

        \App\Models\Billing\Subscription::create([
            'client_id'       => $client->id,
            'plan_id'         => null,
            'type'            => 'default',
            'status'          => \App\Models\Billing\Subscription::STATUS_FREE,
            'free_started_at' => now()->subYear(),
            'free_ends_at'    => null,
            'metadata'        => ['grandfathered' => true],
        ]);

        $html = $this->sidebarFor($client->fresh(), $user);

        foreach (['telephony.index', 'channels.index', 'flows.index', 'roles.index'] as $route) {
            $this->assertStringContainsString(
                route($route, ['client' => $client->slug]),
                $html,
                "grandfathered workspaces must keep [{$route}]"
            );
        }
    }
}
