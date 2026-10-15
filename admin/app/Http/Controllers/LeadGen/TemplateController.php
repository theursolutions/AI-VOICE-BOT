<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LeadGen\OfferProfile;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Template;
use App\Services\LeadGen\Ai\TemplateRenderer;
use App\Services\LeadGen\LeadGenContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Reusable outreach templates with {{variables}}. */
class TemplateController extends Controller
{
    public function __construct(private LeadGenContext $context) {}

    public function index(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        $templates = Template::orderBy('channel')->orderBy('name')->get();
        return view('leadgen.templates.index', compact('client', 'project', 'projects', 'templates'));
    }

    public function create(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        return $this->form($client, $project, $projects, new Template(['channel' => 'email', 'kind' => 'intro', 'language' => 'en',
            'subject' => 'A quick idea for {{business_name}}',
            'body' => "Hi {{business_name}} team,\n\nI'm {{sender_name}}. {{value_proposition}}\n\nWould a 15-minute call next week be useful? {{booking_link}}\n\nBest regards,\n{{sender_name}}"]));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        Template::create($this->validated($request) + ['created_by' => $request->user()->id]);
        return redirect()->route('leadgen.templates.index', ['client' => $client->slug, 'project_id' => $project->id])->with('success', 'Template saved.');
    }

    public function edit(Request $request, Client $client, int $id): View
    {
        [$project, $projects] = $this->open($request, $client);
        return $this->form($client, $project, $projects, Template::findOrFail($id));
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        Template::findOrFail($id)->update($this->validated($request));
        return redirect()->route('leadgen.templates.index', ['client' => $client->slug, 'project_id' => $project->id])->with('success', 'Template saved.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->open($request, $client);
        Template::findOrFail($id)->softDelete();
        return back()->with('success', 'Template deleted.');
    }

    /** Fill the template for a real (or sample) prospect, for the editor's preview. */
    public function preview(Request $request, Client $client): JsonResponse
    {
        $this->open($request, $client);
        $d = $request->validate(['subject' => 'nullable|string|max:255', 'body' => 'required|string|max:5000',
            'prospect_id' => 'nullable|integer', 'profile_id' => 'nullable|integer']);
        $p = !empty($d['prospect_id']) ? Prospect::findOrFail($d['prospect_id'])
            : new Prospect(['business_name' => 'Acme Realty', 'category' => 'real_estate_agent', 'city' => 'Dubai', 'website' => 'https://acme-realty.ae']);
        if (empty($d['prospect_id'])) {
            $p->domain_norm = 'acme-realty.ae';
        }
        $profile = !empty($d['profile_id']) ? OfferProfile::findOrFail($d['profile_id']) : OfferProfile::orderBy('id')->first();
        $vars = TemplateRenderer::variables($p, $profile);
        $body = TemplateRenderer::render($d['body'], $vars);
        $subject = TemplateRenderer::render((string) ($d['subject'] ?? ''), $vars);
        return response()->json(['subject' => $subject['text'], 'body' => $body['text'],
            'missing' => array_values(array_unique(array_merge($body['missing'], $subject['missing']))),
            'unknown' => array_values(array_unique(array_merge($body['unknown'], $subject['unknown'])))]);
    }

    private function form(Client $client, $project, $projects, Template $template): View
    {
        return view('leadgen.templates.form', compact('client', 'project', 'projects', 'template') + [
            'profiles' => OfferProfile::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name'     => 'required|string|max:160',
            'channel'  => ['required', Rule::in(Template::CHANNELS)],
            'kind'     => 'required|in:intro,follow_up,meeting,inquiry',
            'language' => ['required', Rule::in(array_keys(config('leadgen.languages')))],
            'subject'  => 'nullable|required_if:channel,email|string|max:255',
            'body'     => 'required|string|max:5000',
            'wa_template_name' => 'nullable|required_if:channel,whatsapp|string|max:191|regex:/^[a-z0-9_]+$/',
            'wa_template_lang' => 'nullable|string|max:12',
        ], ['wa_template_name.required_if' => 'WhatsApp only allows approved templates — enter the template name from your WhatsApp templates.']);

        $unknown = array_merge(
            TemplateRenderer::render((string) ($d['subject'] ?? ''), [])['unknown'],
            TemplateRenderer::render($d['body'], [])['unknown'],
        );
        if ($unknown) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'Unknown variable(s): ' . implode(', ', array_unique($unknown))
                . '. Available: ' . implode(', ', array_map(fn ($v) => '{{' . $v . '}}', Template::VARIABLES))]);
        }
        if ($d['channel'] !== 'email') {
            $d['subject'] = null;
        }
        return $d;
    }

    private function open(Request $request, Client $client): array
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        abort_unless($project && LeadGenContext::tablesReady(), 404);
        return [$project, $projects];
    }
}
