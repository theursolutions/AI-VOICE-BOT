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

    /**
     * Stands in for "no cap" inside the cache.
     *
     * Cache::remember treats a null payload as a miss and re-resolves on every
     * single call, which on the reply path means two queries per inbound message
     * for the one plan that needs none.
     */
    private const NO_LIMIT = 'none';

    private const CACHE_TTL = 300;

    /**
     * The limit for a project's workspace. NULL means no automatic handoff.
     *
     * Three sources, in order:
     *
     *   1. the owner's own setting, if they have chosen one
     *   2. their plan's `replies_per_conversation`, which is what each tier is
     *      priced and advertised against
     *   3. DEFAULT_LIMIT
     *
     * The plan tier has to sit in the middle, not be replaced by a single global
     * default. Growth's 75,000 messages divided by a fixed 20 advertises 3,750
     * conversations where the plan was designed and sold as 2,500 — the cards
     * would contradict the arithmetic they came from.
     *
     * Cached briefly: read on every inbound message, changed about once in a
     * workspace's lifetime.
     */
    public function limitFor(?int $projectId): ?int
    {
        if (! $projectId) {
            return self::DEFAULT_LIMIT;
        }

        $value = Cache::remember(
            "conv-budget:{$projectId}",
            self::CACHE_TTL,
            function () use ($projectId) {
                $project = Project::find($projectId);

                if (! $project) {
                    return self::DEFAULT_LIMIT;
                }

                return self::resolveFor(Client::find($project->client_id));
            }
        );

        // Cache::remember cannot store null (it would re-resolve every call), so
        // "no cap" round-trips as a sentinel.
        return $value === self::NO_LIMIT ? null : (int) $value;
    }

    /**
     * The limit for a workspace, uncached. NULL means no automatic handoff.
     *
     * The billing pages read through here rather than reaching for the stored
     * setting directly. They used to, and it was wrong the moment tiers gained
     * their own default: a Growth workspace that had never touched the control
     * would have been shown 20 while its conversations actually ran to 30, so
     * the page contradicted both the plan card and the behaviour.
     */
    public function limitForClient(?Client $client): ?int
    {
        $value = self::resolveFor($client);

        return $value === self::NO_LIMIT ? null : (int) $value;
    }

    /** Owner's choice, else the plan's, else the coded default. */
    private static function resolveFor(?Client $client): int|string
    {
        if (! $client) {
            return self::DEFAULT_LIMIT;
        }

        $own = data_get($client->json_data, self::SETTING_KEY);

        if (is_numeric($own)) {
            return self::clamp($own);
        }

        return self::planDefaultFor($client);
    }

    /**
     * The tier's starting figure.
     *
     * Two values need care, and both would be silent:
     *
     *   null    the plan grants unlimited replies (-1, i.e. Enterprise). Real,
     *           and means no automatic handoff — returned as a sentinel because
     *           the cache cannot hold null.
     *   0       the plan has NO replies_per_conversation row. planLimit() reads
     *           an absent feature as "not granted" rather than "unset", so this
     *           is a plan that predates the feature, not a plan that wants a cap
     *           of zero. Falling through clamp() would give it 5 — every
     *           conversation escalating after five replies, from a missing row.
     */
    private static function planDefaultFor(Client $client): int|string
    {
        $plan = $client->currentPlan();

        if (! $plan) {
            return self::DEFAULT_LIMIT;
        }

        $limit = app(\App\Services\Billing\PlanFeatureService::class)
            ->planLimit($plan, 'replies_per_conversation');

        if ($limit === null) {
            return self::NO_LIMIT;
        }

        return $limit > 0 ? self::clamp($limit) : self::DEFAULT_LIMIT;
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
     *
     * A null limit is a plan with unlimited replies — never escalates on count.
     * Checked before the count so an uncapped workspace does not pay for a query
     * whose answer cannot matter.
     */
    public function reached(Session $session): bool
    {
        $limit = $this->limitFor((int) $session->project_id);

        if ($limit === null) {
            return false;
        }

        return $this->repliesIn($session) >= $limit;
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
