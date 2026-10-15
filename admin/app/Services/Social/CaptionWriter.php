<?php

namespace App\Services\Social;

use App\Models\Project;
use App\Services\Conversation\BrainResolver;
use App\Services\Conversation\PythonClient;

/**
 * Writes social captions with the project's own brain.
 *
 * Routed through BrainResolver + PythonClient::llm() like every other LLM
 * call, so a client on their own key is billed on their key and the tokens
 * land in ai_brain_usage.
 *
 * Grounded in the Project Profile (name, industry, about, website) — without
 * it the model writes generic copy that could belong to any business.
 */
class CaptionWriter
{
    /** Instagram's hard limit; Facebook allows far more but nobody reads it. */
    public const MAX_CHARS = 2200;

    public function __construct(
        private PythonClient $python,
        private BrainResolver $brains,
    ) {}

    /**
     * @param string   $brief      what the post is about (may be empty when rewriting)
     * @param string   $draft      an existing caption to improve, or ''
     * @param string[] $platforms  e.g. ['Facebook', 'Instagram']
     */
    public function write(Project $project, string $brief, string $draft, array $platforms, string $format, string $tone, string $language): string
    {
        $profile = (array) data_get($project->json_data, 'profile', []);

        $business = array_filter([
            'Business'  => $project->name,
            'Industry'  => $profile['industry'] ?? null,
            'About'     => $profile['about'] ?? null,
            'Website'   => $profile['website'] ?? null,
            'Phone'     => $profile['support_phone'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        $facts = collect($business)->map(fn ($v, $k) => "{$k}: " . trim($v))->implode("\n");
        $where = $platforms ? implode(' and ', $platforms) : 'Facebook and Instagram';
        $kind  = match ($format) {
            'reel'  => 'a short-form video (Reel)',
            'story' => 'a Story',
            default => 'a feed post',
        };

        $system = <<<TXT
You write social media captions for a business.

{$facts}

Rules:
- Write ONE caption for {$kind} on {$where}.
- Tone: {$tone}.
- Language: {$language}. Write the whole caption in that language.
- Open with a hook in the first line. Keep it skimmable: short lines, at most 2-3 emojis.
- End with a clear call to action, then 3 to 8 relevant hashtags on the last line.
- Never invent prices, discounts, dates, phone numbers or claims that are not in the brief or the business details.
- Under 1,200 characters.
- Output ONLY the caption text. No quotes, no title, no explanations.
TXT;

        $user = trim($draft) !== ''
            ? "Improve this caption. Keep its facts and offer.\n\n" . trim($draft) . (trim($brief) !== '' ? "\n\nExtra direction: " . trim($brief) : '')
            : 'Write the caption. The post is about: ' . trim($brief);

        $options = $this->brains->optionsFor($project->id, BrainResolver::CALL_REPLY);
        if (! $options) {
            // No brain configured — use the platform's capable model, the same
            // fallback the Team Assistant uses, rather than the small chat model.
            $options = array_filter([
                'provider' => (string) config('services.python.reasoning_provider', ''),
                'model'    => (string) config('services.python.reasoning_model', ''),
            ]);
        }
        $options['project_id']   = $project->id;
        $options['respond_with'] = 'text';
        $options['call_type']    = BrainResolver::CALL_REPLY;
        $options['temperature']  = 0.8;
        $options['max_tokens']   = 700;

        $resp = $this->python->llm([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], $options);

        return $this->clean((string) ($resp['text'] ?? ''));
    }

    /** Strip wrappers small models add despite being told not to. */
    private function clean(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^(here(\'s| is)[^\n]*:\s*\n+|caption:\s*)/i', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"“”");

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
