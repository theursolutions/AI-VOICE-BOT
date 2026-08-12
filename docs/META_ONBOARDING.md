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
- Data Deletion Instructions URL → **you still need this** (see §5)
- App icon, category, and a real description

**Typically 1–4 weeks**, and rejection on the first attempt is common. The
usual causes are a video that doesn't show the permission actually being
used, and test credentials that don't work.

---

## 4. Embedded Signup (after approval)

Requires **Tech Provider** status: App → **WhatsApp → Embedded Signup**.

Create a configuration, copy its id, and set:
```dotenv
META_WA_CONFIG_ID=<configuration id>
```

That single variable switches `Connect WhatsApp` from the redirect flow to
the popup. Nothing else changes — no deploy of code, no UI work.

---

## 5. Still to build

- [ ] **Data Deletion Instructions URL** — Meta requires it for App Review.
      A short public page explaining how a customer deletes their data, plus
      ideally the deletion *callback* endpoint. Blocks approval.
- [ ] **Token refresh sweep** — a scheduled command using
      `ChannelConnection::tokenExpiringWithin(7)` to re-exchange before
      expiry. Not urgent while page tokens are permanent, but WhatsApp user
      tokens do lapse at 60 days.
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
