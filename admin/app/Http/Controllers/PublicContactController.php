<?php

namespace App\Http\Controllers;

use App\Models\ContactLead;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public /contact form.
 *
 * Distinct from PublicLandingController::demoCall, which captures a bare
 * phone number from the homepage call-bar: this endpoint takes the whole
 * message (name, email, phone, what they want) and files it as a
 * `contact_form` lead. Both land in `contact_leads` and surface in the
 * super-admin ops console at /admin/contacts.
 */
class PublicContactController extends Controller
{
    /**
     * POST /api/contact
     *
     * Body: { name?, email?, phone?, subject?, message?, company_website? }
     * Returns: { ok: bool, message: string }
     */
    public function store(Request $request): JsonResponse
    {
        // Honeypot: a field hidden from humans by CSS. Anything that fills it
        // is a bot, so return the normal success shape (never tell a scraper
        // it was caught) and drop the submission on the floor.
        if (trim((string) $request->input('company_website', '')) !== '') {
            return response()->json([
                'ok'      => true,
                'message' => 'Thanks — we’ll be in touch shortly.',
            ]);
        }

        // Throttle by IP. Looser than the call-bar's 3/min because writing a
        // real message takes a moment, but tight enough to stop a spam run.
        $ip  = (string) $request->ip();
        $key = 'contact-form:' . $ip;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $mins = (int) ceil(RateLimiter::availableIn($key) / 60);
            return response()->json([
                'ok'      => false,
                'message' => "You’ve sent a few already — try again in {$mins} minute(s).",
            ], 429);
        }
        RateLimiter::hit($key, 600);

        $data = $request->validate([
            // At least one way to reach them back, or the lead is useless.
            'phone'   => ['nullable', 'string', 'min:7', 'max:32', 'required_without:email'],
            'email'   => ['nullable', 'email:filter', 'max:190', 'required_without:phone'],
            'name'    => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:4000'],
        ], [
            'phone.required_without' => 'Give us a phone number or an email address.',
            'email.required_without' => 'Give us an email address or a phone number.',
        ]);

        try {
            ContactLead::create([
                'name'       => $data['name']    ?? null,
                'email'      => $data['email']   ?? null,
                'phone'      => Phone::e164ish($data['phone'] ?? null),
                'subject'    => $data['subject'] ?? null,
                'message'    => $data['message'] ?? null,
                'source'     => 'contact_form',
                'status'     => 'new',
                'ip'         => $ip,
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'referrer'   => substr((string) $request->headers->get('referer'), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // Never show a visitor a stack trace over a contact form. Log it
            // loudly instead — a dropped lead is a real incident.
            Log::error('contact-form: failed to persist contact lead', [
                'err' => $e->getMessage(),
                'ip'  => $ip,
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Something broke on our side. Please email us directly and we’ll pick it up.',
            ], 500);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'Got it — we’ll get back to you within one business day.',
        ]);
    }
}
