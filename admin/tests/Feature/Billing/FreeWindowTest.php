<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Subscription;
use App\Models\Billing\TrialFingerprint;
use App\Services\Billing\SubscriptionService;

/**
 * The 7-day, no-card free window — and its separation from a paid trial.
 *
 * The approved model: Free is a 7-day window, the free week IS the trial, and
 * a lapse degrades to read-only rather than locking anyone out.
 */
class FreeWindowTest extends BillingTestCase
{
    public function test_registration_starts_a_seven_day_free_window_with_no_stripe_customer(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Jane',
            'company_name'          => 'Jane Dental',
            'email'                 => 'jane@dental.test',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            // Required by RegisteredUserController. Turnstile passes
            // automatically when it isn't configured, so no token is needed.
            'terms'                 => 'on',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $client = \App\Models\Client::query()->where('slug', 'jane-dental')->firstOrFail();
        $sub    = $client->currentSubscription();

        $this->assertNotNull($sub, 'A new workspace must get a subscription row.');
        $this->assertSame(Subscription::STATUS_FREE, $sub->status);
        $this->assertSame('free', $sub->plan->slug);
        $this->assertEqualsWithDelta(7, $sub->free_started_at->diffInDays($sub->free_ends_at), 0.01);

        // A free workspace must never create a Stripe customer: it would
        // litter the dashboard and blur "free plan" vs "subscription".
        $this->assertNull($client->stripe_customer_ref);
        $this->assertNull($sub->stripe_subscription_ref);

        $this->assertTrue($client->hasBillingAccess());
    }

    public function test_free_plan_has_no_telephony_but_does_have_widget_voice_messages(): void
    {
        $features = app(\App\Services\Billing\PlanFeatureService::class);
        $free     = $this->plan('free');

        // The single most important line for gross margin: carrier minutes
        // cost real money, so the free plan gets none.
        $this->assertSame(0, $features->planLimit($free, 'telephony_minutes'));
        $this->assertFalse($features->planHas($free, 'telephony'));
        $this->assertNotContains('telephony', $features->modulesForPlan($free));

        // But a mic message in the widget runs on local models, so it's free.
        $this->assertSame(50, $features->planLimit($free, 'voice_messages'));
        $this->assertTrue($features->planHas($free, 'web_widget'));
    }

    public function test_free_window_grants_access_until_it_elapses(): void
    {
        [$client] = $this->makeWorkspace();

        app(SubscriptionService::class)->startFreeWindow($client);
        $client->forgetSubscription();

        $this->assertTrue($client->hasBillingAccess());

        // Access is decided by comparing the clock to free_ends_at on every
        // request, NOT by the nightly command having run. Prove it.
        $this->travel(8)->days();
        $client->forgetSubscription();

        $this->assertFalse($client->hasBillingAccess());
        $this->assertSame(Subscription::STATUS_FREE, $client->currentSubscription()->status);
    }

    public function test_lifecycle_command_expires_a_lapsed_window_and_sets_a_purge_date(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->travel(8)->days();

        $this->artisan('billing:lifecycle')->assertSuccessful();

        $sub = $client->fresh()->currentSubscription();

        $this->assertSame(Subscription::STATUS_EXPIRED, $sub->status);
        $this->assertNotNull($sub->read_only_since);
        $this->assertNotNull($sub->purge_after, 'A retention deadline must be set so warnings can be sent.');
        $this->assertSame('read_only', $client->fresh()->access_state);
    }

    public function test_expired_workspace_can_still_read_but_not_write(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->travel(8)->days();
        $this->artisan('billing:lifecycle');

        $this->actingAs($user);

        // Reads pass, so nobody is locked away from their own data or export.
        // project-profile is used rather than the dashboard because it only
        // reads the master `projects` table — the dashboard queries the tenant
        // database, which these tests don't provision, and a 500 from that
        // would say nothing about the gate.
        $this->get(route('project-profile.index', ['client' => $client->slug]))->assertOk();

        // Billing is always reachable — a paywall you can't pay through is
        // just an outage.
        $this->get(route('billing.index', ['client' => $client->slug]))->assertOk();

        // Writes are what actually stop.
        $this->post(route('skills.store', ['client' => $client->slug]), ['name' => 'Sales'])
             ->assertRedirect(route('billing.index', ['client' => $client->slug]));
    }

    public function test_expired_workspace_gets_402_on_json_writes(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->travel(8)->days();
        $this->artisan('billing:lifecycle');

        $this->actingAs($user)
             ->postJson(route('skills.store', ['client' => $client->slug]), ['name' => 'Sales'])
             ->assertStatus(402)
             ->assertJsonPath('error', 'subscription_required');
    }

    // ── Abuse control ────────────────────────────────────────────────

    public function test_a_second_workspace_from_the_same_owner_gets_no_free_window(): void
    {
        [$first, $user] = $this->makeWorkspace('First Co', 'repeat@acme.test');
        app(SubscriptionService::class)->startFreeWindow($first, $user);

        // Same person, new workspace. Without fingerprinting this is unlimited
        // free weeks for the price of clicking "new workspace".
        [$second] = $this->makeWorkspace('Second Co', 'other@acme.test');
        $second->users()->detach();
        $user->attachMembership($second->id, null, $user->id, \App\Models\Role::where('client_id', $second->id)->value('id'));

        $sub = app(SubscriptionService::class)->startFreeWindow($second->fresh(), $user);

        $this->assertSame(Subscription::STATUS_EXPIRED, $sub->status);
        $this->assertNotNull($sub->read_only_since);
    }

    public function test_email_aliases_are_normalised_so_plus_tags_do_not_earn_another_window(): void
    {
        $this->assertSame('me@gmail.com', TrialFingerprint::normaliseEmail('Me+test@Gmail.com'));
        $this->assertSame('me@gmail.com', TrialFingerprint::normaliseEmail('m.e@googlemail.com'));

        // Dots are only stripped for Google-family domains — elsewhere they're
        // significant, and merging them would wrongly block distinct people.
        $this->assertSame('m.e@fastmail.com', TrialFingerprint::normaliseEmail('M.E+tag@Fastmail.com'));
    }

    public function test_super_admin_can_extend_a_free_window(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->travel(8)->days();
        $this->artisan('billing:lifecycle');

        $sub   = $client->fresh()->currentSubscription();
        $admin = \App\Models\User::create([
            'name' => 'Ops', 'email' => 'ops@serveai.test',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);

        $this->actingAs($admin)
             ->post(route('ops.billing.subscriptions.extend-free', ['id' => $sub->id]), ['days' => 14])
             ->assertRedirect();

        $sub->refresh();

        $this->assertSame(Subscription::STATUS_FREE, $sub->status);
        $this->assertTrue($sub->free_ends_at->isFuture());
        $this->assertNull($sub->read_only_since, 'Extending must clear the degraded flags.');
        $this->assertNull($sub->purge_after);
    }

    public function test_extension_is_measured_from_now_not_from_a_past_end_date(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        // 30 days after a 7-day window ended. Adding 7 days to the old end
        // date would land in the past and grant nothing.
        $this->travel(37)->days();
        $this->artisan('billing:lifecycle');

        $sub   = $client->fresh()->currentSubscription();
        $admin = \App\Models\User::create([
            'name' => 'Ops', 'email' => 'ops2@serveai.test',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);

        $this->actingAs($admin)
             ->post(route('ops.billing.subscriptions.extend-free', ['id' => $sub->id]), ['days' => 7]);

        // Compared in hours: Carbon's diffInDays() truncates, so a genuine
        // 7-day extension reads as 6 and the assertion would fail on a
        // correct implementation.
        $this->assertTrue($sub->refresh()->free_ends_at->isFuture());
        $this->assertEqualsWithDelta(7 * 24, now()->diffInHours($sub->free_ends_at), 2);
    }

    public function test_grandfathered_workspaces_have_a_permanent_free_window(): void
    {
        // The backfill migration's contract: pre-billing workspaces get
        // free_ends_at = NULL, which must never expire. Getting this wrong
        // switches off every existing customer 7 days after deploy.
        [$client] = $this->makeWorkspace();

        Subscription::create([
            'client_id'       => $client->id,
            'plan_id'         => null,
            'type'            => 'default',
            'status'          => Subscription::STATUS_FREE,
            'free_started_at' => now()->subYear(),
            'free_ends_at'    => null,
            'metadata'        => ['grandfathered' => true],
        ]);

        $this->travel(400)->days();
        $client->forgetSubscription();

        $this->assertTrue($client->hasBillingAccess());
        $this->assertFalse($client->currentSubscription()->freeWindowHasElapsed());

        // A null plan must also fail OPEN on features, so a grandfathered
        // customer keeps everything they had yesterday.
        $features = app(\App\Services\Billing\PlanFeatureService::class);
        $this->assertTrue($features->clientHasModule($client, 'telephony'));
    }
}
