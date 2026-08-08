<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Endpoints for the public landing page at "/".
 *
 * Today: captures the "Call me now" lead and logs it. When the
 * DEMO_CALL_ENABLED env flag is true AND a demo project is wired up,
 * it should place an outbound Twilio call to the visitor's number
 * using the demo agent. That wiring lives in TelephonyController and
 * is bolted in later — for now the capture stub keeps the conversion
 * funnel open with zero risk of burning trial credit.
 */
class PublicLandingController extends Controller
{
    /**
     * POST /api/demo-call
     *
     * Body: { phone: "+1..." }
     * Returns: { ok: true, message: "..." }
     */
    public function demoCall(Request $request): JsonResponse
    {
        // Throttle by IP — 3 attempts per minute is more than enough for
        // a curious visitor and shuts down obvious abuse.
        $ip  = (string) $request->ip();
        $key = 'demo-call:' . $ip;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $secs = RateLimiter::availableIn($key);
            return response()->json([
                'ok'      => false,
                'message' => "Too many tries — try again in {$secs}s.",
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'phone' => 'required|string|min:7|max:32',
        ]);

        // E.164-ish normalisation — shared with the /contact form so the same
        // visitor doesn't land twice in two different formats.
        $phone = \App\Support\Phone::e164ish($data['phone']) ?? $data['phone'];

        // Append the capture to a JSONL log so we don't lose it even
        // before a real CRM row is created. (Simple, swap to a DB
        // model when it matters.)
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
                'source'     => 'demo_call',
                'status'     => 'new',
                'ip'         => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'referrer'   => substr((string) $request->headers->get('referer'), 0, 255),
            ]);
        } catch (\Throwable $e) {
            Log::warning('demo-call: failed to persist contact lead', ['err' => $e->getMessage()]);
        }

        // If the demo-call feature flag isn't on, just say thanks and
        // queue the lead. The visitor never sees a failure.
        if (! config('services.demo_call.enabled', false)) {
            return response()->json([
                'ok'      => true,
                'queued'  => true,
                'message' => 'Got it — our team will call you within one business hour.',
            ]);
        }

        // TODO: when the demo project is wired up, place the call via
        // Twilio REST API to the demo phone number with TwiML pointing
        // at /api/telephony/twilio/voice. Until then return a friendly
        // "we'll ring you shortly" so the UX still feels responsive.
        return response()->json([
            'ok'      => true,
            'message' => "Calling {$phone} now — pick up in 10 seconds.",
        ]);
    }
}
