<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Msd\MailChannel\Models\EmailAccount;
use Msd\MailChannel\Services\ImapClient;
use Msd\MailChannel\Services\SmtpMailer;

/**
 * Connect/list/enable/disable/remove the mailboxes a project sends and
 * receives email through. Parallel to ChannelWebController, one level
 * simpler: no OAuth/onboarding-log machinery, since SMTP/IMAP is entered
 * directly (see EmailAccount for the reserved OAuth columns, unused today).
 */
class EmailAccountController extends Controller
{
    private const RULES = [
        'label'           => 'nullable|string|max:191',
        'from_name'       => 'nullable|string|max:191',
        'from_email'      => 'required|email|max:191',
        'imap_host'       => 'required|string|max:191',
        'imap_port'       => 'required|integer|min:1|max:65535',
        'imap_encryption' => 'required|in:ssl,tls,none',
        'imap_username'   => 'required|string|max:191',
        'imap_password'   => 'nullable|string|max:512',
        'smtp_host'       => 'required|string|max:191',
        'smtp_port'       => 'required|integer|min:1|max:65535',
        'smtp_encryption' => 'required|in:ssl,tls,none',
        'smtp_username'   => 'required|string|max:191',
        'smtp_password'   => 'nullable|string|max:512',
    ];

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(array_merge(self::RULES, [
            'project_id'    => 'required|integer',
            'imap_password' => 'required|string|max:512',
            'smtp_password' => 'required|string|max:512',
        ]));

        $project = $this->guard($client, (int) $data['project_id']);

        EmailAccount::create(array_merge($data, [
            'project_id' => $project->id,
            'status'     => EmailAccount::STATUS_ENABLED,
        ]));

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Mailbox {$data['from_email']} connected.");
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $projectId = (int) $request->validate(['project_id' => 'required|integer'])['project_id'];
        $project = $this->guard($client, $projectId);
        $account = $this->find($project, $id);

        // An OAuth-connected account has no user-editable host/port/
        // credentials — those live on the provider's side. Only the display
        // fields are ours to change; re-authenticating happens via
        // "Reconnect" (EmailOAuthController::start()), not this form.
        if ($account->oauth_provider) {
            $data = $request->validate([
                'label'     => 'nullable|string|max:191',
                'from_name' => 'nullable|string|max:191',
            ]);
            $account->update($data);

            return back()
                ->withInput(['project_id' => $project->id])
                ->with('success', 'Mailbox updated.');
        }

        $data = $request->validate(array_merge(self::RULES, [
            'project_id' => 'required|integer',
        ]));

        // Passwords are optional on update — blank means "keep the one on file".
        if ($data['imap_password'] === null || $data['imap_password'] === '') {
            unset($data['imap_password']);
        }
        if ($data['smtp_password'] === null || $data['smtp_password'] === '') {
            unset($data['smtp_password']);
        }

        $account->update($data);

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', 'Mailbox updated.');
    }

    public function toggle(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $project = $this->guard($client, (int) $data['project_id']);
        $account = $this->find($project, $id);

        $account->status = $account->isEnabled() ? EmailAccount::STATUS_DISABLED : EmailAccount::STATUS_ENABLED;
        $account->save();

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', $account->isEnabled() ? 'Mailbox enabled.' : 'Mailbox disabled.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $project = $this->guard($client, (int) $data['project_id']);
        $account = $this->find($project, $id);

        $account->delete();

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', 'Mailbox removed.');
    }

    /**
     * Opens the IMAP and SMTP connections with the submitted (or, on an
     * existing account, the stored) credentials right now, rather than
     * saving something broken and finding out from a silent poll failure.
     */
    public function test(Request $request, Client $client, int $id = 0): JsonResponse
    {
        $projectId = (int) $request->validate(['project_id' => 'required|integer'])['project_id'];
        $project = $this->guard($client, $projectId);
        $account = $id ? $this->find($project, $id) : null;

        // An OAuth account has no posted credentials to probe — test the
        // stored account directly (ImapClient/SmtpMailer pull a fresh token
        // through OAuthTokenBroker as needed).
        $probe = $account && $account->oauth_provider ? $account : $this->manualProbe($request, $project, $account);

        $errors = [];
        try {
            ImapClient::forAccount($probe)->testConnection();
        } catch (\Throwable $e) {
            $errors['imap'] = $e->getMessage();
        }
        try {
            SmtpMailer::forAccount($probe)->testConnection();
        } catch (\Throwable $e) {
            $errors['smtp'] = $e->getMessage();
        }

        if ($errors) {
            return response()->json(['ok' => false, 'errors' => $errors], 422);
        }

        return response()->json(['ok' => true, 'message' => 'IMAP and SMTP both connected successfully.']);
    }

    /**
     * Build a throwaway (unsaved) account from the submitted manual SMTP/IMAP
     * fields, so "Test" works before the form has ever been saved. Blank
     * passwords on an edit fall back to what's already stored.
     */
    private function manualProbe(Request $request, Project $project, ?EmailAccount $account): EmailAccount
    {
        $data = $request->validate(self::RULES);
        $probe = new EmailAccount(array_merge($data, ['project_id' => $project->id]));

        if ($account) {
            $probe->imap_password = $data['imap_password'] ?: $account->imap_password;
            $probe->smtp_password = $data['smtp_password'] ?: $account->smtp_password;
        }

        return $probe;
    }

    private function find(Project $project, int $id): EmailAccount
    {
        $account = EmailAccount::findOrFail($id);
        abort_unless((int) $account->project_id === $project->id, 404);

        return $account;
    }

    private function guard(Client $client, int $projectId): Project
    {
        return Project::where('client_id', $client->id)
            ->where('id', $projectId)
            ->firstOrFail();
    }
}
