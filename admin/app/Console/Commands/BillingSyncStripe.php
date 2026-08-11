<?php

namespace App\Console\Commands;

use App\Models\Billing\PlanPrice;
use App\Services\Billing\StripeClientFactory;
use App\Services\Billing\StripeSyncService;
use Illuminate\Console\Command;

/**
 * Create Stripe Products/Prices for any local price that lacks one.
 *
 * Idempotent — a price that already has a stripe_price_ref is skipped, so this
 * is safe to run on every deploy. Also reports mode mismatches (a price minted
 * with a test key while the app now runs a live key), which would otherwise
 * only surface as a failed checkout for a real customer.
 */
class BillingSyncStripe extends Command
{
    protected $signature = 'billing:sync-stripe {--dry-run : List what would be created without calling Stripe}';

    protected $description = 'Create missing Stripe Products/Prices for the local plan catalogue';

    public function handle(StripeSyncService $sync, StripeClientFactory $factory): int
    {
        if (! $factory->isConfigured()) {
            $this->error('Stripe is not configured. Set STRIPE_SECRET and STRIPE_KEY in .env.');

            return self::FAILURE;
        }

        $this->info('Stripe mode: ' . ($factory->isLiveMode() ? 'LIVE' : 'TEST'));

        $pending = PlanPrice::query()
            ->with('plan')
            ->where('is_active', true)
            ->whereNull('stripe_price_ref')
            ->get()
            ->reject(fn (PlanPrice $p) => $p->plan?->isFree());

        if ($pending->isEmpty()) {
            $this->info('Nothing to sync — every active price already has a Stripe price.');
        } else {
            $this->table(
                ['Plan', 'Interval', 'Amount'],
                $pending->map(fn ($p) => [
                    $p->plan?->slug ?? '?',
                    $p->interval,
                    $p->formatted(),
                ])->all()
            );

            if ($this->option('dry-run')) {
                $this->warn('Dry run — nothing was created.');
            } else {
                $result = $sync->syncAll();

                $this->info("Created {$result['synced']} Stripe price(s).");

                if ($result['failed'] > 0) {
                    $this->error("{$result['failed']} failed:");
                    foreach ($result['errors'] as $error) {
                        $this->line('  • ' . $error);
                    }

                    return self::FAILURE;
                }
            }
        }

        // Mode mismatches are the silent killer: checkout fails only when a
        // real customer tries to pay, so surface them loudly here.
        $mismatches = $sync->modeMismatches();

        if ($mismatches !== []) {
            $this->newLine();
            $this->error(count($mismatches) . ' price(s) were created in the OTHER Stripe mode:');

            foreach ($mismatches as $price) {
                $this->line("  • {$price->plan?->slug} / {$price->interval} → {$price->stripe_price_ref}");
            }

            $this->warn('Checkout against these will fail. Archive them and add fresh prices in this mode.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
