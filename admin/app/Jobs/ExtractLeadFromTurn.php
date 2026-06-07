<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\Message;
use App\Models\Session;
use App\Services\Conversation\PythonClient;
use App\Services\Tenant\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Extract / refresh the Lead row for a session after each assistant turn.
 *
 * Pipeline:
 *   1. Build conversation history (last N msgs of the session).
 *   2. Call Python /extract — which sanitises and scores confidence.
 *   3. Decide whether to create a new lead, update the existing one,
 *      or skip entirely (confidence below threshold and no contact info).
 *   4. Merge fields so we never blank out values the previous extraction
 *      already captured.
 */
class ExtractLeadFromTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    /** Drop extractions weaker than this UNLESS they include email or phone. */
    private const MIN_CONFIDENCE = 0.30;

    /** How many recent messages of the session to send to Python. */
    private const HISTORY_LIMIT = 20;

    public function __construct(
        public int $projectId,
        public int $sessionId,
        public int $assistantMessageId,
    ) {}

    public function handle(TenantManager $tenants, PythonClient $python): void
    {
        $tenants->useForProjectId($this->projectId);

        $session = Session::find($this->sessionId);
        if (!$session) {
            return;
        }

        // Build conversation history — last N user+assistant turns,
        // chronologically, capped at HISTORY_LIMIT.
        $history = Message::where('session_id', $this->sessionId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('id', '<=', $this->assistantMessageId)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => (string) ($m->content ?? '')])
            ->all();

        $existing = Lead::where('session_id', $session->id)->first();

        $result = $python->extract([
            'session_id'      => $session->id,
            'project_id'      => $session->project_id,
            'history'         => $history,
            'existing_fields' => $existing?->fields ?? new \stdClass(),
        ]);

        $fields     = $result['fields']     ?? [];
        $confidence = (float) ($result['confidence'] ?? 0.0);

        if (!is_array($fields)) {
            $fields = [];
        }

        // Decide whether this extraction is worth persisting.
        $hasContact  = !empty($fields['email']) || !empty($fields['phone']);
        $hasAnything = !empty($fields);

        if (!$hasAnything) {
            return;
        }
        if (!$hasContact && $confidence < self::MIN_CONFIDENCE) {
            // Vague hits with no way to follow up — skip the noise.
            Log::info('ExtractLeadFromTurn: skipping low-confidence non-contact lead', [
                'session_id' => $session->id,
                'confidence' => $confidence,
                'fields_keys' => array_keys($fields),
            ]);
            return;
        }

        $now = time();

        if ($existing) {
            $existing->fields = $this->mergeFields($existing->fields ?? [], $fields);
            // Only raise confidence — never overwrite a stronger past
            // extraction with a weaker one.
            $existing->confidence = max((float) ($existing->confidence ?? 0), $confidence);
            $existing->update_at = $now;
            $existing->save();
        } else {
            Lead::create([
                'session_id' => $session->id,
                'project_id' => $session->project_id,
                'fields'     => $fields,
                'confidence' => $confidence,
                'status'     => 'new',
                'created_at' => $now,
                'update_at'  => $now,
            ]);
        }
    }

    /**
     * Merge new extracted fields into the existing lead.
     *
     * Rules:
     *   - Non-empty new value WINS over old value (LLM had fresher evidence).
     *   - Empty new value never overwrites a non-empty old value.
     *   - `custom` is deep-merged with the same rule.
     */
    private function mergeFields(array $old, array $new): array
    {
        $merged = $old;
        foreach ($new as $k => $v) {
            if ($k === 'custom' && is_array($v)) {
                $oldCustom = is_array($merged['custom'] ?? null) ? $merged['custom'] : [];
                foreach ($v as $ck => $cv) {
                    if ($cv === null || $cv === '' || $cv === []) continue;
                    $oldCustom[$ck] = $cv;
                }
                if (!empty($oldCustom)) {
                    $merged['custom'] = $oldCustom;
                }
                continue;
            }
            if ($v === null || $v === '' || $v === []) continue;
            $merged[$k] = $v;
        }
        return $merged;
    }
}
