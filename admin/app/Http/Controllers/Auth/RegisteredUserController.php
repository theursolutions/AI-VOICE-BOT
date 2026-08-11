<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Rules\Turnstile as TurnstileRule;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signup. Creates the user AND their first workspace (Client) in one step.
 *
 * This logic came from the laravel/ui RegisterController, which used to win
 * the POST /register route because Auth::routes() was registered after
 * routes/auth.php and Laravel's route lookup is keyed by method+URI (last
 * registration wins). That duplicate scaffolding is gone; this class is now
 * the only thing serving /register, so the workspace-creation behaviour lives
 * here, unchanged, plus the Turnstile check the old controller never ran.
 */
class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            // Deliberately NOT `lowercase`: that rule rejects "User@Example.com"
            // rather than normalising it, and the previous controller accepted
            // mixed case. Adding it here would turn working signups into 422s.
            'email'        => ['required', 'string', 'email', 'max:255'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
            // Terms + privacy acceptance. The checkbox on the form previously
            // had no `name` and no rule here, so it was decoration: the page
            // asked for agreement and the server neither received nor checked
            // one. `required` in the markup alone is trivially bypassed.
            'terms'        => ['accepted'],
            // CAPTCHA. Passes automatically when Turnstile isn't configured.
            'cf-turnstile-response' => TurnstileRule::rules(),
        ], [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to continue.',
        ]);

        $isNewUser = false;

        DB::beginTransaction();
        try {
            $existing = User::where('email', $data['email'])->first();

            if ($existing) {
                // A repeat email means "same person adding another workspace",
                // not a collision — but they have to prove it's them.
                if (! Hash::check($data['password'], $existing->password)) {
                    throw ValidationException::withMessages([
                        'email' => __('An account with this email already exists. Please log in to add a workspace.'),
                    ]);
                }
                $user = $existing;
            } else {
                $user = User::create([
                    'name'     => $data['name'],
                    'email'    => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);
                $isNewUser = true;
            }

            $client = Client::create([
                'name'           => $data['company_name'],
                'slug'           => $this->uniqueSlug($data['company_name']),
                'client_api_key' => bin2hex(random_bytes(16)),
                'description'    => null,
                'is_active'      => 'Yes',
                'created_at'     => time(),
                'updated_at'     => time(),
            ]);

            // The signup user is the agency Owner: an all-access role they
            // can later use to invite teammates with narrower custom roles.
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

            // Start the 7-day, no-card free window. Deliberately inside the
            // transaction: a workspace with no subscription row would fail OPEN
            // in EnsureSubscribed (that path exists for pre-billing accounts),
            // so a half-created signup must not become permanently free.
            //
            // startFreeWindow() is idempotent and checks the trial-abuse
            // fingerprints itself — a repeat email or a reused card gets the
            // plan with no free window rather than a second free week.
            //
            // No Stripe customer is created here: a free workspace never
            // touches Stripe until it buys something.
            app(\App\Services\Billing\SubscriptionService::class)
                ->startFreeWindow($client, $user);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        Auth::login($user);

        // Kick off email verification via the 6-digit OTP. `Registered` is
        // wired to SendEmailVerificationNotification in EventServiceProvider,
        // so firing it IS the send for a new account — calling the notifier as
        // well would mail two different codes. An existing-but-unverified user
        // (adding a second workspace) isn't "registered" again, so they get the
        // direct call instead. Either way: exactly one OTP, and none at all for
        // someone already verified.
        if ($isNewUser) {
            event(new Registered($user));
        } elseif (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        // Resume a plan choice made on /pricing before signing up. Without
        // this the visitor picks Growth, registers, and lands on a dashboard
        // with no memory of what they came to buy.
        if ($intent = $request->session()->pull('billing.intent')) {
            return redirect()
                ->route('billing.index', ['client' => $client->slug])
                ->with('billing_intent', $intent)
                ->with('info', 'Verify your email whenever you like — first, finish setting up your '
                    . ucfirst((string) ($intent['plan'] ?? 'plan')) . ' plan.');
        }

        return redirect(route('dashboard', ['client' => $client->slug]));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;
        while (Client::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
