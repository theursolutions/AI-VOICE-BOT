<?php

namespace Tests\Feature;

use App\Models\BotAgent;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\MemoryBuilder;
use App\Services\Telephony\CallGreeting;
use Tests\TestCase;

/**
 * Phone and SIP calls used to run with NO system prompt: the caller got a
 * generic assistant that knew neither the business nor the agent persona.
 * The voice engine now fetches MemoryBuilder::voicePrompt() through
 * /api/internal/call-profile at the start of every call.
 */
class CallProfilePromptTest extends TestCase
{
    private function callSession(): Session
    {
        $project = new Project();
        $project->name = 'Acme Foods';
        $project->json_data = ['profile' => ['industry' => 'FMCG distribution']];

        $session = new Session(['customer_phone' => '+923001234567']);
        $session->id = 0;   // no lead lookup will match
        $session->metadata = ['language' => 'ur'];
        $session->setRelation('project', $project);
        $session->setRelation('summary', null);

        return $session;
    }

    public function test_a_call_hears_the_business_the_caller_and_the_language_rule(): void
    {
        $prompt = (new MemoryBuilder())->voicePrompt($this->callSession());

        $this->assertStringContainsString('Acme Foods', $prompt);
        $this->assertStringContainsString('FMCG distribution', $prompt);
        // Caller ID is already known — the agent must not ask for it.
        $this->assertStringContainsString('+923001234567', $prompt);
        $this->assertStringContainsString('Urdu', $prompt);
    }

    private function promptFor(array $call): string
    {
        $session = $this->callSession();
        $session->metadata = ['language' => 'auto', 'call' => $call];

        return (new MemoryBuilder())->voicePrompt($session);
    }

    public function test_auto_detect_answers_each_caller_in_their_own_language(): void
    {
        $prompt = $this->promptFor(['language' => 'roman', 'auto_detect' => true]);

        $this->assertStringContainsString('reply in the language the caller is speaking', $prompt);
        $this->assertStringContainsString('If they speak English, reply in English', $prompt);
        $this->assertStringContainsString('Roman Urdu', $prompt);
    }

    public function test_a_fixed_language_is_held_whatever_the_caller_speaks(): void
    {
        $this->assertStringContainsString('speak only Urdu written in Urdu script',
            $this->promptFor(['language' => 'ur', 'auto_detect' => false]));
        $this->assertStringContainsString('speak English only',
            $this->promptFor(['language' => 'en', 'auto_detect' => false]));
    }

    public function test_the_greeting_follows_the_agents_language(): void
    {
        $project = new Project();
        $project->json_data = ['widget' => ['welcome_message' => 'Welcome to Acme!']];
        $session = new Session();

        $session->metadata = ['call' => ['language' => 'roman']];
        $this->assertStringStartsWith('Assalam o Alaikum!', CallGreeting::for($project, $session));

        $session->metadata = ['call' => ['language' => 'en']];
        $this->assertSame('Welcome to Acme!', CallGreeting::for($project, $session));

        $session->metadata = ['call' => ['language' => 'ur', 'greeting' => 'Acme mein khush aamdeed!']];
        $this->assertSame('Acme mein khush aamdeed!', CallGreeting::for($project, $session));
    }

    public function test_any_listed_language_can_be_the_call_language(): void
    {
        $this->assertStringContainsString('speak only Spanish, whatever language the caller uses',
            $this->promptFor(['language' => 'es', 'auto_detect' => false]));

        // Auto-detect still answers Urdu callers in Urdu, and falls back to
        // the agent's own language when the caller's is unclear.
        $auto = $this->promptFor(['language' => 'ar', 'auto_detect' => true]);
        $this->assertStringContainsString('If they speak another language, reply in that language', $auto);
        $this->assertStringContainsString('use Arabic', $auto);

        $this->assertSame('auto', BotAgent::sttLanguage(['language' => 'ta', 'auto_detect' => true]));
        $this->assertSame('ta', BotAgent::sttLanguage(['language' => 'ta', 'auto_detect' => false]));
        $this->assertSame('ur', BotAgent::sttLanguage(['language' => 'roman', 'auto_detect' => false]));
        $this->assertSame('Chinese, Mandarin', BotAgent::callLanguageName('zh'));
    }

    public function test_every_call_language_is_grouped_and_has_a_greeting(): void
    {
        $grouped = array_merge(...array_values(BotAgent::CALL_LANGUAGE_GROUPS));
        $this->assertEqualsCanonicalizing(array_keys(BotAgent::CALL_LANGUAGES), $grouped);
        $this->assertSame(count($grouped), count(array_unique($grouped)));

        $session = new Session();
        foreach (array_keys(BotAgent::CALL_LANGUAGES) as $code) {
            $session->metadata = ['call' => ['language' => $code]];
            $this->assertArrayHasKey($code, CallGreeting::DEFAULTS, "no greeting for {$code}");
            $this->assertSame(CallGreeting::DEFAULTS[$code], CallGreeting::for(new Project(), $session));
        }
    }

    public function test_the_call_sounds_human_and_knows_how_to_end(): void
    {
        $prompt = (new MemoryBuilder())->voicePrompt($this->callSession());

        $this->assertStringContainsString('acknowledge the feeling sincerely', $prompt);
        $this->assertStringContainsString('Never ask for something the caller already told you', $prompt);
        $this->assertStringContainsString('never the same opener', $prompt);
        // The goodbye carries the marker the voice engine hangs up on.
        $this->assertStringContainsString('end your reply with ' . MemoryBuilder::END_CALL, $prompt);
        // Fillers are spoken by the voice engine; the model must not add more.
        $this->assertStringContainsString("Don't announce that you are checking", $prompt);
    }

    public function test_spoken_reply_rules_come_last_so_they_override_chat_formatting(): void
    {
        $prompt = (new MemoryBuilder())->voicePrompt($this->callSession());

        $spoken = strrpos($prompt, 'THIS IS A LIVE PHONE CALL');
        $this->assertNotFalse($spoken);
        // The chat rules ask for numbered lines; read aloud they are noise.
        $this->assertGreaterThan(strrpos($prompt, 'numbered'), $spoken);
        $this->assertStringContainsString('Never use lists, numbering', substr($prompt, $spoken));
    }
}
