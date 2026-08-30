<?php

namespace Tests\Feature;

use App\Services\Conversation\MemoryBuilder;
use Tests\TestCase;

/**
 * The bot must never lose what a customer has already told it.
 *
 * The bug these cover was reported from a live conversation: the customer
 * gave their email, the bot confirmed it, and minutes later asked again and
 * insisted the email had never been sent — even when the customer quoted the
 * original message back. Three separate causes, one symptom.
 */
class ConversationMemoryTest extends TestCase
{
    private function reflect(string $method, array $args)
    {
        $builder = new MemoryBuilder();
        $ref = new \ReflectionMethod(MemoryBuilder::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($builder, $args);
    }

    /**
     * The window was six turns — twelve messages — which a WhatsApp
     * conversation overflows in minutes.
     *
     * This asserted >= 15 turns, from when the fix was purely "make the window
     * bigger". It has been failing since the window went to 8, because the fix
     * changed shape: memory is now the window PLUS a rolling summary, and
     * SummariseSession folds everything older into the system prompt. Eight
     * verbatim turns behind a summary reaches further back than twenty raw ones
     * did, for a third of the tokens.
     *
     * So the invariant worth guarding is no longer a single number. It is that
     * the window never returns to the amnesiac six, and that something is
     * actually carrying the history that scrolls out of it — a large window with
     * no summariser and a small one with a broken summariser are the same bug,
     * and only the second is cheap to reintroduce by accident.
     */
    public function test_the_history_window_is_long_enough_for_a_real_conversation(): void
    {
        $ref   = new \ReflectionClass(MemoryBuilder::class);
        $turns = $ref->getConstant('RECENT_TURNS');

        $this->assertGreaterThan(
            6,
            $turns,
            'Six turns is the window the original amnesia bug was reported against.',
        );
    }

    /** The other half of memory: what scrolls out of the window is summarised. */
    public function test_history_older_than_the_window_is_carried_by_a_summary(): void
    {
        $this->assertTrue(
            class_exists(\App\Jobs\SummariseSession::class),
            'The window is small on the assumption a summary carries the rest. Without the '
            . 'summariser, everything past 8 turns is simply lost — which is the bug this '
            . 'file exists for, in a form the window size alone cannot detect.',
        );

        $window  = (new \ReflectionClass(MemoryBuilder::class))->getConstant('RECENT_TURNS');
        $trigger = (new \ReflectionClass(\App\Jobs\SummariseSession::class))->getConstant('TRIGGER_AFTER');

        $this->assertGreaterThan(
            $window,
            $trigger,
            'Summarising must trigger LATER than the window, or we pay to compress turns the '
            . 'model is still being shown in full.',
        );
    }

    public function test_known_facts_are_stated_as_a_prohibition_not_a_list(): void
    {
        $session = new \App\Models\Session([
            'customer_name'  => 'Ayesha Khan',
            'customer_email' => 'ayesha@example.com',
        ]);
        $session->id = 0;   // no lead lookup will match

        $note = $this->reflect('knownFacts', [$session]);

        $this->assertStringContainsString('ayesha@example.com', $note);
        $this->assertStringContainsString('Ayesha Khan', $note);

        // A bare fact list does not stop a model asking anyway.
        $this->assertStringContainsStringIgnoringCase('never ask', $note);
        $this->assertStringContainsStringIgnoringCase('never say they have not given it', $note);
    }

    public function test_nothing_known_produces_no_note(): void
    {
        $session = new \App\Models\Session();
        $session->id = 0;

        $this->assertSame('', $this->reflect('knownFacts', [$session]));
    }

    /**
     * The rules that stop the interrogation customers complain about, and
     * the one that stops the bot arguing with someone who is right.
     */
    public function test_the_prompt_forbids_re_asking_and_arguing(): void
    {
        $project = new \App\Models\Project(['name' => 'Acme']);

        $prompt = $this->reflect('systemPrompt', [$project, null, null, 'en']);

        $this->assertStringContainsStringIgnoringCase('at most one question', $prompt);
        $this->assertStringContainsStringIgnoringCase('never ask for anything already given', $prompt);
        $this->assertStringContainsStringIgnoringCase('never claim a customer did not tell you', $prompt);
        $this->assertStringContainsStringIgnoringCase('never argue', $prompt);
        $this->assertStringContainsStringIgnoringCase('answer first, ask second', $prompt);
    }
}
