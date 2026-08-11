<?php

namespace App\Console\Commands;

use App\Services\Currency\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * Refresh USD → local exchange rates.
 *
 * THE ONLY WRITER of the exchange_rates table. Scheduled every 6 hours so no
 * user request ever triggers an outbound FX call — the pricing page reads
 * cache → DB → USD-only and can never be slowed or broken by a third party.
 *
 * On provider failure this is a no-op: existing rows are left untouched, so a
 * bad response can't blank every local price on the site.
 */
class BillingRefreshRates extends Command
{
    protected $signature = 'billing:refresh-rates';

    protected $description = 'Fetch and cache USD exchange rates for approximate local pricing (display only)';

    public function handle(ExchangeRateService $rates): int
    {
        if (! config('billing.fx.enabled', true)) {
            $this->warn('FX is disabled (FX_ENABLED=false) — nothing to do. Prices will show USD only.');

            return self::SUCCESS;
        }

        $provider = $rates->provider()->name();

        $this->info("Fetching USD rates from [{$provider}]…");

        $stored = $rates->refresh();

        if ($stored === 0) {
            // Not a failure exit code: the site is still perfectly functional
            // on the last good rates, and a red cron alert every time a free
            // FX API hiccups trains people to ignore alerts.
            $this->warn('No rates stored. Existing rates were left untouched.');

            return self::SUCCESS;
        }

        $this->info("Stored {$stored} exchange rate(s).");

        // Spot-check the home market so the log line is actually useful.
        if ($rate = $rates->rateFor('PKR')) {
            $this->line('  USD→PKR: ' . number_format($rate, 4)
                . '  ($19 ≈ ' . $rates->convertAndFormat(1900, 'PKR') . ')');
        }

        return self::SUCCESS;
    }
}
