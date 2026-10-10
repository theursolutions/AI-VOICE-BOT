<?php

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates and removes SIP accounts on our Asterisk, live, through the
 * voice engine (it holds the ARI connection — voice-engine/app/api/routes/sip.py).
 *
 * The voice engine only accepts the contexts "internal-calls" and
 * "web-guest", neither of which can dial an outside line.
 */
class SipAccounts
{
    public const CONTEXT_INTERNAL = 'internal-calls';
    public const CONTEXT_WEB      = 'web-guest';

    public function put(string $name, string $password, string $kind, string $context, string $callerId = ''): void
    {
        $resp = $this->http()->put($this->url('/internal/sip/accounts/' . $name), [
            'password' => $password,
            'kind'     => $kind,
            'context'  => $context,
            'callerid' => $callerId,
        ]);

        if (! $resp->successful()) {
            throw new RuntimeException($this->reason($resp));
        }
    }

    public function delete(string $name): void
    {
        $resp = $this->http()->delete($this->url('/internal/sip/accounts/' . $name));
        if (! $resp->successful()) {
            throw new RuntimeException($this->reason($resp));
        }
    }

    /**
     * {name: "online"|"offline"} for every account starting with $prefix;
     * null when Asterisk can't be asked (the page then shows "unknown").
     */
    public function states(string $prefix): ?array
    {
        try {
            $resp = $this->http()->get($this->url('/internal/sip/accounts'), ['prefix' => $prefix]);
        } catch (\Throwable $e) {
            return null;
        }

        return $resp->successful() ? (array) $resp->json('accounts', []) : null;
    }

    /** A password a person never has to type twice: 20 letters and digits. */
    public static function password(): string
    {
        return \Illuminate\Support\Str::password(20, true, true, false, false);
    }

    private function http()
    {
        return Http::timeout(8)
            ->acceptJson()
            ->withHeaders(['X-Internal-Secret' => (string) config('services.python.internal_secret')]);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.python.base_url'), '/') . $path;
    }

    private function reason($resp): string
    {
        $detail = $resp->json('detail');
        if ($resp->status() === 503) {
            return 'The phone server is not connected to the voice engine (set ASTERISK_ARI_PASSWORD in voice-engine/.env).';
        }

        return 'The phone server refused it: ' . (is_string($detail) ? $detail : 'HTTP ' . $resp->status());
    }
}
