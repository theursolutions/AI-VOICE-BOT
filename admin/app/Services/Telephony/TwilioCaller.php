<?php

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Log;

/**
 * Places outbound Twilio calls over the REST API.
 *
 * Deliberately raw cURL rather than the Twilio SDK: the rest of this app
 * already talks to Twilio that way (see TelephonyController::twilioApiGet)
 * and pulling in the SDK for one POST isn't worth the dependency.
 */
class TwilioCaller
{
    /**
     * Create a call. Twilio rings `$to`, and when the visitor picks up it
     * fetches TwiML from `$answerUrl` — the same voice webhook an inbound
     * caller hits, so the demo runs the real agent.
     *
     * @param  array{time_limit?:int,ring_timeout?:int,status_callback?:string}  $opts
     * @return array{ok:bool,sid:?string,status:?string,http:int,error:?string,twilio_code:?int}
     */
    public function call(string $to, string $from, string $answerUrl, array $opts = []): array
    {
        $sid   = (string) config('services.twilio.account_sid');
        $token = (string) config('services.twilio.auth_token');

        if ($sid === '' || $token === '') {
            return $this->fail(0, 'Twilio credentials are not configured.', null);
        }

        $form = [
            'To'     => $to,
            'From'   => $from,
            'Url'    => $answerUrl,
            'Method' => 'POST',
        ];

        // The duration cap is enforced by Twilio, not by us: once TimeLimit
        // elapses the carrier tears the call down even if our webhook, the
        // voice engine, or this process has gone away.
        if (! empty($opts['time_limit'])) {
            $form['TimeLimit'] = (int) $opts['time_limit'];
        }
        if (! empty($opts['ring_timeout'])) {
            $form['Timeout'] = (int) $opts['ring_timeout'];
        }
        if (! empty($opts['status_callback'])) {
            $form['StatusCallback']       = $opts['status_callback'];
            $form['StatusCallbackMethod'] = 'POST';
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Calls.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_USERPWD        => $sid . ':' . $token,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            Log::warning('demo-call: Twilio request failed', ['err' => $err]);
            return $this->fail(0, 'Could not reach Twilio.', null);
        }

        $body = $raw ? (json_decode($raw, true) ?: []) : [];

        if ($code < 200 || $code >= 300) {
            $twilioCode = isset($body['code']) ? (int) $body['code'] : null;
            Log::warning('demo-call: Twilio rejected the call', [
                'http' => $code, 'code' => $twilioCode, 'message' => $body['message'] ?? null,
            ]);

            return $this->fail($code, (string) ($body['message'] ?? 'Twilio rejected the call.'), $twilioCode);
        }

        return [
            'ok'          => true,
            'sid'         => $body['sid'] ?? null,
            'status'      => $body['status'] ?? null,
            'http'        => $code,
            'error'       => null,
            'twilio_code' => null,
        ];
    }

    /**
     * Turn a Twilio error code into something a website visitor can act on.
     * Returns null when the failure isn't one we have specific words for.
     *
     * @see https://www.twilio.com/docs/api/errors
     */
    public static function visitorMessage(?int $twilioCode): ?string
    {
        return match ($twilioCode) {
            // Trial accounts may only dial numbers verified in the console.
            21219, 21210 => 'Our demo line is still on a trial plan and can only ring verified numbers. Leave your number and we’ll call you back instead.',
            // Malformed / unreachable destination.
            21211, 21214, 13224 => 'That number doesn’t look reachable. Check the country code and try again.',
            // Geo permissions not enabled for that country.
            21215 => 'We can’t dial that country from our demo line yet. Leave your number and we’ll call you back.',
            // Out of credit.
            20003 => 'Our demo line is out of credit right now. Leave your number and we’ll call you back.',
            default => null,
        };
    }

    /** @return array{ok:bool,sid:?string,status:?string,http:int,error:?string,twilio_code:?int} */
    private function fail(int $http, string $error, ?int $twilioCode): array
    {
        return [
            'ok'          => false,
            'sid'         => null,
            'status'      => null,
            'http'        => $http,
            'error'       => $error,
            'twilio_code' => $twilioCode,
        ];
    }
}
