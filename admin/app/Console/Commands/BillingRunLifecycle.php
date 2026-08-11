<?php

namespace App\Console\Commands;

use App\Models\Billing\Subscription;
use App\Notifications\BillingLifecycleNotification;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The daily billing clock: warn, expire, warn again, purge.
 *
 * IMPORTANT DESIGN POINT — this command is a JANITOR, NOT A GATE. Access is
 * decided live by Subscription::grantsAccess(), which compares free_ends_at to
 * the clock on every request. So if this command doesn't run for a week, nobody
 * gets free access they shouldn't; the only thing that lags is the status
 * column, the emails and the purge. Never make access depend on a cron having
 * fired on time.
 *
 * Ordering matters: expire before purge-warning, so a workspace that lapsed
 * today can't also be told its data is about to be deleted.
 */
class BillingRunLifecycle extends Command
{
    protected $signature = 'billing:lifecycle
                            {--dry-run : Report what would happen without writing or emailing}';

    protected $description = 'Warn, expire, and purge lapsed free windows; send dunning reminders';

    private bool $dry = false;

    public function handle(SubscriptionService $subscriptions): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('DRY RUN — no writes, no emails.');
        }

        $this->warnFreeWindowsEnding();
        $this->expireLapsedFreeWindows($subscriptions);
        $this->warnBeforePurge();
        $this->purge();

        return self::SUCCESS;
    }

    // ── 1. "Your free access ends in N days" ─────────────────────────

    private function warnFreeWindowsEnding(): void
    {
        $offsets = (array) config('billing.free.warn_before_expiry_days', [3, 1]);

        foreach ($offsets as $days) {
            // Exact-day window so each workspace gets each reminder once,
            // rather than every day once it crosses the threshold.
            $subs = Subscription::query()
                ->with('client')
                ->where('status', Subscription::STATUS_FREE)
                ->whereNotNull('free_ends_at')
                ->whereBetween('free_ends_at', [
                    now()->addDays($days)->startOfDay(),
                    now()->addDays($days)->endOfDay(),
                ])
                ->get();

            foreach ($subs as $sub) {
                $this->line("  free ending in {$days}d: {$sub->client?->name}");

                $this->notify($sub, BillingLifecycleNotification::FREE_ENDING, ['days' => $days]);
            }

            if ($subs->isNotEmpty()) {
                $this->info("Free-window reminders ({$days} day): {$subs->count()}");
            }
        }
    }

    // ── 2. Expire lapsed free windows ────────────────────────────────

    private function expireLapsedFreeWindows(SubscriptionService $subscriptions): void
    {
        $lapsed = Subscription::query()->with('client')->freeWindowLapsed()->get();

        foreach ($lapsed as $sub) {
            $this->line("  expiring: {$sub->client?->name} (ended {$sub->free_ends_at?->format('j M')})");

            if (! $this->dry) {
                $subscriptions->expireFreeWindow($sub);
            }

            $this->notify($sub->fresh('client'), BillingLifecycleNotification::FREE_ENDED);
        }

        if ($lapsed->isNotEmpty()) {
            $this->info("Free windows expired: {$lapsed->count()}");
        }
    }

    // ── 3. "Your data will be deleted in N days" ─────────────────────

    private function warnBeforePurge(): void
    {
        $offsets = (array) config('billing.free.warn_before_purge_days', [7, 1]);

        foreach ($offsets as $days) {
            $subs = Subscription::query()
                ->with('client')
                ->whereNotNull('purge_after')
                ->whereBetween('purge_after', [
                    now()->addDays($days)->startOfDay(),
                    now()->addDays($days)->endOfDay(),
                ])
                ->get();

            foreach ($subs as $sub) {
                $this->line("  purge warning {$days}d: {$sub->client?->name}");

                $this->notify($sub, BillingLifecycleNotification::PURGE_WARNING, ['days' => $days]);
            }

            if ($subs->isNotEmpty()) {
                $this->info("Purge warnings ({$days} day): {$subs->count()}");
            }
        }
    }

    // ── 4. Purge ─────────────────────────────────────────────────────

    /**
     * Deliberately conservative: this reports what is due and does NOT delete
     * tenant data.
     *
     * Deleting a tenant database is irreversible and the blast radius is
     * someone's entire customer history. It needs a human decision and the
     * existing operator tooling (Ops → Clients → delete, which is audited and
     * recoverable), not an unattended nightly cron that can never be undone.
     * The two warning emails have already gone out; this surfaces the queue.
     */
    private function purge(): void
    {
        $due = Subscription::query()->with('client')->dueForPurge()->get();

        if ($due->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn("{$due->count()} workspace(s) are past their data-retention date:");

        foreach ($due as $sub) {
            $this->line(sprintf(
                '  • %s (slug: %s) — due %s',
                $sub->client?->name ?? '(deleted)',
                $sub->client?->slug ?? '?',
                $sub->purge_after?->format('j M Y')
            ));
        }

        $this->line('');
        $this->line('Review and delete these from Ops → Clients, which is audited and recoverable.');
        $this->line('Automatic deletion is intentionally not implemented: it is irreversible and');
        $this->line('destroys a customer\'s entire conversation history.');

        Log::info('billing.lifecycle.purge_due', [
            'count'      => $due->count(),
            'client_ids' => $due->pluck('client_id')->all(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function notify(?Subscription $sub, string $stage, array $context = []): void
    {
        if ($this->dry || ! $sub) {
            return;
        }

        $client = $sub->client;
        $owner  = $client?->billingOwner();

        if (! $owner || ! $owner->email) {
            Log::info('billing.lifecycle.no_recipient', [
                'client_id' => $sub->client_id,
                'stage'     => $stage,
            ]);

            return;
        }

        try {
            $owner->notify(new BillingLifecycleNotification($stage, $client, $sub, $context));
        } catch (\Throwable $e) {
            // One bad mailbox must not abort the rest of the run.
            Log::warning('billing.lifecycle.notify_failed', [
                'client_id' => $sub->client_id,
                'stage'     => $stage,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
