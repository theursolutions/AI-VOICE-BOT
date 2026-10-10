<?php

namespace App\Services\Voice;

use App\Models\Voice;

/**
 * The voice that speaks for a session or project: a ready-made Google
 * voice (by name) or a cloned voice (its reference audio, run on XTTS).
 * `voice` is the Voice row when there is one (a Google voice picked for an
 * agent has one; the project-level Google default does not).
 */
final class VoiceChoice
{
    public const GOOGLE = 'google';
    public const XTTS   = 'xtts';

    private function __construct(
        public readonly string $provider,
        public readonly ?string $googleVoice,
        public readonly ?Voice $voice,
        public readonly ?string $language,
    ) {}

    public static function google(string $name, ?Voice $voice = null): self
    {
        return new self(self::GOOGLE, $name, $voice, $voice?->language);
    }

    public static function cloned(?Voice $voice): self
    {
        return new self(self::XTTS, null, $voice, $voice?->language);
    }

    public static function fromVoice(Voice $voice): self
    {
        return $voice->isGoogle() ? self::google((string) $voice->external_id, $voice) : self::cloned($voice);
    }

    public function isGoogle(): bool
    {
        return $this->provider === self::GOOGLE;
    }

    /** Reference audio for a cloned voice; null for Google (or the engine's own default). */
    public function speakerWav(): ?string
    {
        return $this->isGoogle() ? null : $this->voice?->reference_url;
    }
}
