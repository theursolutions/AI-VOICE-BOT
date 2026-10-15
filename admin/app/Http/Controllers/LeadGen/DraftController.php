<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LeadGen\Event;
use App\Models\LeadGen\OfferProfile;
use App\Models\LeadGen\OutreachMessage;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Template;
use App\Services\LeadGen\Ai\Drafter;
use App\Services\LeadGen\Ai\TemplateRenderer;
use App\Services\LeadGen\LeadGenContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Outreach drafts for one prospect: written by AI or filled from a template,
 * then edited by a person. Drafts are never sent from here — sending needs an
 * approved campaign.
 */
class DraftController extends Controller
{
    public function __construct(private LeadGenContext $context) {}

    public function store(Request $request, Client $client, int $id, Drafter $drafter): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $p = Prospect::findOrFail($id);
        $d = $request->validate([
            'profile_id'  => 'required|integer',
            'template_id' => 'nullable|integer',
            'channel'     => ['required', Rule::in(Template::CHANNELS)],
            'language'    => ['required', Rule::in(array_keys(config('leadgen.languages')))],
            'tone'        => ['required', Rule::in(array_keys(Drafter::TONES))],
            'length'      => ['required', Rule::in(array_keys(Drafter::LENGTHS))],
            'mode'        => 'required|in:ai,template',
        ]);
        $profile  = OfferProfile::findOrFail($d['profile_id']);
        $template = !empty($d['template_id']) ? Template::findOrFail($d['template_id']) : null;

        if ($p->suppressed_at) {
            return back()->withErrors(['draft' => 'This business is on your do-not-contact list.']);
        }

        if ($d['mode'] === 'template') {
            abort_unless($template, 422, 'Choose a template.');
            $vars = TemplateRenderer::variables($p, $profile);
            $body = TemplateRenderer::render($template->body, $vars);
            $subject = TemplateRenderer::render((string) $template->subject, $vars);
            $missing = array_unique(array_merge($body['missing'], $subject['missing']));
            $msg = OutreachMessage::create([
                'prospect_id' => $p->id, 'channel' => $template->channel, 'language' => $template->language,
                'subject' => $template->channel === 'email' ? mb_substr($subject['text'], 0, 255) : null,
                'body' => $body['text'], 'ai_generated' => false, 'status' => 'draft', 'created_by' => $request->user()->id,
                'last_error' => $missing ? 'No value for ' . implode(', ', array_map(fn ($m) => '{{' . $m . '}}', $missing)) . ' — fill it in or remove it.' : null,
            ]);
        } else {
            try {
                $msg = $drafter->draft($p, $profile, $template, $d, $request->user()->id);
            } catch (\Throwable $e) {
                report($e);
                return back()->withErrors(['draft' => 'The AI service didn’t answer. Try again shortly, or start from a template.']);
            }
        }

        return redirect()->route('leadgen.drafts.edit', ['client' => $client->slug, 'id' => $msg->id, 'project_id' => $project->id]);
    }

    public function edit(Request $request, Client $client, int $id): View
    {
        [$project, $projects] = $this->open($request, $client);
        $msg = OutreachMessage::with('prospect')->findOrFail($id);
        return view('leadgen.drafts.edit', compact('client', 'project', 'projects', 'msg'));
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->open($request, $client);
        $msg = OutreachMessage::findOrFail($id);
        abort_unless($msg->status === 'draft', 409, 'Only drafts can be edited.');
        $d = $request->validate(['subject' => 'nullable|string|max:255', 'body' => 'required|string|max:5000']);

        // Re-check what the person wrote, the same way the AI's text is checked.
        $warnings = [];
        if ($hits = Drafter::falseFamiliarity(($d['subject'] ?? '') . ' ' . $d['body'])) {
            $warnings[] = 'Mentions a past conversation (“' . $hits[0] . '”). Only keep it if it really happened.';
        }
        $placeholders = TemplateRenderer::render(($d['subject'] ?? '') . ' ' . $d['body'], []);
        if ($placeholders['missing'] || $placeholders['unknown']) {
            $warnings[] = 'Still contains placeholders: ' . implode(', ', array_map(fn ($m) => '{{' . $m . '}}', array_merge($placeholders['missing'], $placeholders['unknown']))) . '.';
        }

        $msg->forceFill(['subject' => $msg->channel === 'email' ? ($d['subject'] ?? null) : null, 'body' => $d['body'],
            'last_error' => $warnings ? implode("\n", $warnings) : null])->save();
        Event::log('prospect', $msg->prospect_id, 'draft.edited', ['message_id' => $msg->id]);
        return back()->with('success', $warnings ? 'Saved — please check the warnings.' : 'Draft saved.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $msg = OutreachMessage::findOrFail($id);
        abort_unless($msg->status === 'draft', 409);
        $prospectId = $msg->prospect_id;
        $msg->delete();
        return redirect()->route('leadgen.prospects.show', ['client' => $client->slug, 'id' => $prospectId, 'project_id' => $project->id])
            ->with('success', 'Draft deleted.');
    }

    private function open(Request $request, Client $client): array
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        abort_unless($project && LeadGenContext::tablesReady(), 404);
        return [$project, $projects];
    }
}
