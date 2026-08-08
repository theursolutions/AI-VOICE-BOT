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

    // ── robots.txt / sitemap.xml ─────────────────────────────────────

    /**
     * Both files are now GENERATED PER REQUEST by
     * App\Http\Controllers\SeoFilesController (routes /robots.txt and
     * /sitemap.xml), so they always reflect the current settings.
     *
     * A leftover static file under public/ would silently win over the
     * route — nginx's `try_files $uri` serves the file and PHP never runs —
     * and that is exactly how production ended up advertising a robots.txt
     * with no Sitemap: line for months. So "applying" the SEO settings now
     * means *removing* any stale static copy and dropping the response
     * cache, not writing new files.
     *
     * @return array<int,array{ok:bool,message:string}>
     */
    public function syncCrawlerFiles(): array
    {
        $notices = [];

        foreach (['robots.txt', 'sitemap.xml'] as $rel) {
            $path = $this->publicPath($rel);
            if (! is_file($path)) {
                continue;
            }
            $notices[] = @unlink($path)
                ? ['ok' => true,  'message' => "Removed the stale static /{$rel} (it is now generated live)."]
                : ['ok' => false, 'message' => "Could not remove public/{$rel} — it shadows the live /{$rel} route. Delete it on the server."];
        }

        return $notices;
    }

    /** Is a stale static file still shadowing one of the live routes? */
    public function staticShadowExists(string $rel): bool
    {
        return is_file($this->publicPath($rel));
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
