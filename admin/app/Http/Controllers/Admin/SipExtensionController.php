<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\SipExtension;
use App\Models\UserPhoneAccount;
use App\Services\Telephony\SipAccounts;
use App\Services\Telephony\SipServer;
use App\Services\Telephony\TelephonyNumbers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Telephony → Extensions: a client's own SIP extensions on our Asterisk,
 * for calling each other and the AI from MicroSIP, Zoiper, a desk phone or
 * the admin's browser phone. Every client gets the same page; accounts are
 * "c<client>_<extension>" so clients never see or reach each other.
 */
class SipExtensionController extends Controller
{
    public function __construct(private SipAccounts $accounts, private SipServer $server) {}

    public function index(Request $request, Client $client): View
    {
        $extensions = SipExtension::where('client_id', $client->id)->with('user:id,name')->orderBy('extension')->get();
        $states     = $this->accounts->states('c' . $client->id . '_');

        return view('telephony.extensions', [
            'title'      => 'Extensions',
            'client'     => $client,
            'extensions' => $extensions,
            'states'     => $states,
            'server'     => $this->server->settings(),
            'domain'     => $this->server->domain(),
            'aiNumbers'  => $this->aiNumbers($client),
            'reveal'     => session('reveal'),  // [extension id => password], once
        ]);
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'extension' => ['required', 'regex:/^[1-9][0-9]{1,5}$/', Rule::notIn(['600']),
                            Rule::unique('mysql.sip_extensions', 'extension')->where('client_id', $client->id)],
            'name'      => 'required|string|max:100',
            'kind'      => ['required', Rule::in(array_keys(SipExtension::KINDS))],
        ], [
            'extension.regex'  => 'Use 2–6 digits, not starting with 0 (e.g. 2001).',
            'extension.unique' => 'You already have extension :input.',
            'extension.not_in' => '600 is the echo test — pick another number.',
        ]);

        if (in_array($data['extension'], $this->aiNumbers($client), true)) {
            return back()->withErrors(['extension' => "{$data['extension']} is one of your AI agent numbers."])->withInput();
        }

        $password  = SipAccounts::password();
        $extension = SipExtension::create([
            'client_id'    => $client->id,
            'extension'    => $data['extension'],
            'name'         => $data['name'],
            'kind'         => $data['kind'],
            'sip_username' => SipExtension::usernameFor($client->id, $data['extension']),
            'secret'       => $password,
        ]);

        $this->push($extension);

        return redirect()->route('telephony.extensions', ['client' => $client])
            ->with('success', "Extension {$extension->extension} created.")
            ->with('reveal', [$extension->id => $password]);
    }

    public function resetPassword(Client $client, string $extension): RedirectResponse
    {
        $ext = $this->find($client, $extension);
        $password = SipAccounts::password();
        $ext->secret = $password;
        $ext->save();
        $this->push($ext);

        return back()->with('success', "New password for {$ext->extension} — update it on the phone.")
            ->with('reveal', [$ext->id => $password]);
    }

    public function reveal(Client $client, string $extension): RedirectResponse
    {
        $ext = $this->find($client, $extension);

        return back()->with('reveal', [$ext->id => $ext->secret]);
    }

    public function sync(Client $client, string $extension): RedirectResponse
    {
        $ext = $this->find($client, $extension);
        $ok  = $this->push($ext);

        return back()->with($ok ? 'success' : 'error', $ok
            ? "Extension {$ext->extension} is on the phone server."
            : "Extension {$ext->extension} could not be sent: {$ext->last_error}");
    }

    public function destroy(Client $client, string $extension): RedirectResponse
    {
        $ext = $this->find($client, $extension);
        try {
            $this->accounts->delete($ext->sip_username);
        } catch (\Throwable $e) {
            return back()->with('error', "Extension {$ext->extension} could not be removed from the phone server: {$e->getMessage()}");
        }
        UserPhoneAccount::where('extension', $ext->sip_username)->delete();
        $ext->delete();

        return back()->with('success', "Extension {$ext->extension} deleted.");
    }

    /** Sign the signed-in user's admin-panel phone in as this extension. */
    public function useInBrowser(Request $request, Client $client, string $extension): RedirectResponse
    {
        $ext = $this->find($client, $extension);
        if ($ext->kind !== SipExtension::KIND_WEBRTC) {
            return back()->with('error', 'Only "Browser phone" extensions work in the admin panel.');
        }

        UserPhoneAccount::updateOrCreate(['user_id' => $request->user()->id], [
            'extension'    => $ext->sip_username,
            'sip_password' => $ext->secret,
            'ws_url'       => $this->server->wsUrl(),
            'display_name' => $ext->name,
        ]);
        $ext->user_id = $request->user()->id;
        $ext->save();

        return back()->with('success', "Your browser phone is now extension {$ext->extension}. Reload the phone window to connect.");
    }

    private function push(SipExtension $ext): bool
    {
        try {
            $this->accounts->put($ext->sip_username, $ext->secret, $ext->kind,
                SipAccounts::CONTEXT_INTERNAL, "{$ext->name} <{$ext->extension}>");
            $ext->pushed_at  = now();
            $ext->last_error = null;
            $ext->save();

            return true;
        } catch (\Throwable $e) {
            $ext->last_error = \Illuminate\Support\Str::limit($e->getMessage(), 250);
            $ext->save();

            return false;
        }
    }

    private function find(Client $client, string $id): SipExtension
    {
        return SipExtension::where('client_id', $client->id)->findOrFail((int) $id);
    }

    /** The client's AI numbers on Asterisk — dialing one from an extension reaches the AI. */
    private function aiNumbers(Client $client): array
    {
        $numbers = [];
        foreach (Project::where('client_id', $client->id)->get(['id', 'json_data']) as $project) {
            foreach ((array) data_get($project->json_data, 'telephony.numbers', []) as $n) {
                if (($n['provider'] ?? TelephonyNumbers::TWILIO) === TelephonyNumbers::ASTERISK && ! empty($n['phone_number'])) {
                    $numbers[] = (string) $n['phone_number'];
                }
            }
        }

        return array_values(array_unique($numbers));
    }
}
