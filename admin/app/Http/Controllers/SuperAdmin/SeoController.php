<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Services\Seo\SeoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Super-admin SEO console — one page to control everything search engines
 * read from the public marketing site: meta tags, Open Graph / Twitter
 * cards, robots.txt, sitemap.xml, Google/Bing verification, analytics tags,
 * icons, and JSON-LD structured data. Writes are applied immediately:
 * settings → DB, and robots.txt / sitemap.xml / verification file → the
 * real files in public/.
 */
class SeoController extends Controller
{
    public function __construct(private SeoManager $seo) {}

    public function index(Request $request): View
    {
        $title = 'SEO';
        $seo   = tva_seo_all();

        // Recent SEO activity (append-only audit trail, action LIKE seo.%)
        $logs = AuditLog::query()
            ->where('action', 'like', 'seo.%')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        // Live status of the on-disk crawler files.
        $files = [
            'robots'  => $this->fileStatus('robots.txt'),
            'sitemap' => $this->fileStatus('sitemap.xml'),
        ];

        $baseUrl = $this->seo->baseUrl();

        return view('ops.seo.index', compact('title', 'seo', 'logs', 'files', 'baseUrl'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'meta_title'        => ['nullable', 'string', 'max:180'],
            'meta_description'  => ['nullable', 'string', 'max:320'],
            'meta_keywords'     => ['nullable', 'string', 'max:500'],
            'author'            => ['nullable', 'string', 'max:120'],
            'theme_color'       => ['nullable', 'string', 'max:32'],
            'canonical_url'     => ['nullable', 'string', 'max:255'],

            'og_title'          => ['nullable', 'string', 'max:180'],
            'og_description'    => ['nullable', 'string', 'max:320'],
            'og_image'          => ['nullable', 'string', 'max:500'],
            'og_type'           => ['nullable', 'string', 'max:40'],
            'og_site_name'      => ['nullable', 'string', 'max:120'],

            'twitter_card'      => ['nullable', 'string', 'max:40'],
            'twitter_site'      => ['nullable', 'string', 'max:120'],
            'twitter_image'     => ['nullable', 'string', 'max:500'],

            'google_site_verification' => ['nullable', 'string', 'max:255'],
            'bing_site_verification'   => ['nullable', 'string', 'max:255'],
            'verification_file_name'   => ['nullable', 'string', 'max:120'],
            'verification_file_body'   => ['nullable', 'string', 'max:2000'],

            'ga4_id'            => ['nullable', 'string', 'max:60'],
            'gtm_id'            => ['nullable', 'string', 'max:60'],
            'facebook_pixel'    => ['nullable', 'string', 'max:60'],

            'apple_touch_icon'  => ['nullable', 'string', 'max:500'],
            'favicon'           => ['nullable', 'image', 'mimes:ico,png,jpg,jpeg,svg,webp', 'max:1024'],

            'org_name'          => ['nullable', 'string', 'max:160'],
            'org_logo'          => ['nullable', 'string', 'max:500'],
            'org_phone'         => ['nullable', 'string', 'max:60'],
            'org_email'         => ['nullable', 'string', 'max:160'],
            'social_links'      => ['nullable', 'string', 'max:3000'],

            'custom_head_html'  => ['nullable', 'string', 'max:10000'],
            'robots_txt'        => ['nullable', 'string', 'max:10000'],

            // Sitemap repeater (parallel arrays)
            'sm_loc'            => ['nullable', 'array'],
            'sm_loc.*'          => ['nullable', 'string', 'max:500'],
            'sm_changefreq'     => ['nullable', 'array'],
            'sm_priority'       => ['nullable', 'array'],
        ]);

        // ── Plain string settings ────────────────────────────────────
        $stringKeys = [
            'meta_title', 'meta_description', 'meta_keywords', 'author', 'theme_color', 'canonical_url',
            'og_title', 'og_description', 'og_image', 'og_type', 'og_site_name',
            'twitter_card', 'twitter_site', 'twitter_image',
            'google_site_verification', 'bing_site_verification',
            'verification_file_name', 'verification_file_body',
            'ga4_id', 'gtm_id', 'facebook_pixel', 'apple_touch_icon',
            'org_name', 'org_logo', 'org_phone', 'org_email', 'custom_head_html',
        ];
        foreach ($stringKeys as $k) {
            SiteSetting::set("seo.{$k}", (string) ($data[$k] ?? ''));
        }

        // ── Booleans (checkboxes) ────────────────────────────────────
        SiteSetting::set('seo.allow_indexing', $request->boolean('allow_indexing'));
        SiteSetting::set('seo.structured_data', $request->boolean('structured_data'));

        // ── Social links (textarea → array) ──────────────────────────
        $social = collect(preg_split('/\R/', (string) ($data['social_links'] ?? '')))
            ->map(fn ($l) => trim($l))->filter()->values()->all();
        SiteSetting::set('seo.social_links', $social);

        // ── Sitemap URL list ─────────────────────────────────────────
        $locs  = $data['sm_loc'] ?? [];
        $freqs = $request->input('sm_changefreq', []);
        $pris  = $request->input('sm_priority', []);
        $urls  = [];
        foreach ($locs as $i => $loc) {
            $loc = trim((string) $loc);
            if ($loc === '') continue;
            $urls[] = [
                'loc'        => $loc,
                'changefreq' => trim((string) ($freqs[$i] ?? 'weekly')) ?: 'weekly',
                'priority'   => trim((string) ($pris[$i] ?? '0.5')) ?: '0.5',
            ];
        }
        if (! empty($urls)) {
            SiteSetting::set('seo.sitemap_urls', $urls);
        }

        // ── Favicon upload ───────────────────────────────────────────
        if ($request->hasFile('favicon') && $request->file('favicon')->isValid()) {
            $ext  = strtolower($request->file('favicon')->getClientOriginalExtension() ?: 'png');
            $name = 'favicon-' . substr(md5((string) microtime(true)), 0, 8) . '.' . $ext;
            $path = $request->file('favicon')->storeAs('site', $name, 'public');
            SiteSetting::set('seo.favicon_url', Storage::url($path));
        }

        // ── robots.txt ───────────────────────────────────────────────
        $robotsBody = (string) ($data['robots_txt'] ?? '');
        if (trim($robotsBody) === '') {
            $robotsBody = $request->boolean('allow_indexing')
                ? "User-agent: *\nDisallow:\n"
                : "User-agent: *\nDisallow: /\n";
        }
        SiteSetting::set('seo.robots_txt', $robotsBody);

        // ── Apply to the real files crawlers fetch ───────────────────
        $notices = [];
        $r = $this->seo->writeRobots($robotsBody);                 $notices[] = $r;
        $s = $this->seo->writeSitemap(tva_setting('seo.sitemap_urls', [])); $notices[] = $s;
        $v = $this->seo->writeVerificationFile(
            $data['verification_file_name'] ?? null,
            $data['verification_file_body'] ?? null,
        );
        if ($v) $notices[] = $v;

        AuditLog::record('seo.update', [
            'payload' => ['files' => array_map(fn ($n) => $n['message'] ?? '', $notices)],
        ]);

        $failed = collect($notices)->filter(fn ($n) => ! ($n['ok'] ?? true));
        if ($failed->isNotEmpty()) {
            return back()
                ->with('success', 'SEO settings saved.')
                ->with('error', $failed->pluck('message')->implode(' '));
        }

        return back()->with('success', 'SEO settings saved and applied to robots.txt + sitemap.xml.');
    }

    /** Regenerate + submit the sitemap to the search engines. */
    public function ping(Request $request): RedirectResponse
    {
        // Make sure the file on disk reflects current settings first.
        $this->seo->writeSitemap(tva_setting('seo.sitemap_urls', []));
        $results = $this->seo->pingSearchEngines();

        AuditLog::record('seo.sitemap_ping', ['payload' => ['results' => $results]]);

        $summary = collect($results)
            ->map(fn ($r) => "{$r['engine']}: " . ($r['ok'] ? 'ok' : "failed ({$r['status']})"))
            ->implode(' · ');

        return back()->with('success', "Sitemap submitted — {$summary}");
    }

    private function fileStatus(string $rel): array
    {
        $path = public_path($rel);
        return [
            'exists'   => is_file($path),
            'modified' => is_file($path) ? date('M j, Y H:i', filemtime($path)) : null,
            'url'      => $this->seo->baseUrl() . '/' . $rel,
        ];
    }
}
