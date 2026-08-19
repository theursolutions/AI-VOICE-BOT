<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AiBrain;
use App\Models\AiBrainUsage;
use App\Models\AuditLog;
use App\Services\Conversation\BrainResolver;
use App\Services\Conversation\PythonClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The platform's pool of model backends, in the order they should be used.
 *
 * Replaces the old brain-settings page, which wrote LLM_PROVIDER and the API
 * keys into voice-engine/.env — a file absent from the app image and read by
 * nobody, in a container the voice-engine cannot see. It also applied to every
 * client at once, which made per-client tiers and bring-your-own-key impossible.
 *
 * What the super admin controls here:
 *
 *   priority   the order the resolver tries them in
 *   quota      tokens a brain may spend before the next one takes over
 *   verify     a real one-token call, required before a brain can go live
 *
 * Verification is not optional by design. An unverified brain is skipped by the
 * resolver, so a mistyped key cannot become a silent outage across every
 * conversation on the platform — it stays off until it has demonstrably worked.
 */
class AiBrainsController extends Controller
{
    public function __construct(private PythonClient $python) {}

    public function index(Request $request): View
    {
        $title = 'AI Brains';

        $brains = AiBrain::query()
            ->platform()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        // 30-day totals per brain, so the page shows what each one has actually
        // carried rather than only what it is configured to allow.
        $usage = AiBrainUsage::query()
            ->whereIn('brain_id', $brains->pluck('id'))
            ->where('usage_date', '>=', date('Y-m-d', strtotime('-30 days')))
            ->selectRaw('brain_id, SUM(tokens_in + tokens_out) AS tokens, SUM(calls) AS calls, SUM(failures) AS failures')
            ->groupBy('brain_id')
            ->get()
            ->keyBy('brain_id');

        // Client-owned brains are listed read-only. The super admin does not
        // manage them — they are someone else's key and someone else's bill —
        // but needs to see they exist, because a client on their own brain is a
        // client whose traffic is not on our invoice.
        $clientBrains = AiBrain::query()
            ->whereNotNull('client_id')
            ->with('client')
            ->orderBy('client_id')
            ->orderBy('priority')
            ->get();

        return view('ops.ai-brains.index', [
            'title'        => $title,
            'brains'       => $brains,
            'usage'        => $usage,
            'clientBrains' => $clientBrains,
            'presets'      => AiBrain::PRESETS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $brain = new AiBrain();
        $brain->fill($data + [
            'client_id'  => null,
            'is_active'  => false,   // cannot go live until verified
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $brain->save();

        AuditLog::record('ai_brain.created', [
            'target_type' => 'ai_brain', 'target_id' => $brain->id,
            'payload' => ['name' => $brain->name, 'preset' => $brain->preset],
        ]);

        return back()->with('success', "Added “{$brain->name}”. Test it before switching it on.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $brain = AiBrain::platform()->findOrFail($id);
        $data  = $this->validated($request);

        // A blank key field means "leave it alone", not "delete it" — the form
        // never receives the existing key back, so an empty submit would
        // otherwise wipe a working credential on every unrelated edit.
        if (($data['api_key'] ?? '') === '') {
            unset($data['api_key']);
        }

        // Anything that changes WHERE the call goes invalidates the proof that it
        // works, so the brain drops back to unverified and switches itself off.
        $reverifyOn = ['kind', 'base_url', 'model', 'api_key'];
        $changed = collect($reverifyOn)->contains(
            fn ($f) => array_key_exists($f, $data) && (string) $data[$f] !== (string) $brain->$f
        );

        $brain->fill($data + ['updated_at' => time()]);

        if ($changed) {
            $brain->is_verified = false;
            $brain->is_active   = false;
            $brain->verify_error = null;
        }

        $brain->save();
        BrainResolver::forget();

        AuditLog::record('ai_brain.updated', [
            'target_type' => 'ai_brain', 'target_id' => $brain->id,
            'payload' => ['reverify_required' => $changed],
        ]);

        return back()->with(
            'success',
            $changed
                ? "Saved. “{$brain->name}” needs testing again because its connection changed."
                : "Saved “{$brain->name}”.",
        );
    }

    /**
     * Prove a brain works with a real call.
     *
     * A one-token request against the actual credentials. Cheaper than any
     * validation we could write, and the only check that catches the failures
     * that matter: a revoked key, a model name that does not exist on this
     * provider, a base URL off by a path segment.
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $brain = AiBrain::platform()->findOrFail($id);

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
            $resp = $this->python->llm(
                [['role' => 'user', 'content' => 'Reply with the single word: ready']],
                $options,
            );
        } catch (\Throwable $e) {
            $reason = $this->readableFailure($e);

            $brain->forceFill([
                'is_verified'  => false,
                'is_active'    => false,
                'verify_error' => mb_substr($reason, 0, 500),
                'updated_at'   => time(),
            ])->save();

            BrainResolver::forget();

            Log::warning('AI brain verification failed', ['brain_id' => $brain->id, 'error' => $reason]);

            return response()->json(['ok' => false, 'message' => $reason], 200);
        }

        $text = trim((string) ($resp['text'] ?? ''));

        // A 200 with no text is still a failure. Some providers answer an
        // unusable request cleanly, and treating that as success would let a
        // brain that returns nothing sit at the top of the chain.
        if ($text === '') {
            $brain->forceFill([
                'is_verified'  => false,
                'is_active'    => false,
                'verify_error' => 'The provider answered but returned no text.',
                'updated_at'   => time(),
            ])->save();

            BrainResolver::forget();

            return response()->json([
                'ok'      => false,
                'message' => 'The provider answered but returned no text. Check the model name.',
            ], 200);
        }

        $brain->forceFill([
            'is_verified'  => true,
            'verified_at'  => time(),
            'verify_error' => null,
            'updated_at'   => time(),
        ])->save();

        BrainResolver::forget();

        AuditLog::record('ai_brain.verified', ['target_type' => 'ai_brain', 'target_id' => $brain->id]);

        return response()->json([
            'ok'      => true,
            'message' => 'Works. Replied: “' . mb_substr($text, 0, 60) . '”',
            'model'   => $resp['model'] ?? null,
        ]);
    }

    /** Switch a brain on or off. Off is always allowed; on requires verification. */
    public function toggle(Request $request, int $id): RedirectResponse
    {
        $brain = AiBrain::platform()->findOrFail($id);

        if (! $brain->is_active && ! $brain->is_verified) {
            return back()->with('error', "“{$brain->name}” has not been tested yet. Test it first.");
        }

        $brain->forceFill([
            'is_active'  => ! $brain->is_active,
            'updated_at' => time(),
        ])->save();

        BrainResolver::forget();

        AuditLog::record('ai_brain.toggled', [
            'target_type' => 'ai_brain', 'target_id' => $brain->id,
            'payload' => ['active' => $brain->is_active],
        ]);

        return back()->with('success', "“{$brain->name}” is now " . ($brain->is_active ? 'live' : 'off') . '.');
    }

    /** Persist a new priority order from the drag-and-drop list. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['order']) as $i => $brainId) {
                // Steps of 10, so a later insert can be slotted between two
                // existing brains without renumbering the whole list.
                AiBrain::platform()->whereKey($brainId)->update([
                    'priority'   => ($i + 1) * 10,
                    'updated_at' => time(),
                ]);
            }
        });

        BrainResolver::forget();

        return response()->json(['ok' => true]);
    }

    /** Reset a quota counter early, without waiting for the window to roll. */
    public function resetQuota(Request $request, int $id): RedirectResponse
    {
        $brain = AiBrain::platform()->findOrFail($id);

        $brain->forceFill([
            'tokens_used'    => 0,
            'quota_reset_at' => time(),
            'updated_at'     => time(),
        ])->save();

        BrainResolver::forget();

        AuditLog::record('ai_brain.quota_reset', ['target_type' => 'ai_brain', 'target_id' => $brain->id]);

        return back()->with('success', "Usage counter reset for “{$brain->name}”.");
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $brain = AiBrain::platform()->findOrFail($id);
        $name  = $brain->name;

        // Usage rows are kept deliberately. They are the record of what was
        // spent, and that history should survive the configuration being tidied
        // up — otherwise deleting a brain quietly rewrites past cost reporting.
        $brain->delete();

        BrainResolver::forget();

        AuditLog::record('ai_brain.deleted', ['target_type' => 'ai_brain', 'payload' => ['name' => $name]]);

        return back()->with('success', "Removed “{$name}”. Its usage history was kept.");
    }

    /**
     * The reason a verification call failed, in a form worth showing someone.
     *
     * Guzzle's message is a summary line — "resulted in a 502 Bad Gateway
     * response:" — followed by the response body on the NEXT line. Taking only
     * the first line therefore reports the transport status and discards the one
     * piece of information that identifies the fault, which is precisely what the
     * provider said. The voice-engine wraps provider errors as
     * {"detail": "llm failure: <real reason>"}, so that body is the answer.
     */
    private function readableFailure(\Throwable $e): string
    {
        $body = '';

        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
            $body = trim((string) $e->getResponse()->getBody());
        }

        if ($body !== '') {
            $decoded = json_decode($body, true);
            $detail  = is_array($decoded)
                ? ($decoded['detail'] ?? $decoded['message'] ?? $decoded['error'] ?? null)
                : null;

            if (is_string($detail) && $detail !== '') {
                // Strip the engine's own prefix; the operator does not need to be
                // told twice that an LLM call failed.
                return mb_substr(preg_replace('/^llm failure:\s*/i', '', $detail), 0, 400);
            }

            return mb_substr($body, 0, 400);
        }

        return mb_substr(strtok($e->getMessage(), "\n") ?: 'the provider could not be reached', 0, 400);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'preset'       => ['required', 'string', 'in:' . implode(',', array_keys(AiBrain::PRESETS))],
            'kind'         => ['required', 'string', 'in:openai_compat,anthropic,ollama'],
            'base_url'     => ['nullable', 'string', 'max:255', 'url'],
            'model'        => ['nullable', 'string', 'max:120'],
            'api_key'      => ['nullable', 'string', 'max:512'],
            'max_tokens'   => ['required', 'integer', 'min:256', 'max:32000'],
            'priority'     => ['required', 'integer', 'min:1', 'max:9999'],
            // Blank = unlimited. Expressed in tokens because that is what every
            // provider bills on and what the engine reports back to us.
            'quota_tokens' => ['nullable', 'integer', 'min:1000'],
            'quota_window' => ['required', 'string', 'in:month,total'],
            // What clients see instead of the vendor and model, so our cost base
            // is not published to the people we are billing.
            'public_label' => ['nullable', 'string', 'max:60'],
        ]);
    }
}
