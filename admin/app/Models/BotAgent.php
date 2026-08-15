<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

/**
 * AI Agent persona — the entity that "talks" to the customer in chat
 * or on a call. Each project can have many; each one has its own
 * voice (cloned WAV), persona/system-prompt, and a set of skills it
 * handles. Routing picks one of these per session.
 *
 * Distinct from App\Models\Agent (Tier 3b "Query Agent" — that's a
 * customer-hosted infrastructure agent that runs SQL on their box).
 * Same word, different concept. This one lives in the tenant DB.
 */
class BotAgent extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'agents';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    // An agent is either an AI persona or a real person who takes over chats.
    public const TYPE_AI    = 'ai';
    public const TYPE_HUMAN = 'human';

    public const PRESENCE_ONLINE  = 'online';
    public const PRESENCE_AWAY    = 'away';
    public const PRESENCE_OFFLINE = 'offline';

    /**
     * Channels an agent can be assigned to.
     *
     * `web` and `phone` are included so one agent can span the whole
     * business — the point of this is that an agent has MANY channels, not
     * that Meta channels are special.
     */
    public const CHANNELS = [
        'whatsapp'  => 'WhatsApp',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'web'       => 'Web chat',
        'phone'     => 'Phone',
    ];

    /**
     * What an agent is permitted to do, per capability.
     *
     * Every key maps 1:1 to a real endpoint on ChatController, which is what
     * makes these permissions rather than decoration — the gate is enforced
     * server-side, and the UI merely reflects it.
     *
     *   key => [label, applies to: 'ai' | 'human' | 'both']
     *
     * Defaults are ALL ON. An existing workspace upgrading to this must
     * behave exactly as it did before; restrictions are something the owner
     * opts into, never something a deploy imposes.
     */
    public const CAPABILITIES = [
        'send_text'       => ['Send text replies',        'both'],
        'send_media'      => ['Send images and files',    'both'],
        'send_voice'      => ['Send voice notes',         'both'],
        'quick_replies'   => ['Send quick-reply buttons', 'both'],
        'send_template'   => ['Send WhatsApp templates',  'both'],
        'send_flow'       => ['Send WhatsApp Flows',      'both'],
        'send_catalog'    => ['Send catalog products',    'both'],
        'transfer'        => ['Transfer conversations',   'human'],
        'set_status'      => ['Set conversation status',  'human'],
        'manage_statuses' => ['Create and edit statuses', 'human'],
        'toggle_bot'      => ['Pause or resume the AI',   'human'],
        'resolve'         => ['Resolve conversations',    'human'],
    ];

    protected $fillable = [
        'project_id', 'name', 'voice_id', 'persona',
        'is_default', 'status', 'metadata',
        'type', 'user_id', 'presence', 'max_active_chats',
    ];

    protected $casts = [
        'is_default'       => 'boolean',
        'metadata'         => 'array',
        'voice_id'         => 'integer',
        'user_id'          => 'integer',
        'max_active_chats' => 'integer',
        'created_at'       => 'integer',
        'update_at'        => 'integer',
        'deleted_at'       => 'integer',
    ];

    public $timestamps = false;

    public function voice()
    {
        return $this->belongsTo(Voice::class);
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'agent_skills', 'agent_id', 'skill_id')
                    ->withPivot('priority');
    }

    public function isHuman(): bool
    {
        return $this->type === self::TYPE_HUMAN;
    }

    /** Human agents that are active + online and can receive a chat. */
    public function scopeAvailableHuman($query)
    {
        return $query->where('type', self::TYPE_HUMAN)
            ->where('status', self::STATUS_ACTIVE)
            ->where('presence', self::PRESENCE_ONLINE);
    }

    /** Count of chats this human is actively handling right now. */
    public function activeChatCount(): int
    {
        return Session::where('assigned_agent_id', $this->id)
            ->where('handoff_status', 'assigned')
            ->where('status', 'active')
            ->count();
    }

    public function atCapacity(): bool
    {
        return $this->activeChatCount() >= max(1, (int) ($this->max_active_chats ?: 3));
    }

    // ── Channels ─────────────────────────────────────────────────────

    /**
     * Channels this agent handles.
     *
     * An unset value means ALL channels, not none. That distinction is the
     * whole upgrade path: every agent that existed before this feature has
     * no `channels` key, and must keep receiving everything it did
     * yesterday rather than silently going deaf.
     *
     * @return array<int,string>
     */
    public function channels(): array
    {
        $set = data_get($this->metadata, 'channels');

        if (! is_array($set) || $set === []) {
            return array_keys(self::CHANNELS);
        }

        return array_values(array_intersect($set, array_keys(self::CHANNELS)));
    }

    public function handlesChannel(?string $channel): bool
    {
        if (! $channel) {
            return true;
        }

        // Messenger and Facebook are one channel to a human choosing who
        // answers; sessions store `facebook`, the provider says
        // `facebook_page`, and nobody configuring this should have to know.
        $channel = in_array($channel, ['messenger', 'facebook_page'], true) ? 'facebook' : $channel;

        return in_array($channel, $this->channels(), true);
    }

    // ── Capabilities ─────────────────────────────────────────────────

    /**
     * Is this agent allowed to do something?
     *
     * Unknown keys return true deliberately. A capability added in a later
     * release must not retroactively forbid an action for every existing
     * agent — the failure mode of a permission system that fails closed on
     * upgrade is a support queue, not security.
     */
    public function can(string $capability): bool
    {
        if (! isset(self::CAPABILITIES[$capability])) {
            return true;
        }

        $value = data_get($this->metadata, "capabilities.{$capability}");

        return $value === null ? true : (bool) $value;
    }

    /**
     * The full capability map, filled in with defaults — for the settings UI
     * and for the chat console, which hides controls the agent cannot use.
     *
     * @return array<string,bool>
     */
    public function capabilities(): array
    {
        $out = [];
        foreach (self::CAPABILITIES as $key => [$label, $appliesTo]) {
            $out[$key] = $this->can($key);
        }

        return $out;
    }

    /** Capabilities relevant to this agent's type, for rendering the form. */
    public static function capabilitiesFor(string $type): array
    {
        return array_filter(
            self::CAPABILITIES,
            fn ($def) => $def[1] === 'both' || $def[1] === $type,
        );
    }
}
