<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Twilio's X-Twilio-Signature HMAC on incoming webhook
 * requests. Twilio docs:
 *   https://www.twilio.com/docs/usage/webhooks/webhooks-security
 *
 * Algorithm:
 *   1. Take the full URL Twilio called (incl. scheme, host, path,
 *      query string). For us, this MUST be the public webhook_base
 *      URL — NOT the local Apache URL Laravel sees, because ngrok
 *      rewrites the request.
 *   2. Sort POST params by key, concatenate as key + value.
 *   3. Append to the URL.
 *   4. HMAC-SHA1 with the Auth Token, base64-encode.
 *   5. Compare to the X-Twilio-Signature header.
 *
 * Dev-mode bypass: when TWILIO_AUTH_TOKEN is unset OR the header is
 * absent (local curl tests), the request passes through. In prod
 * Twilio always signs.
 */
class TwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.twilio.auth_token');
        if ($token === '') {
            // Not configured — let it through (dev/single-tenant).
            return $next($request);
        }

        $sig = $request->header('X-Twilio-Signature');
        if (!$sig) {
            // No signature header → not from Twilio. Could be a local
            // curl test; allow if not in production.
            if (app()->environment('production')) {
                return response('Missing Twilio signature', 403);
            }
            return $next($request);
        }

        // Twilio signs with the public URL it called — that's what
        // we configured as TWILIO_WEBHOOK_BASE + the request path. The
        // url() helper would give us Laravel's local view of the URL
        // (different host because ngrok / proxy), which would never
        // match. So we construct from config + path explicitly.
        $base = rtrim((string) config('services.twilio.webhook_base', ''), '/');
        if ($base === '') {
            // No webhook_base set → can't validate; fail closed in prod.
            if (app()->environment('production')) {
                return response('TWILIO_WEBHOOK_BASE not set', 500);
            }
            return $next($request);
        }
        $url = $base . $request->getPathInfo();
        if ($qs = $request->getQueryString()) {
            $url .= '?' . $qs;
        }

        // For application/x-www-form-urlencoded POSTs Twilio uses,
        // signature data is the URL + sorted (key . value) of all
        // POST params.
        $params = $request->post();
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k . $v;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));
        if (!hash_equals($expected, $sig)) {
            return response('Invalid Twilio signature', 403);
        }

        return $next($request);
    }
}
