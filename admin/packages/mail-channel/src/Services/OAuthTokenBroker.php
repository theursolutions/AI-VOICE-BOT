<?php

namespace Msd\MailChannel\Services;

use Msd\MailChannel\Models\EmailAccount;
use Msd\MailChannel\Services\OAuth\GoogleMailOAuthService;
use Msd\MailChannel\Services\OAuth\MicrosoftMailOAuthService;

/**
 * The single place ImapClient/SmtpMailer ask for a token that is good for
 * the next connection — refreshing it first when it's missing or close to
 * expiring.
 *
 * On-demand only, no separate scheduled sweep: unlike Meta's Instagram-Login
 * tokens (60-day lived, easy to let lapse unnoticed), an email account is
 * used constantly — every poll (every few minutes) and every send — so
 * there's always a moment to refresh before the token actually goes stale.
 */
class OAuthTokenBroker
{
    public function __construct(
        private GoogleMailOAuthService $google,
        private MicrosoftMailOAuthService $microsoft,
    ) {}

    public function freshAccessToken(EmailAccount $account): string
    {
        $expiring = !$account->oauth_expires_at || now()->addMinutes(5)->greaterThanOrEqualTo($account->oauth_expires_at);

        if ($expiring) {
            $service = $this->providerFor((string) $account->oauth_provider);
            $result  = $service->refresh((string) $account->oauth_refresh_token);

            $account->oauth_token = $result['access_token'];
            // Google usually returns no refresh_token on refresh (keep the
            // old one); Microsoft rotates it on every call (always persist
            // the new one) — either way, only overwrite when one comes back.
            if (!empty($result['refresh_token'])) {
                $account->oauth_refresh_token = $result['refresh_token'];
            }
            $account->oauth_expires_at = isset($result['expires_in']) ? now()->addSeconds($result['expires_in']) : null;
            $account->last_error = null;
            $account->save();
        }

        return (string) $account->oauth_token;
    }

    private function providerFor(string $provider): GoogleMailOAuthService|MicrosoftMailOAuthService
    {
        return match ($provider) {
            'google'    => $this->google,
            'microsoft' => $this->microsoft,
            default     => throw new \RuntimeException("Unknown mail OAuth provider: {$provider}"),
        };
    }
}
