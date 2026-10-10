<?php

namespace App\Services\Conversation;

use App\Models\Session;
use App\Services\Voice\VoiceResolver;

class SessionTokenService
{
    public function __construct(private VoiceResolver $voices) {}

    public function mint(Session $session): string
    {
        $secret = config('services.python.jwt_secret');
        $ttl    = (int) config('services.python.token_ttl', 3600);

        // Resolve the voice here so Python doesn't need to round-trip back
        // into Laravel: a Google voice travels as its name, a cloned voice
        // as the path of its reference audio. See VoiceResolver for the
        // order (agent → project setting → default star → Google default).
        $choice = $this->voices->forSession($session);

        // Language precedence: the visitor's explicit pick (widget header
        // dropdown, stored on the session) wins, then the bound voice's
        // language, then the project/global default. This is only the
        // *fallback* language — the model still mirrors whatever language
        // the user actually writes/speaks.
        $language = data_get($session->metadata, 'language')
            ?: ($choice->language ?? config('services.voice.default_language', 'en'));

        $payload = [
            'iat'          => time(),
            'exp'          => time() + $ttl,
            'session_id'   => $session->id,
            'project_id'   => $session->project_id,
            'channel'      => $session->channel,
            'voice_id'     => $choice->voice?->id,
            'speaker_wav'  => $choice->speakerWav(),
            'language'     => $language,
            'tts_provider' => $choice->provider,
            'tts_voice'    => $choice->googleVoice,
        ];

        $header  = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = $this->b64(json_encode($payload));
        $sig     = $this->b64(hash_hmac('sha256', $header.'.'.$body, $secret, true));

        return "$header.$body.$sig";
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
