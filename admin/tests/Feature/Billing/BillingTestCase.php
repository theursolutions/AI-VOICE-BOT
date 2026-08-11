<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\StripeClientFactory;
use Database\Seeders\BillingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Shared scaffolding for the billing suite.
 *
 * NO TEST IN THIS SUITE TOUCHES THE NETWORK. Stripe is a container-bound
 * double, geolocation is forced to the null driver, and exchange rates are
 * seeded straight into the table. A billing suite that needs live API keys is
 * a suite nobody runs.
 */
abstract class BillingTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Real Stripe keys must never be required for the suite to pass, but
        // the code guards on "is Stripe configured", so give it plausible
        // test-mode values.
        config([
            'billing.stripe.key'            => 'pk_test_fake',
            'billing.stripe.secret'         => 'sk_test_fake',
            'billing.stripe.webhook_secret' => 'whsec_test_secret',

            // Deterministic pricing display: no .mmdb, no FX API.
            'billing.geo.driver'            => 'null',
            'billing.geo.fallback_country'  => null,
            'billing.fx.driver'             => 'null',

            // The suite exercises the LIVE behaviour. The shipped default is
            // false (information-only) while billing is being finished — the
            // off state has its own coverage in CheckoutDisabledTest.
            'billing.checkout.enabled'      => true,
        ]);

        // SiteSetting memoises the whole table in a STATIC property, which
        // survives RefreshDatabase's rollback and therefore leaks between
        // tests in the same process — a test that disables the pricing page
        // would silently 404 every later test.
        \App\Models\SiteSetting::flushCache();

        $this->seed(BillingSeeder::class);

        // Give every seeded price a Stripe reference so checkout is reachable
        // without calling Stripe. resolvePrice() refuses an unsynced price on
        // purpose, which is the behaviour a "sync first" test asserts.
        PlanPrice::query()->whereNull('stripe_price_ref')->get()->each(function (PlanPrice $price) {
            $price->forceFill([
                'stripe_price_ref'   => 'price_test_' . $price->plan_id . '_' . $price->interval,
                'stripe_product_ref' => 'prod_test_' . $price->plan_id,
                'stripe_livemode'    => false,
                'stripe_synced_at'   => now(),
            ])->save();
        });
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /**
     * A workspace with an owner, mirroring what RegisteredUserController
     * builds (int unix timestamps, enum is_active, an Owner role).
     *
     * Includes an active Project: EnsureWorkspaceProvisioned runs BEFORE the
     * billing gates and bounces a project-less workspace to /setup, which
     * would mask every access-control assertion in this suite.
     *
     * @return array{0: Client, 1: User}
     */
    protected function makeWorkspace(string $name = 'Acme Ltd', string $email = 'owner@acme.test'): array
    {
        $user = User::create([
            'name'     => 'Owner',
            'email'    => $email,
            'password' => bcrypt('password'),
        ]);

        $client = Client::create([
            'name'           => $name,
            'slug'           => \Illuminate\Support\Str::slug($name),
            'client_api_key' => bin2hex(random_bytes(8)),
            'is_active'      => 'Yes',
            'created_at'     => time(),
            'updated_at'     => time(),
        ]);

        $role = Role::create([
            'client_id'  => $client->id,
            'name'       => 'Owner',
            'modules'    => ['*'],
            'is_owner'   => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $user->attachMembership($client->id, null, $user->id, $role->id);
        $user->forceFill([
            'active_client_id'  => $client->id,
            'email_verified_at' => now(),
        ])->save();

        // Only real columns: the `projects` table has no `api_key` (despite the
        // model's $fillable listing one) and its update column is misspelled
        // `update_at`, so anything else here throws. `is_active` defaults to
        // 'Yes', which is what EnsureWorkspaceProvisioned looks for.
        \App\Models\Project::create([
            'name'               => $name . ' Project',
            'client_id'          => $client->id,
            'project_api_key'    => bin2hex(random_bytes(8)),
            'project_api_secret' => bin2hex(random_bytes(8)),
        ]);

        return [$client->fresh(), $user->fresh()];
    }

    protected function plan(string $slug): Plan
    {
        return Plan::query()->where('slug', $slug)->firstOrFail();
    }

    protected function price(string $slug, string $interval): PlanPrice
    {
        return $this->plan($slug)->priceFor($interval);
    }

    // ── Stripe double ────────────────────────────────────────────────

    /**
     * Bind a fake StripeClientFactory.
     *
     * `$services` maps a Stripe service name to a double, e.g.
     *   ['checkout' => $checkoutDouble, 'subscriptions' => $subsDouble]
     *
     * StripeClient is SUBCLASSED rather than Mockery-mocked. Its services are
     * magic properties served by a real __get(), and Mockery overrides __get()
     * on any class that declares one — so a mocked StripeClient silently
     * returns null for ->checkout, and the failure surfaces as an unrelated
     * "redirected to /" assertion three layers away. Subclassing is boring and
     * deterministic.
     *
     * The factory's make() has a StripeClient return type, so the double must
     * genuinely be one.
     */
    protected function fakeStripe(array $services = []): void
    {
        $client = new class($services) extends \Stripe\StripeClient {
            public function __construct(private array $doubles)
            {
                parent::__construct(['api_key' => 'sk_test_fake']);
            }

            public function __get($name)
            {
                return $this->doubles[$name] ?? parent::__get($name);
            }
        };

        $factory = Mockery::mock(StripeClientFactory::class);
        $factory->shouldReceive('make')->andReturn($client);
        $factory->shouldReceive('isConfigured')->andReturn(true);
        $factory->shouldReceive('isLiveMode')->andReturn(false);

        $this->app->instance(StripeClientFactory::class, $factory);
    }

    /** A `->sessions->create()` double for Checkout. */
    protected function checkoutServiceReturning(string $url = 'https://checkout.stripe.test/session', string $id = 'cs_test_1'): object
    {
        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')->andReturn(
            \Stripe\Util\Util::convertToStripeObject([
                'id'     => $id,
                'object' => 'checkout.session',
                'url'    => $url,
            ], [])
        );

        return new class($sessions) {
            public function __construct(public $sessions)
            {
            }
        };
    }

    /** A `->subscriptions` double whose retrieve()/update() return $payload. */
    protected function subscriptionServiceReturning(array $payload): object
    {
        $double = Mockery::mock();
        $object = \Stripe\Util\Util::convertToStripeObject($payload, []);

        $double->shouldReceive('retrieve')->andReturn($object);
        $double->shouldReceive('update')->andReturn($object);
        $double->shouldReceive('cancel')->andReturn($object);

        return $double;
    }

    protected function customerServiceReturning(string $id = 'cus_test_1'): object
    {
        $double = Mockery::mock();
        $double->shouldReceive('create')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => $id, 'object' => 'customer'], [])
        );
        $double->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject([
                'id'               => $id,
                'object'           => 'customer',
                'invoice_settings' => ['default_payment_method' => null],
            ], [])
        );

        return $double;
    }

    // ── Webhook helper ───────────────────────────────────────────────

    /**
     * POST a webhook with a REAL Stripe signature.
     *
     * Signing for real (rather than mocking Webhook::constructEvent) means the
     * signature-verification path is actually exercised — the one thing
     * standing between this endpoint and an unauthenticated
     * "make me a subscriber" API.
     */
    protected function postWebhook(array $event, ?string $secret = null, ?int $timestamp = null): \Illuminate\Testing\TestResponse
    {
        $secret    = $secret ?? (string) config('billing.stripe.webhook_secret');
        $timestamp = $timestamp ?? time();
        $payload   = json_encode($event);

        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        return $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE'          => 'application/json',
            ],
            $payload
        );
    }

    /** A `customer.subscription.*` event body. */
    protected function subscriptionEvent(
        string $type,
        Client $client,
        string $status = 'active',
        string $planSlug = 'growth',
        string $interval = 'monthly',
        string $subscriptionRef = 'sub_test_1',
        array $overrides = [],
    ): array {
        $price = $this->price($planSlug, $interval);

        return [
            'id'      => 'evt_' . bin2hex(random_bytes(6)),
            'object'  => 'event',
            'type'    => $type,
            'api_version' => '2024-06-20',
            'livemode'    => false,
            'data'    => [
                'object' => array_merge([
                    'id'       => $subscriptionRef,
                    'object'   => 'subscription',
                    'customer' => 'cus_test_1',
                    'status'   => $status,
                    'items'    => ['data' => [[
                        'id'       => 'si_test_1',
                        'quantity' => 1,
                        'price'    => [
                            'id'          => $price->stripe_price_ref,
                            'unit_amount' => $price->unit_amount,
                            'currency'    => 'usd',
                        ],
                    ]]],
                    'current_period_start' => now()->getTimestamp(),
                    'current_period_end'   => now()->addMonth()->getTimestamp(),
                    'cancel_at_period_end' => false,
                    'trial_end'            => null,
                    'canceled_at'          => null,
                    'metadata'             => ['client_ref' => (string) $client->id],
                ], $overrides),
            ],
        ];
    }

    // NOTE: deliberately NO tearDown() override here.
    //
    // An earlier version called Mockery::close() before parent::tearDown().
    // An unmet `->once()` expectation makes close() throw, which meant
    // parent::tearDown() never ran, RefreshDatabase never rolled back its
    // transaction, and every subsequent test died on a lock-wait timeout — so
    // one genuine assertion failure looked like a dozen unrelated deadlocks.
    // Laravel's own TestCase already closes Mockery, in the right order.
}
