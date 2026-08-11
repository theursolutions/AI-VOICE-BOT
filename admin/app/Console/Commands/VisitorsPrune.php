<?php

namespace App\Console\Commands;

use App\Models\Visitor;
use App\Models\VisitorPageView;
use Illuminate\Console\Command;

/**
 * Enforces the analytics retention window from config('visitors.retention_days').
 *
 * Two reasons this exists: visitor_page_views is the highest-volume table in
 * the app and will grow without bound, and an IP address is personal data in
 * several jurisdictions — "we keep it for N days" is only true if something
 * actually deletes it.
 */
class VisitorsPrune extends Command
{
    protected $signature = 'visitors:prune
                            {--days= : Override config(visitors.retention_days)}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete visitor analytics older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('visitors.retention_days', 365));

        if ($days <= 0) {
            $this->info('Retention is unlimited (retention_days = 0) — nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $dry    = (bool) $this->option('dry-run');

        $views    = VisitorPageView::where('created_at', '<', $cutoff);
        $visitors = Visitor::where('last_seen_at', '<', $cutoff);

        $viewCount    = $views->count();
        $visitorCount = $visitors->count();

        if ($dry) {
            $this->info("Would delete {$viewCount} page view(s) and {$visitorCount} visitor(s) older than {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        // Page views first: the FK cascades from visitors, but deleting the
        // rows explicitly keeps the counts we report honest either way.
        $views->delete();
        $visitors->delete();

        $this->info("Deleted {$viewCount} page view(s) and {$visitorCount} visitor(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
