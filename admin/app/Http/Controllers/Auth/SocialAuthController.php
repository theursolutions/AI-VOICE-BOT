<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            // The visitor gets a plain sentence; the REASON goes to the log.
            // Without this the only signal is a generic notice on the login
            // page, which is indistinguishable from a wrong password and
            // leaves nothing to diagnose — a mismatched redirect URI, a bad
            // secret and a revoked token all look identical.
            Log::warning('Social sign-in failed', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
                'class'    => get_class($e),
            ]);

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

        // A social sign-up has to build the SAME things an email sign-up does.
        // Creating only the user left the account with no workspace, no role
        // and no subscription: EnsureActiveClient found zero memberships and
        // sent them to the workspace picker with nothing to pick, so signing
        // in with Google or Facebook produced an account that could not be
        // used at all.
        //
        // Guarded on membership, not on "is new", so an account
        // orphaned by the earlier version is repaired the next time its owner
        // signs in, instead of staying stuck.
        if ($user->clients()->count() === 0) {
            $this->provisionWorkspace($user, $social->getName() ?: Str::before($email, '@'));
        }

        // The provider vouches for the email → mark verified, skip OTP.
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, true);

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Build the workspace a signed-up account needs to be usable: a client,
     * the all-access Owner role, the membership joining them, and the free
     * window. Mirrors RegisteredUserController::store — the only difference
     * is that the name comes from the provider rather than a form, since a
     * social sign-in never asks for a company name.
     *
     * In a transaction for the reason that controller gives: a workspace with
     * no subscription row fails OPEN in EnsureSubscribed, so a half-created
     * signup would become permanently free.
     */
    private function provisionWorkspace(User $user, string $displayName): void
    {
        DB::transaction(function () use ($user, $displayName) {
            $name = trim($displayName) !== '' ? $displayName . "'s workspace" : 'My workspace';

            $client = Client::create([
                'name'           => $name,
                'slug'           => $this->uniqueSlug($name),
                'client_api_key' => bin2hex(random_bytes(16)),
                'description'    => null,
                'is_active'      => 'Yes',
                'created_at'     => time(),
                'updated_at'     => time(),
            ]);

            $ownerRole = Role::create([
                'client_id'  => $client->id,
                'name'       => 'Owner',
                'modules'    => ['*'],
                'is_owner'   => true,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            $user->attachMembership($client->id, null, $user->id, $ownerRole->id);

            $user->forceFill([
                'active_client_id' => $client->id,
                'last_picked_at'   => time(),
            ])->save();

            app(SubscriptionService::class)->startFreeWindow($client, $user);
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;
        while (Client::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function configured(string $provider): bool
    {
        return (bool) config("services.$provider.client_id")
            && (bool) config("services.$provider.client_secret");
    }
}
