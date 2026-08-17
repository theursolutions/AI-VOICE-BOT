<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

        // stateless() + our own signed `state`, NOT Socialite's session state.
        //
        // Socialite's default puts a random string in the session and compares it
        // on the way back. That makes sign-in depend on the session cookie
        // surviving a round trip through the provider, and when it does not, the
        // failure is InvalidStateException with an EMPTY message — which reads as
        // a provider fault and is in fact ours. Email/password login can be
        // working perfectly at the same time, because it never leaves the site.
        //
        // The `state` parameter exists to stop an attacker replaying someone
        // else's callback, so it cannot simply be dropped. Instead we mint it
        // ourselves: encrypted (so it cannot be forged without APP_KEY) and
        // timestamped (so a captured callback URL is not replayable tomorrow).
        // ChannelOnboardController has carried its context this way through the
        // same proxy and replica setup since it was written.
        return Socialite::driver($provider)
            ->stateless()
            ->redirectUrl(route('social.callback', ['provider' => $provider]))
            ->with(['state' => $this->encodeState($provider)])
            ->redirect();
    }

    /** How long a minted state stays usable. */
    private const STATE_TTL = 900;

    /**
     * Signed, short-lived CSRF token for the OAuth round trip.
     *
     * `provider.timestamp.nonce.hmac`, and deliberately NOT encrypted.
     *
     * State needs integrity, not secrecy: it carries nothing private, and its
     * only job is to prove this callback answers a request we made. An HMAC
     * gives that, and it buys something encryption cannot — every character is
     * `[A-Za-z0-9.]`, so the token is URL-safe by construction.
     *
     * That matters because Crypt::encryptString returns base64, which contains
     * `+`, `/` and `=`. A `+` that survives as a literal and is later decoded as
     * a space corrupts the token, and only for the fraction of attempts whose
     * random output happens to contain one — an intermittent "sign-in link
     * expired" that reproduces for one account and not the next, with the round
     * trip passing through two systems we do not control. Not a hazard worth
     * keeping for a value that never needed encrypting.
     */
    private function encodeState(string $provider): string
    {
        $payload = $provider . '.' . time() . '.' . Str::random(16);

        return $payload . '.' . hash_hmac('sha256', $payload, $this->stateKey());
    }

    /**
     * Verify the state we minted came back intact, unexpired, and for THIS
     * provider — the last check stops a callback for one provider being replayed
     * against the other.
     */
    private function stateValid(string $raw, string $provider): bool
    {
        $parts = explode('.', $raw);

        if (count($parts) !== 4) {
            return false;
        }

        [$who, $ts, $nonce, $mac] = $parts;

        // Recompute over the received payload and compare in constant time, so
        // a near-miss cannot be walked to a valid signature byte by byte.
        $expected = hash_hmac('sha256', $who . '.' . $ts . '.' . $nonce, $this->stateKey());

        if (! hash_equals($expected, $mac)) {
            return false;
        }

        return $who === $provider
            && ctype_digit($ts)
            && (time() - (int) $ts) <= self::STATE_TTL;
    }

    /**
     * Keyed on APP_KEY, so a state cannot be forged without it and every
     * replica derives the same key from the same environment.
     */
    private function stateKey(): string
    {
        return (string) config('app.key');
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        if (!$this->configured($provider)) {
            return redirect()->route('login')->withErrors([
                'email' => ucfirst($provider) . ' sign-in isn\'t configured yet.',
            ]);
        }

        // The provider refused BEFORE issuing a code — the user declined, or the
        // app asked for something it may not have (a Business-type app rejects
        // `email`, which Socialite's Facebook driver requests by default).
        //
        // Handled before ->user() because Socialite has no code to work with and
        // throws a generic exception, which threw away the one useful artefact in
        // the whole exchange: the provider's own error_description. That is why
        // this failure was indistinguishable from a bad secret or a lost session.
        if ($request->query('error') || ! $request->query('code')) {
            $reason = (string) ($request->query('error_description')
                ?: $request->query('error')
                ?: 'no authorization code');

            Log::warning('Social sign-in refused by provider', [
                'provider' => $provider,
                'error'    => $request->query('error'),
                'reason'   => $reason,
            ]);

            // `access_denied` is the user changing their mind — not a fault, and
            // it should not read like one.
            $message = $request->query('error') === 'access_denied'
                ? 'Sign-in with ' . ucfirst($provider) . ' was cancelled.'
                : 'Could not sign in with ' . ucfirst($provider) . ': ' . $reason;

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        // Our own CSRF check, replacing the session-backed one Socialite does.
        if (! $this->stateValid((string) $request->query('state'), $provider)) {
            Log::warning('Social sign-in state rejected', [
                'provider' => $provider,
                'reason'   => 'state missing, expired (>15 min), forged, or for another provider',
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'That sign-in link expired. Please try again.',
            ]);
        }

        // The callback runs TWICE for one sign-in. HAProxy runs `retries 3` with
        // `option redispatch`, so a connection hiccup resends the same GET to the
        // other app replica; a browser prefetch or a double-click does the same.
        //
        // Do NOT try to pick a winner up front. An earlier version claimed the
        // code in the cache before exchanging it, and that made things worse:
        // HAProxy abandons the first request, PHP-FPM kills the script on client
        // disconnect, and the claim outlived the request that made it. The code
        // was then poisoned for ten minutes and the retry — the one request still
        // attached to a live browser — was refused before it could do anything.
        // That is why sign-in went quiet: no error, no login, and a log saying
        // the winner never published.
        //
        // The provider is already the single-use gate, and it is the only one that
        // cannot be orphaned. So both requests attempt the exchange; at most one
        // can succeed, and the other is told so explicitly. This key is used only
        // to hand the result from whichever won to whichever did not.
        $claim = 'social:code:' . hash('sha256', $provider . '|' . $request->query('code'));

        try {
            $social = Socialite::driver($provider)
                ->stateless()
                ->redirectUrl(route('social.callback', ['provider' => $provider]))
                ->user();
        } catch (\Throwable $e) {
            // The visitor gets a plain sentence; the REASON goes to the log.
            // Without this the only signal is a generic notice on the login
            // page, which is indistinguishable from a wrong password and
            // leaves nothing to diagnose — a mismatched redirect URI, a bad
            // secret and a revoked token all look identical.
            //
            // `hint` names the fix per failure class, because the exception text
            // alone does not imply one. InvalidStateException in particular reads
            // like a provider fault when it is ours: the state Socialite stashed
            // in the session was not there on the way back, which is a session
            // problem (lost cookie, SameSite, a session store the replicas do not
            // share) and has nothing to do with the provider at all.
            // The provider's response BODY, which is the only part that names
            // the fault. Guzzle puts it in the exception message after a
            // newline, where `docker logs | grep` cuts it off — so the one field
            // worth having was the one nobody could read. Pulled out and logged
            // as its own single-line value.
            $body = '';
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
                $body = trim((string) $e->getResponse()->getBody());
            }

            // Match on the body first: `error: invalid_grant` lives there, not in
            // Guzzle's "resulted in a 400 Bad Request" summary line.
            $haystack = $e->getMessage() . ' ' . $body;

            $hint = match (true) {
                $e instanceof \Laravel\Socialite\Two\InvalidStateException =>
                    'session lost between redirect and callback — check SESSION_DRIVER is shared across replicas, SESSION_SAME_SITE=lax, and SESSION_SECURE_COOKIE matches the scheme',
                str_contains($haystack, 'redirect_uri_mismatch') || str_contains($haystack, 'redirect_uri') =>
                    'the redirect_uri sent at token exchange does not match the one registered with the provider — compare `php artisan auth:doctor` output against the provider dashboard',
                str_contains($haystack, 'invalid_grant') =>
                    'the authorization code was already used or has expired — a second request replayed the callback, or the code sat unused for minutes',
                str_contains($haystack, 'invalid_client') || str_contains($haystack, 'client_secret') || str_contains($haystack, 'client_id') =>
                    'wrong client id/secret for this provider — run `php artisan auth:doctor`',
                default => null,
            };

            // A spent code that got past the claim above — the duplicate arrived
            // more than ten minutes later, or the cache was flushed between the
            // two. Still not the visitor's problem and still not an error: the
            // first request already did the work.
            $spent = str_contains($haystack, '36009')
                || str_contains($haystack, 'authorization code has been used')
                || str_contains($haystack, 'invalid_grant');

            if ($spent) {
                // The other request got there first. Adopt whoever it signed in:
                // both requests share one session cookie, so if this one finishes
                // without logging in, its save at end-of-request overwrites the
                // winner's authenticated session with a stale pre-login copy —
                // the login happens and is then erased.
                $userId = $this->awaitAuthenticatedUser($claim);

                if ($userId && ($user = User::find($userId))) {
                    Auth::login($user, true);

                    Log::info('Social sign-in: duplicate callback adopted the completed sign-in', [
                        'provider' => $provider,
                    ]);

                    return redirect()->intended(RouteServiceProvider::HOME);
                }

                // The code was spent by a request that then died before it could
                // authenticate anyone — so nobody is signed in and there is
                // nothing to adopt. Say so, because a silent redirect back to a
                // login page the visitor just used reads as the button not working.
                Log::warning('Social sign-in: code was redeemed but no user was authenticated', [
                    'provider' => $provider,
                    'note'     => 'the request that redeemed it did not finish — likely aborted by a proxy retry',
                ]);

                return redirect()->route('login')->withErrors([
                    'email' => 'Sign-in was interrupted. Please try once more.',
                ]);
            }

            Log::warning('Social sign-in failed', array_filter([
                'provider' => $provider,
                // First line only: the rest is Guzzle repeating the body we log
                // separately, and it is what pushed the useful part out of view.
                'error'    => strtok($e->getMessage(), "\n"),
                'response' => $body !== '' ? mb_substr($body, 0, 600) : null,
                'class'    => get_class($e),
                'hint'     => $hint,
            ]));

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

        // Everything from here to Auth::login is wrapped, because it runs AFTER
        // the authorization code has been spent and it is the only part that can
        // fail without leaving a trace.
        //
        // Previously only the token exchange was guarded, so a throw in account
        // creation, workspace provisioning or the subscription window became an
        // anonymous 500 with no `Social sign-in` prefix anywhere. Meanwhile the
        // duplicate request found the code spent with nobody authenticated and
        // reported an interrupted sign-in — attributing our own failure to a
        // proxy retry, and sending whoever was reading the log looking at the
        // network instead of at this method.
        try {
            return $this->signIn($social, $email, $provider, $claim);
        } catch (\Throwable $e) {
            Log::error('Social sign-in failed after the token exchange', [
                'provider' => $provider,
                'email'    => $email,
                'error'    => $e->getMessage(),
                'class'    => get_class($e),
                'at'       => $e->getFile() . ':' . $e->getLine(),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => 'Signed in with ' . ucfirst($provider)
                    . ', but we could not finish setting up your account. Please try again.',
            ]);
        }
    }

    /**
     * Find or build the local account, then authenticate it.
     *
     * Split out so the caller can wrap it: the authorization code is already
     * spent by the time this runs, so a failure here is not retryable by the
     * visitor without going back to the provider for a fresh one.
     */
    private function signIn(\Laravel\Socialite\Contracts\User $social, string $email, string $provider, string $claim): RedirectResponse
    {
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

        // canAuthenticate(), NOT is_disabled || trashed().
        //
        // trashed() does not exist on this model. User soft-deletes through
        // App\Models\Concerns\IntSoftDeletes, because `deleted_at` here is a unix
        // INTEGER rather than a datetime, and that trait exposes isSoftDeleted()
        // — there is no Laravel SoftDeletes to inherit trashed() from.
        //
        // So this line threw BadMethodCallException on EVERY social sign-in, for
        // every account, since the day it was written. And it threw at the worst
        // possible point: after the authorization code had been spent, so the
        // provider had done its part and the code could not be reused. The visitor
        // got a generic 500, the duplicate request found the code already redeemed
        // and blamed a proxy retry, and no log line ever mentioned sign-in.
        //
        // canAuthenticate() is the model's own answer to this question and is what
        // the rest of the app uses, so the two cannot drift apart again.
        if (! $user->canAuthenticate()) {
            Log::info('Social sign-in refused: account not active', [
                'provider' => $provider,
                'user'     => $user->id,
            ]);

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

        // Tell any duplicate request who this code authenticated, so it can log
        // the same user in rather than overwriting this session with its own
        // stale copy. Short-lived: it is only useful for the seconds during which
        // a retry can still be in flight.
        Cache::put($claim . ':user', $user->id, now()->addMinutes(2));

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Wait briefly for the request that won the code to publish the user it
     * authenticated.
     *
     * Polls rather than taking a lock because the thing worth waiting for is the
     * RESULT, not the critical section — the winner may finish its exchange and
     * still be provisioning a workspace. Capped low: this runs while someone is
     * staring at a redirect, and a duplicate that gives up merely lands on the
     * login page instead of the dashboard.
     */
    private function awaitAuthenticatedUser(string $claim, int $attempts = 30, int $sleepMicros = 100_000): ?int
    {
        for ($i = 0; $i < $attempts; $i++) {
            if ($id = Cache::get($claim . ':user')) {
                return (int) $id;
            }

            usleep($sleepMicros);
        }

        return null;
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
