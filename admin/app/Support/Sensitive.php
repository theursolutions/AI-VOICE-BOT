<?php

namespace App\Support;

/**
 * Security guardrail for AI replies. Layered defense so the bot NEVER
 * surfaces credentials, secrets, connection details, API keys, or
 * model/infrastructure internals:
 *
 *   1. isSensitiveQuestion()  — refuse the turn outright before any query.
 *   2. redactRow()/isSensitiveColumn() — mask secret-looking columns so
 *      values never reach the LLM or the screen even if a query returns them.
 *   3. refusal()              — a soft, polite decline.
 *
 * Applied to the internal assistant AND the reference-data builders used
 * by the customer-facing bot. Deliberately conservative: better to refuse
 * a borderline ask than to leak.
 */
class Sensitive
{
    /** Question intents we decline without querying anything. */
    public static function isSensitiveQuestion(string $q): bool
    {
        $q = strtolower(trim($q));
        if ($q === '') {
            return false;
        }

        $patterns = [
            // passwords / secrets / keys / tokens / credentials
            '/\b(pass ?word|passwd|pwd)\b/',
            '/\b(api[ _-]?key|api[ _-]?secret|secret[ _-]?key|access[ _-]?key|private[ _-]?key|encryption[ _-]?key|client[ _-]?secret|jwt[ _-]?secret|webhook[ _-]?secret|internal[ _-]?secret)\b/',
            '/\b(secret|secrets|credential|credentials|auth[ _-]?token|access[ _-]?token)\b/',
            // database connection details
            '/\b(connection[ _-]?string|dsn)\b/',
            '/\b(db|database)[ _-]?(password|pass|user|username|name|host|hostname|port|credential|credentials|connection)\b/',
            '/\b(database|db) (password|pass|user|username|name|host|hostname|port|credentials|connection)\b/',
            // env / config
            '/\b(\.env|env(ironment)?[ _-]?(var|variable|variables|file)|config (secret|key|password))\b/',
            // AI model / provider / infrastructure
            '/\b(llm|language model|ai model|a\.i\. model)\b/',
            '/\b(model|provider|api)\b.{0,24}\b(are you|do you use|you use|you run|running|powered|built on|behind|based on)\b/',
            '/\b(what|which|name of).{0,24}\b(model|provider|ai|llm)\b.{0,24}\b(you|use|using|run|behind|power)\b/',
            '/\b(openai|anthropic|claude|groq|gemini|ollama|qwen|gpt-?\d|llama[ -]?\d|mistral)\b/',
            // server / host
            '/\b(server (ip|host|address)|host ?name|ip address)\b/',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $q)) {
                return true;
            }
        }
        return false;
    }

    /** A soft, polite refusal (kept short so it can be spoken aloud). */
    public static function refusal(): string
    {
        return "I'm sorry, but I can't share that — it's restricted information I'm not allowed to provide.";
    }

    /** Does a column name look like it holds a secret? */
    public static function isSensitiveColumn(string $name): bool
    {
        return (bool) preg_match(
            '/(pass(word|wd)?|pwd|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|encryption[_-]?key|client[_-]?secret|credential|jwt|salt|hash|ssn|cvv|card[_-]?number|account[_-]?number|auth[_-]?token)/i',
            $name
        );
    }

    /** Mask the values of secret-looking columns in a result row. */
    public static function redactRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = self::isSensitiveColumn((string) $k) ? '••••••' : $v;
        }
        return $out;
    }
}
