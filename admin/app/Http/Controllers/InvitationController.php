<?php

namespace App\Http\Controllers;

use App\Mail\WorkspaceInvitation;
use App\Models\Client;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Manages invitations for the authenticated user's active workspace.
 * All actions are scoped to auth()->user()->active_client_id.
 */
class InvitationController extends Controller
{
    /**
     * List invitations (pending + accepted) for the active workspace.
     */
    public function index(): View
    {
        $clientId = (int) Auth::user()->active_client_id;

        $invitations = Invitation::with(['inviter', 'acceptedBy'])
            ->where('client_id', $clientId)
            ->orderByDesc('created_at')
            ->get();

        $client = Client::find($clientId);

        return view('invitations.index', compact('invitations', 'client'));
    }

    /**
     * Create + send a new invitation.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'name'  => ['nullable', 'string', 'max:255'],
        ]);

        $user     = Auth::user();
        $clientId = (int) $user->active_client_id;
        $client   = Client::findOrFail($clientId);

        $invitation = Invitation::create([
            'client_id'   => $clientId,
            'invited_by'  => $user->id,
            'email'       => $data['email'],
            'token'       => Invitation::mintToken(),
            'expires_at'  => time() + (7 * 24 * 60 * 60),
            'is_active'   => 'Yes',
            'created_at'  => time(),
            'update_at'   => time(),
            'created_by'  => $user->id,
            'updated_by'  => $user->id,
        ]);

        try {
            Mail::to($data['email'])->send(
                new WorkspaceInvitation($invitation, $client, $user, $data['name'] ?? null)
            );
        } catch (\Throwable $e) {
            // Don't block the UX on transient mail failures — invite still exists
            // and the link is available from the index view.
            report($e);
        }

        return redirect()
            ->route('invitations.index')
            ->with('success', 'Invitation sent to '.$data['email'].'.');
    }

    /**
     * Revoke a pending invitation. Anyone in the same workspace can revoke.
     * POST (not DELETE) to keep CSRF method-spoofing out of the form flow.
     */
    public function destroy(int $id): RedirectResponse
    {
        $user       = Auth::user();
        $clientId   = (int) $user->active_client_id;
        $invitation = Invitation::where('id', $id)
            ->where('client_id', $clientId)
            ->firstOrFail();

        // Inviter or any current workspace member may revoke.
        $isMember = $user->hasMembership($invitation->client_id);
        if ($invitation->invited_by !== $user->id && !$isMember) {
            abort(403);
        }

        if ($invitation->revoked_at === null && $invitation->accepted_at === null) {
            $invitation->forceFill([
                'revoked_at' => time(),
                'update_at'  => time(),
                'updated_by' => $user->id,
            ])->save();
        }

        return redirect()
            ->route('invitations.index')
            ->with('success', 'Invitation revoked.');
    }
}
