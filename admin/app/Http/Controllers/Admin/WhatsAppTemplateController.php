<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\GraphClient;

/**
 * WhatsApp message templates for a project's connected number.
 *
 * Templates are WABA-level assets, not conversation-level ones, so this is
 * project-scoped rather than hanging off a chat session like the send-template
 * endpoint does. One template serves every conversation on the number.
 *
 * Why this exists as a UI at all, when Meta has its own template manager:
 *
 *  - Sending a template already lives here (ChatController::sendTemplate), so
 *    an agent who needs one that does not exist yet had to leave the product,
 *    find WhatsApp Manager, and come back — and the picker only shows APPROVED
 *    templates, so there was no way to even tell that a template was pending.
 *  - `whatsapp_business_management` is requested precisely so we can manage a
 *    customer's assets on their behalf. Listing templates used the permission;
 *    nothing exercised the write half of it.
 *
 * The 24-hour service window is the reason templates matter: outside it, a
 * template is the ONLY message a business may send. See MetaManager.
 */
class WhatsAppTemplateController extends Controller
{
    /**
     * Meta's categories. MARKETING is deliberately last: it is the one that
     * costs the most, is approved the most slowly, and is the wrong answer for
     * the re-engagement case people usually have in mind.
     */
    public const CATEGORIES = ['UTILITY', 'AUTHENTICATION', 'MARKETING'];

    public function __construct(private TenantManager $tenants) {}

    /** The template management page. */
    public function index(Request $request, Client $client)
    {
        $project = $this->guard($client, (int) $request->query('project_id'));

        [$conn, $waba, $problem] = $this->resolve($project);

        $templates = [];

        if (! $problem) {
            // Unfiltered on purpose — the point of this page is to show PENDING
            // and REJECTED, which is exactly what the chat picker hides.
            $templates = collect(GraphClient::forConnection($conn)->listTemplates($waba))
                ->map(fn ($t) => [
                    'name'     => $t['name'] ?? '',
                    'status'   => $t['status'] ?? 'UNKNOWN',
                    'language' => $t['language'] ?? '',
                    'category' => $t['category'] ?? '',
                    'body'     => $this->bodyOf($t),
                    'rejected' => $t['rejected_reason'] ?? null,
                ])
                ->sortBy(fn ($t) => [$t['status'] === 'APPROVED' ? 1 : 0, $t['name']])
                ->values()
                ->all();
        }

        return view('whatsapp.templates', [
            'client'     => $client,
            'project'    => $project,
            'projectId'  => $project->id,
            'templates'  => $templates,
            'categories' => self::CATEGORIES,
            'problem'    => $problem,
            'waba'       => $waba,
            'number'     => $conn?->name,
        ]);
    }

    /** Create a template on the WABA. */
    public function store(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required'],
            // Meta's own constraint: lowercase letters, digits and underscores.
            // Validated here so the form can say so, rather than round-tripping
            // to Graph for a rejection we could predict.
            'name'       => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'category'   => ['required', 'string', 'in:' . implode(',', self::CATEGORIES)],
            'language'   => ['required', 'string', 'max:10'],
            'body'       => ['required', 'string', 'max:1024'],
            'header'     => ['nullable', 'string', 'max:60'],
            'footer'     => ['nullable', 'string', 'max:60'],
            'buttons'    => ['nullable', 'array', 'max:3'],
            'buttons.*'  => ['string', 'max:25'],
        ], [
            'name.regex' => 'The name may only use lowercase letters, numbers and underscores — no spaces or capitals.',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        [$conn, $waba, $problem] = $this->resolve($project);

        if ($problem) {
            return response()->json(['ok' => false, 'message' => $problem], 422);
        }

        // Quick replies only. URL and PHONE_NUMBER buttons each need their own
        // extra field and their own example rules, and shipping them half-done
        // would fail at Graph with a message about a field this form never
        // showed. Better to offer less and have it work.
        $buttons = collect($data['buttons'] ?? [])
            ->map(fn ($t) => trim((string) $t))
            ->filter()
            ->map(fn ($t) => ['type' => 'QUICK_REPLY', 'text' => $t])
            ->values()
            ->all();

        try {
            $created = GraphClient::forConnection($conn)->createTemplate(
                wabaId:   $waba,
                name:     $data['name'],
                category: $data['category'],
                language: $data['language'],
                body:     $data['body'],
                header:   $data['header'] ?? null,
                footer:   $data['footer'] ?? null,
                buttons:  $buttons,
            );
        } catch (\Throwable $e) {
            Log::warning('WhatsApp template creation failed', [
                'project' => $project->id, 'name' => $data['name'], 'error' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $status = $created['status'] ?? 'PENDING';

        Log::info('WhatsApp template created', [
            'project' => $project->id, 'name' => $data['name'], 'status' => $status,
        ]);

        return response()->json([
            'ok'       => true,
            'id'       => $created['id'] ?? null,
            'status'   => $status,
            // Said explicitly because "created" reads as "ready", and it is not:
            // sending a PENDING template fails, and the chat picker filters it
            // out until Meta approves it.
            'message'  => $status === 'APPROVED'
                ? "Template “{$data['name']}” is approved and ready to send."
                : "Template “{$data['name']}” was submitted and is {$status}. "
                  . 'It appears in the chat template picker once Meta approves it.',
        ], 201);
    }

    /**
     * The project's WhatsApp connection and WABA id.
     *
     * @return array{0: ?ChannelConnection, 1: string, 2: ?string} conn, waba, problem
     */
    private function resolve(Project $project): array
    {
        $conn = ChannelConnection::query()
            ->where('provider', ChannelConnection::PROVIDER_WHATSAPP)
            ->where('project_id', $project->id)
            ->where('status', ChannelConnection::STATUS_ENABLED)
            ->latest('id')
            ->first();

        if (! $conn) {
            return [null, '', 'No WhatsApp number is connected to this project. Connect one on the Channels page first.'];
        }

        // Falls back to the single-number env config for the same reason
        // MetaManager does: an install can be messaging perfectly through
        // META_WHATSAPP_* before anything was onboarded through the UI.
        $waba = (string) (data_get($conn->metadata, 'waba_id')
            ?: config('meta.whatsapp.business_account_id') ?: '');

        if ($waba === '') {
            return [$conn, '', 'This channel has no WhatsApp Business Account id, so templates cannot be listed or created. Reconnect the number on the Channels page.'];
        }

        return [$conn, $waba, null];
    }

    /** The BODY text of a template as Meta returns it. */
    private function bodyOf(array $tpl): string
    {
        foreach ((array) ($tpl['components'] ?? []) as $c) {
            if (strtoupper((string) ($c['type'] ?? '')) === 'BODY') {
                return (string) ($c['text'] ?? '');
            }
        }

        return '';
    }

    private function guard(Client $client, int $projectId): Project
    {
        $project = Project::where('client_id', $client->id)->where('id', $projectId)->firstOrFail();
        $this->tenants->useFor($project);

        return $project;
    }
}
