<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WorkspacePickerController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user    = Auth::user();
        $clients = $user->clients()->orderBy('clients.name')->get();

        if ($clients->count() === 1) {
            return $this->switchTo($clients->first()->id);
        }

        return view('workspace.pick', compact('clients'));
    }

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => 'required|integer',
        ]);

        return $this->switchTo((int) $data['client_id']);
    }

    private function switchTo(int $clientId): RedirectResponse
    {
        $user = Auth::user();

        if (!$user->hasMembership($clientId)) {
            return redirect()->route('workspace.pick')
                ->withErrors(['client_id' => 'You are not a member of that workspace.']);
        }

        $user->forceFill([
            'active_client_id' => $clientId,
            'last_picked_at'   => time(),
        ])->save();

        $client = Client::find($clientId);

        return redirect()->intended(route('dashboard', ['client' => $client->slug]));
    }
}
