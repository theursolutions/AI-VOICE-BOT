# SEO Implementation Report — serveai.com.pk

**Date:** 8 August 2026
**Branch:** `development`
**Test result:** 38 passed (166 assertions) — full suite, no regressions
**Companion documents:** [`SEO_AUDIT.md`](SEO_AUDIT.md) (what was wrong) · [`KEYWORD_MAP.md`](KEYWORD_MAP.md) (what to build next)

---

## 1. Problems found

Ranked by severity. Full detail in `SEO_AUDIT.md`.

**CRITICAL**
1. `/sitemap.xml` returned **404** in production — Google had no URL inventory.
2. `/robots.txt` had **no `Sitemap:` line** and left the whole authenticated app crawlable.
3. Canonical tags echoed `request()->fullUrl()` — every `?utm_source=…` variant self-canonicalised.

**HIGH**
4. Every page reachable at two URLs (`/about` and `/about/`), both HTTP 200, in production only.
5. Escaped HTML (`&lt;span class="accent"&gt;`) inside the `<title>` of every inner page.
6. No `og:image` at all, with `twitter:card = summary_large_image`.
7. `Organization` JSON-LD carried a **relative** logo URL, invalidating the node.
8. `/login` and `/register` served the purchased admin theme's meta description, crediting "LEFT4CODE".
9. `/v2` — a near-duplicate of the homepage — was fully indexable.

**MEDIUM**
10. No `Cache-Control` on any static asset.
11. ~700 KB of synchronous third-party JS + render-blocking web fonts.
12. No breadcrumbs, visible or structured.
13. Structured data limited to `Organization` + `WebSite`.
14. `/about` and `/security` linked only from the footer.
15. No `X-Robots-Tag` protecting the authenticated app.
16. `www.serveai.com.pk` does not resolve *(cannot be fixed in code — see §18)*.

---

## 2. Problems fixed

All of 1–15. Item 16 requires a DNS change you must make. Item 11 is partially addressed — see §13.

---

## 3. Files changed

| File | Change |
|---|---|
| `admin/resources/views/partials/seo-head.blade.php` | Rewritten. Correct canonical, per-page noindex, absolute image URLs, `og:image` + dimensions, `og:locale`, and a single Schema.org `@graph` with page-specific nodes |
| `admin/resources/views/layouts/public.blade.php` | `seoTitle` support (fixes the HTML-in-title bug), visible breadcrumb trail + styles, `Security`/`About` added to the nav |
| `admin/resources/views/welcome.blade.php` | FAQ hoisted to a single shared array feeding both the visible list and `FAQPage` markup; `SoftwareApplication` + `FAQPage` JSON-LD; deferred CDN scripts with the inline block moved to `DOMContentLoaded`; non-blocking font loading; `preconnect` to jsDelivr; `Security`/`Contact` nav links |
| `admin/resources/views/pages/{about,contact,security,privacy,terms,refund,cookies}.blade.php` | Unique `seoTitle`, rewritten meta descriptions, breadcrumbs; `AboutPage`/`ContactPage` schema types |
| `admin/resources/views/layouts/auth.blade.php` | Vendor boilerplate meta removed, `noindex, follow` added, per-page titles |
| `admin/resources/views/auth/{login,register,forgot-password,reset-password}.blade.php` | Pass an `authTitle` |
| `admin/resources/views/voice-chat.blade.php` | `noindex, nofollow`, `lang`, viewport |
| `admin/resources/views/ops/seo/index.blade.php` | Status cards report the live routes and warn if a static file is shadowing them; corrected sitemap and ping copy |
| `admin/app/Http/Kernel.php` | Registered the two new global middleware |
| `admin/app/Services/Seo/SeoManager.php` | `writeRobots`/`writeSitemap`/`buildSitemapXml` replaced by `syncCrawlerFiles()` + `staticShadowExists()` |
| `admin/app/Http/Controllers/SuperAdmin/SeoController.php` | Save applies settings by clearing stale static files; honest messaging about the retired ping endpoints |
| `admin/config/site.php` | `robots_disallow`, `noindex_paths`, `page_views` (for truthful `lastmod`), curated `sitemap_urls`, default `og_image` |
| `admin/routes/web.php` | Three public crawler routes |
| `admin/docker/nginx.conf` | Static-asset caching; documented why no `robots.txt` location block exists |
| `admin/docker/entrypoint.sh` | Runs `seo:publish` + `seo:og-image` on every web-container boot |
| `admin/public/robots.txt` | **Deleted** — a static file shadows the route (that shadowing is what kept the broken version live) |

## 4. New files

| File | Purpose |
|---|---|
| `admin/app/Support/Seo.php` | Canonical origin/path/URL normalisation, tracking-parameter list, absolute-URL helper |
| `admin/app/Services/Seo/SitemapBuilder.php` | Builds the sitemap: drops noindex paths, drops paths with no GET route, dedupes, derives `lastmod` from template mtime, splits into a sitemap index above 5,000 URLs |
| `admin/app/Http/Controllers/SeoFilesController.php` | Serves `/robots.txt`, `/sitemap.xml`, `/sitemap-{n}.xml` |
| `admin/app/Http/Middleware/RedirectTrailingSlash.php` | 301 `/x/` → `/x` (GET/HEAD only, query preserved) |
| `admin/app/Http/Middleware/NoIndexPrivateAreas.php` | `X-Robots-Tag: noindex, nofollow` on the authenticated app |
| `admin/app/Console/Commands/SeoPublish.php` | Deploy step: removes shadowing static files, prints the live crawler view, fails loudly on an empty sitemap |
| `admin/app/Console/Commands/SeoOgImage.php` | Generates the 1200×630 share card via GD |
| `admin/public/assets/dist/images/og-cover.png` | The generated card |
| `admin/tests/Feature/SeoTest.php` | 12 tests locking in the crawler-facing contract |

## 5. Routes added

| Method | URI | Name |
|---|---|---|
| GET | `/robots.txt` | `seo.robots` |
| GET | `/sitemap.xml` | `seo.sitemap` |
| GET | `/sitemap-{n}.xml` | `seo.sitemap.chunk` |

**No existing URL was changed or removed**, so no redirect debt was created and no ranking equity was put at risk.

---

## 6. SEO features implemented

- Live, always-correct `robots.txt` and `sitemap.xml`
- Correct canonicalisation (host, scheme, trailing slash, tracking parameters)
- 301 trailing-slash consolidation
- Unique, hand-written titles and meta descriptions on all 8 public pages
- `noindex` on duplicates, thin pages and auth forms — enforced in the meta tag *and* the sitemap from one config list, so they cannot disagree
- `X-Robots-Tag` defence-in-depth on the authenticated app
- Full Schema.org `@graph`: Organization (with address, phone, `sameAs`), WebSite, WebPage/AboutPage/ContactPage, BreadcrumbList, SoftwareApplication, FAQPage
- Visible breadcrumbs matching the structured data
- Open Graph + Twitter cards with a real share image and dimensions
- Static-asset caching, deferred third-party JS, non-blocking fonts
- Regression tests

---

## 7. Sitemap implementation

`GET /sitemap.xml` renders from `App\Services\Seo\SitemapBuilder`:

- URLs come from `seo.sitemap_urls` (config default, editable in `/admin/seo`);
- each is rewritten onto the canonical origin and canonical path spelling;
- anything in `seo.noindex_paths` is **dropped** — the sitemap can never contradict a page's robots meta;
- anything with no matching GET route is dropped, so it can never contain a 404;
- `lastmod` comes from the mtime of the Blade template behind the URL (config `seo.page_views`), so it moves when content actually changes rather than being stamped "today" on every fetch;
- above 5,000 URLs the response becomes a `<sitemapindex>` and `/sitemap-1.xml`, `/sitemap-2.xml`… serve the chunks. Ready for the blog in `KEYWORD_MAP.md` §3.

Current output — 8 URLs:

```
https://serveai.com.pk/                (priority 1.0, weekly)
https://serveai.com.pk/contact         (0.8, monthly)
https://serveai.com.pk/security        (0.7, monthly)
https://serveai.com.pk/about           (0.6, monthly)
https://serveai.com.pk/privacy         (0.3, yearly)
https://serveai.com.pk/terms           (0.3, yearly)
https://serveai.com.pk/refund-policy   (0.3, yearly)
https://serveai.com.pk/cookies         (0.3, yearly)
```

## 8. robots.txt implementation

`GET /robots.txt` composes: the operator-editable body from `/admin/seo`, plus guaranteed `Disallow` rules for private surfaces, plus exactly one `Sitemap:` line. If indexing is switched off in the console (staging), it emits `Disallow: /` and advertises no sitemap.

```
User-agent: *
Disallow: /admin/
Disallow: /c/
Disallow: /dashboard
Disallow: /profile
Disallow: /workspace/
Disallow: /api/
Disallow: /invitations/
Disallow: /auth/
Disallow: /oauth/
Disallow: /meta/

Sitemap: https://serveai.com.pk/sitemap.xml
```

`/login`, `/register`, `/v2` and `/voice-bot` are deliberately **not** blocked here. Blocking a URL in robots.txt stops Google reading the very `noindex` tag that removes it from the index — an already-indexed URL would stay indexed forever. They are crawlable and noindexed instead, which is what actually gets them dropped.

## 9. Canonical implementation

`App\Support\Seo::canonical()` = configured origin + normalised path. One scheme, one host, no trailing slash, no query string. The homepage keeps its trailing slash (`https://serveai.com.pk/`); every other page has none.

The origin comes from `seo.canonical_url` (SEO console), falling back to `APP_URL` — so staging never advertises production URLs. `php artisan seo:publish` warns in the deploy log if it is not HTTPS.

## 10. Schema implementation

One `@graph` per page, cross-linked by `@id`. Everything marked up is visible on the page.

| Node | Where | Notes |
|---|---|---|
| `Organization` | All public pages | Name, absolute logo, email, phone, `contactPoint` (English + Urdu), `PostalAddress` (Lahore, PK), `sameAs` from the configured social links |
| `WebSite` | All public pages | Publisher → Organization |
| `WebPage` / `AboutPage` / `ContactPage` | All public pages | Self-referencing `@id`, links to WebSite + Organization |
| `BreadcrumbList` | Inner pages | Generated from the same array as the visible trail |
| `SoftwareApplication` | Homepage | `BusinessApplication` / CRM, feature list from the live content |
| `FAQPage` | Homepage | Generated from the same array that renders the visible `<details>` list — a test asserts every marked-up question appears in the HTML |

**Deliberately absent:** `aggregateRating` and `offers`. There are no published reviews and no public price list. Fabricating either is the fastest route to a structured-data manual action. Add `offers` when `/pricing` ships; add `aggregateRating` only when you have a real review source.

## 11. Metadata implementation

| URL | `<title>` |
|---|---|
| `/` | Serve AI — your AI receptionist that never sleeps |
| `/about` | About Serve AI — the AI receptionist that never sleeps |
| `/contact` | Contact Serve AI — book a demo or request a callback |
| `/security` | Security & data protection — Serve AI |
| `/privacy` | Privacy Policy — Serve AI |
| `/terms` | Terms of Service — Serve AI |
| `/refund-policy` | Refund & Cancellation Policy — Serve AI |
| `/cookies` | Cookie Policy — Serve AI |

All descriptions rewritten to be unique, specific and human. A test fails the build if any two public pages share a title or description, or if a title contains escaped HTML.

Titles remain fully editable: global defaults in `/admin/seo`, per-page `seoTitle` in the Blade view.

## 12. Internal linking improvements

- Primary nav (homepage + public layout) now links `/security` and `/contact`; the public layout adds `/about`.
- Breadcrumbs give every inner page a crawlable link back to `/`.
- No page is orphaned; every public URL is reachable from the nav or the sitewide footer.

## 13. Performance improvements

**Done**
- Static assets: `/build/` → `Cache-Control: public, immutable, 1y`; other assets → `public, 30d`. Previously no caching headers at all.
- three.js / GSAP / ScrollTrigger now `defer`red; the page's inline script moved to `DOMContentLoaded`. Deferred scripts run in order before that event, so `THREE` and `gsap` are defined exactly as before — verified by parsing the served inline block. Removes ~700 KB from the blocking path.
- Google Fonts loaded via `media="print"` → `onload`, with `<noscript>` fallback and `preload`. `preconnect` added for jsDelivr.

**Already healthy** — server-rendered Blade (no client-side rendering cost), one image on the homepage (with `width`/`height`, so no CLS), no N+1 queries on public routes (they touch only `site_settings`), zstd/gzip compression at the Caddy edge.

**Still open** — three.js is 600 KB for decorative background geometry. Lazy-loading it when the hero scrolls into view, or self-hosting a tree-shaken build, would remove most of the remaining Core Web Vitals cost. That is a design call, so I have not made it for you.

---

## 14. Remaining SEO issues

| Issue | Owner | Notes |
|---|---|---|
| `www.serveai.com.pk` does not resolve | You (DNS) | §18.1 |
| Only 8 pages, one of them commercial | Content | The single biggest constraint on ranking. `KEYWORD_MAP.md` §3 |
| No pricing page | Content | Highest-intent query in the category; you have none |
| No blog / article system | Build | Needed for Tier 3 content. `SitemapBuilder` is ready for it |
| three.js weight | Frontend | §13 |
| `/v2` still exists as a route | Cleanup | Now noindexed; delete once the draft is resolved |
| No analytics configured | You | `ga4_id` / `gtm_id` are blank in `/admin/seo` — you are flying blind on what converts |
| `/about` is thin (~400 words) | Content | And never mentions the Lahore office, which is a wasted local-SEO asset |

---

## 15. Recommended content strategy

1. **Ship `/pricing` first.** Buyers who cannot find a price leave. Every competitor publishes one.
2. **Then the three Tier-1 commercial pages** — `/ai-receptionist-pakistan`, `/whatsapp-ai-chatbot`, `/ai-voice-agent`.
3. **Then industry pages**, one at a time, each with genuinely segment-specific substance. If you cannot write 800 words that only apply to clinics, do not publish a clinics page — near-identical pages are actively harmful.
4. **Then sustained articles**, roughly weekly. This is the compounding layer and the slowest to pay off.
5. **Deepen `/about` and `/security`.** `/security` is a real differentiator (per-tenant databases, column-level AI access control) that competitors cannot easily match, and it handles the biggest B2B objection.

Never: spun content, keyword stuffing, doorway pages, AI-generated filler at scale, or invented statistics. All of it is detectable and all of it is recoverable-from only slowly.

## 16. Recommended keyword strategy

Full detail in `KEYWORD_MAP.md`. The core judgement:

**Win Pakistan and the region first.** Local competitors are agencies with thin content and weak technical SEO — beatable within months. You have a real product, a Lahore address, Urdu support and local numbers. Global head terms like "ai receptionist" are dominated by companies with years of authority; treat them as a long-term play funded by regional traffic, not as the opening move.

Target long-tail, high-intent, specific phrasing over head terms. "ai receptionist for dental clinic in lahore" converts and is winnable; "ai receptionist" is neither.

## 17. Recommended backlinks & authority strategy

Authority is the one input no code change can supply. In rough order of value per unit of effort:

1. **Local business citations** — Google Business Profile (highest priority), Pakistani directories, Arfa Software Technology Park's own tenant listing.
2. **SaaS directories** — G2, Capterra, Product Hunt, AlternativeTo, SaaSHub, There's An AI For That. Free, permanent, and they rank for your category terms themselves.
3. **Pakistani tech press and startup coverage** — TechJuice, ProPakistani, Startup Pakistan. A real product launch story is a legitimate pitch.
4. **Customer case studies** — with the customer's permission and a link back. The most credible link type there is.
5. **Genuinely useful free tools** — a missed-call cost calculator, a WhatsApp API setup checklist. These earn links passively for years.
6. **Founder presence** — LinkedIn, relevant communities, conference talks. Slow, compounding, and hard to fake.

Never buy links, use PBNs, or run link-exchange schemes. Recovery from a link-based manual action takes months and is not guaranteed.

---

## 18. Manual actions you must perform

These cannot be done from the codebase.

### 18.1 Add the `www` DNS record — do this first
`https://www.serveai.com.pk` currently fails to connect. Any link, citation or business listing written with `www.` is dead and its value lost.

1. Add a DNS record: `www.serveai.com.pk` → **CNAME** → `serveai.com.pk` (or an A record to the same IP).
2. Add a redirect at the TLS edge, in `deploy/production/Caddyfile`:
   ```caddyfile
   www.{$APP_DOMAIN} {
       redir https://{$APP_DOMAIN}{uri} permanent
   }
   ```
3. Add `www.serveai.com.pk` to the Cloudflare Turnstile hostname allow-list (see `DEPLOYMENT.md` §4.2), or logins from that hostname will fail.

### 18.2 Deploy, then verify
```bash
# on the server, after deploying this branch
docker compose exec app php artisan seo:publish        # removes the stale static robots.txt
curl -I https://serveai.com.pk/sitemap.xml             # expect 200, application/xml
curl    https://serveai.com.pk/robots.txt              # expect the Sitemap: line
curl -I https://serveai.com.pk/about/                  # expect 301 → /about
```
The deploy entrypoint runs `seo:publish` automatically; the manual command is for a server that is already running.

Confirm `APP_URL=https://serveai.com.pk` in the production `.env` — every canonical tag, sitemap URL and JSON-LD `@id` is built from it. `seo:publish` prints a warning if it is not HTTPS.

### 18.3 Google Search Console
1. Add the property at <https://search.google.com/search-console> — use the **Domain** property type (covers http/https and all subdomains) and verify by DNS TXT record. Failing that, paste the meta token into `/admin/seo` → *Verification* → `google_site_verification`; the head partial renders it.
2. **Sitemaps** → submit `sitemap.xml`.
3. **URL Inspection** → request indexing for `https://serveai.com.pk/` (and each new page as it ships).
4. Check **Pages** after a week for coverage errors; check **Enhancements** for structured-data warnings.
5. After 2–4 weeks, the **Performance → Queries** report becomes your best keyword research source. Nothing else tells you what *your* site is actually shown for.

### 18.4 Bing Webmaster Tools
<https://www.bing.com/webmasters> — import directly from Search Console, then submit the same sitemap. This also feeds ChatGPT search and Copilot.

### 18.5 Google Business Profile — high value, often skipped
<https://business.google.com>. Register the Lahore address (Arfa Software Technology Park), category "Software Company", real phone, real hours, photos. This is the single highest-leverage action for local queries and it costs nothing. Then request reviews from real customers — never fabricate them.

### 18.6 Analytics
`/admin/seo` → *Analytics* → set `ga4_id` (and `gtm_id` if you use Tag Manager). The tags are already wired in the head partial; they simply render nothing while the fields are blank. Without this you cannot tell which pages convert.

### 18.7 Validate the structured data
Once deployed, run the homepage and `/about` through:
- <https://search.google.com/test/rich-results>
- <https://validator.schema.org>

Both should pass clean. Re-run after any content change that touches the FAQ.

### 18.8 Social & directory listings
Fill in `/admin/content` → social links (they feed `sameAs` in the Organization markup, which helps Google connect your profiles to the brand). Then work down the directory list in §17.

---

## 19. What improves immediately

On deploy:
- `/sitemap.xml` returns 200 instead of 404, and `robots.txt` points at it — Google can discover every page.
- Duplicate URLs stop competing; link equity consolidates.
- Search-result titles become clean and readable instead of showing escaped HTML.
- Shared links render a proper card on WhatsApp, LinkedIn, Facebook and X — this affects click-through the day it ships.
- Rich-result eligibility for FAQ, breadcrumbs, sitelinks and the knowledge panel.
- Faster repeat visits from asset caching; lower blocking time from deferred JS.

## 20. What takes days, weeks, months

| Timeframe | What to expect |
|---|---|
| **1–3 days** | Google re-crawls the homepage; the sitemap is read; corrected titles start appearing in results |
| **1–2 weeks** | All 8 pages re-indexed with correct canonicals and metadata; Search Console shows structured data; duplicate `/about/` variants dropped |
| **2–4 weeks** | Search Console accumulates enough query data to guide content; rich results (FAQ, breadcrumbs) may appear |
| **1–3 months** | With the Tier-1 pages published: first rankings for long-tail local terms. Google Business Profile starts producing local visibility |
| **3–6 months** | With sustained content: rankings for regional commercial terms ("ai receptionist pakistan", "whatsapp chatbot lahore"). Meaningful organic traffic |
| **6–12+ months** | Competitive standing on broader category terms — *if* content and authority building are sustained. Global head terms remain a long shot against incumbents with years of accumulated authority |

---

## A note on expectations

I have fixed every technical blocker I could find and built the infrastructure so those fixes stay fixed — the test suite fails if the sitemap empties, if a canonical breaks, if two pages share a title, or if a marked-up FAQ question stops being visible on the page.

**No code change can guarantee a #1 ranking, and anyone who tells you otherwise is selling something.** Google ranks on competition, content quality, relevance and authority. What is now true is that nothing technical is holding the site back, and the highest-leverage remaining work — a pricing page, a handful of genuinely useful commercial pages, a Google Business Profile, and content published consistently — is work only you can do. That work is where the ranking actually comes from.
