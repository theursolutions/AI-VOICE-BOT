<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Google / Facebook sign-in. The provider has already confirmed the user's
 * email, so a successful social login marks the local account verified and
 * skips the OTP step.
 *
 * Requires provider credentials in config/services.php (.env). Without them
 * the buttons bounce back to /login with a friendly notice.
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'facebook'];

    public function redirect(string $provider): SymfonyRedirect|RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        if (!$this->configured($provider)) {
            return redirect()->route('login')->withErrors([
                'email' => ucfirst($provider) . ' sign-in isn\'t configured yet.',
            ]);
        }

        return Socialite::driver($provider)
            ->redirectUrl(route('social.callback', ['provider' => $provider]))
            ->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        if (!$this->configured($provider)) {
            return redirect()->route('login')->withErrors([
                'email' => ucfirst($provider) . ' sign-in isn\'t configured yet.',
            ]);
        }

        try {
            $social = Socialite::driver($provider)
                ->redirectUrl(route('social.callback', ['provider' => $provider]))
                ->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')->withErrors([
                'email' => 'Could not sign in with ' . ucfirst($provider) . '. Please try again.',
            ]);
        }

        $email = $social->getEmail();
        if (!$email) {
            return redirect()->route('login')->withErrors([
                'email' => ucfirst($provider) . ' did not share an email address with us.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            // First-time social user — create a verified account with a
            // random password (they can set one later via "forgot password").
            $user = User::create([
                'name'     => $social->getName() ?: Str::before($email, '@'),
                'email'    => $email,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        if ($user->is_disabled || $user->trashed()) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account is not active. Please contact your administrator.',
            ]);
        }

        // The provider vouches for the email → mark verified, skip OTP.
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, true);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    private function configured(string $provider): bool
    {
        return (bool) config("services.$provider.client_id")
            && (bool) config("services.$provider.client_secret");
    }
}
