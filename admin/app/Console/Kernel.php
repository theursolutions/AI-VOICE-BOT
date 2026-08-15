<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Visitor analytics upkeep. Both are no-ops when there's nothing to do,
        // so they're safe to leave scheduled on every deploy.

        // Only does work on installs without a local GeoLite2 file, where
        // locations can't be resolved during the visitor's own request.
        // --sleep spaces the calls out to stay inside free-tier rate limits.
        $schedule->command('visitors:geolocate --limit=100 --sleep=250')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Enforces config('visitors.retention_days'). Without this, "we keep
        // visitor data for N days" is not actually true of anything.
        $schedule->command('visitors:prune')->dailyAt('03:20');

        // ── Billing ──────────────────────────────────────────────────
        //
        // Refresh USD→local exchange rates. THE ONLY writer of the
        // exchange_rates table: the pricing page reads cache → DB → USD-only
        // and never calls an FX API itself, so a slow or down provider can
        // never slow down or break a page a buyer is looking at.
        // Every 6h against a 6h cache TTL, so a single failed run leaves no gap.
        $schedule->command('billing:refresh-rates')
            ->everySixHours()
            ->withoutOverlapping();

        // Warn → expire → warn again → report purge queue.
        // This is a JANITOR, NOT A GATE: access is decided live by
        // Subscription::grantsAccess() comparing free_ends_at to the clock, so
        // if this doesn't run nobody gets free access they shouldn't — only the
        // emails and the status column lag. Early morning, before support hours.
        $schedule->command('billing:lifecycle')
            ->dailyAt('06:15')
            ->withoutOverlapping();

        // ── Meta channels ────────────────────────────────────────────
        //
        // Instagram Login tokens live 60 days and, unlike Facebook Page
        // tokens, have no permanent form. Meta will not refresh an already-
        // expired one, so this runs daily and works ~20 days ahead of the
        // deadline rather than on it. Miss the window and the customer has to
        // reconnect from Instagram; the symptom is replies silently failing.
        // A no-op when Instagram Login is not configured.
        $schedule->command('meta:refresh-tokens')
            ->dailyAt('04:40')
            ->withoutOverlapping();

        // Quality-rating watchdog. Meta degrades a number GREEN → YELLOW →
        // RED over days before restricting it, so a daily read means nobody
        // is ever surprised by a suspension — the warning was in the logs a
        // fortnight earlier. No-op when no WhatsApp numbers are connected.
        $schedule->command('meta:quality-check')
            ->dailyAt('05:10')
            ->withoutOverlapping();

        // GeoLite2 refresh. Shared .mmdb — one file serves both the local-price
        // display and visitor analytics. MaxMind publishes weekly; the command
        // no-ops if the file is under 6 days old, and is a silent no-op with no
        // MAXMIND_LICENSE_KEY set (pricing then simply shows USD only).
        $schedule->command('geoip:update')
            ->weeklyOn(2, '04:10')
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
