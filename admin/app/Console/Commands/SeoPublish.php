<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoManager;
use App\Services\Seo\SitemapBuilder;
use App\Support\Seo;
use Illuminate\Console\Command;

/**
 * Deploy-time SEO sanity step.
 *
 * /robots.txt and /sitemap.xml are routes now, but nginx serves a real file
 * of the same name in preference to any route. A server that was deployed
 * before this change still has public/robots.txt sitting there, silently
 * overriding the live version — so the deploy has to delete it once.
 *
 * Also prints what a crawler will actually see, which makes a broken
 * canonical origin (APP_URL still http://localhost, say) obvious in the
 * deploy log instead of three weeks later in Search Console.
 */
class SeoPublish extends Command
{
    protected $signature = 'seo:publish {--check : Report only; change nothing}';

    protected $description = 'Remove stale static robots.txt/sitemap.xml and report the live crawler view';

    public function handle(SeoManager $seo, SitemapBuilder $sitemap): int
    {
        $this->line('Canonical origin: ' . Seo::origin());

        if (! str_starts_with(Seo::origin(), 'https://')) {
            $this->warn('  ⚠ Canonical origin is not HTTPS. Set APP_URL (or the canonical URL in /admin/seo) to the public https:// origin — every canonical tag, sitemap URL and JSON-LD id is built from it.');
        }

        foreach (['robots.txt', 'sitemap.xml'] as $rel) {
            if (! $seo->staticShadowExists($rel)) {
                $this->line("  ok  /{$rel} — served live by the app");
                continue;
            }
            if ($this->option('check')) {
                $this->warn("  ⚠ public/{$rel} exists and shadows the live route (run without --check to remove).");
                continue;
            }
            $this->info("  removing stale public/{$rel} …");
        }

        if (! $this->option('check')) {
            foreach ($seo->syncCrawlerFiles() as $notice) {
                $notice['ok'] ? $this->info('  ' . $notice['message']) : $this->error('  ' . $notice['message']);
            }
        }

        $urls = $sitemap->urls();
        $this->line('Sitemap: ' . count($urls) . ' indexable URL(s)'
            . ($sitemap->needsIndex() ? ' across ' . $sitemap->chunkCount() . ' child sitemaps' : ''));
        foreach ($urls as $u) {
            $this->line('  ' . $u['loc'] . '  (lastmod ' . $u['lastmod'] . ')');
        }

        if ($urls === []) {
            $this->error('Sitemap is EMPTY — check seo.sitemap_urls in config/site.php or /admin/seo.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
