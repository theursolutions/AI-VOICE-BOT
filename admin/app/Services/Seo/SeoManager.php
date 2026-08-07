<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Http;

/**
 * Turns stored SEO settings into the real files search engines fetch from
 * the document root: /robots.txt, /sitemap.xml, and the Google/Bing
 * site-verification HTML file. Everything else (meta tags, analytics,
 * JSON-LD) is rendered inline by resources/views/partials/seo-head.blade.php.
 *
 * Physical files are written to public/ so the web server serves them
 * directly — this is what crawlers expect and it survives even if PHP
 * routing changes. All writers return a human-readable status string and
 * never throw; callers surface the message to the operator.
 */
class SeoManager
{
    /** Absolute path under the public web root. */
    protected function publicPath(string $rel): string
    {
        return public_path($rel);
    }

    /** Site origin (no trailing slash) used to build absolute URLs. */
    public function baseUrl(): string
    {
        $url = (string) tva_setting('seo.canonical_url', config('app.url'));
        return rtrim($url, '/');
    }

    // ── robots.txt ───────────────────────────────────────────────────

    /**
     * Write public/robots.txt. We always append a `Sitemap:` line pointing
     * at the generated sitemap (de-duplicated) so crawlers discover it.
     */
    public function writeRobots(string $body): array
    {
        $body = trim($body) . "\n";

        // Ensure exactly one Sitemap: line, pointing at our sitemap.
        $sitemapUrl = $this->baseUrl() . '/sitemap.xml';
        $lines = preg_split('/\R/', $body);
        $lines = array_values(array_filter($lines, fn ($l) => stripos(trim($l), 'sitemap:') !== 0));
        $lines[] = 'Sitemap: ' . $sitemapUrl;
        $final = implode("\n", $lines) . "\n";

        return $this->put('robots.txt', $final);
    }

    // ── sitemap.xml ──────────────────────────────────────────────────

    /**
     * @param array<int,array{loc:string,changefreq?:string,priority?:string}> $urls
     */
    public function buildSitemapXml(array $urls): string
    {
        $base = $this->baseUrl();
        $today = date('Y-m-d');

        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $u) {
            $loc = trim((string) ($u['loc'] ?? ''));
            if ($loc === '') {
                continue;
            }
            // Allow either absolute URLs or root-relative paths.
            if (! preg_match('#^https?://#i', $loc)) {
                $loc = $base . '/' . ltrim($loc, '/');
            }
            $changefreq = trim((string) ($u['changefreq'] ?? 'weekly'));
            $priority   = trim((string) ($u['priority'] ?? '0.5'));

            $out .= "  <url>\n";
            $out .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
            $out .= '    <lastmod>' . $today . "</lastmod>\n";
            $out .= '    <changefreq>' . htmlspecialchars($changefreq, ENT_XML1) . "</changefreq>\n";
            $out .= '    <priority>' . htmlspecialchars($priority, ENT_XML1) . "</priority>\n";
            $out .= "  </url>\n";
        }

        $out .= '</urlset>' . "\n";
        return $out;
    }

    public function writeSitemap(array $urls): array
    {
        return $this->put('sitemap.xml', $this->buildSitemapXml($urls));
    }

    // ── Google / Bing site-verification file ─────────────────────────

    /**
     * Write the verification HTML file (e.g. googleabc123.html) to the
     * web root. Filename is sanitised to a safe basename.
     */
    public function writeVerificationFile(?string $filename, ?string $body): ?array
    {
        $filename = trim((string) $filename);
        if ($filename === '') {
            return null;  // nothing to do
        }

        // Only a bare filename, alnum + dash/underscore/dot, .html/.txt.
        $filename = basename($filename);
        if (! preg_match('/^[A-Za-z0-9._-]+\.(html?|txt)$/', $filename)) {
            return ['ok' => false, 'message' => "Verification filename \"{$filename}\" is invalid (use letters/numbers and a .html or .txt extension)."];
        }

        // Google's file contains a single line; if the operator left the
        // body blank, fall back to the conventional content.
        $body = $body !== null && trim($body) !== ''
            ? $body
            : 'google-site-verification: ' . $filename;

        return $this->put($filename, $body);
    }

    // ── Sitemap submission / ping ────────────────────────────────────

    /**
     * Best-effort ping of the major engines with the sitemap URL. Google
     * & Bing have wound down their public ping endpoints, so a failure here
     * is informational — the canonical path is "submit in Search Console".
     *
     * @return array<int,array{engine:string,ok:bool,status:int|string}>
     */
    public function pingSearchEngines(): array
    {
        $sitemap = urlencode($this->baseUrl() . '/sitemap.xml');
        $targets = [
            'Google' => "https://www.google.com/ping?sitemap={$sitemap}",
            'Bing'   => "https://www.bing.com/ping?sitemap={$sitemap}",
        ];

        $results = [];
        foreach ($targets as $engine => $url) {
            try {
                $resp = Http::timeout(8)->get($url);
                $results[] = ['engine' => $engine, 'ok' => $resp->successful(), 'status' => $resp->status()];
            } catch (\Throwable $e) {
                $results[] = ['engine' => $engine, 'ok' => false, 'status' => 'unreachable'];
            }
        }

        return $results;
    }

    // ── low-level writer ─────────────────────────────────────────────

    protected function put(string $rel, string $contents): array
    {
        $path = $this->publicPath($rel);
        try {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $bytes = @file_put_contents($path, $contents);
            if ($bytes === false) {
                return ['ok' => false, 'message' => "Could not write {$rel} — check that public/ is writable."];
            }
            return ['ok' => true, 'message' => "Wrote /{$rel}", 'path' => $path];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Failed writing {$rel}: " . $e->getMessage()];
        }
    }
}
