# SEO Audit — serveai.com.pk

**Audited:** 8 August 2026
**Property:** `https://serveai.com.pk` (Serve AI — AI receptionist + CRM)
**Stack:** Laravel 10 (`admin/`), Blade server-rendered, nginx + php-fpm in Docker, Caddy TLS edge → HAProxy → app
**Scope:** the public marketing site only. The authenticated app (`/c/{workspace}/…`, `/admin`) is deliberately out of the index.

Findings are ordered by severity. Everything marked ✅ **Fixed** was implemented in this pass — see `SEO_IMPLEMENTATION_REPORT.md` for the file-by-file record.

---

## 0. Site architecture as found

| Layer | What's there |
|---|---|
| Framework | Laravel 10, Blade templates, no SPA — **all content is in the server-rendered HTML** (good: nothing depends on JS to be crawled) |
| Public routes | `/`, `/v2`, `/voice-bot`, `/about`, `/contact`, `/privacy`, `/terms`, `/refund-policy`, `/cookies`, `/security`, `/login`, `/register` + password-reset flow |
| Dynamic/DB pages | **None public.** Every DB-driven page (`sessions`, `leads`, `flows`…) sits behind auth in `/c/{workspace}/…` |
| Content management | `config/site.php` defaults, overridable per-key from the super-admin console (`/admin/seo`, `/admin/content`) writing to `site_settings` |
| Existing SEO work | A genuinely good starting point: `partials/seo-head.blade.php` (meta + OG + Twitter + JSON-LD + analytics), a `SeoManager` service, an ops console at `/admin/seo` |
| Public pages total | 8 indexable URLs |

The headline problem is not the plumbing — it's that **there are only eight pages, seven of which are legal/company boilerplate.** The whole commercial proposition lives on one URL. That is the ceiling on rankings, and no amount of technical work moves it. See §5 and `KEYWORD_MAP.md`.

---

## 1. CRITICAL

### 1.1 `/sitemap.xml` returned HTTP 404 ✅ Fixed
**Severity:** CRITICAL · **Safe for production:** yes

`https://serveai.com.pk/sitemap.xml` → `404`. The sitemap was only ever written to `public/sitemap.xml` when a super-admin pressed *Save* in `/admin/seo`, and nobody ever had. Google therefore had no URL inventory for the site and depended entirely on crawling links.

**Files:** `admin/app/Services/Seo/SeoManager.php`, `admin/config/site.php`
**Fix:** `/sitemap.xml` is now a route (`App\Http\Controllers\SeoFilesController@sitemap`) rendering from `App\Services\Seo\SitemapBuilder` on every request. It cannot go stale and cannot be forgotten.
**Impact:** Google can discover and re-crawl every page; `lastmod` tells it what changed.

### 1.2 `robots.txt` advertised no sitemap ✅ Fixed
**Severity:** CRITICAL · **Safe for production:** yes

Live content was exactly:
```
User-agent: *
Disallow:
```
No `Sitemap:` line (the primary discovery mechanism), and no protection for the authenticated app — Googlebot was free to crawl `/c/…`, `/admin`, `/api/…`, burning crawl budget on redirects to `/login`.

**Files:** `admin/public/robots.txt` (deleted), `admin/app/Http/Controllers/SeoFilesController.php`, `admin/config/site.php`
**Fix:** `/robots.txt` is a route now, always emitting the `Sitemap:` line plus `Disallow:` rules for the private surfaces. The static file was removed because nginx's `try_files $uri` serves a real file in preference to any route — that shadowing is exactly what kept the broken version live.

### 1.3 Canonical URL echoed the requested URL, tracking parameters and all ✅ Fixed
**Severity:** CRITICAL · **Safe for production:** yes

`seo-head.blade.php` emitted `<link rel="canonical" href="{{ request()->fullUrl() }}">`. Consequences:

- `/about?utm_source=facebook` declared **itself** canonical → every ad campaign, every newsletter link, every Facebook share minted a new "page" competing with the real one;
- `/about/` (trailing slash — see 2.1) declared itself canonical too;
- `http://` variants would self-canonicalise if the HTTPS redirect ever lapsed.

**Files:** `admin/resources/views/partials/seo-head.blade.php`, new `admin/app/Support/Seo.php`
**Fix:** canonical is now built as `configured origin + normalised path` — one scheme, one host, no trailing slash, no query string. Regression-tested in `tests/Feature/SeoTest.php`.
**Impact:** consolidates link equity onto one URL per page; stops index bloat from ad traffic.

---

## 2. HIGH

### 2.1 Every page reachable at two URLs (trailing slash), both HTTP 200 ✅ Fixed
**Severity:** HIGH · **Safe for production:** yes

`GET /about` → 200 and `GET /about/` → 200. Verified live. Development hid this: `admin/public/.htaccess` has an Apache trailing-slash redirect, but **production runs nginx** (`admin/docker/nginx.conf`), which has no such rule — so the duplicate existed only where it mattered.

**Files:** new `admin/app/Http/Middleware/RedirectTrailingSlash.php`, `admin/app/Http/Kernel.php`
**Fix:** global middleware 301s `/x/` → `/x`, GET/HEAD only (redirecting a POST would silently drop the request body), query string preserved.

### 2.2 Escaped HTML in the `<title>` of every inner page ✅ Fixed
**Severity:** HIGH · **Safe for production:** yes

`layouts/public.blade.php` built the title from `$pageTitle`, which carries markup because it is *also* the visible `<h1>`:

```
'pageTitle' => 'Every business deserves an assistant that <span class="accent">never sleeps.</span>'
```

Blade escapes it, so the About page's title tag rendered as
`Every business deserves an assistant that &lt;span class="accent"&gt;never sleeps.&lt;/span&gt; — Serve AI` —
a garbled, 100+ character title in search results.

**Files:** `admin/resources/views/layouts/public.blade.php`, `admin/resources/views/partials/seo-head.blade.php`, all of `admin/resources/views/pages/*.blade.php`
**Fix:** the partial strips tags as a backstop, and each page now sets an explicit, purpose-written `seoTitle`.

### 2.3 No Open Graph image ✅ Fixed
**Severity:** HIGH · **Safe for production:** yes

`og:image` was empty and `twitter:card` was `summary_large_image`. Every share on WhatsApp, LinkedIn, Facebook or X rendered as a bare text link. This is a click-through and referral-traffic problem, not a ranking one, but it is the cheapest fix on this list.

**Files:** new `admin/app/Console/Commands/SeoOgImage.php`, `admin/public/assets/dist/images/og-cover.png`, `admin/config/site.php`
**Fix:** `php artisan seo:og-image` renders a 1200×630 branded card from the live brand name + tagline; it is regenerated on every deploy and wired to `og:image` / `twitter:image` with width/height.

### 2.4 Organization JSON-LD carried a relative logo URL ✅ Fixed
**Severity:** HIGH · **Safe for production:** yes

`"logo":"/assets/dist/images/logo.svg"` — Schema.org requires an absolute URL; a relative one invalidates the node, so the entire Organization markup was being discarded.

**Files:** `admin/resources/views/partials/seo-head.blade.php`, `admin/app/Support/Seo.php`
**Fix:** every URL in the structured data goes through `Seo::absolute()`.

### 2.5 Vendor-template meta served on `/login` and `/register` ✅ Fixed
**Severity:** HIGH · **Safe for production:** yes

`layouts/auth.blade.php` still shipped the purchased admin theme's boilerplate:

```html
<meta name="description" content="Midone admin is super flexible, powerful, clean & modern responsive tailwind admin template…">
<meta name="author" content="LEFT4CODE">
```

Both pages were fully indexable with someone else's marketing copy and the brand's name nowhere in it.

**Files:** `admin/resources/views/layouts/auth.blade.php`, `admin/resources/views/auth/*.blade.php`
**Fix:** boilerplate removed, `noindex, follow` added, real per-page titles (`Sign in — Serve AI`, `Create your account — Serve AI`).

### 2.6 `/v2` — a near-duplicate of the homepage, fully indexable ✅ Fixed
**Severity:** HIGH · **Safe for production:** yes

`welcome-v2.blade.php` is a 100 KB alternate draft of the homepage on a live public route, with the same headings and largely the same copy. Classic self-inflicted duplicate content.

**Files:** `admin/config/site.php` (`seo.noindex_paths`)
**Fix:** `/v2` now emits `noindex, follow` and is excluded from the sitemap. The route still works for internal review. Same treatment for `/voice-bot`, a bare recording harness with no indexable content.

---

## 3. MEDIUM

### 3.1 No static-asset caching ✅ Fixed
**Severity:** MEDIUM · **Safe for production:** yes

`admin/docker/nginx.conf` set no `Cache-Control` on any asset, so every CSS file, font, icon and image was revalidated on each navigation. Directly hurts repeat-visit LCP.
**Fix:** content-hashed Vite output under `/build/` → `1y, immutable`; other static assets → `30d, public`.

### 3.2 ~700 KB of render-path JavaScript ✅ Partially fixed
**Severity:** MEDIUM · **Safe for production:** yes

The homepage loads three.js (~600 KB), GSAP and ScrollTrigger synchronously from jsDelivr, plus Google Fonts as a render-blocking stylesheet. This is the site's main Core Web Vitals cost (TBT/INP).

**Fix applied:** the CDN scripts are `defer`red and the page's own inline script now runs on `DOMContentLoaded` (deferred scripts execute in order before that event, so `THREE` and `gsap` are still defined — behaviour is unchanged). Fonts load non-blocking via the `media="print"` → `onload` pattern with a `<noscript>` fallback, plus `preconnect` to jsDelivr.

**Still open:** three.js is a 600 KB dependency for decorative background geometry. Loading it only when the hero scene scrolls into view, or self-hosting a tree-shaken build, would remove most of the remaining cost. Not done here — it is a design decision, not a bug.

### 3.3 No breadcrumbs ✅ Fixed
**Severity:** MEDIUM
Inner pages had no breadcrumb trail and no `BreadcrumbList` markup.
**Fix:** `layouts/public.blade.php` renders a visible trail from a `breadcrumbs` array, and the head partial emits the matching `BreadcrumbList` JSON-LD from the *same* array, so the two cannot drift apart.

### 3.4 Thin structured data ✅ Fixed
**Severity:** MEDIUM
Only `Organization` + `WebSite` were emitted. Missing: what the product actually is, and the page's own identity.
**Fix:** a single `@graph` per page now carries `Organization` (with postal address, phone, `sameAs`), `WebSite`, `WebPage`/`AboutPage`/`ContactPage`, `BreadcrumbList`, plus `SoftwareApplication` and `FAQPage` on the homepage. The FAQ markup is generated from the same array that renders the visible `<details>` list. **No `aggregateRating` and no `offers`** — there are no published reviews and no public price list, and inventing either is a manual-action risk.

### 3.5 `/about` and `/security` reachable only from the footer ✅ Fixed
**Severity:** MEDIUM
**Fix:** both added to the primary navigation on the homepage and the public layout.

### 3.6 No `X-Robots-Tag` on the authenticated app ✅ Fixed
**Severity:** MEDIUM
`robots.txt` is a *crawl* instruction, not an *index* instruction — a linked-to URL can still be indexed with no snippet.
**Fix:** `NoIndexPrivateAreas` middleware stamps `X-Robots-Tag: noindex, nofollow` on `/admin`, `/c`, `/dashboard`, `/profile`, `/workspace`, `/api`, OAuth callbacks and webhooks.

### 3.7 `www.serveai.com.pk` does not resolve ⚠️ Manual action required
**Severity:** MEDIUM · **Cannot be fixed in code**

`curl https://www.serveai.com.pk/` fails to connect — there is no DNS record for `www`. Any inbound link or citation written as `www.serveai.com.pk` is dead, and that link equity is lost. See `SEO_IMPLEMENTATION_REPORT.md` §18 for the exact DNS + Caddy change.

---

## 4. LOW / verified healthy

| Check | Result |
|---|---|
| HTTPS | ✅ Valid cert, `http://` → `https://` returns **308** |
| HSTS | ✅ `max-age=31536000; includeSubDomains` at the Caddy edge |
| Mobile | ✅ Responsive; viewport meta present; mobile drawer nav on the homepage; `overflow-x: hidden` guards horizontal scroll |
| JS-rendered content | ✅ None — all copy is in the server HTML. Three.js is decorative only |
| Heading structure | ✅ One `<h1>` per page, ordered `<h2>`/`<h3>` sections |
| Images | ✅ Effectively one (the logo), with `width`/`height` and real alt text — no CLS risk, no bloat |
| FAQ accessibility | ✅ Native `<details>`/`<summary>` — content is in the DOM, not behind a click handler |
| URL structure | ✅ Already clean and readable (`/refund-policy`, not `/page?id=4`). **No URLs were changed**, so no redirect debt was created |
| 404 handling | ✅ Branded `errors/404.blade.php`, correct status code |
| Orphan pages | ✅ None after 3.5 — every public page is linked from the nav or footer |
| Pagination | n/a — no paginated public content |
| Broken internal links | ✅ None found; every `url()`/`route()` target resolves |
| Content behind auth | ✅ Intentional and correct — no cloaking, no accidental gating of public pages |

---

## 5. The real constraint: content depth

Everything above is table stakes. It gets the site *eligible* to rank; it does not make it competitive.

Serve AI is currently one commercial page competing against companies with 50–500 indexed pages each. Search competitors — [Synthflow](https://synthflow.ai), [Retell AI](https://retellai.com), [Smith.ai](https://smith.ai), [Rosie](https://heyrosie.com), [Goodcall](https://goodcall.com), [My AI Front Desk](https://myaifrontdesk.com), plus local players like [Intellicon](https://intellicon.io) and [TekkPak](https://tekkpak.com) — rank because they have a page for every *question* and every *use case*, not because their `robots.txt` is tidier.

Two structural gaps stand out:

1. **No pricing page.** "ai receptionist pricing" and "ai receptionist cost" are among the highest-intent queries in this market, and every competitor has a page for them. Serve AI has no indexable page containing the word "pricing".
2. **No use-case or industry pages.** The homepage's "Made for your business" grid names six real segments (shops, clinics, real estate, restaurants, trades, agencies) in one sentence each. Each of those deserves its own page — that is genuinely useful content, not a doorway page, *provided each is written specifically for that audience*.

`KEYWORD_MAP.md` sets out the target topic per existing URL and the recommended new pages in priority order.

---

## 6. What this audit does **not** claim

No technical change guarantees a ranking. Position depends on competition, domain authority, content quality and relevance, and Google's algorithms — none of which are controllable from a codebase. What has been done here removes the blockers that were preventing the site from being crawled, indexed and represented correctly. Ranking follows from content and authority built over months.
