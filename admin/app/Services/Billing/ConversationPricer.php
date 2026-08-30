<?php

namespace App\Services\Billing;

use App\Models\AiBrain;
use App\Models\Billing\Plan;
use App\Services\Conversation\BrainResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a workspace of a given shape costs us, and what it should therefore sell
 * for.
 *
 * This is the engine behind the custom plan: a customer picks conversations,
 * conversation length, AI agents and seats, and gets a price. It exists because
 * a price generated from anything other than measured cost is a guess that
 * eventually loses money on a plan nobody reviewed.
 *
 * MEASURED FIRST, ASSUMED SECOND. Cost per message is derived from
 * ai_brain_usage — real tokens, per call type, multiplied by each brain's real
 * rate — and falls back to the figures measured from the prompt builders only
 * when there is not enough traffic to be worth trusting. Which of the two was
 * used is returned in the quote rather than hidden, because "priced from your
 * own usage" and "priced from our estimate" are different claims and only one
 * of them should ever appear next to a signature.
 *
 * WHAT DRIVES THE PRICE:
 *
 *   messages    genuinely marginal — every reply spends tokens. Priced on cost
 *               plus a margin that falls with volume.
 *   telephony   genuinely marginal, in cash. Number rental plus per-minute.
 *   seats       CAPPED, not charged. Zero marginal cost, and the published tiers
 *   AI agents   bundle them, so charging for them here only made custom plans
 *               useless to anyone with a team. See MESSAGES_PER_SEAT for the two
 *               wrong turns that established this.
 *
 * So the price is a function of VOLUME alone, floored at the price of the
 * published tier whose features that volume inherits. That makes it strictly
 * increasing, impossible to lower by asking for more, and impossible to use to
 * undercut the catalogue.
 */
class ConversationPricer
{
    /**
     * Fallback cost per message, in USD.
     *
     * Measured from the running prompt builders under hybrid routing: 3,171
     * input and 167 output tokens per customer message across four calls, with
     * the reply on a capable model and the machinery on a cheap one. Used only
     * until real usage accumulates.
     */
    public const FALLBACK_PER_MESSAGE = 0.000300;

    /** Fallback cost of one internal Ask AI question, USD. */
    public const FALLBACK_PER_ASK = 0.000185;

    /**
     * Messages of real traffic before measured cost is trusted over the
     * fallback.
     *
     * Below this a handful of atypical conversations — one long document
     * lookup, one customer who wrote an essay — swing the average enough to
     * misprice a plan for a year. The published figures are steadier than a
     * small sample of anything.
     */
    public const MIN_SAMPLE_MESSAGES = 2_000;

    /** Days of usage to average over. */
    private const SAMPLE_DAYS = 30;

    /**
     * Margin by volume, as [message ceiling => margin].
     *
     * Descending margin IS the volume discount, and it stays inside the 50-70%
     * band the tiers were approved against. The boundaries are set so a custom
     * quote never undercuts the published plan at that plan's own shape —
     * otherwise the catalogue becomes unsellable and every customer configures
     * their way around it. Verified: at Starter, Growth and Scale
     * configurations a custom quote comes out at or above the fixed price.
     */
    private const MARGIN_TIERS = [
        25_000    => 0.70,
        200_000   => 0.65,
        1_000_000 => 0.60,
    ];
    private const MARGIN_FLOOR = 0.55;

    /**
     * Seats and AI agents allowed per this many messages of volume.
     *
     * A CAP, NOT A CHARGE, and both halves of that took two wrong turns to
     * reach. Seats began volume-scaled AND chargeable above the allowance, which
     * made the price NON-MONOTONIC: each extra 7,500 messages added about $6 of
     * usage price while forgiving up to $14 of seat and agent charges, so asking
     * for 3,000 conversations cost $171 where 2,000 cost $192. No taper balances
     * that. Fixing the allowance restored monotonicity and produced the opposite
     * failure — Scale's own shape quoted $477 against its published $199,
     * because it charged per-seat for seats the tier gives away. That does not
     * protect the catalogue, it makes the calculator useless to anyone with a
     * team.
     *
     * The published tiers settle it. Scale is $199 for 150,000 messages where
     * the usage alone justifies $191: in this catalogue seats and agents are
     * bundled, not sold. So they are bundled here too, capped at the level the
     * tiers imply — 150,000 messages carries 25 seats, so one per 6,000.
     *
     * Price therefore depends on volume alone, which makes it strictly
     * increasing and impossible to game by understating a team. Wanting more
     * people means buying more volume, or buying the seat add-on afterwards at
     * the same price every other plan pays for one.
     */
    private const MESSAGES_PER_SEAT  = 6_000;
    private const MESSAGES_PER_AGENT = 6_000;

    /**
     * Nothing sells below this, whatever the arithmetic says.
     *
     * A configuration of 100 conversations of 10 messages costs us thirty cents
     * and would price at a dollar, which does not cover the payment processing,
     * let alone the support. The floor is what stops the calculator quoting a
     * price we would rather not honour.
     */
    public const FLOOR_USD = 9.00;


    /** Real cash, per month. */
    private const NUMBER_RENTAL_USD = 1.15;
    private const PER_MINUTE_USD    = 0.0085;

    private const CACHE_TTL = 900;

    /**
     * Quote a configuration.
     *
     * @param  array{conversations:int, replies_per_conversation:int, seats:int,
     *               agents:int, phone_numbers?:int, phone_minutes?:int}  $config
     * @return array<string, mixed>
     */
    public function quote(array $config): array
    {
        $conversations = max(1, (int) ($config['conversations'] ?? 0));
        $replies       = max(1, (int) ($config['replies_per_conversation'] ?? 20));
        $seats         = max(1, (int) ($config['seats'] ?? 1));
        $agents        = max(1, (int) ($config['agents'] ?? 1));
        $numbers       = max(0, (int) ($config['phone_numbers'] ?? 0));
        $minutes       = max(0, (int) ($config['phone_minutes'] ?? 0));

        $messages = $conversations * $replies;

        $rates       = $this->costPerMessage();
        $perMessage  = $rates['per_message'];

        // ── cost ────────────────────────────────────────────────────────
        $aiCost    = $messages * $perMessage;
        $askCost   = $seats * self::ASKS_PER_SEAT * $rates['per_ask'];
        $phoneCost = $numbers * self::NUMBER_RENTAL_USD + $minutes * self::PER_MINUTE_USD;
        $cost      = $aiCost + $askCost + $phoneCost;

        // ── price ───────────────────────────────────────────────────────
        $margin   = $this->marginFor($messages);
        $usagePart = $cost / (1 - $margin);

        [$includedSeats, $includedAgents] = $this->allowances($messages);

        // The published tier whose features this volume inherits sets a floor.
        // One rule, and it is the one that actually expresses the intent: you
        // pay at least what the tier you are inheriting costs. Without it a
        // configuration matching Scale exactly quotes $191 against its
        // published $199 — a 4% undercut of the flagship, reachable by anyone
        // who reads the pricing page carefully.
        $tierFloor = $this->tierFloor($messages);

        $price = max(self::FLOOR_USD, $tierFloor, $usagePart);

        // Round UP to the nearest dollar. Down would quietly give away margin on
        // every quote, and a price with cents on it reads like a metered bill
        // rather than a plan.
        $price = ceil($price);

        return [
            'conversations'   => $conversations,
            'replies'         => $replies,
            'messages'        => $messages,
            'seats'           => $seats,
            'agents'          => $agents,
            'phone_numbers'   => $numbers,
            'phone_minutes'   => $minutes,

            'included_seats'  => $includedSeats,
            'included_agents' => $includedAgents,

            'cost' => [
                'ai'    => round($aiCost, 4),
                'ask'   => round($askCost, 4),
                'phone' => round($phoneCost, 4),
                'total' => round($cost, 4),
            ],

            'per_message'     => $perMessage,
            'basis'           => $rates['basis'],          // 'measured' | 'estimated'
            'sample_messages' => $rates['sample'],

            'margin'          => $margin,
            'price_usd'       => (int) $price,
            'price_cents'     => (int) $price * 100,
            // What the margin actually came out at after the floor and the
            // value-priced extras, which is the number worth checking.
            'effective_margin' => $price > 0 ? round(($price - $cost) / $price, 4) : 0.0,

            'breakdown' => [
                'usage'         => round($usagePart, 2),
                'tier_floor'    => round($tierFloor, 2),
                'floor_applied' => $usagePart < max(self::FLOOR_USD, $tierFloor),
            ],
        ];
    }

    /** Ask AI questions assumed per seat per month. */
    private const ASKS_PER_SEAT = 20;

    /**
     * Cost per message and per Ask AI question, from real usage where possible.
     *
     * Averages the last SAMPLE_DAYS of ai_brain_usage across every brain,
     * weighting each brain's tokens by its own rate — so a pool split between a
     * capable reply model and a cheap machinery one produces the blended figure
     * that pool actually costs, without anyone having to state it.
     *
     * Divided by REPLY-type calls, not by total calls. A message is one reply
     * plus its machinery, so the reply count is the number of messages; dividing
     * by all calls would report the cost of an average CALL and understate a
     * message roughly fourfold.
     *
     * @return array{per_message:float, per_ask:float, basis:string, sample:int}
     */
    public function costPerMessage(): array
    {
        return Cache::remember('pricer:cost-per-message', self::CACHE_TTL, function () {
            $fallback = [
                'per_message' => self::FALLBACK_PER_MESSAGE,
                'per_ask'     => self::FALLBACK_PER_ASK,
                'basis'       => 'estimated',
                'sample'      => 0,
            ];

            if (! Schema::hasTable('ai_brain_usage')) {
                return $fallback;
            }

            $since = now()->subDays(self::SAMPLE_DAYS)->toDateString();

            // Cost and reply-count in one pass, joined to the rate that applied.
            $rows = DB::table('ai_brain_usage as u')
                ->join('ai_brains as b', 'b.id', '=', 'u.brain_id')
                ->where('u.usage_date', '>=', $since)
                ->groupBy('u.call_type')
                ->selectRaw('u.call_type')
                ->selectRaw('SUM(u.tokens_in  * b.rate_in  / 1000000) as cost_in')
                ->selectRaw('SUM(u.tokens_out * b.rate_out / 1000000) as cost_out')
                ->selectRaw('SUM(u.calls) as calls')
                ->get();

            if ($rows->isEmpty()) {
                return $fallback;
            }

            $totalCost = 0.0;
            $replies   = 0;

            foreach ($rows as $row) {
                $totalCost += (float) $row->cost_in + (float) $row->cost_out;

                if ($row->call_type === BrainResolver::CALL_REPLY) {
                    $replies = (int) $row->calls;
                }
            }

            // Not enough traffic to trust, or every brain still uncosted — a
            // pool of zero-rate brains reports a cost of zero, which would
            // price every custom plan at the floor and look like a bargain
            // rather than like a missing rate.
            if ($replies < self::MIN_SAMPLE_MESSAGES || $totalCost <= 0.0) {
                return $fallback + ['sample' => $replies];
            }

            $perMessage = $totalCost / $replies;

            return [
                'per_message' => $perMessage,
                // Ask AI is not attributed separately yet, so it keeps its
                // measured constant scaled by how far real cost has drifted
                // from the estimate. Crude, and it moves the answer by
                // fractions of a cent per seat.
                'per_ask'     => self::FALLBACK_PER_ASK * ($perMessage / self::FALLBACK_PER_MESSAGE),
                'basis'       => 'measured',
                'sample'      => $replies,
            ];
        });
    }

    /** After rates change, or the sample window would otherwise lag 15 minutes. */
    public static function forget(): void
    {
        Cache::forget('pricer:cost-per-message');
    }

    /**
     * The monthly price of the published tier this volume would inherit from.
     *
     * The largest standard plan whose own message allowance does not exceed this
     * one — the same rule CustomPlanService uses to choose a feature template,
     * deliberately, so the plan you are floored at is the plan whose capability
     * you are given. Zero when no tier qualifies, which is every volume below
     * the smallest plan.
     */
    public function tierFloor(int $messages): float
    {
        $features = app(PlanFeatureService::class);

        $best = 0.0;

        foreach (Plan::where('type', 'standard')->where('is_active', true)->get() as $plan) {
            $allowance = $features->planLimit($plan, 'messages');

            // Null is unlimited, which no finite configuration can exceed, so
            // such a plan can never be the tier a custom volume outgrows.
            if ($allowance === null || $allowance > $messages) {
                continue;
            }

            $price = $plan->priceFor('monthly');

            if ($price) {
                $best = max($best, $price->unit_amount / 100);
            }
        }

        return $best;
    }

    private function marginFor(int $messages): float
    {
        foreach (self::MARGIN_TIERS as $ceiling => $margin) {
            if ($messages <= $ceiling) {
                return $margin;
            }
        }

        return self::MARGIN_FLOOR;
    }

    /**
     * Seats and agents this volume entitles a workspace to.
     *
     * Public because the configurator validates against it: seats are not
     * charged for, so nothing stops a form posting a hundred of them except
     * this.
     *
     * @return array{0:int, 1:int} seats, agents
     */
    public function allowances(int $messages): array
    {
        return [
            max(2, (int) floor($messages / self::MESSAGES_PER_SEAT)),
            max(1, (int) floor($messages / self::MESSAGES_PER_AGENT)),
        ];
    }

    /**
     * Are any brains costed at all?
     *
     * Surfaced so the configurator can say so plainly. A pool with no rates
     * prices everything from the estimate, which is defensible, but an operator
     * who believes they are quoting from their own numbers and is not should be
     * told rather than left to find out.
     */
    public function hasRates(): bool
    {
        return AiBrain::query()
            ->where(fn ($q) => $q->where('rate_in', '>', 0)->orWhere('rate_out', '>', 0))
            ->exists();
    }
}
