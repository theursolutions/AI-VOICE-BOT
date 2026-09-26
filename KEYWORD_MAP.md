# Keyword & Content Map — serveai.com.pk

## September 2026 update — keyword-to-page map

The site is now positioned as **AI customer support & AI agents** (it was "AI receptionist"). Each search intent has exactly one target page. Near-synonyms share a page on purpose: separate pages that differ only by the keyword would be doorway pages, and they would compete with each other.

No search volumes here either. Validate with Search Console → Performance → Queries once the pages have been indexed for 3–4 weeks.

| Page | Primary intent | Also targets (same intent) | Does NOT target |
|---|---|---|---|
| `/` | serveAI brand + "AI customer support & AI agents" | serveAI, Serve AI, ServeAI, serveAI Pakistan, AI automation platform, AI customer support platform | Any single channel |
| `/ai-customer-support` | AI customer support software | AI customer service (software), AI support agent(s), AI customer service agent, customer support automation, customer service automation, AI helpdesk, AI support automation, AI customer support for small business | Voice specifics, WhatsApp specifics |
| `/ai-agents` | AI agents for business | AI agent platform, AI support agents that take actions, AI business assistant, AI virtual assistant for business, AI agent for customer service | "What are AI agents" (informational: blog) |
| `/ai-voice-agent` | AI voice agent | AI phone agent, AI call agent, AI voice customer support, AI voice assistant for business, AI voice support | Outbound / cold calling (not supported) |
| `/ai-chatbot` | AI chatbot for (your) website / business | AI chatbot for customer support, AI support chatbot, AI customer service chatbot, AI webchat, AI chat agent, AI chatbot for online businesses | WhatsApp chatbot |
| `/whatsapp-ai-chatbot` | WhatsApp AI chatbot | AI WhatsApp customer support, WhatsApp Business API chatbot | Unofficial WhatsApp automation |
| `/omnichannel-customer-support` | Omnichannel customer support | Omnichannel AI support, AI social media customer support, Facebook AI chatbot, Instagram AI chatbot, unified inbox | Instagram/Facebook comment moderation (not supported) |
| `/pricing` | serveAI pricing | AI customer support pricing / cost | — |
| `/blog/what-is-ai-customer-support` *(draft)* | What is AI customer support (informational) | How AI customer support works, AI vs traditional support, what to automate | Commercial "software" queries (→ `/ai-customer-support`) |
| `/blog/whatsapp-business-api-chatbot-guide` *(draft)* | WhatsApp chatbot rules (informational) | WhatsApp 24-hour window, templates, WhatsApp API vs app | — |
| `/blog/ai-agents-vs-chatbots-vs-assistants` | AI agents vs chatbots | What is an AI agent | — |
| `/blog/ai-voice-agents-how-they-work-cost` | How AI voice agents work / cost | Voice AI latency, cost per minute | — |
| `/blog/ai-lead-qualification-workflow` | AI lead qualification | Lead scoring, lead routing | — |
| `/blog/why-ai-support-projects-fail` | AI support implementation | AI customer service implementation checklist | — |

### Deliberately not built

| Requested page | Why not |
|---|---|
| `/ai-customer-service`, `/ai-support-agent`, `/ai-customer-support-chatbot`, `/ai-support-automation` | Same search intent as `/ai-customer-support` or `/ai-chatbot`. Separate pages would be near-duplicates |
| `/ai-business-assistant` | Covered by `/ai-agents`. There isn't enough distinct substance for a standalone page |
| E-commerce landing page ("AI chatbot for ecommerce customer support") | No native Shopify/WooCommerce integration. Order lookups work through a generic webhook skill. Worth building once there is a native integration or a real customer case study |

### Content cluster roadmap (write in this order)

Pillar: **AI customer support** (`/ai-customer-support` ↔ `/blog/what-is-ai-customer-support`).

1. How to automate customer support with AI (step-by-step, links to `/ai-customer-support`)
2. AI customer support for small businesses: what to automate first
3. Human handoff: designing the escalation path (links to `/omnichannel-customer-support`)
4. Omnichannel vs multichannel customer support (links to `/omnichannel-customer-support`)
5. How AI voice agents handle customer calls, and when they should not (links to `/ai-voice-agent`)
6. AI customer support for e-commerce: order status, returns, stock (only with real examples)
7. Can one AI agent handle Urdu and English? (only once Urdu voice output is production quality)

Every article: one primary topic, sources for every statistic, a link to its one closest product page, and links to 1–2 siblings. Articles are seeded as drafts (`php artisan blog:seed-articles`) and published by a person from `/admin/blog`.

---

## August 2026 baseline (original map)


**Prepared:** 8 August 2026

## How to read this

**No search-volume numbers appear in this document.** I have no verified keyword-data source connected, and inventing volumes would give you false confidence when deciding what to build. Pull the real numbers before committing to the roadmap — see "Getting real numbers" at the end.

What *is* here is grounded in evidence: the site's actual content, the actual public routes, and how the competitors currently ranking in this market structure their own sites.

---

## 1. Who you are actually competing against

Two different battles, and they need different content.

### Global SaaS competitors — "AI receptionist / AI voice agent" category
[Synthflow](https://synthflow.ai) · [Retell AI](https://www.retellai.com) · [Smith.ai](https://smith.ai) · [Rosie](https://heyrosie.com) · [Goodcall](https://www.goodcall.com) · [My AI Front Desk](https://www.myaifrontdesk.com) · [Dialzara](https://dialzara.com) · [CloudTalk](https://www.cloudtalk.io) · [Nextiva](https://www.nextiva.com) · [Voksha](https://voksha.com)

These have large content libraries, established domain authority and years of backlinks. **You will not out-rank them on "ai receptionist" head terms in the short or medium term, and possibly not at all.** Their published pricing sits between $49/mo (Rosie) and $299/mo flat-rate, with enterprise tiers $700–$2,000+ — useful context for how you position your own pricing page.

### Regional competitors — Pakistan / Lahore, "AI chatbot & WhatsApp automation"
[Intellicon](https://www.intellicon.io) · [TekkPak](https://tekkpak.com) · [Tecveq](https://tecveq.com) · [NexZion Solutions](https://nexzionsolutions.com) · [StriveX Digi Solutions](https://www.strivexdigisolutions.com) · [Zargham Labs](https://www.zarghamlabs.com) · [The Instant Convo](https://theinstantconvo.com) · [RTC League](https://rtcleague.com)

**This is where you can realistically win, and win soon.** These are mostly agencies and service shops, not products. They have thinner content, weaker technical SEO, and no equivalent of a self-serve platform. You have a real product, a Lahore address (Arfa Software Technology Park), Urdu support, and local phone presence.

> **Strategic recommendation:** win the Pakistan/regional market first (months, not years), and treat global head terms as a long-term play funded by that traffic. Trying to rank for "best ai receptionist" from a standing start is the most common and most expensive mistake in this category.

---

## 2. Existing URLs

| URL | Primary topic | Secondary topics | Search intent | Current status | Recommended action |
|---|---|---|---|---|---|
| `/` | AI receptionist & CRM for small business | AI voice agent, 24/7 call answering, voice cloning, lead capture, omnichannel inbox | Commercial investigation | **Strong.** Real depth: channels, 12-feature grid, 6 use cases, security, 6-question FAQ. Now carries `SoftwareApplication` + `FAQPage` markup | Keep as the brand/category hub. Add outbound links to the new pages below as they ship. Do **not** stuff keywords — the copy is good |
| `/about` | Company mission & story | why we built it, who it's for | Informational / trust | Thin (~400 words) but honest | Add: founding story, team, the Lahore/Arfa location (a genuine local-SEO asset — it is currently invisible on this page), and a customer count once you have one |
| `/contact` | Contact, demo request, callback | phone, email, address, "AI calls you back" | Transactional / local | Good. Has a real form, real address, real phone, and the live callback demo | Add an embedded map and explicit opening hours; both feed local search. Now marked up as `ContactPage` |
| `/security` | Data security & AI access control | tenant isolation, column-level permissions, encryption, audit trail | Commercial (objection handling) | Good, and a genuine differentiator | Expand into the strongest B2B trust asset you have. Add a sub-section on data residency and one on what happens to conversation data on cancellation |
| `/privacy` | Privacy policy | GDPR, data retention | Legal | Fine | No SEO action. Keep indexable — legal pages are a trust signal |
| `/terms` | Terms of service | — | Legal | Fine | No action |
| `/refund-policy` | Refunds & cancellation | 14-day guarantee | Legal / pre-purchase | Fine | Link to it from the future pricing page — it answers a real buying objection |
| `/cookies` | Cookie policy | — | Legal | Fine | No action |
| `/v2` | *(duplicate homepage draft)* | — | — | **noindex** as of this pass | Delete the route once the draft is either merged or abandoned |
| `/voice-bot` | *(internal recording harness)* | — | — | **noindex** as of this pass | Move behind auth or delete |
| `/login`, `/register` | Auth | — | Navigational | **noindex** as of this pass | Correct as-is |

---

## 3. Recommended new pages — priority order

Each of these is a page a real buyer would search for and read. **None of them should be spun from a template.** If a page cannot be written with genuine, specific substance, do not publish it — six real pages beat sixty thin ones, and thin near-duplicates are actively penalised.

### Tier 1 — build these first

| Proposed URL | Primary topic | Search intent | Why it earns its place |
|---|---|---|---|
| `/pricing` | What Serve AI costs | Transactional — **highest intent in the category** | Every competitor has one; you have no indexable page containing the word "pricing". Buyers who cannot find a price leave. Publish real numbers, name what counts as a "minute", and link to `/refund-policy` |
| `/ai-receptionist-pakistan` | AI receptionist for Pakistani businesses | Commercial, local | Your most winnable commercial term. Local pricing in PKR, Urdu support, local phone numbers, Pakistani business examples. This is the page the local agencies cannot write as well as you can |
| `/whatsapp-ai-chatbot` | WhatsApp Business API AI agent | Commercial | WhatsApp is *the* business channel in Pakistan and the region. Your competitors' single strongest topic. You already ship official Cloud API support — say so on a dedicated page |
| `/ai-voice-agent` | AI voice agents / phone automation | Commercial investigation | The category term for the voice half of the product. Covers voice cloning, 13 languages, inbound + outbound, Twilio/BYO numbers |

### Tier 2 — industry pages (one per segment already named on the homepage)

Only build a page here if you can write 800+ words of *segment-specific* substance: the actual questions that industry's customers ask, the actual objections, a realistic scenario. If all you can produce is the homepage copy with a noun swapped, skip it — that is a doorway page.

| Proposed URL | Segment | Intent |
|---|---|---|
| `/solutions/clinics-and-salons` | Appointment booking, after-hours patient calls, no-show reduction | Commercial, high value |
| `/solutions/real-estate` | Listing enquiries, buyer qualification, viewing scheduling | Commercial |
| `/solutions/restaurants` | Reservations, menu & hours questions, dinner-rush overflow | Commercial |
| `/solutions/ecommerce` | Stock, order status, pre-sales questions | Commercial |
| `/solutions/trades-and-services` | Job capture, quoting from a price list, missed-call recovery | Commercial |
| `/solutions/agencies` | Lead qualification, demo booking, CRM handoff | Commercial, B2B |

### Tier 3 — informational content (the compounding, long-term layer)

This is what actually builds topical authority. It is also the slowest. Target roughly one substantial article a week, sustained.

| Topic | Intent | Notes |
|---|---|---|
| How much does an AI receptionist cost? | Informational → commercial | Honest comparison including competitors. Ranks, converts, and builds trust |
| AI receptionist vs. human receptionist vs. answering service | Comparative | Do not strawman the alternatives — buyers can tell |
| How to set up a WhatsApp Business API chatbot (step by step) | Informational, how-to | Genuinely useful; earns links |
| What is voice cloning, and is it safe to use for business calls? | Informational | Addresses a real, unspoken objection |
| How many leads do businesses lose to missed calls? | Informational | Cite real studies with links. **Do not invent statistics** |
| Serve AI vs. [named competitor] | Comparative, high intent | Only if written fairly and kept factually accurate |
| Can an AI agent handle Urdu and English in the same call? | Informational, local | A differentiator almost nobody else can claim |

**Requires a blog system.** None exists in the codebase today. Recommended: `/blog` index + `/blog/{slug}` posts, with `Article` JSON-LD, author attribution, and automatic inclusion in the sitemap. `App\Services\Seo\SitemapBuilder` was written to absorb this — it already handles splitting into a sitemap index above 5,000 URLs.

---

## 4. Internal linking plan

Current state (after this pass) is sound for eight pages:

```
Homepage ──┬─→ /security ─┐
           ├─→ /contact ──┤
           ├─→ /about ────┼─→ (footer, sitewide) → all legal pages
           └─→ /register  ┘
```

As the new pages land:

- **Homepage** → `/pricing`, `/ai-voice-agent`, `/whatsapp-ai-chatbot` from the nav; the "Made for your business" grid tiles link to their `/solutions/*` pages.
- **Each `/solutions/*` page** → `/pricing`, `/contact`, and 2–3 sibling solutions. Not more — link every page to every page and none of the links mean anything.
- **Each blog post** → the one commercial page it is closest to, in the body copy, with descriptive anchor text.
- **`/pricing`** → `/refund-policy`, `/security`, `/contact`.

Rules: descriptive anchor text (not "click here"), no more than a handful of contextual links per page, and never a link that a reader would not plausibly want to follow.

---

## 5. Getting real numbers

Before committing to Tier 2/3, validate demand with an actual data source:

1. **Google Search Console** (free, and the only source that tells you what *your* site is already being shown for) — connect it first, then wait 2–4 weeks. The Performance report's "queries" tab is the highest-signal keyword research you will ever get.
2. **Google Keyword Planner** (free with an Ads account) — volumes for Pakistan specifically, which most third-party tools estimate poorly.
3. **Ahrefs / Semrush / Ubersuggest** (paid) — competitor gap analysis: what the eight regional competitors above rank for that you do not.
4. **Google autocomplete + "People also ask"** on your target terms — free, and directly reflects real phrasing.

Fill the volume/difficulty column in from those sources, then re-prioritise. The order above is my judgement from intent and competitive position, not from measured demand.
