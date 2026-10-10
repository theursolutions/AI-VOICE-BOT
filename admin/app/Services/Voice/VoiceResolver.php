<?php

namespace App\Services\Voice;

use App\Models\Project;
use App\Models\Session;
use App\Models\Voice;

/**
 * Which voice speaks — one answer for calls, the web widget, Twilio
 * greetings and Flow prompts. Expects the project's tenant DB selected.
 *
 * In order:
 *   1. the session's own voice (cloned from its agent's choice);
 *   2. the project's "Response voice" setting on the Voices page
 *      (json_data.voice: source google|own, google_voice, voice_id);
 *   3. a cloned voice starred as default before that setting existed;
 *   4. the platform default — a Google voice (services.voice.default_*).
 */
class VoiceResolver
{
    public function forSession(Session $session): VoiceChoice
    {
        if ($session->voice_id) {
            $voice = Voice::find($session->voice_id);
            if ($voice && $voice->isUsable()) {
                return VoiceChoice::fromVoice($voice);
            }
        }

        return $this->forProject($session->project);
    }

    public function forProject(?Project $project): VoiceChoice
    {
        $setting = (array) data_get($project?->json_data, 'voice', []);

        if (($setting['source'] ?? null) === 'own' && ! empty($setting['voice_id'])) {
            $voice = Voice::find((int) $setting['voice_id']);
            if ($voice && $voice->isUsable()) {
                return VoiceChoice::fromVoice($voice);
            }
        }

        if (($setting['source'] ?? null) === 'google') {
            return VoiceChoice::google($this->googleVoiceName($setting['google_voice'] ?? null));
        }

        if ($project) {
            foreach ($this->usableVoices($project) as $voice) {
                if (! empty(($voice->metadata ?? [])['is_default'])) {
                    return VoiceChoice::fromVoice($voice);
                }
            }
        }

        if (config('services.voice.default_source', 'google') === 'google') {
            return VoiceChoice::google($this->googleVoiceName(null));
        }

        $newest = $project ? $this->usableVoices($project)->first(fn (Voice $v) => ! $v->isGoogle()) : null;

        return VoiceChoice::cloned($newest);
    }

    /** A known Google voice name, else the configured default. */
    public function googleVoiceName(?string $name): string
    {
        $voices = (array) config('services.voice.google_voices', []);

        return ($name && isset($voices[$name]))
            ? $name
            : (string) config('services.voice.default_google_voice', 'Kore');
    }

    private function usableVoices(Project $project)
    {
        return Voice::where('project_id', $project->id)
            ->where('status', 'ready')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Voice $v) => $v->isUsable())
            ->values();
    }
}
