# Telephony — Asterisk (SIP + WebRTC)

Asterisk is our second telephony provider next to Twilio. It connects the AI to
telco SIP trunks, office PBXs (e.g. Grandstream UCM) and browsers (WebRTC).
Our code controls calls through **ARI**; call audio reaches the voice engine
through **AudioSocket** (Phase 2).

```
telephony/
├── asterisk/
│   ├── install.sh            Phase 0 lab installer (Ubuntu 24.04, WSL2 or VM)
│   ├── conf/                 our Asterisk configs (passwords filled in at install)
│   └── static/webrtc-lab.html  browser test phone, served by Asterisk
└── tools/
    └── ari_smoke.py          proves our code can answer and control a call
```

## Phase 0 — local lab (Windows + WSL)

**Install on Windows:** [MicroSIP](https://www.microsip.org) (free softphone).
Chrome or Edge for the browser phone. Nothing else.

**1. Install Asterisk in WSL** (asks for your Ubuntu password):

```bash
wsl -d Ubuntu -- sudo bash /mnt/c/laragon/www/AI-CRM-AGENT/telephony/asterisk/install.sh
```

The output ends with the server address (the WSL IP, e.g. `172.27.60.6`) and
the lab passwords. Keep it open.

**2. MicroSIP → Add account**

| Field | Value |
|---|---|
| SIP Server | the WSL IP from step 1 |
| Username / Login | `1001` |
| Domain | the WSL IP from step 1 |
| Password | `PASS_1001` from step 1 |
| Transport | UDP |

**3. Test, in this order**

| # | Test | Expected |
|---|---|---|
| 1 | MicroSIP dials `600` | You hear your own voice (echo test) |
| 2 | Open `http://localhost:8088/static/webrtc-lab.html`, register as 1002, dial `600` | Echo in the browser |
| 3 | Browser dials `1001` | MicroSIP rings; talk both ways |
| 4 | Run the ARI smoke test (below), then dial `1122` | "Hello world", then hang-up |

```bash
voice-engine/.venv/Scripts/python telephony/tools/ari_smoke.py --password <ARI_PASSWORD>
```

### Troubleshooting

- **MicroSIP won't register** — the WSL IP changes after `wsl --shutdown` or a
  reboot. Get the new one with `wsl -d Ubuntu hostname -I` and update the account.
- **Call connects but no sound** — allow MicroSIP through Windows Firewall on
  *Public* networks too (the WSL network counts as public).
- **Asterisk CLI** — `wsl -d Ubuntu -- sudo asterisk -rvvv`, then e.g.
  `pjsip show endpoints`, `pjsip set logger on`, `core show channels`.
- **Re-run the installer** any time configs change; it keeps the passwords.

The lab config is for WSL only. ARI runs over plain HTTP and the test phones
exist. Production (TLS, firewall, fail2ban, trunks) is Phase 5.

## Phase 2 — the AI answers Asterisk calls

```
MicroSIP (1001) dials 1122 → Asterisk → Stasis(ai-crm)
   → voice engine (ARI): asks Laravel who answers 1122, answers, bridges
   → AudioSocket: call audio ⇄ the same call core Twilio calls use
   → "I want to talk to a person" → transferred to the number's target (1002)
```

**1. Re-run the installer** (picks up the new `[ai-transfer]` dialplan):

```bash
wsl -d Ubuntu -- sudo bash /mnt/c/laragon/www/AI-CRM-AGENT/telephony/asterisk/install.sh
```

**2. Let Asterisk (in WSL) reach the voice engine (on Windows).** Find the
Windows address as WSL sees it:

```bash
wsl -d Ubuntu -- ip route show default     # "default via 172.27.48.1 …" → 172.27.48.1
```

and allow the AudioSocket port from the WSL network only (PowerShell **as admin**):

```powershell
New-NetFirewallRule -DisplayName "serveAI AudioSocket (lab)" -Direction Inbound -Protocol TCP -LocalPort 9092 -RemoteAddress 172.16.0.0/12 -Action Allow
```

**3. `voice-engine/.env`**

```ini
ASTERISK_ARI_URL=http://127.0.0.1:8088
ASTERISK_ARI_PASSWORD=<ARI_PASSWORD from the install output>
AUDIOSOCKET_ADVERTISE=172.27.48.1:9092      # the address from step 2
```

**4. Give 1122 to a project.** Admin → Telephony → Add number: *Connected
through* = Asterisk, number `1122`, routed to your agent, *Transfer to a
person* = `1002`. (Shortcut for a single-project lab: `ASTERISK_DEFAULT_PROJECT_ID=<id>`
in `admin/.env`.)

**5. Start the voice engine** (stop `ari_smoke.py` first — same ARI app):

```bash
cd voice-engine && .venv/Scripts/python -m uvicorn app.api.http:app --port 8002
```

`http://127.0.0.1:8002/metrics` should show `"asterisk_connected": true`.

**6. Test**

| # | Test | Expected |
|---|---|---|
| 1 | MicroSIP dials `1122` | The agent greets you, then answers what you ask |
| 2 | Talk over the AI while it speaks | It stops and listens |
| 3 | Browser phone registered as 1002; on MicroSIP say "I want to talk to a person" | The AI says it's connecting you; the browser rings |
| 4 | Admin → Conversations | The call is there with its transcript; Telephony minutes counted |

On this CPU-only laptop the cloned voice (XTTS) runs slower than real
time; pick a Google voice (Admin → Voices) for real-time replies.

### How the AI sounds on a call

Set per agent (Admin → Agents → *On calls*): the call language — 48
languages, each tested end to end over an 8 kHz phone line — and
*Auto-detect the caller's language*, which answers every caller in the
language they speak. All but 17 of them need a Google voice (the modal
lists which).

What a caller hears (`voice-engine/app/calls/session.py`, settings in
`voice-engine/.env.example` under `CALL_*`):

| Moment | The AI |
|---|---|
| Caller asks something that needs a lookup | Says a fitting line only if the lookup is slow — about their order, a price, their account — or reassurance if they sound upset. Never the same line twice in a call. |
| Caller sounds worried / angry / confused | Acknowledges it in its own words; a Google voice also speaks more gently and slowly |
| Caller pauses mid-sentence | Waits; what they add is heard together with what they'd said |
| ~4.5 s of silence after an answer | "Is there anything else I can help you with, sir / madam?" (from the caller's voice) |
| ~8 s of silence after a question | "Take your time, I'm right here." |
| Still silent 6 s later, or the caller says they're done | A warm goodbye, then it hangs up |

| # | Test | Expected |
|---|---|---|
| 5 | Ask about your order, then stay silent | "…anything else?" after ~5 s; goodbye and hang-up ~6 s later |
| 6 | Say "Nahi bas, shukriya" | A goodbye ("…Allah Hafiz!") and the call ends — no "let me check" first |
| 7 | Start "My order number is…", pause a second, finish | One answer to the whole sentence |
| 8 | Speak English to an agent set to Roman Urdu with auto-detect | English replies |

## Phase 3 — the agent softphone (admin panel)

The call lives in a small **phone window** (`/c/{client}/phone`); the phone
bottom-right on every admin page is a live mirror and remote control of it
(BroadcastChannel). Refreshing or moving between admin pages never drops a
call. A Web Lock keeps one phone window per browser
(`admin/public/assets/softphone`, `SoftphoneController`).

**Set up (once per user):** phone button → *Open phone* (allow pop-ups) →
allow the microphone in the phone window → ⚙ → extension `1002`, password
`PASS_1002`, server `ws://localhost:8088/ws` → *Save & connect*. Keep the
phone window open (minimise it). Close `webrtc-lab.html` — it is also 1002.

| # | Test | Expected |
|---|---|---|
| 1 | Dial `600` from the admin phone | Echo |
| 2 | MicroSIP dials `1002` | The admin phone rings on any page; answer → timer, hold, mute, keypad |
| 3 | Minimise during the call, then expand | Banner with timer, mute, hang-up |
| 4 | MicroSIP → `1122` → "I want to talk to a person" | The admin phone rings with what the caller told the AI + *Open conversation* |
| 5 | Blind transfer / Consultation to `1001` | The other party moves to MicroSIP |
| 6 | Refresh the admin page (F5) during a call | The page comes back showing the same call, timer still running |

Rec saves the recording to your computer; Conference is not built yet.

## Extensions and the website "Call us" button

Both are SIP accounts the admin panel pushes into Asterisk over ARI, live
(`sorcery.conf` keeps them in astdb, so they survive restarts). Laravel owns
the list; the voice engine does the pushing (`voice-engine/app/api/routes/sip.py`).
Accounts can only be put in two contexts, and neither reaches an outside line.

| | Extensions | Website call button |
|---|---|---|
| Where | Admin → Telephony → Extensions (every client) | Admin → Telephony → Website call button |
| Account | `c<client>_<extension>`, context `internal-calls` | `w<random>`, one call, context `web-guest` |
| Dials | each other by number; any other number → that client's AI | the project's AI only |
| Limits | — | per visitor IP: call length, calls a day, hours between (serveai.com.pk: 1 min, 3, 4 h) |

Embed: `<script src="https://serveai.com.pk/webcall/call.js" data-project-key="…" async></script>`.
The served file is obfuscated; edit `admin/resources/webcall/call.js` and run
`npm run build:webcall` (in `admin/`) to rebuild `public/webcall/call.js`.

Super admin: Ops → Telephony → **SIP & WebRTC server** (WebSocket URL, STUN,
TURN) and **Website calls** (how many people called, from where, how long).

**Lab test:** create extension 2001 (Phone or app), log MicroSIP in with the
login and password shown, dial `600` (echo) and your AI number.

## Production (deploy/production)

`asterisk` is a service in `docker-compose.prod.yml` (image from
`telephony/asterisk/Dockerfile`, configs `conf/` + `conf-prod/`), on the host
network. Browsers reach it at `wss://<VOICE_DOMAIN>/sip-ws` (Caddy → port 8088).
Once per server:

```bash
# .env — see deploy/production/.env.prod.example (ASTERISK_*, AUDIOSOCKET_*, SIP_*)
echo "ASTERISK_ARI_PASSWORD=$(openssl rand -hex 24)" >> .env
sudo ufw allow 5060/udp comment 'SIP (phones)'
sudo ufw allow 5060/tcp comment 'SIP (phones)'
sudo ufw allow 10000:20000/udp comment 'RTP (call audio)'
sudo ufw allow from 172.16.0.0/12 to any port 8088 proto tcp comment 'Asterisk ARI/WS from Docker'
./deploy/production/deploy.sh update
```

### Phase 2 troubleshooting

- **`asterisk_connected` false** — almost always: **WSL shut Ubuntu down.**
  WSL stops the distro seconds after its last program exits, and Asterisk
  goes with it. Keep one Ubuntu window open while testing — best the
  Asterisk console, which also shows calls live:
  `wsl -d Ubuntu -- sudo asterisk -rvvv`. The voice engine reconnects by
  itself within ~30 s. Otherwise: the ARI password in `voice-engine/.env`
  doesn't match `/etc/asterisk/lab-credentials.env`.
- **Call answers, then silence and hang-up** — Asterisk couldn't reach the
  AudioSocket port: check `AUDIOSOCKET_ADVERTISE` and the firewall rule.
  In the Asterisk CLI: `core show channels`, and look for AudioSocket errors.
- **Call rejected straight away** — no project owns the dialed number: add it
  (step 4) with provider *Asterisk*, exactly as dialed.
