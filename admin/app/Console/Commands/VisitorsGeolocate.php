<?php

namespace App\Console\Commands;

use App\Models\Visitor;
use App\Support\IpLocator;
use Illuminate\Console\Command;

/**
 * Fills in locations for visitor rows still marked `pending`.
 *
 * Only installs without a local GeoLite2 file need this: those fall back to a
 * rate-limited public endpoint, which deliberately never runs inside a
 * visitor's request. Schedule it every few minutes, or run it by hand.
 */
class VisitorsGeolocate extends Command
{
    protected $signature = 'visitors:geolocate
                            {--limit=200 : How many addresses to resolve in this run}
                            {--sleep=0 : Milliseconds to pause between lookups (respect provider limits)}';

    protected $description = 'Resolve pending visitor IP addresses to locations';

    public function handle(IpLocator $locator): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $pause = max(0, (int) $this->option('sleep'));

        if ($locator->isInstant()) {
            $this->info('Local GeoLite2 database present — new visits resolve inline. Backfilling any older rows.');
        }

        $rows = Visitor::needsGeo()->orderByDesc('last_seen_at')->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing pending.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        $resolved = 0;
        foreach ($rows as $v) {
            $geo = $locator->locate((string) $v->ip);

            $v->fill([
                'geo_status'      => $geo['status'],
                'continent'       => $geo['continent'],
                'country'         => $geo['country'],
                'country_code'    => $geo['country_code'],
                'region'          => $geo['region'],
                'city'            => $geo['city'],
                'postal'          => $geo['postal'],
                'timezone'        => $geo['timezone'],
                'org'             => $geo['org'],
                'asn'             => $geo['asn'],
                'connection_type' => $geo['connection_type'],
                'latitude'        => $geo['latitude'],
                'longitude'       => $geo['longitude'],
            ])->save();

            if ($geo['status'] === Visitor::GEO_DONE) {
                $resolved++;
            }

            $bar->advance();
            if ($pause > 0) {
                usleep($pause * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Resolved {$resolved} of {$rows->count()}. " . Visitor::needsGeo()->count() . ' still pending.');

        return self::SUCCESS;
    }
}
