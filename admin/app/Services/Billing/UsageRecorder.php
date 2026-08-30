<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use Illuminate\Support\Facades\Log;

/**
 * Records metered usage from the product's hot paths.
 *
 * THE ONE RULE: metering must never break a conversation. Every public method
 * swallows its own exceptions and logs. An under-counted invoice is a problem
 * we can reconcile; a customer's AI agent throwing a 500 mid-reply because the
 * billing tables were briefly unreachable is not.
 *
 * WHAT COUNTS AS A "CONVERSATION": one session with at least one AI reply —
 * the same definition the pricing page uses, and the one buyers already know
 * from Tidio and Chatbase. Not per message: a twenty-turn chat is one
 * conversation. It is recorded when the FIRST assistant message lands in a
 * session, which is why the call sites are the two places an AI reply is
 * persisted rather than anywhere a message is created.
 *
 * Deliberately NOT counted:
 *   • human agent replies from the shared inbox — a person typing is not AI usage
 *   • tool/system messages
 *   • sessions that never got a reply (a bounced webhook, an abandoned widget)
 */
class UsageRecorder
{
    /** project id => Client|false. Memoised: a busy session hits this per turn. */
    private array $clients = [];

    public function __construct(private readonly UsageLimitService $usage)
    {
    }

    /**
     * Call immediately AFTER an assistant message has been persisted.
     *
     * Counts the conversation once, and counts a widget voice message when the
     * customer spoke rather than typed.
     */
    public function assistantReplied(Session $session, ?Message $userMessage = null): void
    {
        $this->safely(function () use ($session, $userMessage) {
            $client = $this->clientForProject((int) $session->project_id);

            if (! $client) {
                return;
            }

            // EVERY reply is one metered message.
            //
            // This is the unit our cost is actually incurred in. A reply costs
            // four LLM calls whether it is the first of a session or the two
            // hundredth, so a session-based allowance put no bound on spend at
            // all: a plan sold as "1,000 conversations" is 20,000 messages at
            // twenty turns and 100,000 at a hundred, and the same price had to
            // cover both. Every tier was underwater past a certain chattiness
            // and nothing in the product could tell you which customers those
            // were.
            $this->usage->record($client, 'messages', 1, (int) $session->project_id);

            // First AI reply in this session → one conversation.
            //
            // Kept, but as a STATISTIC rather than a limit: plans are still sold
            // in conversations because that is what a customer can picture, and
            // the count is what makes "1,000 conversations" checkable against
            // "20,000 messages". Whether it caps anything is decided entirely by
            // whether a feature row claims `metric_key = conversations` — see
            // UsageLimitService::allowanceFor(), which resolves the cap from the
            // data, so moving the ceiling from one unit to the other is a
            // migration and not a deploy.
            //
            // Counted after the insert and tested for exactly 1, so the same
            // session can't be counted twice however many turns follow. A
            // dead-heat between two concurrent first replies could double-count;
            // that costs the customer nothing and cannot happen in a real
            // back-and-forth.
            $assistantCount = Message::where('session_id', $session->id)
                ->where('role', 'assistant')
                ->count();

            if ($assistantCount === 1) {
                $this->usage->record($client, 'conversations', 1, (int) $session->project_id);
            }

            // A spoken message in the web widget. Phone calls are billed by the
            // minute instead — counting both would charge twice for one call.
            $spoken  = $userMessage && ! empty($userMessage->audio_url);
            $onPhone = in_array((string) $session->channel, ['phone', 'voice'], true);

            if ($spoken && ! $onPhone) {
                $this->usage->record($client, 'voice_messages', 1, (int) $session->project_id);
            }
        }, 'assistantReplied');
    }

    /**
     * Telephony, billed per minute.
     *
     * Twilio reports whole seconds on the completed-call webhook. Rounded UP
     * to the minute, which is how carriers bill us and therefore the only way
     * the allowance can cover its own cost. A 5-second wrong number is one
     * minute; that matches every telco bill anyone has ever read.
     */
    public function callCompleted(int $projectId, int $seconds): void
    {
        $this->safely(function () use ($projectId, $seconds) {
            if ($seconds <= 0) {
                return;
            }

            $client = $this->clientForProject($projectId);

            if (! $client) {
                return;
            }

            $this->usage->record($client, 'telephony_minutes', (int) ceil($seconds / 60), $projectId);
        }, 'callCompleted');
    }

    /** Pages added to the knowledge base by a data-source sync. */
    public function pagesIndexed(int $projectId, int $pages): void
    {
        $this->safely(function () use ($projectId, $pages) {
            if ($pages <= 0) {
                return;
            }

            $client = $this->clientForProject($projectId);

            if ($client) {
                $this->usage->record($client, 'indexed_pages', $pages, $projectId);
            }
        }, 'pagesIndexed');
    }

    /** Stored bytes, as a running total (an absolute metric, not per period). */
    public function storageUsed(int $projectId, int $megabytes): void
    {
        $this->safely(function () use ($projectId, $megabytes) {
            if ($megabytes <= 0) {
                return;
            }

            $client = $this->clientForProject($projectId);

            if ($client) {
                $this->usage->record($client, 'storage_mb', $megabytes, $projectId);
            }
        }, 'storageUsed');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * The billable workspace behind a project.
     *
     * `Project` and `Client` both pin the master connection, so this is safe
     * to call while the tenant connection is swapped in — which it always is
     * on these code paths.
     */
    private function clientForProject(int $projectId): ?Client
    {
        if (array_key_exists($projectId, $this->clients)) {
            return $this->clients[$projectId] ?: null;
        }

        $clientId = Project::whereKey($projectId)->value('client_id');
        $client   = $clientId ? Client::find($clientId) : null;

        $this->clients[$projectId] = $client ?: false;

        return $client;
    }

    /** Run a metering block, never letting it escape into the caller. */
    private function safely(callable $fn, string $context): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("usage.record.{$context}_failed", ['error' => $e->getMessage()]);
        }
    }
}
