# WhatsApp Calling Integration

How to connect **live WhatsApp voice calls** to the AI voice pipeline.

This document covers (1) what you need from Meta, (2) how to configure the
WhatsApp Business Calling API, and (3) how the WhatsApp call's audio stream
connects to the existing voice-engine pipeline.

> Status: **planned / not yet implemented.** The `sessions.channel` enum
> already reserves the `whatsapp` value, but no webhook handler or media
> bridge exists yet. This doc is the implementation spec.

---

## 1. Background — how WhatsApp calling differs from Twilio

The system today drives calls through **Twilio Media Streams**: Twilio opens a
plain WebSocket to the voice-engine (`/ws/phone`) and streams **μ-law 8 kHz**
audio frames. See [phone.py](../voice-engine/app/api/phone.py) and
[TelephonyController.php](../admin/app/Http/Controllers/Api/TelephonyController.php).

WhatsApp calling (Meta WhatsApp Business Calling API, GA July 2025) is
**different in two ways**:

| | Twilio | WhatsApp Business Calling |
|---|---|---|
| Signaling | HTTP webhook → TwiML | HTTP webhook (`calls` field) → SDP offer/answer via Graph API |
| Media transport | μ-law 8 kHz over plain WebSocket | **WebRTC** (Opus, DTLS/SRTP, ICE) |
| Who terminates media | Twilio ↔ our WebSocket | We must terminate a **WebRTC peer connection** |

> **Key consequence:** WhatsApp cannot connect to `/ws/phone` directly. We need
> a **WebRTC media bridge** that terminates the WhatsApp peer connection,
> transcodes Opus ↔ PCM16, and feeds the *existing* STT → LLM → TTS pipeline.

---

## 2. Requirements (Meta side)

### 2.1 Accounts & one-time prerequisites

| Requirement | Notes |
|---|---|
| **Meta Business Account** (verified) | Business verification required for production. |
| **WhatsApp Business Account (WABA)** | Created in Meta Business Manager. |
| **Meta App** (type: Business) | At [developers.facebook.com](https://developers.facebook.com); add the *WhatsApp* product. |
| **Phone number registered to the WABA** | Not a personal WhatsApp number. Yields a `phone_number_id`. |
| **`whatsapp_business_messaging` permission** | Required even for calling. |
| **Public HTTPS webhook endpoint** | Meta only delivers webhooks over HTTPS with a valid cert. For local dev use a tunnel (ngrok / Cloudflare Tunnel). |
| **System User + permanent access token** | Temporary tokens expire in ~24h; production needs a permanent token. |

### 2.2 Calling-specific requirements

- Subscribe to the **`calls`** webhook field (in addition to `messages` if you
  also want chat).
- **Enable calling** on the phone number (off by default — see §3.2).
- **Consent rule:** Meta requires an existing messaging relationship or granted
  call permission. You **cannot** cold-call arbitrary numbers like a PSTN
  dialer. This mainly constrains *outbound* calling.
- **SIP option** (alternative to direct WebRTC) requires a messaging limit of
  **≥ 2000 conversations / rolling 24h** — likely not available early, so this
  doc uses the direct-WebRTC path.

---

## 3. Meta configuration steps

### 3.1 Add WhatsApp product & get IDs
App dashboard → *Add Product* → **WhatsApp**. Note the `phone_number_id` and
WABA ID. Generate a temporary token for testing.

### 3.2 Enable calling on the phone number
Calling is disabled by default. Enable it via the Graph API:

```http
POST /{phone_number_id}/settings
Authorization: Bearer {ACCESS_TOKEN}
Content-Type: application/json

{
  "calling": {
    "status": "ENABLED",
    "call_icon_visibility": "DEFAULT",
    "callback_permission_status": "ENABLED"
  }
}
```

### 3.3 Configure the webhook
App dashboard → WhatsApp → *Configuration*:

- **Callback URL:** `https://<your-domain>/api/telephony/whatsapp/webhook`
- **Verify token:** a random string you choose (echoed back during the
  verification handshake — see §5.1).
- **Subscribe to the `calls` field** (and `messages` if needed).

### 3.4 Permanent access token
Create a **System User** in Business Manager, assign it to the WABA, and
generate a permanent token with `whatsapp_business_messaging` +
`whatsapp_business_management` scopes.

### 3.5 App Review (production)
To call real users (beyond test numbers), submit the app for review with the
WhatsApp permissions and complete business verification.

---

## 4. Credentials in our system

Add to [admin/config/services.php](../admin/config/services.php) (mirroring the
existing `twilio` block) and the `.env` files:

```env
WHATSAPP_PHONE_NUMBER_ID=        # from §3.1
WHATSAPP_WABA_ID=                # WhatsApp Business Account ID
WHATSAPP_ACCESS_TOKEN=           # permanent token from §3.4
WHATSAPP_APP_SECRET=             # App → Settings → Basic (verifies webhook signature)
WHATSAPP_VERIFY_TOKEN=           # the random string chosen in §3.3
WHATSAPP_GRAPH_VERSION=v21.0     # Graph API version
WHATSAPP_GRAPH_BASE=https://graph.facebook.com
```

```php
// admin/config/services.php
'whatsapp' => [
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'waba_id'         => env('WHATSAPP_WABA_ID'),
    'access_token'    => env('WHATSAPP_ACCESS_TOKEN'),
    'app_secret'      => env('WHATSAPP_APP_SECRET'),
    'verify_token'    => env('WHATSAPP_VERIFY_TOKEN'),
    'graph_version'   => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
    'graph_base'      => env('WHATSAPP_GRAPH_BASE', 'https://graph.facebook.com'),
],
```

- `WHATSAPP_APP_SECRET` → verifies the `X-Hub-Signature-256` header on incoming
  webhooks (the WhatsApp analogue of the existing Twilio HMAC check).
- `WHATSAPP_ACCESS_TOKEN` → authorizes the call-answer request to
  `/{phone_number_id}/calls`.

---

## 5. How the call stream connects to our pipeline

### 5.1 Webhook verification (GET, one-time per config save)
Meta sends `GET /api/telephony/whatsapp/webhook?hub.mode=subscribe&hub.challenge=...&hub.verify_token=...`.
Respond with `hub.challenge` verbatim **iff** `hub.verify_token === WHATSAPP_VERIFY_TOKEN`.

### 5.2 Incoming call event (POST)
When a user calls, Meta POSTs a `calls` event containing the `call_id` and an
**SDP offer**:

```json
{
  "object": "whatsapp_business_account",
  "entry": [{
    "changes": [{
      "field": "calls",
      "value": {
        "calls": [{
          "id": "<call_id>",
          "from": "<e164>",
          "event": "connect",
          "session": { "sdp_type": "offer", "sdp": "<SDP offer>" }
        }]
      }
    }]
  }]
}
```

### 5.3 Answer the call (Graph API)
After the media bridge produces an SDP answer, reply:

```http
POST /{phone_number_id}/calls
Authorization: Bearer {ACCESS_TOKEN}

{
  "call_id": "<call_id>",
  "action": "accept",
  "session": { "sdp_type": "answer", "sdp": "<our SDP answer>" }
}
```

### 5.4 Media bridge (the new component)
A new `voice-engine/app/api/whatsapp.py` using **`aiortc`** (pure-Python
WebRTC):

1. Create an `RTCPeerConnection`, set the remote SDP offer, generate the
   local SDP answer (returned to Laravel → §5.3).
2. Receive Opus frames → decode to **PCM16 16 kHz** → feed the **existing**
   STT → LLM → TTS pipeline (reused unchanged from the Twilio path).
3. Take TTS output (PCM16 24 kHz) → encode to Opus → push onto the WebRTC
   track back to WhatsApp.
4. On `terminate` event, close the peer connection and POST
   `/api/internal/turn-completed` / mark the session `ended` (same as Twilio).

### 5.5 End-to-end flow

```
WhatsApp user taps "call"
   │  webhook: calls event (call_id + SDP offer)
   ▼
Laravel  TelephonyController::whatsappWebhook()         ← NEW
   ├─ verify X-Hub-Signature-256 (WHATSAPP_APP_SECRET)
   ├─ create Session (channel='whatsapp', external_id=call_id), mint JWT
   └─ forward SDP offer → voice-engine
        │
        ▼
voice-engine  app/api/whatsapp.py  (aiortc)             ← NEW
   ├─ RTCPeerConnection: set remote offer → produce SDP answer
   │      └─ answer returned → Laravel → POST /{phone_number_id}/calls (accept)
   ├─ Opus → PCM16 ─┐
   │                ├─►  EXISTING  STT → LLM → TTS  (reused)
   └─ PCM16 → Opus ◄┘
        │  per-turn webhook
        ▼
Laravel  POST /api/internal/turn-completed (existing)
   ├─ persist message, queue ExtractLeadFromTurn
   └─ on 'terminate' → mark session ended
```

---

## 6. Implementation checklist (our code)

| # | Layer | File | Work |
|---|---|---|---|
| 1 | Config | [admin/config/services.php](../admin/config/services.php), `.env(.example)` | Add `whatsapp` block + env keys (§4) |
| 2 | Routes | [admin/routes/api.php](../admin/routes/api.php) | `GET/POST /api/telephony/whatsapp/webhook` |
| 3 | Signature middleware | new middleware | Verify `X-Hub-Signature-256` (HMAC-SHA256 of raw body w/ `app_secret`) |
| 4 | Webhook handler | [TelephonyController.php](../admin/app/Http/Controllers/Api/TelephonyController.php) | `whatsappWebhook()`: verify GET, parse `calls` event, create session, mint JWT, relay SDP, answer via Graph API |
| 5 | Session | already has `whatsapp` channel enum | none (reuse) |
| 6 | Media bridge | `voice-engine/app/api/whatsapp.py` | **New** — aiortc peer connection + Opus codec; reuse STT/LLM/TTS |
| 7 | App wiring | [voice-engine/app/api/http.py](../voice-engine/app/api/http.py) | Include the new router |
| 8 | Pipeline core | existing STT/LLM/TTS | Reused unchanged |

**Effort note:** the media bridge (step 6) is the bulk of the work — WebRTC/Opus
negotiation is fiddly. The Laravel side (steps 1–4) is straightforward and
closely mirrors the existing Twilio handler.

---

## 7. Open questions / decisions before building

- **Outbound calling?** Constrained by Meta's consent rules — confirm the use
  case (inbound-only is far simpler to ship first).
- **Codec/sample-rate:** confirm Opus params Meta negotiates; resample to the
  pipeline's 16 kHz STT / 24 kHz TTS rates.
- **Scale path:** if volume later exceeds the SIP threshold (≥2000 conv/24h),
  evaluate the SIP→Asterisk route as a more robust alternative to aiortc.
- **Per-project credentials:** decide whether WhatsApp creds are global or
  stored per-project (multi-tenant), like other channel config.

---

## 8. References

- [WebRTC.ventures — Integrating WhatsApp Business Calling API with WebRTC](https://webrtc.ventures/2025/11/how-to-integrate-the-whatsapp-business-calling-api-with-webrtc-to-enable-customer-voice-calls/)
- [wuseller — WhatsApp Business Calling API: Integration, SIP & Limits (2026)](https://www.wuseller.com/whatsapp-business-knowledge-hub/whatsapp-business-calling-api-integration-sip-limits-2026/)
- [ORENCloud — WhatsApp Cloud Voice Calling to Asterisk with PJSIP](https://www.orencloud.com/integrating-whatsapp-cloud-api-calling-into-asterisk-freepbx/)
- [VoiceInfra — WhatsApp Business API Voice AI Integration Setup Guide](https://voiceinfra.ai/blog/whatsapp-business-api-voice-ai-integration-setup-guide)
