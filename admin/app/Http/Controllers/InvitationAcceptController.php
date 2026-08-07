<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public-facing controller for accepting workspace invitations.
 * Routes are intentionally NOT auth-gated — the invited colleague may
 * not yet have an account.
 */
class InvitationAcceptController extends Controller
{
    /**
     * Show the accept page. Branches on auth state:
     *  - authed  → confirm screen with a single button
     *  - guest   → combined sign-up / log-in form
     */
    public function show(string $token): View|RedirectResponse
    {
        $invitation = $this->resolveInvitation($token);
        $client     = Client::findOrFail($invitation->client_id);

        if (Auth::check()) {
            // If already a member, just bounce to the dashboard with that workspace active.
            if (Auth::user()->hasMembership($invitation->client_id)) {
                Auth::user()->forceFill([
                    'active_client_id' => $invitation->client_id,
                    'last_picked_at'   => time(),
                ])->save();

                return redirect('/dashboard')
                    ->with('success', 'You are already a member of '.$client->name.'.');
            }

            return view('invitations.accept-authenticated', compact('invitation', 'client'));
        }

        return view('invitations.accept-public', compact('invitation', 'client'));
    }

    /**
     * Accept the invitation. Two branches:
     *  - authed → attach membership, mark accepted.
     *  - guest  → mirror RegisterController logic (existing email + correct
     *             password silently logs in; new email creates an account),
     *             then attach + mark accepted.
     */
    public function confirm(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolveInvitation($token);
        $client     = Client::findOrFail($invitation->client_id);

        if (Auth::check()) {
            $user = Auth::user();
            DB::beginTransaction();
            try {
                $user->attachMembership($invitation->client_id, null, $invitation->invited_by, $invitation->role_id);
                $this->markAccepted($invitation, $user);
                $user->forceFill([
                    'active_client_id' => $invitation->client_id,
                    'last_picked_at'   => time(),
                ])->save();
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            return redirect('/dashboard')
                ->with('success', 'Welcome to '.$client->name.'.');
        }

        // Guest branch — mirror RegisterController's pattern.
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Email is locked to the invitation — never trust whatever the form posts.
        $email = $invitation->email;

        $user = null;

        DB::beginTransaction();
        try {
            $existing = User::where('email', $email)->first();

            if ($existing) {
                if (!Hash::check($data['password'], $existing->password)) {
                    throw ValidationException::withMessages([
                        'password' => __('An account with this email already exists. Enter your existing password to join this workspace.'),
                    ]);
                }
                $user = $existing;
            } else {
                $user = User::create([
                    'name'     => $data['name'],
                    'email'    => $email,
                    'password' => Hash::make($data['password']),
                ]);
            }

            $user->attachMembership($invitation->client_id, null, $invitation->invited_by);
            $this->markAccepted($invitation, $user);

            $user->forceFill([
                'active_client_id' => $invitation->client_id,
                'last_picked_at'   => time(),
            ])->save();

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        Auth::login($user);

        return redirect('/dashboard')
            ->with('success', 'Welcome to '.$client->name.'.');
    }

    /**
     * Look up an invitation by token; abort if missing or unacceptable.
     */
    private function resolveInvitation(string $token): Invitation
    {
        $invitation = Invitation::where('token', $token)->first();
        if (!$invitation || !$invitation->isAcceptable()) {
            abort(404, 'This invitation is no longer valid.');
        }
        return $invitation;
    }

    private function markAccepted(Invitation $invitation, User $user): void
    {
        $invitation->forceFill([
            'accepted_at'         => time(),
            'accepted_by_user_id' => $user->id,
            'update_at'           => time(),
            'updated_by'          => $user->id,
        ])->save();
    }
}
