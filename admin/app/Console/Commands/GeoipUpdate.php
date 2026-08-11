<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Download / refresh the MaxMind GeoLite2 database.
 *
 * ONE FILE, TWO FEATURES: config/billing.php and config/visitors.php both read
 * GEOIP_DATABASE_PATH, so this single download serves both the approximate
 * local pricing on /pricing and the visitor-analytics location column. The
 * City edition is the default because it is a superset of Country — billing
 * only needs the country code, visitors wants the city.
 *
 * A local .mmdb is why the pricing page can show an approximate local price
 * with zero added latency, zero per-lookup cost and no visitor IP leaving our
 * infrastructure. MaxMind updates GeoLite2 weekly; scheduled accordingly.
 *
 * Requires a free MaxMind account:
 *   MAXMIND_ACCOUNT_ID + MAXMIND_LICENSE_KEY
 *
 * Failure is non-fatal by design: without the database, GeoLocationService
 * returns null and every visitor simply sees USD — which is always correct.
 */
class GeoipUpdate extends Command
{
    protected $signature = 'geoip:update {--force : Re-download even if the database is recent}';

    protected $description = 'Download or refresh the MaxMind GeoLite2-Country database';

    public function handle(): int
    {
        $accountId  = (string) config('billing.geo.maxmind.account_id');
        $licenseKey = (string) config('billing.geo.maxmind.license_key');
        $target     = (string) config('billing.geo.maxmind.database_path');
        $edition    = (string) config('billing.geo.maxmind.edition', 'GeoLite2-Country');

        if ($licenseKey === '') {
            $this->warn('MAXMIND_LICENSE_KEY is not set — skipping.');
            $this->line('Get a free key at https://www.maxmind.com/en/geolite2/signup');
            $this->line('Without it, pricing shows USD only. Nothing else is affected.');

            return self::SUCCESS;
        }

        // Weekly cadence upstream; don't re-download on every scheduler tick.
        if (! $this->option('force') && File::exists($target)) {
            $ageDays = (time() - File::lastModified($target)) / 86400;

            if ($ageDays < 6) {
                $this->info(sprintf('Database is %.1f days old — still fresh. Use --force to re-download.', $ageDays));

                return self::SUCCESS;
            }
        }

        File::ensureDirectoryExists(dirname($target));

        $url = 'https://download.maxmind.com/geoip/databases/' . $edition . '/download?suffix=tar.gz';

        $this->info("Downloading {$edition}…");

        try {
            $response = Http::withBasicAuth($accountId, $licenseKey)
                            ->timeout(120)
                            ->get($url);

            if (! $response->successful()) {
                $this->error("MaxMind returned HTTP {$response->status()}.");

                return self::FAILURE;
            }

            // Extract to a temp path and only move into place on success, so a
            // truncated download can never replace a working database.
            $tmpArchive = storage_path('app/geoip/_download.tar.gz');
            File::ensureDirectoryExists(dirname($tmpArchive));
            File::put($tmpArchive, $response->body());

            $extractDir = storage_path('app/geoip/_extract');
            File::deleteDirectory($extractDir);
            File::ensureDirectoryExists($extractDir);

            $phar = new \PharData($tmpArchive);
            $phar->decompress();                                   // .tar.gz → .tar
            $tar = new \PharData(str_replace('.tar.gz', '.tar', $tmpArchive));
            $tar->extractTo($extractDir, null, true);

            $found = collect(File::allFiles($extractDir))
                ->first(fn ($file) => $file->getExtension() === 'mmdb');

            if (! $found) {
                $this->error('No .mmdb file found inside the archive.');

                return self::FAILURE;
            }

            File::move($found->getRealPath(), $target);

            // Tidy up; leaving a ~60MB tar behind on every run adds up.
            File::delete($tmpArchive);
            File::delete(str_replace('.tar.gz', '.tar', $tmpArchive));
            File::deleteDirectory($extractDir);

            $this->info('Installed: ' . $target
                . ' (' . number_format(File::size($target) / 1048576, 1) . ' MB)');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Update failed: ' . $e->getMessage());
            $this->line('Existing database (if any) was left in place.');

            return self::FAILURE;
        }
    }
}
