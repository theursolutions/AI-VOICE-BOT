<?php

namespace Tests\Feature\LeadGen\Fakes;

use App\Services\Conversation\PythonClient;
use RuntimeException;

/**
 * Stands in for the voice-engine's /llm/structured. Answers are queued in
 * order; when the queue runs dry the last answer repeats. Test-only.
 */
class FakePythonClient extends PythonClient
{
    /** @var array<int, array{prompt:string, schema:array, options:array}> */
    public array $calls = [];
    public bool $fail = false;

    public function __construct(public array $answers = []) {}

    public function structured(string $prompt, array $schema, array $options = []): array
    {
        $this->calls[] = compact('prompt', 'schema', 'options');
        if ($this->fail) {
            throw new RuntimeException('engine down');
        }
        $data = count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? []);
        return ['data' => $data, 'valid' => true, 'errors' => [], 'tokens_in' => 10, 'tokens_out' => 5, 'model' => 'fake-llm'];
    }
}
