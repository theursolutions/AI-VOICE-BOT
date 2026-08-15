<?php

namespace App\Meta;

use App\Models\Message;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Contracts\HandlesMessageStatus;

/**
 * Records sent / delivered / read / failed against the message we sent.
 *
 * The interesting problem here is ordering. Meta does not guarantee it, and
 * on a slow handset `delivered` routinely arrives AFTER `read` — so writing
 * whatever turns up last would show a single tick on a message the customer
 * has visibly opened. States are therefore RANKED, and a receipt that would
 * move the message backwards is discarded.
 */
class CrmMessageStatusHandler implements HandlesMessageStatus
{
    /** Higher wins. `failed` outranks everything: it is terminal and it matters. */
    private const RANK = [
        'sent'      => 1,
        'delivered' => 2,
        'read'      => 3,
        'failed'    => 4,
    ];

    /**
     * How far back to look for the message a receipt belongs to.
     *
     * Receipts arrive within seconds; `read` can lag by hours if the customer
     * leaves the chat unopened. A day is generous and keeps the scan cheap.
     */
    private const LOOKBACK_SECONDS = 86400;

    public function __construct(private TenantManager $tenants) {}

    public function handle(int $projectId, string $wamid, string $status, int $timestamp, string $error = ''): void
    {
        if (! isset(self::RANK[$status])) {
            return;                                   // unknown state; ignore
        }

        $project = Project::find($projectId);
        if (! $project) {
            return;
        }
        $this->tenants->useFor($project);

        $message = $this->findByWamid($projectId, $wamid);
        if (! $message) {
            // Normal, not alarming: receipts also arrive for messages sent
            // before this feature existed, and for template sends made
            // outside the console.
            return;
        }

        $meta    = (array) $message->metadata;
        $current = (string) ($meta['delivery'] ?? '');

        // Never move backwards — see the class comment.
        if ($current !== '' && (self::RANK[$current] ?? 0) >= self::RANK[$status]) {
            return;
        }

        $meta['delivery']    = $status;
        $meta['delivery_at'] = $timestamp;
        if ($status === 'failed' && $error !== '') {
            $meta['delivery_error'] = $error;
            Log::warning('WhatsApp delivery failed', [
                'project' => $projectId, 'wamid' => $wamid, 'reason' => $error,
            ]);
        }

        $message->metadata = $meta;
        $message->save();
    }

    /**
     * Find the message carrying this wamid.
     *
     * Matched in PHP rather than with a JSON path in SQL: `metadata` is a
     * JSON column and MySQL and SQLite disagree enough on the syntax that a
     * pushed-down query would pass in production and fail in the test suite.
     * The scan is bounded by time and by role, so it stays small.
     */
    private function findByWamid(int $projectId, string $wamid): ?Message
    {
        return Message::where('project_id', $projectId)
            ->where('role', 'assistant')
            ->where('created_at', '>=', time() - self::LOOKBACK_SECONDS)
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'metadata'])
            ->first(fn (Message $m) => data_get($m->metadata, 'wamid') === $wamid);
    }
}
