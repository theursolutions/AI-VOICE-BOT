<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiBrain;
use App\Models\Client;
use App\Models\Project;
use App\Services\Conversation\BrainResolver;
use App\Services\Conversation\PythonClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The client's own view of which AI serves their conversations, and the place
 * they can point it at their own provider account instead.
 *
 * Deliberately narrower than the super-admin console:
 *
 *   no quotas, no usage    a platform brain's allowance is our commercial
 *                          business, not theirs, and showing a meter they cannot
 *                          act on only invites questions we do not want to answer
 *   neutral tier names     a platform brain shows "Standard", never the vendor
 *                          and model behind our pricing
 *   real names for theirs   a brain they configured shows exactly what they typed
 *
 * Their own brain always outranks our pool — they are paying for it, and routing
 * their customers' messages through our provider account would defeat the point
 * of letting them bring a key at all.
 */
class ClientBrainsController extends Controller
{
    public function __construct(private PythonClient $python) {}

    public function index(Request $request, Client $client): View
    {
        $project = $this->resolveProject($client, (int) $request->query('project_id'));

        // What is actually serving this project right now, resolved through the
        // same code path a real message takes — not re-derived here, or the page
        // could disagree with reality.
        $serving = app(BrainResolver::class)->resolve($project->id);

        $available = AiBrain::query()
            ->usable()
            ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $client->id))
            ->orderByRaw('client_id IS NULL')     // their own brains first
            ->orderBy('priority')
            ->get();

        $ownBrains = AiBrain::query()
            ->forClient($client->id)
            ->orderBy('priority')
            ->get();

        return view('brain-settings.index', [
            'title'     => 'AI Brain',
            'client'    => $client,
            'project'   => $project,
            'projectId' => $project->id,
            'serving'   => $serving,
            'available' => $available,
            'ownBrains' => $ownBrains,
            'chosenId'  => (int) data_get($project->metadata, 'brain_id', 0),
            'presets'   => AiBrain::PRESETS,
        ]);
    }

    /** Point this project at a specific brain, or back to automatic. */
    public function choose(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required'],
            'brain'      => ['nullable', 'integer'],
        ]);

        $project = $this->resolveProject($client, (int) $data['project_id']);
        $brainId = (int) ($data['brain'] ?? 0);

        if ($brainId) {
            // Ownership check, not a convenience. Without it a client could post
            // another client's brain id and have their conversations billed to —
            // and read by — someone else's provider account.
            $ok = AiBrain::query()
                ->usable()
                ->whereKey($brainId)
                ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $client->id))
                ->exists();

            if (! $ok) {
                return back()->with('error', 'That AI is not available to this workspace.');
            }
        }

        $meta = (array) $project->metadata;
        if ($brainId) {
            $meta['brain_id'] = $brainId;
        } else {
            unset($meta['brain_id']);
        }

        $project->metadata = $meta;
        $project->save();

        BrainResolver::forget($project->id);

        return back()->with('success', $brainId
            ? 'Switched. New conversations use it immediately.'
            : 'Back to automatic — we will pick the best available AI for you.');
    }

    /** Add a brain on the client's own provider account. */
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required'],
            'name'       => ['required', 'string', 'max:120'],
            'preset'     => ['required', 'string', 'in:' . implode(',', array_keys(AiBrain::PRESETS))],
            'model'      => ['nullable', 'string', 'max:120'],
            'api_key'    => ['required', 'string', 'max:512'],
            'base_url'   => ['nullable', 'string', 'max:255', 'url', 'required_if:preset,custom'],
        ], [
            'api_key.required'  => 'An API key is needed so we can connect to your AI provider.',
            'base_url.required' => 'This provider needs an endpoint URL. Pick a provider from the list to fill it in automatically.',
        ]);

        $this->resolveProject($client, (int) $data['project_id']);

        $preset = AiBrain::PRESETS[$data['preset']];

        $brain = AiBrain::create([
            'client_id'    => $client->id,
            'name'         => $data['name'],
            'preset'       => $data['preset'],
            'kind'         => $preset['kind'],
            'base_url'     => $data['base_url'] ?: $preset['base_url'],
            'model'        => $data['model'] ?: ($preset['models'][0] ?? null),
            'api_key'      => $data['api_key'],
            'max_tokens'   => 4096,
            // Their brains are ranked among themselves; the resolver already puts
            // the whole client scope ahead of the platform pool.
            'priority'     => (int) (AiBrain::forClient($client->id)->max('priority') ?? 0) + 10,
            'is_active'    => false,
            'is_verified'  => false,
            'quota_window' => 'total',
            'created_at'   => time(),
            'updated_at'   => time(),
        ]);

        return back()->with('success', "Added “{$brain->name}”. Test the connection to start using it.");
    }

    /**
     * Prove the client's brain works, then switch it on.
     *
     * Combined on purpose. For the super admin, testing and going live are
     * separate because they are staging a chain. A client has one intent — "use
     * my AI" — and splitting it into two steps only creates a state where they
     * believe they are on their own key and are not.
     */
    public function verify(Request $request, Client $client, int $id): JsonResponse
    {
        $brain = AiBrain::forClient($client->id)->findOrFail($id);

        $options = array_filter([
            'provider'     => match ($brain->kind) {
                AiBrain::KIND_ANTHROPIC => 'anthropic',
                AiBrain::KIND_OLLAMA    => 'ollama',
                default                 => 'openai_compat',
            },
            'model'        => $brain->model,
            'api_key'      => $brain->api_key,
            'base_url'     => $brain->base_url,
            'max_tokens'   => 8,
            'respond_with' => 'text',
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $resp = $this->python->llm([['role' => 'user', 'content' => 'Reply with the single word: ready']], $options);
            $text = trim((string) ($resp['text'] ?? ''));

            if ($text === '') {
                throw new \RuntimeException('Your provider answered but sent no text back. Check the model name.');
            }

            // Confirm THEIR provider answered, not our fallback chain. Without
            // this a dead key still "connects" — the engine quietly falls back to
            // a platform brain, the client believes their account is serving their
            // conversations, and we pay for every message they send.
            $used = (string) ($resp['model'] ?? '');

            if ($brain->model && $used !== '' && ! str_contains(strtolower($used), strtolower($brain->model))) {
                throw new \RuntimeException(
                    "The reply came from “{$used}”, not your “{$brain->model}” — so your provider "
                    . 'refused the request. Check the API key and model name.'
                );
            }
        } catch (\Throwable $e) {
            $reason = $this->readableFailure($e);

            $brain->forceFill([
                'is_verified'  => false,
                'is_active'    => false,
                'verify_error' => mb_substr($reason, 0, 500),
                'updated_at'   => time(),
            ])->save();

            BrainResolver::forget();

            Log::info('Client brain verification failed', ['brain' => $brain->id, 'client' => $client->id]);

            return response()->json(['ok' => false, 'message' => $reason]);
        }

        $brain->forceFill([
            'is_verified'  => true,
            'is_active'    => true,     // one intent, one step
            'verified_at'  => time(),
            'verify_error' => null,
            'updated_at'   => time(),
        ])->save();

        BrainResolver::forget();

        return response()->json([
            'ok'      => true,
            'message' => 'Connected. Your AI is now handling your conversations.',
        ]);
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $brain = AiBrain::forClient($client->id)->findOrFail($id);
        $name  = $brain->name;

        // Clear any project pinned to it, or the resolver would keep looking up a
        // brain that no longer exists and silently fall through to our pool with
        // no indication of why.
        foreach (Project::where('client_id', $client->id)->get() as $p) {
            if ((int) data_get($p->metadata, 'brain_id') === $brain->id) {
                $meta = (array) $p->metadata;
                unset($meta['brain_id']);
                $p->metadata = $meta;
                $p->save();
            }
        }

        $brain->delete();
        BrainResolver::forget();

        return back()->with('success', "Removed “{$name}”. We are back to handling your AI for you.");
    }

    /** The provider's own words, not the transport status. */
    private function readableFailure(\Throwable $e): string
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
            $body    = trim((string) $e->getResponse()->getBody());
            $decoded = json_decode($body, true);
            $detail  = is_array($decoded) ? ($decoded['detail'] ?? $decoded['message'] ?? null) : null;

            if (is_string($detail) && $detail !== '') {
                return mb_substr(preg_replace('/^llm failure:\s*/i', '', $detail), 0, 300);
            }

            if ($body !== '') {
                return mb_substr($body, 0, 300);
            }
        }

        return mb_substr(strtok($e->getMessage(), "\n") ?: 'We could not reach your provider.', 0, 300);
    }

    /**
     * The project this page is about.
     *
     * Falls back to the workspace's active project, then its first, when no
     * project_id is supplied. Without that, the bare URL — which is what a
     * sidebar link, a bookmark or a pasted address gives you — cast null to 0,
     * matched no row, and returned a 404 telling the owner their own settings
     * page does not exist.
     *
     * A supplied id is still scoped to the client, so the fallback adds
     * convenience without widening what anyone can reach.
     */
    private function resolveProject(Client $client, int $projectId): Project
    {
        if ($projectId > 0) {
            return Project::where('client_id', $client->id)->where('id', $projectId)->firstOrFail();
        }

        return Project::where('client_id', $client->id)->orderBy('id')->firstOrFail();
    }
}
