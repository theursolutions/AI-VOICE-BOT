<?php

namespace App\Services\Telephony;

use App\Models\Project;
use App\Models\Voice;
use Illuminate\Support\Facades\Log;

/**
 * Generate (and cache) the phone-call welcome audio in the project's
 * cloned voice so the very FIRST thing the caller hears is the bot's
 * own voice — not Twilio's Polly stand-in.
 *
 * Cache key: hash(welcome_text + speaker_wav_path). When either
 * changes (admin updates the welcome message in Widget Settings or
 * stars a different voice), the next call lazily regenerates.
 *
 * Output lives at voice-engine/voice_outputs/welcomes/project_N_<hash>.wav
 * and is served by Python's static /voice mount as
 * <PYTHON_PUBLIC_URL>/voice/welcomes/project_N_<hash>.wav.
 */
class WelcomeAudioService
{
    /**
     * Return a public HTTPS URL Twilio can <Play>, or null if we can't
     * generate one (no voice configured, Python down, etc.). Callers
     * fall back to TwiML <Say> in that case.
     */
    public function urlForProject(Project $project, string $welcomeText): ?string
    {
        $voice = $this->resolveDefaultVoice($project);
        if (!$voice || empty($voice->reference_url)) {
            Log::info('WelcomeAudioService: no voice for project, falling back to Say', [
                'project_id' => $project->id,
            ]);
            return null;
        }

        $speakerPath = $voice->reference_url;
        if (!is_file($speakerPath)) {
            Log::warning('WelcomeAudioService: speaker_wav not found on disk', [
                'project_id' => $project->id, 'path' => $speakerPath,
            ]);
            return null;
        }

        // Cache filename embeds a hash of (text + speaker path) so
        // changing the welcome message OR the voice forces regen.
        $cacheKey = sha1($welcomeText . '|' . $speakerPath);
        $filename = "project_{$project->id}_{$cacheKey}.wav";

        $welcomesDir = $this->welcomesDir();
        if (!is_dir($welcomesDir)) {
            @mkdir($welcomesDir, 0775, true);
        }
        $absPath = $welcomesDir . DIRECTORY_SEPARATOR . $filename;

        // Cache hit — return URL immediately.
        if (is_file($absPath) && filesize($absPath) > 0) {
            return $this->publicUrl($filename);
        }

        // Cache miss — synthesize via Python's /tts endpoint.
        $ok = $this->synthesizeViaPython($welcomeText, $speakerPath, $absPath, $voice->language ?? 'en');
        if (!$ok || !is_file($absPath)) {
            return null;
        }

        // Clean up stale cached welcomes for the same project so the
        // disk doesn't accumulate every voice/welcome combination.
        $this->purgeStaleWelcomes($project->id, $filename);

        return $this->publicUrl($filename);
    }

    /** Wipe all cached welcomes for a project — call when settings change. */
    public function invalidateForProject(int $projectId): int
    {
        $welcomesDir = $this->welcomesDir();
        if (!is_dir($welcomesDir)) return 0;

        $deleted = 0;
        foreach (glob($welcomesDir . DIRECTORY_SEPARATOR . "project_{$projectId}_*.wav") ?: [] as $file) {
            if (@unlink($file)) $deleted++;
        }
        return $deleted;
    }

    private function resolveDefaultVoice(Project $project): ?Voice
    {
        $voices = Voice::where('project_id', $project->id)
            ->where('status', 'ready')
            ->orderByDesc('id')
            ->get();
        foreach ($voices as $v) {
            $meta = $v->metadata ?? [];
            if (!empty($meta['is_default'])) return $v;
        }
        return $voices->first();
    }

    /**
     * POST the text + speaker file to Python's /tts endpoint and save
     * the returned wav to $outPath. Returns true on success.
     *
     * Uses multipart/form-data because the /tts route expects a file
     * upload for `speaker_wav`. We could add an internal JSON endpoint
     * later if this becomes a perf concern; for now the upload is fine
     * (it only runs once per (text, voice) combo).
     */
    private function synthesizeViaPython(string $text, string $speakerPath, string $outPath, string $language): bool
    {
        $base = rtrim((string) config('services.python.base_url'), '/');
        $url  = $base . '/tts';

        $ch = curl_init($url);
        $cfile = new \CURLFile($speakerPath, 'audio/wav', basename($speakerPath));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 60,    // welcome synth can be slow on CPU
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => [
                'text'        => $text,
                'language'    => $language,
                'speaker_wav' => $cfile,
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || $code !== 200 || $body === false || $body === '') {
            Log::warning('WelcomeAudioService: /tts synth failed', [
                'http' => $code, 'curl_error' => $err, 'body_len' => strlen((string) $body),
            ]);
            return false;
        }

        $written = file_put_contents($outPath, $body);
        return $written !== false && $written > 0;
    }

    private function purgeStaleWelcomes(int $projectId, string $keepFilename): void
    {
        $welcomesDir = $this->welcomesDir();
        foreach (glob($welcomesDir . DIRECTORY_SEPARATOR . "project_{$projectId}_*.wav") ?: [] as $file) {
            if (basename($file) !== $keepFilename) {
                @unlink($file);
            }
        }
    }

    private function welcomesDir(): string
    {
        // voice-engine/voice_outputs/welcomes — Python's /voice mount
        // serves it as <PYTHON_PUBLIC_URL>/voice/welcomes/...
        return realpath(base_path('../voice-engine/voice_outputs'))
            . DIRECTORY_SEPARATOR . 'welcomes';
    }

    private function publicUrl(string $filename): string
    {
        // Twilio fetches <Play> URLs from the public internet, so this
        // must be the ngrok HTTPS URL — NOT 127.0.0.1. Derive from the
        // ws_url if PYTHON_PUBLIC_URL isn't set explicitly (since they
        // share the same tunnel).
        $explicit = (string) config('services.twilio.python_public_url');
        if ($explicit) {
            $prefix = rtrim($explicit, '/');
        } else {
            // ws://host/path or wss://host/path → http(s)://host (drop path)
            $ws = (string) config('services.python.ws_url');
            $parsed = parse_url($ws);
            if ($parsed && !empty($parsed['host'])) {
                $scheme = (($parsed['scheme'] ?? 'ws') === 'wss') ? 'https' : 'http';
                $port = !empty($parsed['port']) ? ':'.$parsed['port'] : '';
                $prefix = $scheme . '://' . $parsed['host'] . $port;
            } else {
                $prefix = 'http://127.0.0.1:8002';  // dev fallback (Twilio won't reach this — only useful locally)
            }
        }
        return $prefix . '/voice/welcomes/' . rawurlencode($filename);
    }
}
