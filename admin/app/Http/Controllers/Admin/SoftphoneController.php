<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Models\UserPhoneAccount;
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
    public function __construct(private TenantManager $tenants) {}

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
            return response()->json(['configured' => false, 'display_name' => $request->user()->name]);
        }

        return response()->json([
            'configured'   => true,
            'extension'    => $account->extension,
            'password'     => $account->sip_password,
            'ws_url'       => $account->ws_url,
            'display_name' => $account->display_name ?: $request->user()->name,
        ]);
    }

    /** POST /me/phone — save the login. An empty password keeps the saved one. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'extension'    => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_.+-]+$/'],
            'password'     => 'nullable|string|max:128',
            'ws_url'       => ['required', 'string', 'max:255', 'regex:#^wss?://#i'],
            'display_name' => 'nullable|string|max:120',
        ]);

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
