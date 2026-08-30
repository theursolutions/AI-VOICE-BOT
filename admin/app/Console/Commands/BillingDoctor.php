<?php

namespace App\Console\Commands;

use App\Models\Billing\Plan;
use App\Services\Billing\Gateways\GatewayRegistry;
use App\Services\Billing\PlanService;
use App\Support\Payments;
use Illuminate\Console\Command;

/**
 * Why can nobody buy anything?
 *
 * "The plan cards show no buy button" has at least four distinct causes that
 * are indistinguishable from the outside, and the page deliberately renders no
 * placeholder for any of them — so the symptom carries no information at all:
 *
 *   1. checkout.enabled is false          (the master switch, ships OFF)
 *   2. the plan is not purchasable        (wrong type, or inactive)
 *   3. the plan has no active price row   (nothing to charge)
 *   4. no gateway is usable               (button appears, click fails)
 *
 * The provider doctors answer "can we reach Paddle / Safepay". This answers the
 * question before that one: can a customer get as far as pressing a button, and
 * will it do anything. Run it first; it costs nothing and touches no network.
 */
class BillingDoctor extends Command
{
    protected $signature = 'billing:doctor {--country= : Check routing as if the customer were here}';

    protected $description = 'Explain why plans can or cannot be bought right now';

    public function handle(GatewayRegistry $registry, PlanService $plans): int
    {
        $ok = true;

        // ── 1. The master switch ─────────────────────────────────────
        $this->line('');
        $this->line('<options=bold>Can anything be bought at all?</>');

        $enabled = (bool) config('billing.checkout.enabled', false);

        $ok = $this->result(
            'Checkout is switched on',
            $enabled,
            'BILLING_CHECKOUT_ENABLED is not true. Every paid plan card renders with NO button '
            . 'and no placeholder, and the checkout endpoints refuse. This is the usual answer.',
        ) && $ok;

        if ($enabled && config('billing.checkout.in_app_only', true)) {
            $this->line('       <fg=gray>In-app checkout: buying happens on our own pages.</>');
        }

        // ── 2. The plans themselves ──────────────────────────────────
        $this->line('');
        $this->line('<options=bold>Plans</>');

        $rows    = [];
        $sellable = 0;

        foreach (Plan::with(['prices' => fn ($q) => $q->where('is_active', true)])->ordered()->get() as $plan) {
            $prices = $plan->prices->count();
            $onPage = $plan->is_active && $plan->is_public;
            $buyable = $enabled && $plan->isPurchasable() && $prices > 0;

            if ($buyable) {
                $sellable++;
            }

            $rows[] = [
                $plan->slug,
                $plan->type,
                $plan->is_active ? 'yes' : '<fg=red>no</>',
                $plan->is_public ? 'yes' : 'no',
                $prices ?: '<fg=red>none</>',
                $this->verdict($plan, $onPage, $buyable, $enabled, $prices),
            ];
        }

        $this->table(['Plan', 'Type', 'Active', 'Public', 'Prices', 'Buy button'], $rows);

        $ok = $this->result(
            "{$sellable} plan(s) can be bought",
            $sellable > 0,
            'No plan is sellable. A plan needs to be active, of type standard or custom, and have '
            . 'at least one active price.',
        ) && $ok;

        // ── 3. Somewhere for the money to go ─────────────────────────
        $this->line('');
        $this->line('<options=bold>Payment providers</>');

        $usable = $registry->usable();

        $ok = $this->result(
            'At least one provider can take a payment',
            $usable !== [],
            'Every provider is either switched off in Ops → Payments or missing credentials. '
            . 'The buttons will appear and the click will fail.',
        ) && $ok;

        foreach ($registry->all() as $gateway) {
            $on  = Payments::gatewayEnabled($gateway->key());
            $cfg = $gateway->isConfigured();

            $this->line(sprintf(
                '       %-9s %-14s %s',
                $gateway->key(),
                $on ? 'switched on' : 'switched off',
                $cfg ? 'credentials set' : '<fg=red>no credentials</>',
            ));
        }

        // ── 4. What a real customer would get ────────────────────────
        $this->line('');
        $this->line('<options=bold>Routing</>');

        // array_merge, NOT `+`. The union operator keys on INDEX, so
        // ['PK'] + ['US', 'GB'] silently drops 'US' — it collides with index 0.
        // That is how the US quietly disappeared from this table.
        $countries = $this->option('country')
            ? [strtoupper((string) $this->option('country'))]
            : array_merge(array_keys(Payments::LOCAL_COUNTRIES), ['US', 'GB', 'DE']);

        $routes = [];

        foreach (array_unique($countries) as $country) {
            $gateway = $registry->forCountry($country);
            $price   = null;

            try {
                // The whole path a checkout takes, exercised without a customer:
                // if this throws, the button works and the page behind it does not.
                $first = Plan::where('type', 'standard')->where('is_active', true)->ordered()->first();

                if ($first && $gateway) {
                    $row = $plans->resolvePrice(
                        $first->slug,
                        'monthly',
                        null,
                        $gateway->currencies()[0] ?? null,
                    );
                    $price = $row->formatted();
                }
            } catch (\Throwable $e) {
                $price = '<fg=red>' . \Illuminate\Support\Str::limit($e->getMessage(), 60) . '</>';
                $ok = false;
            }

            $routes[] = [
                $country,
                $gateway?->key() ?? '<fg=red>none</>',
                $registry->currencyForCountry($country),
                $price ?? '—',
            ];
        }

        $this->table(['Country', 'Provider', 'Currency', 'First plan resolves to'], $routes);

        $this->line('');

        if ($ok) {
            $this->info('  Plans can be bought.');
            $this->line('');
            $this->comment('  This says a customer can reach a working checkout. Whether the provider');
            $this->comment('  then accepts the payment is what paddle:doctor and safepay:doctor answer.');
        } else {
            $this->warn('  Fix the failures above — until then the plan cards are read-only.');
        }

        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** Why this particular plan has no button, in the fewest possible words. */
    private function verdict(Plan $plan, bool $onPage, bool $buyable, bool $enabled, int $prices): string
    {
        // Order matters: a plan can be perfectly buyable AND invisible. A
        // private custom plan is exactly that — reachable by its own workspace,
        // absent from the public page — and reporting "yes" for it would answer
        // a question nobody asked while hiding the one they did.
        if (! $onPage) {
            return $buyable ? 'buyable, but not public' : 'not on the public page';
        }

        if ($buyable) {
            return '<fg=green>yes</>';
        }

        if ($plan->isFree()) {
            return 'free — sign-up link';
        }

        if ($plan->isEnterprise()) {
            return 'enterprise — contact link';
        }

        if ($prices === 0) {
            return '<fg=red>no active price</>';
        }

        if (! $enabled) {
            return '<fg=red>checkout switched off</>';
        }

        return '<fg=red>not purchasable</>';
    }

    private function result(string $label, bool $passed, ?string $hint = null): bool
    {
        $this->line(sprintf('  %s %s', $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>', $label));

        if (! $passed && $hint) {
            foreach (explode("\n", wordwrap($hint, 84)) as $line) {
                $this->line('       <fg=yellow>' . $line . '</>');
            }
        }

        return $passed;
    }
}
