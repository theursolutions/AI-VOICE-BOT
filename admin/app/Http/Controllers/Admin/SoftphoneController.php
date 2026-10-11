<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Models\SipExtension;
use App\Models\User;
use App\Models\UserPhoneAccount;
use App\Services\Telephony\SipServer;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The in-browser phone (resources/views/partials/softphone.blade.php +
 * public/assets/softphone). It registers with Asterisk over SIP-over-
 * WebSocket as the signed-in user's own extension, on every admin page.
 */
class SoftphoneController extends Controller
{
    public function __construct(private TenantManager $tenants, private SipServer $server) {}

    /**
     * GET /c/{client}/phone — the phone window. It owns the SIP sign-in and
     * the call itself, so refreshing or navigating admin pages (which only
     * mirror it) never drops a call.
     */
    public function window(Request $request, Client $client)
    {
        return view('softphone.window', ['client' => $client]);
    }

    /**
     * GET /me/phone — the signed-in user's SIP login. The password goes to
     * the browser because the browser is what registers; it is only ever
     * this user's own credential.
     */
    public function config(Request $request): JsonResponse
    {
        $account = UserPhoneAccount::where('user_id', $request->user()->id)->first();

        if (! $account) {
            return response()->json(['configured' => false, 'display_name' => $request->user()->name,
                                     'server_ws_url' => $this->server->wsUrl()]);
        }

        // A login saved by hand as "9122" (or with a wrong server address)
        // never registers: point it at the extension's real login and server.
        $ext = $this->workspaceExtension($request->user(), (string) $account->extension);
        if ($ext && $ext->kind === SipExtension::KIND_WEBRTC
            && ($account->extension !== $ext->sip_username || $account->ws_url !== $this->server->wsUrl())) {
            $account->extension = $ext->sip_username;
            $account->ws_url    = $this->server->wsUrl();
            $account->save();
        }

        return response()->json([
            'configured'    => true,
            'extension'     => $account->extension,
            'password'      => $account->sip_password,
            'ws_url'        => $account->ws_url,
            'display_name'  => $account->display_name ?: $request->user()->name,
            'server_ws_url' => $this->server->wsUrl(),
        ]);
    }

    /** POST /me/phone — save the login. An empty password keeps the saved one. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'extension'    => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_.+-]+$/'],
            'password'     => 'nullable|string|max:128',
            'ws_url'       => ['nullable', 'string', 'max:255', 'regex:#^wss?://#i'],
            'display_name' => 'nullable|string|max:120',
        ]);

        // "9122" typed for one of the workspace's own extensions: on our phone
        // server its login is "c<client>_9122", and it lives on that server only.
        if ($ext = $this->workspaceExtension($request->user(), $data['extension'])) {
            if ($ext->kind !== SipExtension::KIND_WEBRTC) {
                return response()->json(['message' => "Extension {$ext->extension} is set up for a phone or app. "
                    . 'Use a "Browser phone" extension here (Telephony → Extensions).'], 422);
            }
            $data['extension'] = $ext->sip_username;
            $data['ws_url']    = $this->server->wsUrl();
        }
        $data['ws_url'] = ($data['ws_url'] ?? '') !== '' ? $data['ws_url'] : $this->server->wsUrl();

        $account = UserPhoneAccount::firstOrNew(['user_id' => $request->user()->id]);
        if (! $account->exists && empty($data['password'])) {
            return response()->json(['message' => 'Enter the extension password.'], 422);
        }

        $account->extension    = $data['extension'];
        $account->ws_url       = $data['ws_url'];
        $account->display_name = $data['display_name'] ?? null;
        if (! empty($data['password'])) {
            $account->sip_password = $data['password'];
        }
        $account->save();

        return $this->config($request);
    }

    /**
     * The extension a user means by "9122" (or its full login "c1_9122") in
     * one of their workspaces, the active one first.
     */
    private function workspaceExtension(User $user, string $typed): ?SipExtension
    {
        $clientIds = $user->clients()->pluck('clients.id')->map(fn ($id) => (int) $id)->all();
        if ($clientIds === []) {
            return null;
        }

        return SipExtension::whereIn('client_id', $clientIds)
            ->where(fn ($q) => $q->where('extension', $typed)->orWhere('sip_username', $typed))
            ->orderByRaw('client_id = ? desc', [(int) $user->active_client_id])
            ->first();
    }

    /**
     * GET /c/{client}/softphone/lookup?number= — who is calling: the most
     * recent conversation with this number in the workspace's projects
     * (caller name, what they said, a link to the transcript). Calls the AI
     * transfers land here with the caller's own number, so the agent sees
     * what the caller already told the AI.
     */
    public function lookup(Request $request, Client $client): JsonResponse
    {
        $number = preg_replace('/[^0-9+*#]/', '', (string) $request->query('number', ''));
        if ($number === '') {
            return response()->json(['found' => false]);
        }

        foreach (Project::where('client_id', $client->id)->orderBy('id')->limit(25)->get() as $project) {
            try {
                $this->tenants->useFor($project);
                $session = Session::where('customer_phone', $number)->orderByDesc('id')->first();
            } catch (\Throwable $e) {
                continue; // a project whose tenant DB is missing
            }
            if (! $session) {
                continue;
            }

            $leadName = null;
            try {
                // first() not value(): value() skips the model's array cast.
                $leadName = data_get(Lead::where('session_id', $session->id)->orderByDesc('id')->first(['fields'])?->fields, 'name');
            } catch (\Throwable $e) {
                // tenant DB without a leads table
            }
            $lastSaid = Message::where('session_id', $session->id)
                ->where('role', 'user')
                ->whereNotNull('content')
                ->orderByDesc('id')
                ->value('content');

            return response()->json([
                'found'   => true,
                'name'    => $session->customer_name ?: (is_string($leadName) ? $leadName : null),
                'project' => $project->name,
                'channel' => $session->channel,
                'note'    => $session->summary?->summary
                    ?: ($lastSaid ? 'Said: “' . Str::limit($lastSaid, 90) . '”' : null),
                'url'     => route('sessions.show', ['client' => $client->slug, 'id' => $session->id])
                    . '?project_id=' . hashid($project->id),
            ]);
        }

        return response()->json(['found' => false]);
    }
}
