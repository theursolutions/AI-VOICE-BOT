# Meta Channels — setup, App Review, and how onboarding works

**Audience:** whoever administers the Meta app for Serve AI.
**Code:** `admin/packages/meta-channels/`, `admin/app/Http/Controllers/Admin/ChannelOnboardController.php`

The engineering is done and tested. What remains is Meta's paperwork, which
has **2–6 weeks of latency you cannot compress** — so start §2 today and
build everything else while it runs.

---

## 1. How onboarding works now

Three ways in, one pipeline behind them.

| Flow | Customer experience | Needs |
|---|---|---|
| **Embedded Signup** | Clicks *Connect WhatsApp* → Meta popup → picks/creates a WhatsApp Business Account → verifies the number → done, never leaves the dashboard. ~2 min | `META_WA_CONFIG_ID` + approved Tech Provider app |
| **QR handoff** | Desktop shows a QR → scans with their phone → finishes there, where WhatsApp lives → the desktop updates itself | Nothing extra |
| **Redirect** | Facebook Login in a popup → picks accounts → returns | Nothing extra |

`Connect WhatsApp` uses Embedded Signup when `META_WA_CONFIG_ID` is set and
**silently falls back to the redirect flow when it isn't** — so an
unconfigured install still works rather than showing a broken button.

### The bit that matters when things go wrong

Onboarding **persists what Meta returns before doing anything with it**:

```
Meta redirect ─▶ [ save code + tokens + discovery ] ─▶ exchange ─▶ discover ─▶ import
                          (channel_onboarding_payloads)
                                     ▲
                        Retry replays from here — no return to Meta
```

The customer's expensive journey is the trip to Facebook. Once that's done,
every later failure — Graph rate limit, a withheld permission, a database
blip — is **our** problem, and *Retry* replays our side alone.

Two consequences worth knowing:

- The OAuth `code` is single-use and dies in ~10 minutes, so it is useless
  as a retry anchor. The pipeline therefore trades it for a **long-lived
  token (~60 days) immediately**. That token is what makes retry possible,
  and it is why the retry window is 60 days rather than 10 minutes.
- Once the import succeeds, the stored credentials are **deleted** — they
  existed only to enable a retry, and the working token now lives on the
  connection.

Where the *Retry* button genuinely cannot help — consent was denied, or the
stored token has lapsed — the UI shows **Reconnect** instead, with a tooltip
saying why. It never offers a button that cannot work.

### Tokens

`channel_connections` stores both:

| Column | What |
|---|---|
| `access_token` | the working credential — long-lived, used for sending |
| `short_lived_token` | what Meta first returned, kept for diagnosis |
| `token_expires_at` | **NULL means never expires** (page tokens, system-user tokens) |

Before this, whatever Facebook returned from the OAuth exchange was stored
directly — a short-lived token — so every connection quietly stopped working
the same afternoon it was made. `ChannelConnection::tokenExpiringWithin($days)`
exists for a future refresh sweep.

---

## 2. Meta app setup — do this first

### 2.1 Create the app
<https://developers.facebook.com> → **My Apps → Create App → Business**.

Add products: **Facebook Login**, **WhatsApp**, **Messenger**, **Instagram**.

### 2.2 Fill in `.env`

```dotenv
META_APP_ID=
META_APP_SECRET=
META_OAUTH_REDIRECT=https://serveai.com.pk/meta/oauth/callback
META_WHATSAPP_VERIFY_TOKEN=<any random string, must match Meta exactly>
```

### 2.3 Register the redirect URI
App → **Facebook Login → Settings → Valid OAuth Redirect URIs**:
```
https://serveai.com.pk/meta/oauth/callback
```
Must match byte-for-byte or the token exchange fails.

### 2.4 Webhooks
App → **WhatsApp → Configuration → Webhook**:
- Callback URL: `https://serveai.com.pk/api/whatsapp/webhook`
- Verify token: your `META_WHATSAPP_VERIFY_TOKEN`
- Subscribe to: `messages`, `message_template_status_update`

> The app is also subscribed **per WABA** automatically at the end of
> Embedded Signup. Without that per-account subscription a number connects
> successfully and then never receives anything — the single most common
> "it says connected but nothing happens" ticket in WhatsApp onboarding.

### 2.5 Business Verification — start now, it's the long pole
App → **Settings → Basic → Business Verification**.

Needs: business registration / NTN certificate, a utility bill or bank
statement showing the business name and address, and a verifiable phone
number and website. **Use exactly the same name and address as your Google
Business Profile and the site footer** — mismatches are a common rejection.

**Typically 3–10 business days.** Everything below is blocked on it.

---

## 3. App Review

All four permissions the product needs are App Review permissions. Until
approved they work **only** for people listed as Admin/Developer/Tester on
the app itself — which is fine for your own testing and useless for customers.

| Permission | For | Why we need it |
|---|---|---|
| `whatsapp_business_messaging` | WhatsApp | Send and receive messages |
| `whatsapp_business_management` | WhatsApp | Read the WABA, numbers, templates |
| `pages_messaging` | Facebook | Reply to Page messages |
| `instagram_manage_messages` | Instagram | Reply to IG DMs |
| `pages_show_list`, `business_management` | all | List what the customer may connect |

### What to submit for each

1. **A screencast** showing the whole flow end to end: a logged-in user on
   the Channels page → *Connect WhatsApp* → Meta's screens → back on the
   Channels page with the channel listed → a message arriving and the AI
   replying. Record it at full resolution and narrate it.
2. **Step-by-step written instructions** so a reviewer can reproduce it.
3. **Test credentials** — a real working account on serveai.com.pk with a
   workspace already set up. Reviewers reject submissions they cannot log
   into more often than for any other reason.
4. **A plain explanation of why** each permission is needed. Tie it to what
   the reviewer can see in the video.

### Also required before approval
- Privacy Policy URL → `https://serveai.com.pk/privacy` ✅ live
- Terms of Service URL → `https://serveai.com.pk/terms` ✅ live
- Data Deletion Instructions URL → `https://serveai.com.pk/data-deletion` ✅ live (see §5)
- App icon, category, and a real description

**Typically 1–4 weeks**, and rejection on the first attempt is common. The
usual causes are a video that doesn't show the permission actually being
used, and test credentials that don't work.

---

## 4. Getting a real WhatsApp number live

Meta's test number can only message five hard-coded recipients. Everything
below is about a number customers can actually write to.

The order matters — each step is gated by the one above it.

### 4.1 Add the number to the WABA

**WhatsApp Manager → Phone numbers → Add phone number.**

The number must NOT be registered on WhatsApp anywhere else. A number in use
on the WhatsApp Business *app* has to be deleted from that app first, which
loses its chat history. There is no way around this without **Coexistence**,
which needs Tech Provider status (see 4.5).

### 4.2 Verify it

Meta sends an SMS or places a voice call. The number has to be able to
receive one at the moment you click — this is not a code you can request
later.

### 4.3 Set two-step verification

**WhatsApp Manager → the number → Two-step verification.** Pick a 6-digit
PIN and *record it*. It is required by the next step and there is no way to
read it back.

### 4.4 Register the number for Cloud API

**This is the step everyone misses.** Verified is not the same as
registered: an unregistered number appears in Graph, shows up in our Channels
page, and silently cannot send a single message.

`OAuthService::registerPhoneNumber($phoneNumberId, $pin, $token)` performs
it. **It is not wired to any UI yet** — see §5b. Until it is, register with:

```bash
curl -X POST "https://graph.facebook.com/v21.0/<PHONE_NUMBER_ID>/register" \
  -H "Authorization: Bearer <ACCESS_TOKEN>" \
  -d "messaging_product=whatsapp" \
  -d "pin=<6-DIGIT-PIN>"
```

### 4.5 The rest, in order of how long they take

| | Why it blocks you |
|---|---|
| **Business Verification** | Gates everything below. Start it first — it is measured in weeks. |
| **Display name approval** | The name customers see. Rejected names block sending. |
| **Payment method on the WABA** | Beyond the free tier, sends fail without one. |
| **App Review → Advanced Access** | Until granted, only people with a role on the app can be messaged. |
| **Tech Provider status** | Unlocks Embedded Signup *and* Coexistence. |

---

## 4a. Embedded Signup

Requires **Tech Provider** or Solution Partner status. Note the config does
NOT live under the WhatsApp product, which is where the old version of this
doc sent people.

**App Dashboard → Facebook Login for Business → Configurations → Create
configuration:**

| Field | Value |
|---|---|
| Login method | **WhatsApp Embedded Signup** |
| Access token | 60 days |
| Assets | WhatsApp Account |
| Permissions | `whatsapp_business_management`, `whatsapp_business_messaging` |

Then **Facebook Login for Business → Settings → Client OAuth Settings** —
both toggles to Yes, and add the site to BOTH:

- Valid OAuth Redirect URIs
- **Allowed Domains for the JavaScript SDK** ← omit this and `FB.login()`
  fails silently in the browser with nothing in the console worth reading

Copy the configuration id and set:
```dotenv
META_WA_CONFIG_ID=<configuration id>
```

That single variable switches `Connect WhatsApp` from the redirect flow to
the popup. No code change, no UI work — the launcher already falls back when
it is absent.

> **Deadline:** Embedded Signup **v2 is deprecated 15 October 2026**. Our
> launcher sends `sessionInfoVersion: '3'`; re-check the parameter list
> against Meta's docs before relying on it past that date.

### Coexistence — worth knowing before you sell it

Coexistence lets a customer keep their WhatsApp Business *app* AND the Cloud
API on one number, syncing contacts and ~180 days of history. For SMEs who
all run the Business app, this removes the single biggest objection.

It also **disables features we already support**: WhatsApp calling (our
`answerCall` path) and catalogs/orders (`sendProduct`, `sendProductList`).
Throughput is capped at 20 messages/second. Decide which matters more per
customer before enabling it.

---

## 4b. Instagram API with Instagram Login

Meta ships **two** ways to do Instagram messaging. They look interchangeable
and share almost nothing:

|                | Facebook Login            | Instagram Login              |
|----------------|---------------------------|------------------------------|
| authorize on   | `facebook.com`            | `instagram.com`              |
| exchange on    | `graph.facebook.com`      | `api.instagram.com`          |
| call Graph on  | `graph.facebook.com`      | `graph.instagram.com`        |
| credentials    | `META_APP_ID` / `SECRET`  | its own id + secret          |
| scopes         | `instagram_manage_messages` | `instagram_business_*`     |
| **requires**   | **a linked Facebook Page** | **nothing**                 |

That last row is the whole reason this exists. Most Instagram business
accounts are not linked to a Facebook Page, and for those the Facebook-Login
route finds nothing and reports "No Instagram business accounts linked to
the granted pages" — which is accurate and completely unhelpful.

### 4b.1 Get the credentials

App dashboard → **Instagram → API setup with Instagram login**. Copy the
**Instagram app ID** and **Instagram app secret** from step 3 on that page.
These are *not* the values under Settings → Basic.

```dotenv
INSTAGRAM_APP_ID=<instagram app id>
INSTAGRAM_APP_SECRET=<instagram app secret>
```

Setting these switches `Connect Instagram` to the Instagram-Login flow.
Leave them blank and it keeps using Facebook Login, so this change is safe
to deploy before you are ready to use it.

### 4b.2 Register the three URLs

Same page → **Business login settings**. All three must match exactly:

| Field | URL |
|---|---|
| OAuth redirect URI | `https://serveai.com.pk/meta/instagram/callback` |
| Deauthorize callback URL | `https://serveai.com.pk/meta/instagram/deauthorize` |
| Data deletion request URL | `https://serveai.com.pk/meta/data-deletion` |

> Meta **pings the data-deletion URL when you save it**. Deploy first, or the
> field rejects the value and the error does not say why.

Instagram requires HTTPS on the redirect URI with no localhost exemption —
unlike Facebook. Test against the deployed site or a tunnel.

### 4b.3 Webhooks

The callback URL is the same one everything else uses —
`https://serveai.com.pk/api/whatsapp/webhook` — because Meta posts all
products to one endpoint and the controller routes on `object`. Under
Instagram → Webhooks, subscribe the **`messages`** field.

Per-account subscription happens automatically at the end of onboarding
(`subscribe_instagram` on the log). To check or repair it:

```bash
php artisan meta:subscribe          # report
php artisan meta:subscribe --fix    # subscribe anything that isn't
```

### 4b.4 Tokens expire — this one bites later

Facebook Page tokens are permanent. **Instagram Login tokens are not**: 60
days, always, with no permanent equivalent. Left alone, every Instagram
account stops working two months after it is connected, and the only symptom
is replies silently failing to send.

`meta:refresh-tokens` runs daily at 04:40 and refreshes anything within 20
days of expiry. Two constraints shape that schedule:

- a token must be **at least 24 hours old** to be refreshable;
- an **expired** token cannot be refreshed at all — the customer has to
  reconnect from Instagram.

So the sweep works well ahead of the deadline rather than on it. **This
requires the Laravel scheduler to be running.** If it is not, Instagram will
appear to work perfectly for two months and then break.

```bash
php artisan meta:refresh-tokens --dry-run   # what would be refreshed
php artisan schedule:list                   # confirm it is scheduled
```

### 4b.5 App Review

Instagram Login needs its own Advanced Access for
`instagram_business_manage_messages`. Until then it works only for accounts
holding a role on the app. `instagram_business_basic` is granted by default.

If consent fails wholesale with an "Invalid Scopes" style error, drop
`instagram_business_manage_comments` from `INSTAGRAM_SCOPES` — one
unapproved permission fails the entire screen, not just itself.

---

## 5. Data deletion

Required by Meta for any app holding messaging permissions. Three URLs that
are easy to confuse:

| Route | Purpose | Dashboard field |
|---|---|---|
| `GET /data-deletion` | human instructions | Data Deletion **Instructions** URL |
| `POST /meta/data-deletion` | signed machine callback | Data Deletion **Request** URL |
| `GET /meta/data-deletion/status/{code}` | where the callback's reply points | — |

The callback verifies Meta's `signed_request` HMAC against every app secret
we hold (Facebook, Instagram, WhatsApp) and returns
`{url, confirmation_code}`. Verification is the *only* thing protecting it:
the endpoint is unauthenticated by necessity and it destroys conversation
history, so without the HMAC anyone who learned a PSID could wipe a
customer's inbox.

Erasure itself is queued (`PurgeMetaUserData`) because Meta's callback
timeout is short and the work spans every tenant database. **The queue worker
must be running** or requests sit at "In progress" indefinitely.

What is deleted: every conversation and message for that platform id, across
every project with a Meta channel, plus cached profile name/photo. What is
kept: the `data_deletion_requests` row itself — provider, opaque id,
timestamps, no content. That row is the proof the deletion happened, which
is exactly what the endpoint exists to be able to demonstrate.

---

## 5b. Still to build

- [ ] **Post comments** — the `feed` webhook field is neither subscribed nor
      handled, so comments on Page/IG posts are not ingested at all. The
      inbox shows DMs only.
- [ ] **Payload purge** — delete `channel_onboarding_payloads` rows past
      `expires_at`. They hold encrypted credentials, so they shouldn't
      linger indefinitely.
- [ ] **Phone number registration** — `OAuthService::registerPhoneNumber()`
      exists but isn't wired to a UI. Needed when a customer brings a number
      that isn't registered for Cloud API yet.

---

## 6. Known friction to expect from customers

**"My number won't connect."** A number already in use in the WhatsApp
Business *app* cannot be used with the Cloud API until it is deleted from
that app. This catches nearly every first-time user. Meta's own error text
is unhelpful; worth catching explicitly in the UI.

**"It connected but nothing happens."** Almost always the per-WABA webhook
subscription (§2.4). The pipeline does this automatically and logs
`subscribe_webhooks`; check that step in the onboarding log.

**"Permissions error."** The customer unticked something on the consent
screen. The pipeline catches this at the token step and names the missing
scopes rather than failing later with `(#200) Permissions error`.

---

## 7. Testing before approval

Add yourself under App → **Roles → Roles** as Administrator, then:

- Development mode + your own account exercises the whole flow for real
- WhatsApp gives you a **free test number** under App → WhatsApp → API Setup
- `php artisan test --filter=ChannelOnboardingTest` covers the pipeline,
  retry semantics and idempotency without touching Meta at all
