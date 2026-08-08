<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side verification of a Cloudflare Turnstile token.
 *
 * The widget in the browser proves nothing on its own — a bot can simply POST
 * the form without ever loading the script. Protection comes entirely from
 * checking the token against Cloudflare here, which is what this rule does.
 *
 * Usage — always via the helper, never a hand-written ['required', new Turnstile()]:
 *     'cf-turnstile-response' => Turnstile::rules(),
 *
 * When the keys are not configured the rule PASSES silently, so local
 * development and any deployment that hasn't set them keeps working. That is a
 * deliberate trade: a missing key must not lock people out of their own login
 * page. Verify it's actually on in production with:
 *     php artisan tinker --execute="var_dump(App\Rules\Turnstile::enabled());"
 *
 * The call sites used to spell this as ['required', new Turnstile()], which
 * quietly broke that promise: with no keys set the partial renders no widget,
 * so no cf-turnstile-response is posted, and the bare `required` rejected the
 * form before this rule ever got to skip. Login was impossible on any
 * unconfigured environment. rules() ties presence to configuration so the
 * widget and the validation can't disagree.
 */
class Turnstile implements ValidationRule
{
    /**
     * Turnstile is only "on" when BOTH keys are present: the site key is what
     * makes the widget render (and therefore produce a token), the secret key
     * is what lets us verify it. With only one, we'd either demand a token
     * nothing produces or show a challenge nothing checks.
     */
    public static function enabled(): bool
    {
        return (string) config('services.turnstile.site_key', '') !== ''
            && (string) config('services.turnstile.secret_key', '') !== '';
    }

    /** Validation rules for the cf-turnstile-response field. */
    public static function rules(): array
    {
        return [self::enabled() ? 'required' : 'nullable', new self()];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not configured → skip. See the class docblock for why.
        if (! self::enabled()) {
            return;
        }

        $secret = (string) config('services.turnstile.secret_key', '');

        if (!is_string($value) || trim($value) === '') {
            $fail('Please complete the security check.');

            return;
        }

        try {
            $res = Http::asForm()
                // Short timeout: a slow CAPTCHA service must not hang a login
                // page. On timeout we fail CLOSED (see catch) because letting
                // requests through unverified defeats the point.
                ->timeout(8)
                ->connectTimeout(4)
                ->retry(2, 200)
                ->post((string) config('services.turnstile.verify_url'), [
                    'secret'   => $secret,
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification unreachable: ' . $e->getMessage());
            $fail('Could not complete the security check. Please try again.');

            return;
        }

        $body = $res->json();

        if (!$res->successful() || !($body['success'] ?? false)) {
            // error-codes is an array like ["timeout-or-duplicate"]. Logged (not
            // shown) so a genuine misconfiguration is diagnosable without
            // leaking detail to whoever is probing the form.
            Log::warning('Turnstile rejected a submission', [
                'errors' => $body['error-codes'] ?? null,
                'ip'     => request()->ip(),
                'path'   => request()->path(),
            ]);

            $fail('The security check failed. Please try again.');
        }
    }
}
