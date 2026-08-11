<?php

namespace App\Http\Controllers;

use App\Services\Telephony\TwilioCaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Endpoints for the public landing page at "/".
 *
 * TODAY (DEMO_CALL_ENABLED off — the shipped default): the hero bar is a
 * plain "reach out" capture. A visitor leaves a phone number, it lands in
 * contact_leads exactly like the /contact form, and they're told an agent
 * will ring them back. Nothing is dialled. The only limit that applies is
 * a 3-per-minute burst throttle per IP.
 *
 * LATER (flag on, once the Twilio account is off its trial plan): the same
 * submission also has our agent ring the visitor straight back for a short
 * demo call. That path is written and tested but dormant; a trial Twilio
 * account can only dial numbers verified in its console, so switching it on
 * now would fail for every visitor but us. Three limits guard it:
 *
 *   1. Burst    — 3 submissions per minute per IP (RateLimiter).
 *   2. Daily    — DEMO_CALL_MAX_PER_DAY *placed calls* per IP, resetting at
 *                 local midnight. Only successful calls consume quota.
 *   3. Duration — DEMO_CALL_MAX_SECONDS, enforced by Twilio itself via the
 *                 `TimeLimit` parameter, so it holds even if our side dies.
 *
 * The daily cap deliberately applies only when calling is enabled: while
 * this is just a contact form, refusing a lead because the visitor already
 * submitted twice would throw away business for no reason.
 */
class PublicLandingController extends Controller
{
    public function __construct(private TwilioCaller $twilio) {}

    /**
     * GET /api/demo-call/status
     *
     * Lets the landing page render the button already disabled for a
     * visitor who has used their allowance, instead of only finding out
     * after they've typed a number and pressed it.
     */
    public function demoCallStatus(Request $request): JsonResponse
    {
        // No calling, no allowance to spend — the bar stays open all day.
        if (! $this->callingEnabled()) {
            return response()->json($this->openPayload());
        }

        $limit = $this->dailyLimit();
        $used  = $this->callsToday((string) $request->ip());

        return response()->json($this->quotaPayload($used, $limit));
    }

    /**
     * POST /api/demo-call
     *
     * Body: { phone: "+1..." }
     */
    public function demoCall(Request $request): JsonResponse
    {
        $ip = (string) $request->ip();

        // Throttle by IP — 3 attempts per minute is more than enough for
        // a genuine visitor and shuts down obvious abuse.
        $key = 'demo-call:' . $ip;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'ok'      => false,
                'reason'  => 'throttled',
                'message' => 'Per-minute limit exceeded. Please try again later.',
            ] + $this->openPayload(), 429);
        }
        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'phone' => 'required|string|min:7|max:32',
        ]);

        // E.164-ish normalisation — shared with the /contact form so the same
        // visitor doesn't land twice in two different formats.
        $phone = \App\Support\Phone::e164ish($data['phone']) ?? $data['phone'];

        // The lead is captured whatever happens next: a visitor who hits the
        // daily cap or whose call fails is still a lead we want to keep.
        $this->captureLead($request, $phone, $ip);

        // Calling switched off — this is a contact capture and nothing more.
        // No daily cap here on purpose: it would discard leads.
        if (! $this->callingEnabled()) {
            return response()->json([
                'ok'      => true,
                'logged'  => true,
                'message' => 'Thank you for reaching out! Your request has been logged — our agent will call you back soon.',
            ] + $this->openPayload());
        }

        $limit = $this->dailyLimit();
        $used  = $this->callsToday($ip);

        // Daily allowance spent — refuse before touching Twilio.
        if ($limit > 0 && $used >= $limit) {
            return response()->json([
                'ok'      => false,
                'reason'  => 'daily_limit',
                'message' => $this->limitMessage($limit),
            ] + $this->quotaPayload($used, $limit), 429);
        }

        $from = trim((string) config('services.twilio.phone_number', ''));
        $base = rtrim((string) config('services.twilio.webhook_base', ''), '/');

        if ($from === '' || $base === '') {
            Log::warning('demo-call: enabled but Twilio number/webhook base missing');
            return response()->json([
                'ok'      => true,
                'logged'  => true,
                'message' => 'Thank you for reaching out! Your request has been logged — our agent will call you back soon.',
            ] + $this->quotaPayload($used, $limit));
        }

        $maxSeconds = max(5, (int) config('services.demo_call.max_seconds', 30));

        // `demo=1` tells the voice webhook this is an outbound demo: it can't
        // route by dialed number (that's the visitor), so it uses the
        // configured demo project instead. The URL is covered by Twilio's
        // request signature, so the flag can't be forged by a third party.
        $result = $this->twilio->call($phone, $from, $base . '/api/telephony/twilio/voice?demo=1', [
            'time_limit'      => $maxSeconds,
            'ring_timeout'    => max(10, (int) config('services.demo_call.ring_seconds', 20)),
            'status_callback' => $base . '/api/telephony/twilio/status',
        ]);

        if (! $result['ok']) {
            // A call Twilio refused never happened, so it must not eat the
            // visitor's allowance.
            $message = TwilioCaller::visitorMessage($result['twilio_code'])
                ?? 'We couldn’t place the call just now — our team will ring you back shortly.';

            return response()->json([
                'ok'      => false,
                'reason'  => 'call_failed',
                'message' => $message,
            ] + $this->quotaPayload($used, $limit), 200);
        }

        $used = $this->consume($ip);

        Log::info('demo-call: placed', [
            'sid' => $result['sid'], 'ip' => $ip, 'used' => $used, 'limit' => $limit,
        ]);

        $remaining = max(0, $limit - $used);
        $message   = "Calling you now — pick up in a few seconds. This test call ends after {$maxSeconds} seconds.";
        if ($limit > 0 && $remaining === 0) {
            $message .= ' ' . $this->limitMessage($limit);
        }

        return response()->json([
            'ok'      => true,
            'calling' => true,
            'message' => $message,
        ] + $this->quotaPayload($used, $limit));
    }

    // ── Quota helpers ────────────────────────────────────────────────

    private function callingEnabled(): bool
    {
        return (bool) config('services.demo_call.enabled', false);
    }

    private function dailyLimit(): int
    {
        return max(0, (int) config('services.demo_call.max_per_day', 2));
    }

    /**
     * Quota shape for the contact-capture mode, where there is no allowance
     * to run out of. Keeps the response contract identical so the page's
     * lock-out branch simply never fires.
     *
     * @return array{allowed:bool,limit:int,used:int,remaining:int,limit_message:null}
     */
    private function openPayload(): array
    {
        return [
            'allowed'       => true,
            'limit'         => 0,
            'used'          => 0,
            'remaining'     => -1,
            'limit_message' => null,
        ];
    }

    /** Cache key is date-stamped, so the count resets at local midnight. */
    private function quotaKey(string $ip): string
    {
        return 'demo-call:day:' . date('Y-m-d') . ':' . sha1($ip);
    }

    private function callsToday(string $ip): int
    {
        return (int) Cache::get($this->quotaKey($ip), 0);
    }

    /** Record one placed call and return the new total. */
    private function consume(string $ip): int
    {
        $key  = $this->quotaKey($ip);
        $used = $this->callsToday($ip) + 1;

        // Expire a little past midnight rather than 24h out, so the
        // allowance genuinely resets "the next day" and not on a rolling
        // window anchored to the visitor's first call.
        Cache::put($key, $used, now()->endOfDay()->addMinutes(5));

        return $used;
    }

    /** @return array{allowed:bool,limit:int,used:int,remaining:int,limit_message:?string} */
    private function quotaPayload(int $used, int $limit): array
    {
        $remaining = $limit > 0 ? max(0, $limit - $used) : PHP_INT_MAX;
        $allowed   = $limit === 0 || $remaining > 0;

        return [
            'allowed'       => $allowed,
            'limit'         => $limit,
            'used'          => $used,
            'remaining'     => $limit > 0 ? $remaining : -1,
            'limit_message' => $allowed ? null : $this->limitMessage($limit),
        ];
    }

    private function limitMessage(int $limit): string
    {
        return 'Only ' . $limit . ' test ' . ($limit === 1 ? 'call is' : 'calls are')
             . ' allowed in one day. Try again tomorrow.';
    }

    // ── Lead capture ─────────────────────────────────────────────────

    private function captureLead(Request $request, string $phone, string $ip): void
    {
        // Append the capture to a JSONL log so we don't lose it even
        // before a real CRM row is created.
        $line = json_encode([
            'ts'    => date('c'),
            'phone' => $phone,
            'ip'    => $ip,
            'ua'    => substr((string) $request->userAgent(), 0, 200),
            'ref'   => substr((string) $request->headers->get('referer'), 0, 200),
        ]) . PHP_EOL;
        try {
            Storage::disk('local')->append('demo-leads.jsonl', $line);
        } catch (\Throwable $e) {
            Log::warning('demo-call: failed to append capture', ['err' => $e->getMessage()]);
        }

        // Persist to the central contacts table so it shows in the super-admin
        // ops console (/admin/contacts). Best-effort — the JSONL above is the
        // safety net, so a DB hiccup never breaks the visitor's submission.
        try {
            \App\Models\ContactLead::create([
                'phone'      => $phone,
                // Distinct from 'contact_form' so an operator can tell a
                // one-tap number drop from a filled-in contact page, and from
                // the older 'demo_call' rows that predate this rename. The
                // ops source filter is built from the column, so it picks
                // this up on its own.
                'source'     => 'reach_out',
                // The Message column would otherwise read "—": there is no
                // message to leave, so say what the row actually is.
                'subject'    => 'Reach-out request from the website',
                'status'     => 'new',
                'ip'         => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'referrer'   => substr((string) $request->headers->get('referer'), 0, 255),
                // Joins this lead to its visitor row, so the operator can see
                // which pages they read before leaving a number.
                'visitor_key' => \App\Support\VisitorIdentity::key($request),
            ]);
        } catch (\Throwable $e) {
            Log::warning('demo-call: failed to persist contact lead', ['err' => $e->getMessage()]);
        }
    }
}
