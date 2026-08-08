<?php

namespace App\Http\Controllers;

use App\Services\Seo\SitemapBuilder;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the two files every crawler asks for — /robots.txt and
 * /sitemap.xml — straight from the live SEO settings.
 *
 * These used to exist only as static files under public/, written when a
 * super-admin happened to press Save in the SEO console. In practice that
 * meant production shipped a robots.txt with no `Sitemap:` line and no
 * sitemap.xml at all (GET /sitemap.xml returned 404), because nobody had
 * pressed the button. Generating them per-request removes that failure
 * mode: the URLs are always present and always agree with the meta tags
 * rendered on the pages themselves.
 *
 * Both responses carry a one-hour Cache-Control so crawlers and any edge
 * cache stop re-fetching them constantly; the bodies themselves are built
 * fresh each time (a handful of array operations — cheaper than the cache
 * lookup would be).
 */
class SeoFilesController extends Controller
{
    public const CACHE_TTL = 3600;

    public function __construct(private SitemapBuilder $sitemap) {}

    /** GET /robots.txt */
    public function robots(): Response
    {
        return $this->xmlOrText($this->robotsBody(), 'text/plain; charset=UTF-8');
    }

    /** GET /sitemap.xml — a urlset, or a sitemapindex once split. */
    public function sitemap(): Response
    {
        return $this->xmlOrText($this->sitemap->xml(), 'application/xml; charset=UTF-8');
    }

    /** GET /sitemap-{n}.xml — one chunk of a split sitemap. */
    public function sitemapChunk(Request $request, int $n): Response
    {
        abort_unless($n >= 1 && $n <= $this->sitemap->chunkCount(), 404);

        return $this->xmlOrText($this->sitemap->urlsetXml($n), 'application/xml; charset=UTF-8');
    }

    /**
     * robots.txt body: the operator-editable rules from the SEO console,
     * with two things this app guarantees regardless of what was typed —
     * the private surfaces stay disallowed, and exactly one Sitemap: line
     * points at the live sitemap.
     *
     * When indexing is switched off in the console (staging, pre-launch),
     * everything is disallowed and no sitemap is advertised.
     */
    public function robotsBody(): string
    {
        $origin = Seo::origin();

        if (! (bool) tva_setting('seo.allow_indexing', true)) {
            return "User-agent: *\nDisallow: /\n";
        }

        $body  = trim((string) tva_setting('seo.robots_txt', ''));
        $lines = $body !== '' ? preg_split('/\R/', $body) : ['User-agent: *', 'Disallow:'];

        // Strip any Sitemap: lines — we re-add the authoritative one below.
        $lines = array_values(array_filter(
            $lines,
            fn ($l) => stripos(trim((string) $l), 'sitemap:') !== 0
        ));

        // Private surfaces that must never be crawled. Appended only when
        // missing, so an operator can reorder/extend the file freely.
        $rules = (array) config('site.seo.robots_disallow', []);

        if ($rules !== []) {
            // A bare "Disallow:" means "allow everything" — harmless but
            // confusing sitting above a list of real rules, so drop it.
            $lines = array_values(array_filter(
                $lines,
                fn ($l) => ! preg_match('/^disallow:\s*$/i', trim((string) $l))
            ));
        }

        $existing = array_map(fn ($l) => strtolower(trim((string) $l)), $lines);
        foreach ($rules as $rule) {
            $line = 'Disallow: ' . $rule;
            if (! in_array(strtolower($line), $existing, true)) {
                $lines[] = $line;
            }
        }

        $lines[] = '';
        $lines[] = 'Sitemap: ' . $origin . '/sitemap.xml';

        return implode("\n", $lines) . "\n";
    }

    private function xmlOrText(string $body, string $contentType): Response
    {
        return response($body, 200, [
            'Content-Type'  => $contentType,
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            'X-Robots-Tag'  => 'noindex',   // the file itself is not a page
        ]);
    }
}
