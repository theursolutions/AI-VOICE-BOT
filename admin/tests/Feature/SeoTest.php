<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the crawler-facing contract of the public site.
 *
 * Each of these failed in production at least once: /sitemap.xml 404'd,
 * robots.txt shipped without a Sitemap: line, every URL declared itself
 * canonical (including the ?utm_source variants), and inner pages put
 * escaped HTML in the <title>.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_txt_is_served_and_points_at_the_sitemap(): void
    {
        $res = $this->get('/robots.txt');

        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $res->assertSee('User-agent: *', false);
        $res->assertSee('Sitemap: ' . \App\Support\Seo::origin() . '/sitemap.xml', false);
        $res->assertSee('Disallow: /admin/', false);
    }

    public function test_robots_txt_does_not_block_the_pages_we_want_indexed(): void
    {
        $body = $this->get('/robots.txt')->getContent();

        // A Disallow that would knock out the marketing site entirely.
        $this->assertStringNotContainsString("Disallow: /\n", $body);
        foreach (['/about', '/contact', '/security'] as $path) {
            $this->assertStringNotContainsString("Disallow: {$path}\n", $body);
        }
    }

    public function test_sitemap_lists_only_indexable_urls(): void
    {
        $res = $this->get('/sitemap.xml');

        $res->assertOk();
        $res->assertSee('<urlset', false);
        $res->assertSee(\App\Support\Seo::canonical('/'), false);
        $res->assertSee(\App\Support\Seo::canonical('/about'), false);

        // noindex paths must never appear — the sitemap and the robots meta
        // tag have to tell Google the same story.
        $body = $res->getContent();
        foreach (['/v2', '/login', '/register', '/voice-bot'] as $path) {
            $this->assertStringNotContainsString('<loc>' . \App\Support\Seo::canonical($path) . '</loc>', $body);
        }
    }

    public function test_every_sitemap_url_returns_200(): void
    {
        $builder = app(\App\Services\Seo\SitemapBuilder::class);
        $this->assertNotEmpty($builder->urls(), 'The sitemap is empty.');

        foreach ($builder->urls() as $url) {
            $this->get($url['path'])->assertOk();
        }
    }

    public function test_canonical_ignores_tracking_parameters_and_trailing_slashes(): void
    {
        $expected = '<link rel="canonical" href="' . \App\Support\Seo::canonical('/about') . '">';

        $this->get('/about')->assertSee($expected, false);
        $this->get('/about?utm_source=facebook&utm_campaign=launch')->assertSee($expected, false);
    }

    /**
     * Exercised through the middleware directly: the test client's
     * prepareUrlForRequest() trims trailing slashes off the URI before the
     * request is ever built, so `$this->get('/about/')` cannot reach it.
     */
    public function test_trailing_slash_redirects_permanently(): void
    {
        $middleware = new \App\Http\Middleware\RedirectTrailingSlash();
        $next       = fn () => response('reached the route');

        $response = $middleware->handle(\Illuminate\Http\Request::create('http://localhost/about/'), $next);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('http://localhost/about', $response->headers->get('Location'));

        // Query strings survive the redirect (ad traffic keeps its params).
        $response = $middleware->handle(\Illuminate\Http\Request::create('http://localhost/about/?utm_source=x'), $next);
        $this->assertSame('http://localhost/about?utm_source=x', $response->headers->get('Location'));

        // The root and non-GET requests are left alone — redirecting a POST
        // would drop the body.
        $this->assertSame('reached the route', $middleware->handle(\Illuminate\Http\Request::create('http://localhost/'), $next)->getContent());
        $this->assertSame('reached the route', $middleware->handle(\Illuminate\Http\Request::create('http://localhost/api/contact/', 'POST'), $next)->getContent());
    }

    public function test_public_pages_have_unique_titles_and_descriptions(): void
    {
        $paths = ['/', '/about', '/contact', '/security', '/privacy', '/terms', '/refund-policy', '/cookies'];

        $titles = [];
        $descriptions = [];

        foreach ($paths as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match('#<title>(.*?)</title>#s', $html, $t);
            preg_match('#<meta name="description" content="(.*?)">#s', $html, $d);

            $this->assertNotEmpty($t[1] ?? '', "No <title> on {$path}");
            $this->assertNotEmpty($d[1] ?? '', "No meta description on {$path}");

            // The <h1> carries markup; the title must not inherit it.
            $this->assertStringNotContainsString('&lt;span', $t[1], "Escaped HTML in the <title> of {$path}");

            $titles[$path] = $t[1];
            $descriptions[$path] = $d[1];
        }

        $this->assertSame(count($titles), count(array_unique($titles)), 'Duplicate <title> across public pages: ' . json_encode($titles));
        $this->assertSame(count($descriptions), count(array_unique($descriptions)), 'Duplicate meta descriptions across public pages.');
    }

    public function test_indexable_pages_are_indexable_and_duplicates_are_not(): void
    {
        foreach (['/', '/about', '/contact', '/security'] as $path) {
            $this->get($path)->assertSee('<meta name="robots" content="index, follow', false);
        }

        foreach (['/v2', '/login', '/register'] as $path) {
            $this->get($path)->assertSee('name="robots" content="noindex, follow"', false);
        }
    }

    public function test_homepage_emits_valid_structured_data(): void
    {
        $html = $this->get('/')->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1] ?? '', 'No JSON-LD on the homepage.');

        $ld = json_decode($m[1], true);
        $this->assertIsArray($ld, 'JSON-LD is not valid JSON: ' . json_last_error_msg());

        $types = array_column($ld['@graph'] ?? [], '@type');
        foreach (['Organization', 'WebSite', 'WebPage', 'SoftwareApplication', 'FAQPage'] as $type) {
            $this->assertContains($type, $types, "Missing {$type} in the homepage @graph.");
        }

        // Schema.org requires absolute URLs; a relative logo silently
        // invalidates the whole Organization node.
        $org = collect($ld['@graph'])->firstWhere('@type', 'Organization');
        $this->assertStringStartsWith('http', $org['logo'], 'Organization logo must be an absolute URL.');
    }

    public function test_faq_structured_data_matches_the_visible_questions(): void
    {
        $html = $this->get('/')->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $faq = collect(json_decode($m[1], true)['@graph'])->firstWhere('@type', 'FAQPage');

        $this->assertNotEmpty($faq['mainEntity']);
        foreach ($faq['mainEntity'] as $question) {
            $this->assertStringContainsString(
                e($question['name']),
                $html,
                'FAQ structured data contains a question that is not visible on the page: ' . $question['name']
            );
        }
    }

    public function test_private_areas_carry_a_noindex_header(): void
    {
        $this->get('/dashboard')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_public_pages_do_not_carry_a_noindex_header(): void
    {
        foreach (['/', '/about', '/contact'] as $path) {
            $this->assertNull(
                $this->get($path)->headers->get('X-Robots-Tag'),
                "X-Robots-Tag must not be set on {$path}"
            );
        }
    }
}
