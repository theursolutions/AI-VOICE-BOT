<?php

namespace App\Console\Commands;

use App\Services\Billing\GatewayCheckoutService;
use App\Services\Billing\Gateways\PaddleGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Settle payments Paddle took that we never heard about.
 *
 * THE SAFETY NET UNDER THE WEBHOOK. A webhook is a single delivery to a single
 * URL, and every reason it can fail ends the same way: the customer paid, and
 * their plan never arrived. A tunnel that was down, a deploy mid-delivery, a
 * firewall, a URL never registered at all — from our side they are
 * indistinguishable, and from the customer's side they are identical to theft.
 *
 * So rather than trusting that deliveries always land, this ASKS. Every charge
 * still pending is looked up at Paddle, and any that Paddle reports as
 * completed is applied through exactly the same path the webhook uses.
 *
 * Safe to run repeatedly and worth scheduling hourly. A charge already settled
 * is skipped, and the conditional update inside the applier means a webhook
 * arriving mid-run cannot double anything.
 */
class PaddleReconcile extends Command
{
    protected $signature = 'paddle:reconcile
                            {--hours=72 : How far back to look}
                            {--dry-run : Report what would be settled, change nothing}';

    protected $description = 'Ask Paddle about pending charges and settle any it reports as paid';

    public function handle(PaddleGateway $paddle): int
    {
        if (! $paddle->isConfigured()) {
            $this->error('Paddle is not configured.');

            return self::FAILURE;
        }

        $pending = DB::table('gateway_charges')
            ->where('gateway', 'paddle')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing pending. Every Paddle payment is accounted for.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  ' . $pending->count() . ' pending charge(s) to check with Paddle.');
        $this->line('');

        $rows     = [];
        $settled  = 0;

        foreach ($pending as $charge) {
            // Our reference is stamped into every transaction we create, so
            // Paddle can be asked "which transaction carries this?" without our
            // having stored their id — which we cannot have done, since storing
            // it is what the missing webhook was for.
            try {
                $found = $this->findTransaction($paddle, $charge->reference);
            } catch (\Throwable $e) {
                $rows[] = [$charge->reference, '—', 'LOOKUP FAILED'];
                $this->line('');
                $this->error('  ' . $charge->reference . ': ' . $e->getMessage());

                continue;
            }

            if (! $found) {
                // Genuinely never paid — a checkout opened and abandoned. Left
                // pending rather than failed: it is a record of an attempt, and
                // marking it failed would claim knowledge we do not have.
                $rows[] = [$charge->reference, '—', 'no transaction — abandoned'];

                continue;
            }

            $status = (string) data_get($found, 'status', '');

            if (! in_array($status, ['completed', 'paid'], true)) {
                $rows[] = [$charge->reference, data_get($found, 'id'), 'at Paddle: ' . $status];

                continue;
            }

            if ($this->option('dry-run')) {
                $rows[] = [$charge->reference, data_get($found, 'id'), 'WOULD SETTLE'];
                $settled++;

                continue;
            }

            $this->applyPaid($found);

            $fresh = DB::table('gateway_charges')->where('id', $charge->id)->first();

            $rows[] = [
                $charge->reference,
                data_get($found, 'id'),
                $fresh->status === 'paid' ? 'SETTLED — plan applied' : 'still ' . $fresh->status,
            ];

            if ($fresh->status === 'paid') {
                $settled++;
            }
        }

        $this->table(['Our reference', 'Paddle transaction', 'Outcome'], $rows);

        if ($this->option('dry-run')) {
            $this->comment("  {$settled} would be settled. Re-run without --dry-run.");

            return self::SUCCESS;
        }

        $this->info("  {$settled} payment(s) settled.");

        if ($settled > 0) {
            $this->line('');
            $this->warn('  These were paid but never reached us — the webhook is not arriving.');
            $this->warn('  Check Paddle → Developer Tools → Notifications is pointed at:');
            $this->warn('    ' . url('/billing/paddle/webhook'));
        }

        return self::SUCCESS;
    }

    /**
     * The transaction carrying one of our references, if there is one.
     *
     * Paddle has no "find by custom_data" filter, so a page of recent
     * transactions is scanned. Bounded deliberately: this is a reconciliation
     * pass over things that are already stale, not a search over all history.
     */
    private function findTransaction(PaddleGateway $paddle, string $reference): ?array
    {
        $response = $paddle->request('GET', '/transactions?per_page=100&status=completed,paid,billed');

        foreach ((array) data_get($response, 'data', []) as $txn) {
            if ((string) data_get($txn, 'custom_data.reference', '') === $reference) {
                return (array) $txn;
            }
        }

        return null;
    }

    /**
     * Settle it the same way the webhook would.
     *
     * Deliberately routed through the ordinary applier rather than reimplemented
     * here: a second code path for granting a plan is a second place for it to
     * be granted wrongly, and this one runs unattended.
     */
    private function applyPaid(array $txn): void
    {
        $reference      = (string) data_get($txn, 'custom_data.reference', '');
        $transactionId  = (string) data_get($txn, 'id', '');
        $subscriptionId = (string) data_get($txn, 'subscription_id', '');

        $charge = DB::table('gateway_charges')->where('reference', $reference)->first();

        if (! $charge || $charge->status === 'paid') {
            return;
        }

        $claimed = DB::table('gateway_charges')
            ->where('id', $charge->id)
            ->where('status', 'pending')
            ->update([
                'status'                 => 'paid',
                'gateway_ref'            => $transactionId,
                'paddle_subscription_id' => $subscriptionId ?: null,
                'raw'                    => json_encode($txn),
                'paid_at'                => now(),
                'updated_at'             => now(),
            ]);

        if (! $claimed) {
            return;   // a webhook landed in the meantime
        }

        $subscription = app(GatewayCheckoutService::class)->applyPaidCharge(
            DB::table('gateway_charges')->find($charge->id)
        );

        if ($subscription) {
            $subscription->forceFill(array_filter([
                'paddle_subscription_id' => $subscriptionId ?: null,
                'paddle_customer_id'     => (string) data_get($txn, 'customer_id', '') ?: null,
            ]))->save();

            if (! $charge->subscription_id) {
                DB::table('gateway_charges')->where('id', $charge->id)->update([
                    'subscription_id' => $subscription->id,
                    'updated_at'      => now(),
                ]);
            }
        }
    }
}
