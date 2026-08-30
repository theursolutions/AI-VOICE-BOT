<?php

namespace App\Console\Commands;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Services\Billing\Gateways\PaddleGateway;
use Illuminate\Console\Command;

/**
 * Mint the Paddle catalogue from our own plans.
 *
 * A transaction can only be created against a Paddle PRICE, which is an object
 * living in Paddle with its own id — so every plan we intend to sell
 * internationally needs a counterpart there. Doing it by hand in their
 * dashboard is possible and is how the ids drift: a price edited in one place
 * and not the other means the customer is charged something the product does
 * not think it sold.
 *
 * NEVER TOUCHES AN EXISTING PRICE. Paddle prices, like Stripe's, are meant to
 * be immutable once sold against; changing the amount on one would silently
 * reprice everybody already subscribed to it. So this only ever CREATES what is
 * missing, and a plan whose price has changed gets a new Paddle price the same
 * way `changePrice()` gets a new row — leaving existing subscribers where they
 * are.
 *
 * PLATFORM CURRENCY ONLY. The rupee rows exist for Safepay and Paddle will
 * never sell them; minting Paddle prices for them would double the catalogue
 * with objects no checkout can reach.
 */
class PaddleSync extends Command
{
    protected $signature = 'paddle:sync
                            {--plan= : Limit to one plan slug}
                            {--retax : Update tax mode on prices that already exist}
                            {--dry-run : Show what would be created, write nothing}';

    protected $description = 'Create the Paddle products and prices our plans need';

    public function handle(PaddleGateway $paddle): int
    {
        if (! $paddle->isConfigured()) {
            $this->error('Paddle is not configured. Set PADDLE_API_KEY and PADDLE_CLIENT_TOKEN.');

            return self::FAILURE;
        }

        $sandbox  = (bool) config('billing.paddle.sandbox');
        $currency = strtoupper((string) config('billing.currency', 'usd'));

        $this->line('');
        $this->line('  Environment  <options=bold>' . ($sandbox ? 'sandbox' : 'PRODUCTION') . '</>');
        $this->line('  API          ' . $paddle->baseUrl());
        $this->line('  Currency     ' . $currency);

        $taxMode = app(\App\Services\Billing\TaxService::class)->paddleTaxMode();

        $this->line('  Tax mode     <options=bold>' . $taxMode . '</>  (from Ops → Payments → Tax)');
        $this->line('');

        if (! $sandbox && ! $this->option('dry-run')
            && ! $this->confirm('This will create LIVE Paddle objects. Continue?', false)) {
            return self::SUCCESS;
        }

        $plans = Plan::query()
            ->where('is_active', true)
            // Add-ons too: a Paddle subscription grows by having the add-on's
            // own Paddle price added as a line item, so an add-on with no
            // Paddle price cannot be sold to a Paddle customer at all.
            ->whereIn('type', ['standard', 'custom', 'addon'])
            ->when($this->option('plan'), fn ($q) => $q->where('slug', $this->option('plan')))
            ->with('prices')
            ->ordered()
            ->get();

        if ($plans->isEmpty()) {
            $this->warn('  No sellable plans matched.');

            return self::SUCCESS;
        }

        $rows    = [];
        $created = 0;

        foreach ($plans as $plan) {
            $prices = $plan->prices
                ->where('is_active', true)
                ->filter(fn (PlanPrice $p) => strtoupper((string) $p->currency) === $currency);

            if ($prices->isEmpty()) {
                continue;
            }

            $productId = null;

            foreach ($prices as $price) {
                if ($price->isSyncedToPaddle()) {
                    // A price's AMOUNT is immutable once sold against, but its
                    // tax mode is not — and changing the tax setting has to be
                    // able to reach prices that already exist, or the setting
                    // would only ever apply to plans created after it.
                    if ($this->option('retax') && ! $this->option('dry-run')) {
                        try {
                            $paddle->request('PATCH', '/prices/' . $price->paddle_price_id, [
                                'tax_mode' => $taxMode,
                            ]);

                            $rows[] = [$plan->slug, $price->interval, $price->formatted(),
                                       $price->paddle_price_id, 'tax → ' . $taxMode];
                        } catch (\Throwable $e) {
                            $rows[] = [$plan->slug, $price->interval, $price->formatted(),
                                       $price->paddle_price_id, 'RETAX FAILED'];
                            $this->line('');
                            $this->error("  {$plan->slug} {$price->interval}: " . $e->getMessage());
                        }

                        continue;
                    }

                    $rows[] = [$plan->slug, $price->interval, $price->formatted(), $price->paddle_price_id, 'already set'];

                    continue;
                }

                if ($this->option('dry-run')) {
                    $rows[] = [$plan->slug, $price->interval, $price->formatted(), '—', 'would create'];
                    $created++;

                    continue;
                }

                try {
                    // One product per PLAN, shared by its intervals — that is
                    // how Paddle models it, and how the customer's invoice
                    // reads "Growth" rather than "Growth monthly".
                    $productId ??= $this->productFor($paddle, $plan);

                    $priceId = $this->priceFor($paddle, $productId, $price, $currency);

                    $price->forceFill([
                        'paddle_price_id'  => $priceId,
                        'paddle_synced_at' => now(),
                    ])->save();

                    $rows[] = [$plan->slug, $price->interval, $price->formatted(), $priceId, 'created'];
                    $created++;
                } catch (\Throwable $e) {
                    $rows[] = [$plan->slug, $price->interval, $price->formatted(), '—', 'FAILED'];
                    $this->line('');
                    $this->error("  {$plan->slug} {$price->interval}: " . $e->getMessage());
                }
            }
        }

        $this->table(['Plan', 'Interval', 'Price', 'Paddle price id', 'Status'], $rows);

        if ($this->option('dry-run')) {
            $this->comment("  {$created} price(s) would be created. Re-run without --dry-run.");

            return self::SUCCESS;
        }

        $this->info("  {$created} Paddle price(s) created.");
        $this->line('');
        $this->comment('  Now point Paddle at the webhook, or nothing a customer pays will be recorded:');
        $this->comment('    ' . url('/billing/paddle/webhook'));
        $this->comment('  Subscribe it to transaction.completed and the subscription.* events.');

        return self::SUCCESS;
    }

    /** A Paddle product for this plan, reusing one if the plan already has it. */
    private function productFor(PaddleGateway $paddle, Plan $plan): string
    {
        $existing = (string) data_get($plan->metadata, 'paddle_product_id', '');

        if ($existing !== '') {
            return $existing;
        }

        $response = $paddle->request('POST', '/products', [
            'name' => $plan->name,
            // Decides which tax rules Paddle applies as Merchant of Record.
            // `saas`, hyphenated-style — NOT `software_as_a_service`, which the
            // API reference implies and Paddle rejects outright.
            'tax_category' => (string) config('billing.paddle.tax_category', 'saas'),
            'custom_data'  => ['plan_slug' => $plan->slug],
        ]);

        $productId = (string) data_get($response, 'data.id', '');

        if ($productId === '') {
            throw new \RuntimeException('Paddle returned no product id.');
        }

        $plan->forceFill([
            'metadata' => array_merge((array) $plan->metadata, ['paddle_product_id' => $productId]),
        ])->save();

        return $productId;
    }

    private function priceFor(PaddleGateway $paddle, string $productId, PlanPrice $price, string $currency): string
    {
        $response = $paddle->request('POST', '/prices', [
            'product_id'  => $productId,
            'description' => trim(($price->plan?->name ?? 'Plan') . ' — ' . $price->intervalLabel()),
            'unit_price'  => [
                // A STRING, and in minor units. Paddle rejects an integer here,
                // and our own column is already minor units — so this is a cast,
                // never a conversion.
                'amount'        => (string) $price->unit_amount,
                'currency_code' => $currency,
            ],
            // Whether the amount above includes tax, in Paddle's vocabulary.
            // `location` lets Paddle decide per customer, which is what the
            // "automatic" setting means and the only correct answer when you
            // sell into both VAT and sales-tax countries.
            'tax_mode'    => app(\App\Services\Billing\TaxService::class)->paddleTaxMode(),
            'billing_cycle' => [
                'interval'  => $price->interval === 'annually' ? 'year' : ($price->interval === 'quarterly' ? 'month' : 'month'),
                'frequency' => $price->interval === 'quarterly' ? 3 : 1,
            ],
            'quantity'    => ['minimum' => 1, 'maximum' => 1],
            'custom_data' => ['plan_price_id' => (string) $price->id],
        ]);

        $priceId = (string) data_get($response, 'data.id', '');

        if ($priceId === '') {
            throw new \RuntimeException('Paddle returned no price id.');
        }

        return $priceId;
    }
}
