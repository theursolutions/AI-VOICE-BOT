<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\SubscriptionService;

/**
 * The features that are sold on the pricing page but live INSIDE an allowed
 * page, so the route-level gate can't reach them.
 *
 * Until this suite existed, all of these were enforced nowhere: a Free
 * workspace could connect a production database, strip our branding, drive the
 * developer API and start a CRM OAuth handshake — all things the pricing page
 * says are paid.
 */
class FeatureEnforcementTest extends BillingTestCase
{
    private int $workspaceSeq = 0;

    /** Put the workspace on a real paid plan. */
    private function subscribe($client, string $planSlug): void
    {
        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', $planSlug)
        )->assertOk();

        $client->forgetSubscription();
    }

    // ── Allocation, as decided ───────────────────────────────────────

    public function test_the_agreed_allocation_is_in_place(): void
    {
        $f = app(\App\Services\Billing\PlanFeatureService::class);

        $expected = [
            //                       free   starter growth scale
            'assistant_access'   => [false, true,  true,  true],   // Starter+
            'bot_strategy'       => [false, true,  true,  true],   // Starter+
            'skills'             => [false, true,  true,  true],   // every plan except Free
            'voice_cloning'      => [false, true,  true,  true],   // above Free
            'crm_connectors'     => [false, false, true,  true],   // Growth+
            'byo_llm'            => [false, false, false, true],   // Scale only
            'database_connector' => [false, false, true,  true],
            'api_access'         => [false, false, true,  true],
            'remove_branding'    => [false, false, true,  true],
        ];

        foreach ($expected as $feature => $rows) {
            foreach (['free', 'starter', 'growth', 'scale'] as $i => $slug) {
                $this->assertSame(
                    $rows[$i],
                    $f->planHas($this->plan($slug), $feature),
                    "{$feature} on {$slug}"
                );
            }
        }
    }

    public function test_bot_strategy_and_brain_settings_are_now_separate_modules(): void
    {
        // They used to share one key, so gating BYO-LLM at Scale also locked
        // Starter and Growth out of the knowledge-tier toggles.
        $modules = array_keys((array) config('modules'));

        $this->assertContains('bot_strategy', $modules);
        $this->assertContains('brain_settings', $modules);

        $this->assertSame(['bot-strategy'], config('modules.bot_strategy.routes'));
        $this->assertSame(['brain-settings'], config('modules.brain_settings.routes'));

        $f = app(\App\Services\Billing\PlanFeatureService::class);

        // Growth reaches Bot Strategy but not Brain Settings.
        $this->assertTrue($f->clientHasModule($this->workspaceOn('growth'), 'bot_strategy'));
        $this->assertFalse($f->clientHasModule($this->workspaceOn('growth'), 'brain_settings'));

        // Scale reaches both.
        $this->assertTrue($f->clientHasModule($this->workspaceOn('scale'), 'brain_settings'));
    }

    public function test_compute_mesh_is_available_on_every_plan(): void
    {
        // Decided: all plans. No feature declares it, so nothing gates it.
        $f = app(\App\Services\Billing\PlanFeatureService::class);

        foreach (['free', 'starter', 'growth', 'scale'] as $slug) {
            $this->assertTrue(
                $f->clientHasModule($this->workspaceOn($slug), 'compute'),
                "compute must be open on {$slug}"
            );
        }
    }

    // ── Module gates ─────────────────────────────────────────────────

    public function test_the_team_assistant_is_closed_on_free_and_open_on_starter(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        // Every question costs LLM tokens, so Free doesn't get it.
        $this->actingAs($user)
             ->get(route('assistant.index', ['client' => $client->slug]))
             ->assertStatus(402);

        $this->subscribe($client, 'starter');

        $this->actingAs($user)
             ->get(route('assistant.index', ['client' => $client->slug]))
             ->assertOk();
    }

    public function test_bot_strategy_is_closed_on_free_and_open_on_starter(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->actingAs($user)
             ->get(route('bot-strategy.index', ['client' => $client->slug]))
             ->assertStatus(402);

        $this->subscribe($client, 'starter');

        // The regression this guards: Starter used to be refused here purely
        // because the module shared a key with BYO-LLM.
        $this->actingAs($user)
             ->get(route('bot-strategy.index', ['client' => $client->slug]))
             ->assertOk();
    }

    public function test_skills_is_closed_on_free_and_open_from_starter(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        // Skills shipped ungated, so multi-agent routing was silently free to
        // everyone — including Free.
        $this->actingAs($user)
             ->get(route('skills.index', ['client' => $client->slug]))
             ->assertStatus(402);

        $this->subscribe($client, 'starter');

        // Asserting "not 402" rather than 200: Skills reads the TENANT
        // database, which this suite deliberately doesn't provision, so the
        // page itself 500s for reasons that say nothing about the plan gate.
        $status = $this->actingAs($user)
            ->get(route('skills.index', ['client' => $client->slug]))
            ->getStatusCode();

        $this->assertNotSame(402, $status, 'Starter must pass the Skills plan gate.');

        $this->assertTrue(
            app(\App\Services\Billing\PlanFeatureService::class)
                ->clientHasModule($client->fresh(), 'skills')
        );
    }

    public function test_brain_settings_stays_scale_only(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->subscribe($client, 'growth');

        $this->actingAs($user)
             ->get(route('brain-settings.index', ['client' => $client->slug]))
             ->assertStatus(402);

        $this->subscribe($client, 'scale');

        $this->actingAs($user)
             ->get(route('brain-settings.index', ['client' => $client->slug]))
             ->assertOk();
    }

    // ── Action-level gates ───────────────────────────────────────────

    public function test_the_database_connector_is_refused_below_growth(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->subscribe($client, 'starter');

        $project = \App\Models\Project::where('client_id', $client->id)->firstOrFail();

        // Redirect-back with an explanation, NOT the full-page upsell — the
        // form carries host/user/password they just typed.
        $this->actingAs($user)
             ->post(route('data-sources.store.database', ['client' => $client->slug]), [
                 'project_id' => $project->id,
                 'name'       => 'Prod DB',
                 'host'       => '127.0.0.1',
                 'port'       => 3306,
                 'db_name'    => 'secrets',
                 'user'       => 'root',
                 'password'   => '',
             ])
             ->assertRedirect()
             ->assertSessionHas('error');

        $this->assertDatabaseMissing('data_sources', ['name' => 'Prod DB']);
    }

    public function test_removing_the_powered_by_badge_is_refused_below_growth(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->subscribe($client, 'starter');

        $project = \App\Models\Project::where('client_id', $client->id)->firstOrFail();

        // The rest of the form saves; only the branding toggle is forced back
        // on. Rejecting the whole submit over one checkbox would lose the lot.
        $this->actingAs($user)->patch(route('widget-settings.update', ['client' => $client->slug]), [
            'project_id'      => $project->id,
            'bot_name'        => 'Aria',
            'primary_color'   => '#6366f1',
            'accent_color'    => '#8b5cf6',
            'welcome_title'   => 'Hi there',
            'welcome_message' => 'How can we help?',
            'position'        => 'bottom-right',
            'show_powered_by' => '0',
        ])->assertSessionHasNoErrors();

        $widget = (array) data_get(\App\Models\Project::find($project->id)->json_data, 'widget', []);

        $this->assertTrue($widget['show_powered_by'], 'Branding must stay on below Growth.');
        $this->assertSame('Aria', $widget['bot_name'], 'The rest of the form must still save.');
    }

    public function test_removing_the_powered_by_badge_is_allowed_on_growth(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->subscribe($client, 'growth');

        $project = \App\Models\Project::where('client_id', $client->id)->firstOrFail();

        $this->actingAs($user)->patch(route('widget-settings.update', ['client' => $client->slug]), [
            'project_id'      => $project->id,
            'bot_name'        => 'Aria',
            'primary_color'   => '#6366f1',
            'accent_color'    => '#8b5cf6',
            'welcome_title'   => 'Hi there',
            'welcome_message' => 'How can we help?',
            'position'        => 'bottom-right',
            'show_powered_by' => '0',
        ])->assertSessionHasNoErrors();

        $widget = (array) data_get(\App\Models\Project::find($project->id)->json_data, 'widget', []);

        $this->assertFalse($widget['show_powered_by']);
    }

    // ── Developer API ────────────────────────────────────────────────

    public function test_the_developer_api_is_refused_below_growth(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->subscribe($client, 'starter');

        $project = \App\Models\Project::where('client_id', $client->id)->firstOrFail();

        $this->getJson('/api/v1/data-sources', ['X-CLIENT-API-KEY' => $project->project_api_key])
             ->assertStatus(402)
             ->assertJsonPath('error', 'plan_upgrade_required');
    }

    public function test_the_widget_runtime_keeps_working_below_growth(): void
    {
        // THE important half of the API gate. The widget authenticates with the
        // same key, so gating the whole middleware would silently switch off
        // the chat widget on the sites of every customer under Growth.
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $project = \App\Models\Project::where('client_id', $client->id)->firstOrFail();

        $response = $this->postJson('/api/v1/sessions',
            ['channel' => 'webchat'],
            ['X-CLIENT-API-KEY' => $project->project_api_key]
        );

        $this->assertNotSame(402, $response->getStatusCode(),
            'The widget runtime must never be blocked by the api_access gate.');
    }

    // ── Helper ───────────────────────────────────────────────────────

    /**
     * A workspace sitting on a given plan.
     *
     * NOT memoised. An earlier version cached these in a `static`, which
     * survives both the test method AND the class — so it handed later tests
     * Eloquent models belonging to a transaction RefreshDatabase had already
     * rolled back. Cheap to rebuild; not worth the class of bug.
     */
    private function workspaceOn(string $planSlug): \App\Models\Client
    {
        // Unique per call: a test may ask for the same plan twice, and both the
        // client slug and the owner email are unique columns.
        $n = ++$this->workspaceSeq;
        [$client] = $this->makeWorkspace("WS {$planSlug} {$n}", "ws-{$planSlug}-{$n}@acme.test");
        app(SubscriptionService::class)->startFreeWindow($client);

        if ($planSlug !== 'free') {
            $this->postWebhook($this->subscriptionEvent(
                'customer.subscription.created', $client, 'active', $planSlug, 'monthly',
                "sub_{$planSlug}_{$n}"
            ))->assertOk();
            $client->forgetSubscription();
        }

        return $client->fresh();
    }
}
