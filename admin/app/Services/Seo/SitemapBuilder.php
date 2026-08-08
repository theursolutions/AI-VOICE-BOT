<?php

namespace App\Services\Seo;

use App\Support\Seo;
use Illuminate\Support\Facades\Route;

/**
 * Builds the public XML sitemap from the curated page list in
 * config/site.php (`seo.sitemap_urls`, overridable from /admin/seo).
 *
 * Rules enforced here so a mis-typed entry in the console can never put a
 * bad URL in front of Google:
 *
 *   • every URL is rewritten onto the canonical origin and canonical path
 *     spelling (see App\Support\Seo) — no duplicates, no trailing slashes;
 *   • anything on the noindex list (`seo.noindex_paths`) is dropped, so the
 *     sitemap can never disagree with the robots meta tag on the page;
 *   • paths that resolve to no route at all are dropped — a sitemap must
 *     not contain 404s;
 *   • `lastmod` comes from the mtime of the Blade template that renders the
 *     page, so it moves when the content actually changes instead of being
 *     stamped with "today" on every fetch (which crawlers learn to ignore).
 *
 * Above 5,000 URLs the output automatically becomes a sitemap index with
 * chunked child sitemaps (Google's hard limits are 50,000 URLs / 50 MB;
 * we stay well under both).
 */
class SitemapBuilder
{
    /** URLs per child sitemap once splitting kicks in. */
    public const CHUNK_SIZE = 5000;

    /**
     * The normalised, de-duplicated, indexable URL set.
     *
     * @return array<int,array{loc:string,path:string,lastmod:string,changefreq:string,priority:string}>
     */
    public function urls(): array
    {
        $configured = tva_setting('seo.sitemap_urls', []);
        $configured = is_array($configured) ? $configured : [];

        $noindex = $this->noindexPaths();
        $views   = (array) config('site.seo.page_views', []);

        $out = [];
        foreach ($configured as $entry) {
            $loc = is_array($entry) ? (string) ($entry['loc'] ?? '') : (string) $entry;
            if (trim($loc) === '') {
                continue;
            }

            // Accept absolute URLs, but only for our own origin — an entry
            // pointing somewhere else is a mistake, not a redirect target.
            if (preg_match('#^https?://#i', $loc)) {
                $host = parse_url($loc, PHP_URL_HOST);
                if ($host && $host !== parse_url(Seo::origin(), PHP_URL_HOST)) {
                    continue;
                }
                $loc = (string) parse_url($loc, PHP_URL_PATH) ?: '/';
            }

            $path = Seo::path($loc);

            if (in_array($path, $noindex, true) || isset($out[$path])) {
                continue;
            }
            if (! $this->routeExists($path)) {
                continue;
            }

            $out[$path] = [
                'loc'        => Seo::canonical($path),
                'path'       => $path,
                'lastmod'    => $this->lastmod($views[$path] ?? null),
                'changefreq' => trim((string) (is_array($entry) ? ($entry['changefreq'] ?? '') : '')) ?: 'monthly',
                'priority'   => trim((string) (is_array($entry) ? ($entry['priority'] ?? '') : '')) ?: '0.5',
            ];
        }

        return array_values($out);
    }

    /** Paths that must never be indexed (and so never appear in a sitemap). */
    public function noindexPaths(): array
    {
        $paths = (array) config('site.seo.noindex_paths', []);

        return array_map(fn ($p) => Seo::path($p), $paths);
    }

    /** True when the sitemap has to be split across an index file. */
    public function needsIndex(): bool
    {
        return count($this->urls()) > self::CHUNK_SIZE;
    }

    /** Number of child sitemaps (1 when no splitting is needed). */
    public function chunkCount(): int
    {
        return max(1, (int) ceil(count($this->urls()) / self::CHUNK_SIZE));
    }

    // ── XML rendering ────────────────────────────────────────────────

    /** A <urlset> document, optionally for one chunk (1-based). */
    public function urlsetXml(?int $chunk = null): string
    {
        $urls = $this->urls();
        if ($chunk !== null) {
            $urls = array_slice($urls, (max(1, $chunk) - 1) * self::CHUNK_SIZE, self::CHUNK_SIZE);
        }

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
            $xml .= '    <lastmod>' . $u['lastmod'] . "</lastmod>\n";
            $xml .= '    <changefreq>' . htmlspecialchars($u['changefreq'], ENT_XML1) . "</changefreq>\n";
            $xml .= '    <priority>' . htmlspecialchars($u['priority'], ENT_XML1) . "</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>' . "\n";

        return $xml;
    }

    /** A <sitemapindex> document pointing at the chunked child sitemaps. */
    public function indexXml(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $lastmod = collect($this->urls())->max('lastmod') ?: date('Y-m-d');

        for ($i = 1; $i <= $this->chunkCount(); $i++) {
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . htmlspecialchars(Seo::origin() . "/sitemap-{$i}.xml", ENT_XML1) . "</loc>\n";
            $xml .= '    <lastmod>' . $lastmod . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }

        $xml .= '</sitemapindex>' . "\n";

        return $xml;
    }

    /** What GET /sitemap.xml serves: an index when split, otherwise the urlset. */
    public function xml(): string
    {
        return $this->needsIndex() ? $this->indexXml() : $this->urlsetXml();
    }

    // ── internals ────────────────────────────────────────────────────

    /**
     * Does a GET route actually answer this path? Guards against a stale
     * console entry advertising a URL that now 404s.
     */
    protected function routeExists(string $path): bool
    {
        $uri = ltrim($path, '/');
        $uri = $uri === '' ? '/' : $uri;

        foreach (Route::getRoutes()->getRoutesByMethod()['GET'] ?? [] as $route) {
            if ($route->uri() === $uri) {
                return true;
            }
        }

        return false;
    }

    /** ISO date from a Blade template's mtime, falling back to today. */
    protected function lastmod(?string $view): string
    {
        if ($view) {
            $file = resource_path('views/' . str_replace('.', '/', $view) . '.blade.php');
            if (is_file($file) && ($t = @filemtime($file))) {
                return date('Y-m-d', $t);
            }
        }

        return date('Y-m-d');
    }
}
