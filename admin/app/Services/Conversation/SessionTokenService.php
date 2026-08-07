<?php

namespace App\Services\Conversation;

use App\Models\Session;
use App\Models\Voice;

class SessionTokenService
{
    public function mint(Session $session): string
    {
        $secret = config('services.python.jwt_secret');
        $ttl    = (int) config('services.python.token_ttl', 3600);

        // Resolve voice → speaker WAV path + language so Python doesn't
        // need to round-trip back into Laravel to look it up. If the
        // session has no voice_id, fall back to the project's default
        // voice (the one with metadata.is_default=true); if none, the
        // claims simply omit speaker_wav and Python uses the global
        // DEFAULT_SPEAKER_WAV from its .env.
        $voice = $this->resolveVoice($session);

        // Language precedence: the visitor's explicit pick (widget header
        // dropdown, stored on the session) wins, then the bound voice's
        // language, then the project/global default. This is only the
        // *fallback* language — the model still mirrors whatever language
        // the user actually writes/speaks.
        $language = data_get($session->metadata, 'language')
            ?: ($voice?->language ?? config('services.voice.default_language', 'en'));

        $payload = [
            'iat'        => time(),
            'exp'        => time() + $ttl,
            'session_id' => $session->id,
            'project_id' => $session->project_id,
            'channel'    => $session->channel,
            'voice_id'   => $voice?->id,
            'speaker_wav'=> $voice?->reference_url,
            'language'   => $language,
        ];

        $header  = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = $this->b64(json_encode($payload));
        $sig     = $this->b64(hash_hmac('sha256', $header.'.'.$body, $secret, true));

        return "$header.$body.$sig";
    }

    private function resolveVoice(Session $session): ?Voice
    {
        if ($session->voice_id) {
            $v = Voice::find($session->voice_id);
            if ($v && $v->status === 'ready') return $v;
        }

        // Project default: voices with metadata.is_default=true.
        // Falls back to the most-recently-created ready voice.
        $all = Voice::where('project_id', $session->project_id)
            ->where('status', 'ready')
            ->orderByDesc('id')
            ->get();
        foreach ($all as $v) {
            $meta = $v->metadata ?? [];
            if (!empty($meta['is_default'])) return $v;
        }
        return $all->first();
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
