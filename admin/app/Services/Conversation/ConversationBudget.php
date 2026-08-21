<?php

namespace App\Services\Conversation;

use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use Illuminate\Support\Facades\Cache;

/**
 * How many AI replies one conversation gets before a person takes over.
 *
 * WHY THIS EXISTS. Plans are sold in conversations because that is the unit a
 * customer can picture, but billed in messages because that is the unit the cost
 * is incurred in — a reply costs four LLM calls whether it is the first of a
 * session or the two hundredth. The two only reconcile if a conversation has a
 * bounded number of replies in it. "1,000 conversations × 20 messages = 20,000
 * messages" is arithmetic that holds because of this class; without it the
 * left-hand side is a guess and one talkative customer can spend a whole
 * month's allowance.
 *
 * It is also the more honest product. A bot that has gone twenty turns without
 * resolving something is not about to resolve it on the twenty-first, and the
 * customer would rather have a person. Reaching the cap is a handoff, never a
 * dead end — the conversation goes to the inbox flagged for a human, and the
 * customer is told so.
 *
 * The limit is the WORKSPACE OWNER's to set, not ours. Their tradeoff: a low cap
 * spends human time and stretches the message allowance across more
 * conversations, a high one lets the AI work longer and burns the allowance
 * faster. We only supply the default and refuse the absurd.
 *
 * COUNTS ASSISTANT REPLIES, deliberately the same thing UsageRecorder meters.
 * If these two ever count different things the plan arithmetic silently stops
 * holding, and the symptom — allowances running out early — appears nowhere near
 * the cause.
 */
class ConversationBudget
{
    /**
     * Replies per conversation when the owner has not chosen.
     *
     * Twenty is the figure the published plans are priced against, so changing
     * this constant changes what every un-configured workspace consumes and
     * therefore the margin on every tier. It is not a UI preference.
     */
    public const DEFAULT_LIMIT = 20;

    /**
     * Floor and ceiling on the owner's choice.
     *
     * The floor is not paternalism: below about five replies the bot cannot
     * complete a greeting, a question and an answer, so every conversation would
     * escalate and the AI would appear broken rather than restrained. The
     * ceiling is where one conversation stops being a conversation and starts
     * being an unbounded bill — at 200 replies a single session can consume a
     * whole Starter allowance.
     */
    public const MIN_LIMIT = 5;
    public const MAX_LIMIT = 200;

    /** Where the owner's choice lives on the client record. */
    public const SETTING_KEY = 'messages_per_conversation';

    private const CACHE_TTL = 300;

    /**
     * The limit for a project's workspace.
     *
     * Cached briefly because this is read on every inbound message, and the
     * value changes about once in a workspace's lifetime.
     */
    public function limitFor(?int $projectId): int
    {
        if (! $projectId) {
            return self::DEFAULT_LIMIT;
        }

        return (int) Cache::remember(
            "conv-budget:{$projectId}",
            self::CACHE_TTL,
            function () use ($projectId) {
                $project = Project::find($projectId);

                if (! $project) {
                    return self::DEFAULT_LIMIT;
                }

                return self::clamp(
                    data_get(Client::find($project->client_id)?->json_data, self::SETTING_KEY)
                );
            }
        );
    }

    /**
     * Coerce a stored or submitted value into a usable limit.
     *
     * is_numeric rather than a cast, for the reason every other knob in this
     * codebase is guarded the same way: a blank value casts to 0, and a limit of
     * zero would hand every conversation to a human on its first message — the
     * AI would look completely dead, from an empty string in a settings blob.
     */
    public static function clamp($value): int
    {
        if (! is_numeric($value)) {
            return self::DEFAULT_LIMIT;
        }

        return max(self::MIN_LIMIT, min(self::MAX_LIMIT, (int) $value));
    }

    /** AI replies already sent in this conversation. */
    public function repliesIn(Session $session): int
    {
        return Message::where('session_id', $session->id)
            ->where('role', 'assistant')
            ->whereNotNull('content')
            ->count();
    }

    /**
     * Has this conversation used up its replies?
     *
     * Compared with >=, so a limit of 20 permits exactly 20 replies and the
     * twenty-first inbound message is the one that escalates.
     */
    public function reached(Session $session): bool
    {
        return $this->repliesIn($session) >= $this->limitFor((int) $session->project_id);
    }

    /** After the owner changes the setting, so the next message sees it. */
    public static function forget(int $projectId): void
    {
        Cache::forget("conv-budget:{$projectId}");
    }

    /** Every project in a workspace, since the setting is workspace-wide. */
    public static function forgetClient(int $clientId): void
    {
        foreach (Project::where('client_id', $clientId)->pluck('id') as $id) {
            self::forget((int) $id);
        }
    }
}
