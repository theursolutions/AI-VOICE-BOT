<?php

namespace App\Console\Commands;

use App\Models\Billing\Plan;
use App\Services\Billing\LocalPriceService;
use Illuminate\Console\Command;

/**
 * Mint selling prices in a currency the local gateway can settle.
 *
 * Run after a repricing, after adding a plan, and once when a new local
 * gateway is introduced. It never touches a row that already exists, so
 * running it twice is a no-op and running it after an operator has adjusted a
 * rupee price by hand does not undo their edit.
 */
class BillingLocalPrices extends Command
{
    protected $signature = 'billing:local-prices
                            {--currency=PKR : Currency to mint prices in}
                            {--plan= : Limit to one plan slug}
                            {--dry-run : Show what would be minted, write nothing}';

    protected $description = 'Create plan prices in a local currency, converted from the platform price';

    public function handle(LocalPriceService $local): int
    {
        $currency = strtoupper((string) $this->option('currency'));

        if (! $local->isConfigured($currency)) {
            $this->error("No rate configured for {$currency}. Set billing.local_pricing.{$currency}.rate.");

            return self::FAILURE;
        }

        $rate = $local->rate($currency);
        $step = (int) config("billing.local_pricing.{$currency}.step", 1);

        $this->line('');
        $this->line("  Converting at <options=bold>1 " . strtoupper((string) config('billing.currency', 'usd'))
            . " = {$rate} {$currency}</>, rounded up to the nearest {$step}.");
        $this->line('');

        $plans = Plan::query()
            ->where('is_active', true)
            ->whereIn('type', ['standard', 'custom', 'addon'])
            ->when($this->option('plan'), fn ($q) => $q->where('slug', $this->option('plan')))
            ->with('prices')
            ->orderBy('sort_order')
            ->get();

        if ($plans->isEmpty()) {
            $this->warn('  No matching plans.');

            return self::SUCCESS;
        }

        $rows  = [];
        $count = 0;

        foreach ($plans as $plan) {
            $base = $plan->prices->where('is_active', true)
                ->filter(fn ($p) => strtolower($p->currency) === strtolower((string) config('billing.currency', 'usd')));

            foreach ($base as $source) {
                $exists = $plan->prices->first(
                    fn ($p) => $p->is_active
                        && $p->interval === $source->interval
                        && strtoupper($p->currency) === $currency
                );

                $converted = $local->convert((int) $source->unit_amount, $currency);

                $rows[] = [
                    $plan->slug,
                    $source->interval,
                    number_format($source->unit_amount / 100, 2) . ' ' . strtoupper($source->currency),
                    number_format($exists ? $exists->unit_amount / 100 : $converted / 100) . ' ' . $currency,
                    $exists ? 'already set' : ($this->option('dry-run') ? 'would mint' : 'minted'),
                ];

                if (! $exists) {
                    $count++;
                }
            }

            if (! $this->option('dry-run')) {
                $local->mirror($plan, $currency);
            }
        }

        $this->table(['Plan', 'Interval', 'Platform price', $currency . ' price', 'Status'], $rows);

        if ($this->option('dry-run')) {
            $this->comment("  {$count} price(s) would be created. Re-run without --dry-run to write them.");

            return self::SUCCESS;
        }

        $this->info("  {$count} price(s) created.");
        $this->line('');
        $this->comment('  These are now SELLING PRICES, not conversions — review them on');
        $this->comment('  Super Admin → Billing → Plans and adjust anything that reads oddly.');

        return self::SUCCESS;
    }
}
