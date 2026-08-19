<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A model backend the platform can route a call to.
 *
 * Platform brains (client_id null) are the super admin's, ordered by priority
 * and shared by every client. A client brain belongs to one client, uses their
 * key and their bill. Both are the same shape, so the resolver walks one list.
 */
class AiBrain extends Model
{
    public const KIND_OPENAI_COMPAT = 'openai_compat';
    public const KIND_ANTHROPIC     = 'anthropic';
    public const KIND_OLLAMA        = 'ollama';

    /**
     * Providers offered in the UI, with the base_url and models pre-filled.
     *
     * Nearly all of these are one `kind` — openai_compat — because they all
     * speak the OpenAI chat-completions wire format. That is what makes
     * bring-your-own-brain a configuration row rather than a code change per
     * vendor: a client pasting a DeepSeek key gets a working brain through the
     * same path that serves Groq and Gemini.
     *
     * `custom` exists so a provider we have never heard of still works, as long
     * as it speaks that format. Anything genuinely different needs a new `kind`
     * and a backend in the voice-engine.
     */
    public const PRESETS = [
        'openai' => [
            'label'    => 'OpenAI',
            'kind'     => self::KIND_OPENAI_COMPAT,
            'base_url' => 'https://api.openai.com/v1',
            'models'   => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1'],
            'needs_key' => true,
        ],
        'deepseek' => [
            'label'    => 'DeepSeek',
            'kind'     => self::KIND_OPENAI_COMPAT,
            'base_url' => 'https://api.deepseek.com/v1',
            'models'   => ['deepseek-chat', 'deepseek-reasoner'],
            'needs_key' => true,
        ],
        'gemini' => [
            'label'    => 'Google Gemini',
            'kind'     => self::KIND_OPENAI_COMPAT,
            // Gemini's OpenAI-compatible surface, not the native one.
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
            'models'   => ['gemini-2.5-flash-lite', 'gemini-2.5-flash', 'gemini-2.5-pro'],
            'needs_key' => true,
        ],
        'groq' => [
            'label'    => 'Groq',
            'kind'     => self::KIND_OPENAI_COMPAT,
            'base_url' => 'https://api.groq.com/openai/v1',
            'models'   => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'],
            'needs_key' => true,
        ],
        'cerebras' => [
            'label'    => 'Cerebras',
            'kind'     => self::KIND_OPENAI_COMPAT,
            'base_url' => 'https://api.cerebras.ai/v1',
            'models'   => ['llama-3.3-70b', 'llama3.1-8b'],
            'needs_key' => true,
        ],
        'openrouter' => [
            'label'    => 'OpenRouter',
            'kind'     => self::KIND_OPENAI_COMPAT,
            'base_url' => 'https://openrouter.ai/api/v1',
            'models'   => ['meta-llama/llama-3.3-70b-instruct', 'deepseek/deepseek-chat'],
            'needs_key' => true,
        ],
        'together' => [
            'label'    => 'Together AI',
            'kind'     => self::KIND_OPENAI_COMPAT,
            'base_url' => 'https://api.together.xyz/v1',
            'models'   => ['meta-llama/Llama-3.3-70B-Instruct-Turbo'],
            'needs_key' => true,
        ],
        'anthropic' => [
            'label'    => 'Anthropic Claude',
            'kind'     => self::KIND_ANTHROPIC,
            'base_url' => null,
            'models'   => ['claude-haiku-4-5', 'claude-sonnet-4-5'],
            'needs_key' => true,
        ],
        'ollama' => [
            'label'    => 'Local model (on our server)',
            'kind'     => self::KIND_OLLAMA,
            'base_url' => 'http://voice-engine:11434',
            'models'   => ['qwen2.5:7b', 'llama3.1:8b'],
            'needs_key' => false,
        ],
        'custom' => [
            'label'    => 'Custom (OpenAI-compatible)',
            'kind'     => self::KIND_OPENAI_COMPAT,
            'base_url' => null,
            'models'   => [],
            'needs_key' => true,
        ],
    ];

    protected $fillable = [
        'client_id', 'name', 'kind', 'preset', 'base_url', 'model', 'api_key',
        'max_tokens', 'priority', 'is_active', 'is_verified', 'verified_at',
        'verify_error', 'quota_tokens', 'quota_window', 'tokens_used',
        'quota_reset_at', 'public_label', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'client_id'      => 'integer',
        'is_active'      => 'boolean',
        'is_verified'    => 'boolean',
        'max_tokens'     => 'integer',
        'priority'       => 'integer',
        'quota_tokens'   => 'integer',
        'tokens_used'    => 'integer',
        'quota_reset_at' => 'integer',
        'verified_at'    => 'integer',
        'created_at'     => 'integer',
        'updated_at'     => 'integer',
    ];

    public $timestamps = false;

    /**
     * Encrypted at rest, transparently.
     *
     * Decryption is wrapped because a key encrypted under a previous APP_KEY
     * cannot be recovered, and an exception here would take down every
     * conversation the brain serves. Returning null instead degrades to "this
     * brain has no key", which the resolver already handles by moving on.
     */
    public function setApiKeyAttribute($value): void
    {
        $this->attributes['api_key'] = ($value === null || $value === '')
            ? null
            : Crypt::encryptString((string) $value);
    }

    public function getApiKeyAttribute($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Last four characters, for showing a key exists without revealing it. */
    public function keyHint(): ?string
    {
        $key = $this->api_key;

        return $key ? '••••' . substr($key, -4) : null;
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /** Platform brains: the super admin's shared pool. */
    public function scopePlatform(Builder $q): Builder
    {
        return $q->whereNull('client_id');
    }

    public function scopeForClient(Builder $q, int $clientId): Builder
    {
        return $q->where('client_id', $clientId);
    }

    /**
     * Usable right now: switched on, proven by a real call, and under quota.
     *
     * Verification is part of "usable" deliberately. A brain that has never
     * completed a call has no business being first in a fallback chain.
     */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('is_verified', true);
    }

    /** Has this brain spent its allowance? Unlimited when quota_tokens is null. */
    public function isOverQuota(): bool
    {
        if ($this->quota_tokens === null) {
            return false;
        }

        $this->rollQuotaWindow();

        return $this->tokens_used >= $this->quota_tokens;
    }

    /**
     * Reset a monthly counter once its window has passed.
     *
     * Lazy rather than scheduled: a brain nobody uses does not need a cron job
     * to stay correct, and the reset has to be right at the moment of the check
     * regardless of whether a scheduler ran.
     */
    public function rollQuotaWindow(): void
    {
        if ($this->quota_window !== 'month' || $this->quota_tokens === null) {
            return;
        }

        $windowStart = (int) strtotime('first day of this month midnight');

        if ((int) $this->quota_reset_at < $windowStart) {
            $this->forceFill([
                'tokens_used'    => 0,
                'quota_reset_at' => $windowStart,
                'updated_at'     => time(),
            ])->save();
        }
    }

    /** Remaining allowance, or null when unlimited. */
    public function remainingTokens(): ?int
    {
        if ($this->quota_tokens === null) {
            return null;
        }

        $this->rollQuotaWindow();

        return max(0, $this->quota_tokens - $this->tokens_used);
    }

    /** Percentage of the allowance consumed, or null when unlimited. */
    public function quotaPercent(): ?int
    {
        if ($this->quota_tokens === null || $this->quota_tokens === 0) {
            return null;
        }

        return min(100, (int) round($this->tokens_used / $this->quota_tokens * 100));
    }

    /** What a client is allowed to see this brain called. */
    public function labelFor(?int $clientId): string
    {
        // Their own brain: they configured it, so they get the real name.
        if ($this->client_id !== null) {
            return $this->name;
        }

        // A platform brain: the neutral tier label, so our vendor and model —
        // and therefore our cost base — are not published to customers.
        return $this->public_label ?: 'Standard';
    }

    public function presetConfig(): array
    {
        return self::PRESETS[$this->preset] ?? self::PRESETS['custom'];
    }

    /**
     * Brand colour and monogram for a provider tile.
     *
     * Monograms rather than official logo paths, deliberately. Hand-authoring
     * nine brand SVGs produces subtly wrong shapes, and a mangled logo looks
     * worse than no logo — it reads as carelessness about someone else's mark.
     * A letter tile in the provider's own colour is recognisable, honest, and
     * cannot be wrong. Swap in official SVGs here when they are to hand;
     * everything downstream reads this one method.
     *
     * @return array{mark:string, color:string, tint:string}
     */
    public function brandTile(): array
    {
        return self::brandTileFor($this->preset ?? 'custom');
    }

    /** @return array{mark:string, color:string, tint:string} */
    public static function brandTileFor(?string $preset): array
    {
        $brands = [
            'openai'     => ['AI', '#10A37F'],
            'deepseek'   => ['DS', '#4D6BFE'],
            'gemini'     => ['G',  '#1A73E8'],
            'groq'       => ['GQ', '#F55036'],
            'cerebras'   => ['CB', '#F04E23'],
            'openrouter' => ['OR', '#6467F2'],
            'together'   => ['TG', '#0F6FFF'],
            'anthropic'  => ['A',  '#D97757'],
            'ollama'     => ['LM', '#0F172A'],
            'custom'     => ['··', '#64748B'],
        ];

        [$mark, $color] = $brands[$preset] ?? $brands['custom'];

        return [
            'mark'  => $mark,
            'color' => $color,
            // A 12%-alpha wash of the brand colour, so every tile sits at the
            // same weight instead of nine saturated blocks fighting each other.
            'tint'  => $color . '1F',
        ];
    }
}
