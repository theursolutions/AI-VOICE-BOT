<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a quote into a real, purchasable plan for one client.
 *
 * Nothing here is a new billing concept. `plans.type` already accepted `custom`
 * and such a plan already passed isPurchasable() and selectable(); checkout only
 * ever needed a plan_prices row plus a synced Stripe price. So a custom plan is
 * an ordinary catalogue row that happens to be private to one workspace, and it
 * flows through the same entitlement, usage and checkout machinery as Starter —
 * which is the point. A parallel path for bespoke plans is how two pricing
 * systems that disagree get built.
 *
 * PRIVATE, NOT PUBLIC: is_public = 0 keeps it off /pricing, and metadata.client_id
 * records whose it is. `plans` has no client_id column and adding one to a table
 * with seven live rows to express something only custom plans need would put a
 * mostly-null column in front of every query that reads the catalogue.
 *
 * FEATURES ARE INHERITED FROM A TEMPLATE TIER, not invented. A custom plan is a
 * volume variation, not a different product, so it takes the module access of
 * the published plan closest below it in message volume. Without that rule the
 * choice is between granting every custom plan the full feature set — so a $9
 * configuration gets white-label and BYOK that Scale charges $199 for — and
 * hand-maintaining a second feature matrix that will drift from the first.
 */
class CustomPlanService
{
    /** Numeric features the quote sets directly; everything else is inherited. */
    private const QUOTED_FEATURES = [
        'messages'                 => 'messages',
        'conversations'            => 'conversations',
        'replies_per_conversation' => 'replies',
        'seats'                    => 'seats',
        'agents'                   => 'agents',
        'phone_numbers'            => 'phone_numbers',
        'telephony_minutes'        => 'phone_minutes',
    ];

    public function __construct(
        private readonly ConversationPricer $pricer,
        private readonly PlanFeatureService $features,
    ) {
    }

    /**
     * Build (or rebuild) a client's custom plan from a configuration.
     *
     * Idempotent per client: reconfiguring retires the previous custom plan
     * rather than accumulating them, so `plans` does not fill with dead rows and
     * there is never a question of which one is live. The old row is deactivated
     * rather than deleted — a subscription may still reference it, and a
     * dangling plan_id would break the billing page for the customer whose plan
     * it was.
     *
     * @param  array<string, mixed>  $config
     */
    public function build(Client $client, array $config): Plan
    {
        $quote    = $this->pricer->quote($config);
        $template = $this->templateFor((int) $quote['messages']);

        return DB::transaction(function () use ($client, $quote, $template) {
            $this->retirePrevious($client);

            $plan = Plan::create([
                'name'        => $this->nameFor($quote),
                'slug'        => $this->slugFor($client),
                'tagline'     => sprintf(
                    '%s conversations a month, %s AI replies each.',
                    number_format($quote['conversations']),
                    number_format($quote['replies'])
                ),
                'type'        => 'custom',
                'is_active'   => true,
                // Private: never on /pricing, only reachable by this workspace.
                'is_public'   => false,
                'is_featured' => false,
                'sort_order'  => 50,
                'cta_label'   => 'Start on this plan',
                'trial_days'  => 0,
                'metadata'    => [
                    'client_id' => $client->id,
                    'template'  => $template?->slug,
                    // The quote is stored verbatim so a price can be explained
                    // months later. Without it "why is this $82" has no answer
                    // once rates or the cost basis have moved on.
                    'quote'     => $quote,
                    'built_at'  => now()->toIso8601String(),
                ],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $this->priceIt($plan, (int) $quote['price_cents']);
            $this->applyFeatures($plan, $template, $quote);

            return $plan;
        });
    }

    /**
     * The published tier a custom plan inherits its feature access from: the
     * largest standard plan whose own message allowance does not exceed this
     * one's, falling back to the smallest.
     *
     * So feature access tracks scale exactly as the published ladder does, and a
     * customer configuring Growth-sized volume gets Growth-sized capability
     * without anyone maintaining a second matrix.
     */
    public function templateFor(int $messages): ?Plan
    {
        $standard = Plan::where('type', 'standard')->where('is_active', true)->ordered()->get();

        if ($standard->isEmpty()) {
            return null;
        }

        $eligible = $standard
            ->filter(fn (Plan $p) => ($this->features->planLimit($p, 'messages') ?? PHP_INT_MAX) <= $messages)
            ->sortByDesc(fn (Plan $p) => $this->features->planLimit($p, 'messages') ?? 0);

        return $eligible->first() ?? $standard->first();
    }

    /**
     * Copy the template's features, then override the quoted ones.
     *
     * Copy-then-override rather than a curated list, because the failure modes
     * are not symmetric: a feature we forget to copy silently locks a paying
     * customer out of a module, while one copied that we did not think about is
     * at worst generous. New features added to the catalogue are picked up here
     * automatically, which is the behaviour that keeps this from drifting.
     */
    private function applyFeatures(Plan $plan, ?Plan $template, array $quote): void
    {
        $values = [];

        if ($template) {
            foreach ($template->planFeatures()->with('feature')->get() as $row) {
                if ($row->feature) {
                    $values[$row->feature->key] = $row->value;
                }
            }
        }

        foreach (self::QUOTED_FEATURES as $featureKey => $quoteKey) {
            // Only override what the quote actually carries. A zero phone
            // allowance is meaningful and must overwrite the template's; a
            // missing key must not.
            if (array_key_exists($quoteKey, $quote)) {
                $values[$featureKey] = (string) $quote[$quoteKey];
            }
        }

        // syncFeatures() is keyed by feature ID, not by key — it casts whatever
        // it is given with (int), so a string key silently becomes feature 0 and
        // every value is written against a row that does not exist. Resolve the
        // ids here, and drop anything the features table does not know rather
        // than persisting an orphan.
        $ids = \App\Models\Billing\Feature::query()
            ->whereIn('key', array_keys($values))
            ->pluck('id', 'key');

        $byId = [];

        foreach ($values as $key => $value) {
            if ($ids->has($key)) {
                $byId[(int) $ids[$key]] = $value;
            }
        }

        $this->features->syncFeatures($plan, $byId);
    }

    /**
     * Monthly and annual, annual at ten months to match the catalogue — then
     * minted at Stripe immediately.
     *
     * SYNCED HERE, NOT LATER, and that is the whole point. The published tiers
     * can wait for an operator to press Sync Stripe because an operator created
     * them; a custom plan is created by a CUSTOMER who has just pressed "use this
     * plan", and leaving its price unminted meant the only possible outcome was
     * being told we would email them. A self-serve flow whose last step is a
     * promise to get back to you is not self-serve.
     *
     * Failure is tolerated rather than fatal. If Stripe is unconfigured or the
     * call fails, the rows still exist with a null stripe_price_ref, checkout
     * still refuses them, and the caller still shows the "we are finishing this
     * off" message — so the worst case is exactly the behaviour this replaces,
     * never a plan that half-exists or a customer charged for something that was
     * never created.
     */
    private function priceIt(Plan $plan, int $monthlyCents): void
    {
        $sync = app(StripeSyncService::class);

        // The product has to exist before any price can point at it.
        try {
            $sync->syncProduct($plan);
        } catch (\Throwable $e) {
            Log::warning('billing.custom_plan.product_sync_failed', [
                'plan_id' => $plan->id, 'error' => $e->getMessage(),
            ]);
        }

        foreach (['monthly' => $monthlyCents, 'annually' => $monthlyCents * 10] as $interval => $amount) {
            $id = DB::table('plan_prices')->insertGetId([
                'plan_id'         => $plan->id,
                'interval'        => $interval,
                'currency'        => 'usd',
                'unit_amount'     => $amount,
                'is_active'       => true,
                'effective_from'  => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            try {
                $sync->syncPrice(PlanPrice::findOrFail($id));
            } catch (\Throwable $e) {
                // Left unminted. Checkout refuses it, which is the safe
                // direction, and the operator can sync it from the Plans page.
                Log::warning('billing.custom_plan.price_sync_failed', [
                    'plan_id' => $plan->id, 'interval' => $interval, 'error' => $e->getMessage(),
                ]);
            }
        }

        // A custom plan is built for one workspace, and that workspace may be
        // the one paying in rupees — so it needs a local price for the same
        // reason the published tiers do. Without this the customer configures a
        // plan, is quoted a price, and is then told at checkout that the plan
        // has no price in their currency.
        try {
            app(LocalPriceService::class)->mirror($plan->refresh(), 'PKR');
        } catch (\Throwable $e) {
            Log::warning('billing.custom_plan.local_price_failed', [
                'plan_id' => $plan->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retire this client's previous custom plans.
     *
     * Deactivated, not deleted: a subscription may still point at one, and a
     * dangling plan_id breaks the billing page for exactly the customer whose
     * plan it was.
     */
    private function retirePrevious(Client $client): void
    {
        $previous = Plan::where('type', 'custom')
            ->where('metadata->client_id', $client->id)
            ->get();

        foreach ($previous as $plan) {
            $plan->forceFill(['is_active' => false, 'updated_at' => now()])->save();

            DB::table('plan_prices')
                ->where('plan_id', $plan->id)
                ->update(['is_active' => false, 'archived_at' => now(), 'updated_at' => now()]);
        }
    }

    /** The live custom plan for a workspace, if it has one. */
    public function currentFor(Client $client): ?Plan
    {
        return Plan::where('type', 'custom')
            ->where('is_active', true)
            ->where('metadata->client_id', $client->id)
            ->first();
    }

    private function nameFor(array $quote): string
    {
        return 'Custom · ' . number_format($quote['conversations']) . ' conversations';
    }

    /**
     * A slug that is unique and stays readable in a URL or an invoice line.
     *
     * Counts existing rows rather than using a timestamp so rebuilding twice in
     * one second cannot collide, which a per-second stamp would.
     */
    private function slugFor(Client $client): string
    {
        $n = Plan::where('type', 'custom')
            ->where('metadata->client_id', $client->id)
            ->count() + 1;

        return "custom-{$client->id}-{$n}";
    }
}
