<?php

use App\Models\Billing\Plan;
use App\Models\Billing\PlanGrant;
use App\Models\Billing\PlanPrice;
use App\Models\Client;
use App\Services\Billing\ConversationPricer;
use App\Services\Billing\LocalPriceService;
use App\Services\Billing\PlanFeatureService;
use App\Services\Billing\WorkspacePlanService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reprice for the local market: lower prices, bought with smaller VOLUMES, and
 * not one feature taken away.
 *
 *   plan      was            now                     messages          phone min   numbers   widget voice
 *   Starter   $26  Rs 8,000   $15  Rs 4,500    20,000 →  10,000     60 →  50      1          500 →   200
 *   Growth    $75  Rs 22,500  $39  Rs 12,000   75,000 →  25,000    300 → 200      3 → 2    3,000 → 1,000
 *   Scale     $199 Rs 60,000  $99  Rs 30,000  150,000 →  60,000  1,200 → 500     10 → 5    unlimited → 5,000
 *
 * Annual stays "2 months free" (ten times the monthly price). Conversations
 * are still derived — messages divided by replies per conversation — and come
 * out at 500 / 1,000 / 2,000, with Growth's default conversation length set to
 * 25 replies so its figure is a round one.
 *
 * ONLY THE THINGS THAT COST MONEY SHRINK. Messages are LLM tokens, phone
 * minutes and numbers are carrier cash, widget voice is speech processing on
 * our own CPUs. Seats, agents, projects, data sources, indexed pages, history
 * and every on/off feature are exactly as they were: none costs anything
 * meaningful to deliver, and taking them away would make the cheaper plan feel
 * like a demo rather than a smaller version of the same product.
 *
 * THE STARTING PRICE IS THE CONSTRAINT: Rs 3,000–5,000 a month. Rupee prices
 * are minted from dollars at billing.local_pricing.PKR (Rs 300 to the dollar,
 * rounded UP to the next Rs 500), so $15 is exactly Rs 4,500.
 *
 * MARGIN — 50 to 70 percent is the target, and every paid tier holds it with the
 * customer using 100% of the allowance, i.e. the worst case; a typical
 * workspace uses a fraction of it and earns more. Variable cost per month:
 *
 *   AI message   $0.0003      ConversationPricer::FALLBACK_PER_MESSAGE, measured
 *                             from the prompt builders under hybrid routing
 *   phone minute $0.012       carrier $0.0085 plus speech-to-text and the AI
 *                             replies inside the call
 *   phone number $1.15        rental
 *   widget voice $0.0005      speech processing; the reply is already a message
 *   Ask AI       $0.0037/seat 20 questions a month
 *
 * and payment fees on top — Paddle 5% + $0.50 abroad, Safepay ~3% at home, with
 * rupee revenue converted at an interbank Rs 280:
 *
 *   plan      cost at 100%   monthly: Paddle / Safepay   annual: Paddle / Safepay
 *   Starter   $4.86          59% / 67%                   56% / 61%
 *   Growth    $12.74         61% / 67%                   56% / 60%
 *   Scale     $32.34         62% / 67%                   56% / 60%
 *
 * The one number that could move this is the cost of a message. At $0.0004 —
 * every reply and task on Gemini Flash-Lite — the worst case falls about four
 * points (Starter annual through Paddle is the tightest, near 48%). Before that
 * becomes likely, ConversationPricer's measured figure will say so.
 *
 * EXISTING SUBSCRIBERS KEEP WHAT THEY PAID FOR. Allowances live on the plan, so
 * without this every current customer's allowance would be cut mid-period. Each
 * one gets the difference as a grant (PlanGrant — additive to the plan):
 *
 *   • a subscription Stripe or Paddle renews by itself keeps its old price
 *     until someone moves it, so it keeps the old allowance too — no expiry;
 *   • anything else (Safepay, a manual or comped plan) renews at the new
 *     price, so the grant ends with the period already paid for;
 *   • a workspace that already carries a grant for that feature is left
 *     alone and logged — that is an operator's decision, not ours to overwrite.
 *
 * NEW PRICES ARE NEW ROWS, as in the previous repricing: Stripe and Paddle
 * prices are immutable, so the old rows are archived (with their provider ids,
 * which is what down() restores) and the new ones start unsynced. Checkout
 * through Stripe or Paddle refuses an unsynced price — the safe way to fail —
 * until `php artisan billing:sync-stripe` / `php artisan paddle:sync` run.
 * Rupee rows need no provider and work immediately.
 */
return new class extends Migration
{
    /** Stamped on every row written or archived here, so down() can find them. */
    private const MARKER = 'reprice-2026-09';

    /** slug => [monthly, annual], in the platform currency's minor units. */
    private const PRICES = [
        'starter' => [1500, 15000],
        'growth'  => [3900, 39000],
        'scale'   => [9900, 99000],
    ];

    private const PREVIOUS_PRICES = [
        'starter' => [2600, 26000],
        'growth'  => [7500, 75000],
        'scale'   => [19900, 199000],
    ];

    /** feature => [slug => value]. Paid plans only: Free and Enterprise are unchanged. */
    private const VALUES = [
        'messages'                 => ['starter' => '10000', 'growth' => '25000', 'scale' => '60000'],
        'conversations'            => ['starter' => '500',   'growth' => '1000',  'scale' => '2000'],
        'replies_per_conversation' => ['starter' => '20',    'growth' => '25',    'scale' => '30'],
        'telephony_minutes'        => ['starter' => '50',    'growth' => '200',   'scale' => '500'],
        'phone_numbers'            => ['starter' => '1',     'growth' => '2',     'scale' => '5'],
        'voice_messages'           => ['starter' => '200',   'growth' => '1000',  'scale' => '5000'],
    ];

    private const PREVIOUS_VALUES = [
        'messages'                 => ['starter' => '20000', 'growth' => '75000', 'scale' => '150000'],
        'conversations'            => ['starter' => '1000',  'growth' => '2500',  'scale' => '5000'],
        'replies_per_conversation' => ['starter' => '20',    'growth' => '30',    'scale' => '30'],
        'telephony_minutes'        => ['starter' => '60',    'growth' => '300',   'scale' => '1200'],
        'phone_numbers'            => ['starter' => '1',     'growth' => '3',     'scale' => '10'],
        'voice_messages'           => ['starter' => '500',   'growth' => '3000',  'scale' => '-1'],
    ];

    /**
     * The allowances a grant can protect: the ones read through clientLimit().
     * Conversations cap nothing, and the reply budget is read from the plan
     * alone — both are defaults rather than something a customer spends.
     */
    private const PROTECTED = ['messages', 'telephony_minutes', 'phone_numbers', 'voice_messages'];

    /** A subscription in any of these is on its plan today. */
    private const LIVE_STATUSES = ['active', 'trialing', 'past_due'];

    public function up(): void
    {
        DB::transaction(function () {
            // Read BEFORE writing: the grants must restore what each plan
            // actually carried, including any operator edit, not what this
            // file believes it carried.
            $before = $this->currentLimits();

            $this->writeValues(self::VALUES);
            $this->reprice(self::PRICES);
            $this->grandfather($before);
        });

        $this->flushCaches();
    }

    // ── Allowances ──────────────────────────────────────────────────────

    /** @return array<string, array<string, int|null>>  slug => feature => limit (null = unlimited) */
    private function currentLimits(): array
    {
        $features = app(PlanFeatureService::class);
        $features->flush();

        $out = [];

        foreach (array_keys(self::PRICES) as $slug) {
            $plan = Plan::where('slug', $slug)->first();

            foreach (self::PROTECTED as $key) {
                $out[$slug][$key] = $plan ? $features->planLimit($plan, $key) : 0;
            }
        }

        return $out;
    }

    /** @param array<string, array<string, string>> $values */
    private function writeValues(array $values): void
    {
        foreach ($values as $key => $byPlan) {
            $featureId = DB::table('features')->where('key', $key)->value('id');

            if (! $featureId) {
                continue;
            }

            foreach ($byPlan as $slug => $value) {
                $planId = DB::table('plans')->where('slug', $slug)->value('id');

                if (! $planId) {
                    continue;
                }

                DB::table('plan_features')->updateOrInsert(
                    ['plan_id' => $planId, 'feature_id' => $featureId],
                    ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }

    // ── Prices ──────────────────────────────────────────────────────────

    /** @param array<string, array{0:int, 1:int}> $prices */
    private function reprice(array $prices): void
    {
        $local = app(LocalPriceService::class);

        foreach ($prices as $slug => [$monthly, $annual]) {
            $plan = Plan::where('slug', $slug)->first();

            if (! $plan) {
                continue;
            }

            $changed = [];

            foreach (['monthly' => $monthly, 'annually' => $annual] as $interval => $amount) {
                if ($this->replaceBasePrice($plan, $interval, $amount)) {
                    $changed[] = $interval;
                }
            }

            // Rupee prices follow the dollar ones. The old rows are retired
            // FIRST because mirror() leaves any interval that still has an
            // active local row alone — which is right for an operator's hand
            // edit and wrong for a repricing. Skipped when no rate is set, so
            // a missing config cannot leave a plan with no rupee price at all.
            if ($changed && $local->isConfigured('PKR')) {
                PlanPrice::where('plan_id', $plan->id)
                    ->where('currency', 'pkr')
                    ->whereIn('interval', $changed)
                    ->where('is_active', true)
                    ->get()
                    ->each(fn (PlanPrice $row) => $this->archive($row));

                foreach ($local->mirror($plan->fresh(), 'PKR') as $minted) {
                    $minted->forceFill([
                        'metadata' => array_merge((array) $minted->metadata, ['created_by' => self::MARKER]),
                    ])->save();
                }
            }
        }
    }

    /**
     * Swap one interval's platform-currency price. False when it is already
     * right, so a rerun changes nothing and churns no provider ids.
     */
    private function replaceBasePrice(Plan $plan, string $interval, int $amount): bool
    {
        $base = strtolower((string) config('billing.currency', 'usd'));

        $existing = PlanPrice::where('plan_id', $plan->id)
            ->where('interval', $interval)
            ->where('currency', $base)
            ->where('is_active', true)
            ->get();

        if ($existing->count() === 1 && (int) $existing->first()->unit_amount === $amount) {
            return false;
        }

        // Retire BEFORE inserting: priceFor() takes the first active row for an
        // interval, so an overlap would make the live price arbitrary.
        $existing->each(fn (PlanPrice $row) => $this->archive($row));

        PlanPrice::create([
            'plan_id'            => $plan->id,
            'interval'           => $interval,
            'currency'           => $base,
            'unit_amount'        => $amount,
            // Same product, new price: the product reference carries over, the
            // price references cannot (they are unique, and immutable upstream).
            'stripe_product_ref' => $existing->first()?->stripe_product_ref,
            'is_active'          => true,
            'effective_from'     => now(),
            'metadata'           => ['created_by' => self::MARKER],
        ]);

        return true;
    }

    private function archive(PlanPrice $row): void
    {
        $row->forceFill([
            'is_active'   => false,
            'archived_at' => now(),
            'metadata'    => array_merge((array) $row->metadata, ['archived_by' => self::MARKER]),
        ])->save();
    }

    // ── Existing subscribers ────────────────────────────────────────────

    /** @param array<string, array<string, int|null>> $before */
    private function grandfather(array $before): void
    {
        $plans = Plan::whereIn('slug', array_keys(self::PRICES))->pluck('slug', 'id');

        if ($plans->isEmpty()) {
            return;
        }

        // The CURRENT subscription is the newest default row per workspace —
        // the rule Billable::currentSubscription() applies.
        $current = DB::table('subscriptions')
            ->where('type', 'default')
            ->selectRaw('MAX(id)')
            ->groupBy('client_id');

        $subscriptions = DB::table('subscriptions')
            ->whereIn('id', $current)
            ->whereIn('plan_id', $plans->keys())
            ->whereIn('status', self::LIVE_STATUSES)
            ->get();

        $workspaces = app(WorkspacePlanService::class);

        foreach ($subscriptions as $sub) {
            $slug      = $plans[$sub->plan_id];
            $recurring = ! empty($sub->stripe_subscription_ref) || ! empty($sub->paddle_subscription_id);
            $periodEnd = $sub->current_period_end ? Carbon::parse($sub->current_period_end) : null;

            // Renews at the new price and its period is already over: nothing
            // left that was paid for at the old one.
            if (! $recurring && $periodEnd && $periodEnd->isPast()) {
                continue;
            }

            $client = Client::find($sub->client_id);

            if (! $client) {
                continue;
            }

            foreach (self::PROTECTED as $key) {
                // array_key_exists, not ??: null is a real value here — it
                // means UNLIMITED — and ?? would turn it into "none".
                $had = array_key_exists($key, $before[$slug] ?? []) ? $before[$slug][$key] : 0;
                $now = (int) self::VALUES[$key][$slug];

                // Unlimited before, bounded now: the grant lifts the bound.
                // Kept apart from the arithmetic on purpose — UNLIMITED is -1,
                // so a difference of -1 must never be mistaken for it.
                if ($had === null) {
                    $topUp = PlanGrant::UNLIMITED;
                } else {
                    $topUp = $had - $now;

                    // Already at or below the new figure (an operator lowered
                    // it): nothing was lost, so nothing to give back.
                    if ($topUp <= 0) {
                        continue;
                    }
                }

                if (PlanGrant::where('client_id', $client->id)->where('feature_key', $key)->exists()) {
                    Log::warning('billing.reprice.grant_exists', [
                        'client'  => $client->id,
                        'feature' => $key,
                        'wanted'  => $topUp,
                    ]);

                    continue;
                }

                $workspaces->grant(
                    $client,
                    $key,
                    $topUp,
                    null,
                    '[' . self::MARKER . '] Kept at the allowance this workspace bought before the '
                        . 'September 2026 repricing, ' . ($recurring
                            ? 'for as long as it pays the original price. Revoke when it moves to the new one.'
                            : 'until the period it already paid for ends.'),
                    $recurring ? null : $periodEnd,
                );
            }
        }
    }

    private function flushCaches(): void
    {
        app(PlanFeatureService::class)->flush();
        ConversationPricer::forget();
    }

    /**
     * Back to the previous catalogue: the old price rows are reactivated with
     * their provider ids intact, the rows this migration created are retired
     * (not deleted — a charge may already reference one), and its grants go.
     */
    public function down(): void
    {
        DB::transaction(function () {
            $this->writeValues(self::PREVIOUS_VALUES);

            $planIds = Plan::whereIn('slug', array_keys(self::PREVIOUS_PRICES))->pluck('id');

            $rows = PlanPrice::whereIn('plan_id', $planIds)->get();

            $rows->filter(fn (PlanPrice $p) => ($p->metadata['created_by'] ?? null) === self::MARKER)
                ->each(function (PlanPrice $p) {
                    $p->forceFill(['is_active' => false, 'archived_at' => now()])->save();
                });

            $rows->filter(fn (PlanPrice $p) => ($p->metadata['archived_by'] ?? null) === self::MARKER)
                ->each(function (PlanPrice $p) {
                    $metadata = (array) $p->metadata;
                    unset($metadata['archived_by']);

                    $p->forceFill(['is_active' => true, 'archived_at' => null, 'metadata' => $metadata])->save();
                });

            $granted = PlanGrant::where('note', 'like', '[' . self::MARKER . ']%');
            $clients = (clone $granted)->distinct()->pluck('client_id');
            $granted->delete();

            // Grants are cached per workspace; the rows are gone, but a cached
            // copy would go on applying until it expired.
            foreach (Client::whereIn('id', $clients)->get() as $client) {
                app(PlanFeatureService::class)->flushGrants($client);
            }
        });

        $this->flushCaches();
    }
};
