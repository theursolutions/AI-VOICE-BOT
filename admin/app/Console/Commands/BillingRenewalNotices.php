<?php

namespace App\Console\Commands;

use App\Services\Billing\RenewalNotifier;
use Illuminate\Console\Command;

/**
 * Send renewal notices for subscriptions the gateway will not renew itself.
 *
 * Safe to run repeatedly. Every notice is claimed against a unique key before
 * it is sent, so a second run the same day finds the slot taken and sends
 * nothing — which matters because the query it runs ("whose period ends in 7
 * days") stays true for the whole of that day.
 */
class BillingRenewalNotices extends Command
{
    protected $signature = 'billing:renewal-notices
                            {--dry : Show who would be notified without sending}';

    protected $description = 'Email and WhatsApp customers whose plan needs renewing by hand';

    public function handle(RenewalNotifier $notifier): int
    {
        if ($this->option('dry')) {
            return $this->preview($notifier);
        }

        $result = $notifier->run();

        $this->info(sprintf(
            'Renewal notices: %d subscription(s) due, %d notice(s) sent, %d skipped.',
            $result['considered'],
            $result['sent'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * Who would be told, without telling them.
     *
     * Worth having as its own path rather than a flag threaded through the
     * notifier: the first question about a reminder system is always "who is
     * this about to message", and answering it should not risk messaging them.
     */
    private function preview(RenewalNotifier $notifier): int
    {
        $offsets = (array) config('billing.renewals.days_before', [7, 3, 1]);
        rsort($offsets);

        $rows = [];

        foreach ($offsets as $days) {
            $from = now()->addDays((int) $days)->startOfDay();
            $to   = now()->addDays((int) $days)->endOfDay();

            $due = \App\Models\Billing\Subscription::query()
                ->with(['client', 'plan'])
                ->whereNotNull('current_period_end')
                ->whereBetween('current_period_end', [$from, $to])
                ->whereIn('status', ['active', 'trialing'])
                ->get();

            foreach ($due as $subscription) {
                $rows[] = [
                    $subscription->client?->name ?? '(no client)',
                    $subscription->plan?->name ?? '—',
                    $subscription->current_period_end?->format('j M Y'),
                    $days . 'd',
                    $notifier->needsReminding($subscription) ? 'would send' : 'skipped',
                ];
            }
        }

        if (! $rows) {
            $this->info('Nothing due within the configured notice windows.');

            return self::SUCCESS;
        }

        $this->table(['Workspace', 'Plan', 'Period ends', 'Notice', 'Action'], $rows);
        $this->comment('Dry run — nothing was sent.');

        return self::SUCCESS;
    }
}
