<?php

namespace Tests\Unit;

use App\Jobs\ExtractLeadFromTurn;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The gate that decides whether a turn is worth an extraction call.
 *
 * Tested at this level of detail because the two failure directions are not
 * symmetric: letting a worthless turn through costs a fraction of a cent, while
 * holding back a real one loses a customer's phone number. Every "skips"
 * assertion below is a claim that a turn contains no lead data at all, and
 * every "reads" assertion is a claim we would rather pay than risk it.
 */
class LeadExtractionGateTest extends TestCase
{
    private function gate(array $span, array $prior = []): bool
    {
        $job    = new ExtractLeadFromTurn(1, 1, 1);
        $method = new ReflectionMethod($job, 'couldCarryLeadFields');
        $method->setAccessible(true);

        return $method->invoke($job, $this->messages($span), $this->messages($prior));
    }

    /** @param array<int, array{0: string, 1: string}> $pairs [role, content] */
    private function messages(array $pairs): Collection
    {
        return collect($pairs)->map(fn ($p) => (object) ['role' => $p[0], 'content' => $p[1]]);
    }

    // ── The turns this exists to skip ───────────────────────────────────

    /** @dataProvider worthlessTurns */
    public function test_it_skips_turns_with_no_lead_data(string $content): void
    {
        $this->assertFalse(
            $this->gate([['assistant', 'Glad to help.'], ['user', $content]]),
            "Expected \"{$content}\" to be skipped as containing no lead data",
        );
    }

    public static function worthlessTurns(): array
    {
        return [
            'ok'            => ['ok'],
            'okay'          => ['okay'],
            'thanks'        => ['thanks'],
            'thank you'     => ['Thank you'],
            'hi'            => ['hi'],
            'hello'         => ['Hello'],
            'salam'         => ['salam'],
            'assalam'       => ['Assalam o alaikum'],
            'hmm'           => ['hmm'],
            'emoji only'    => ['👍'],
            'good'          => ['good'],
            'sure'          => ['sure'],
            'empty'         => [''],
        ];
    }

    /**
     * Direction matters. The bot asking something AFTER an acknowledgement does
     * not make that acknowledgement an answer — otherwise every turn passes,
     * because the assistant closes nearly every reply with a question.
     */
    public function test_a_question_after_the_user_turn_does_not_reopen_it(): void
    {
        $this->assertFalse($this->gate([
            ['user', 'ok'],
            ['assistant', 'Anything else I can help with?'],
        ]));
    }

    /**
     * A pending question is consumed by the turn that answers it. Without that,
     * one question would mark every following acknowledgement as lead data for
     * the rest of the conversation.
     */
    public function test_a_pending_question_is_consumed_by_the_answer(): void
    {
        // The answer itself reads...
        $this->assertTrue($this->gate([
            ['user', 'Ali'],
        ], [
            ['assistant', 'What is your name?'],
        ]));

        // ...and the acknowledgement that follows it does not.
        $this->assertFalse($this->gate([
            ['user', 'ok'],
        ], [
            ['assistant', 'What is your name?'],
            ['user', 'Ali'],
        ]));
    }

    // ── The turns it must never skip ────────────────────────────────────

    /** @dataProvider informativeTurns */
    public function test_it_reads_turns_that_could_carry_a_field(string $content): void
    {
        $this->assertTrue(
            $this->gate([['assistant', 'Glad to help.'], ['user', $content]]),
            "Expected \"{$content}\" to be read as possible lead data",
        );
    }

    public static function informativeTurns(): array
    {
        return [
            'email'            => ['ali@example.com'],
            'phone'            => ['03001234567'],
            'phone spaced'     => ['+92 300 123 4567'],
            'quantity'         => ['2 bed'],
            'budget words'     => ['budget'],
            'price question'   => ['price?'],
            'roman urdu name'  => ['naam Ali'],
            'roman urdu price' => ['qeemat'],
            'roman urdu want'  => ['chahiye'],
            'urdu script'      => ['قیمت'],
            'intent demo'      => ['demo'],
            'intent visit'     => ['visit'],
            'month'            => ['December'],
            'weekday'          => ['Monday'],
            'complaint'        => ['complaint'],
            'prose'            => ['I am looking for something near the office'],

            // Short intents found skipped when this gate was replayed over real
            // traffic. Each one qualifies a lead, and none carries a digit, an
            // @, or 25 characters of prose to catch it by.
            'packages plural'  => ['Packages'],
            'agents plural'    => ['Talk to agents'],
            'balance'          => ['Balance'],
            'negation'         => ['sum is not correct'],
            'bare question'    => ['who is this?'],
            'urdu question'    => ['یہ کیا ہے؟'],
            'plans plural'     => ['plans'],
            'services plural'  => ['services'],
            'human handoff'    => ['human please'],
        ];
    }

    /**
     * A plural must match its singular keyword. Listing singulars and trusting
     * \b silently dismissed "Packages" and "Talk to agents" against real
     * traffic — and a plural is how customers write most of these.
     */
    public function test_plurals_match_their_singular_keyword(): void
    {
        foreach (['package', 'plan', 'agent', 'service', 'product', 'offer', 'deal'] as $word) {
            $this->assertTrue(
                $this->gate([['user', $word . 's']]),
                "Expected the plural \"{$word}s\" to be read",
            );
        }
    }

    /**
     * Greetings phrased as questions cost a call rather than risk a real
     * enquiry — the asymmetry is deliberate, so this documents it as intended
     * rather than leaving it to look like a leak.
     */
    public function test_a_greeting_phrased_as_a_question_is_read(): void
    {
        $this->assertTrue($this->gate([['user', 'how are you?']]));
    }

    /**
     * The case the whole overlap mechanism exists for: a one-word answer that
     * means nothing on its own text and everything after the question.
     */
    public function test_it_reads_a_short_answer_to_a_question_asked_before_the_span(): void
    {
        $this->assertTrue($this->gate(
            span:  [['user', 'Ali']],
            prior: [['assistant', 'Lovely — and what name should I put it under?']],
        ));
    }

    public function test_it_reads_a_short_answer_to_a_question_asked_inside_the_span(): void
    {
        $this->assertTrue($this->gate([
            ['assistant', 'Which area are you looking in?'],
            ['user', 'Clifton'],
        ]));
    }

    public function test_it_reads_a_bare_yes_when_the_bot_just_asked(): void
    {
        $this->assertTrue($this->gate(
            span:  [['user', 'yes']],
            prior: [['assistant', 'Shall I book you a viewing?']],
        ));
    }

    public function test_a_question_mark_before_a_trailing_emoji_still_counts(): void
    {
        $this->assertTrue($this->gate([
            ['assistant', 'Would you like to book a viewing? 😊'],
            ['user', 'yes'],
        ]));
    }

    public function test_urdu_question_mark_counts(): void
    {
        $this->assertTrue($this->gate([
            ['assistant', 'آپ کا نام کیا ہے؟'],
            ['user', 'Ali'],
        ]));
    }

    /**
     * The assistant is not a data source. A bot turn full of prices and dates
     * is not evidence the customer said anything.
     */
    public function test_assistant_content_alone_is_never_lead_data(): void
    {
        $this->assertFalse($this->gate([
            ['assistant', 'Our 2-bed units start at 45 lakh and we have viewings on Monday.'],
            ['user', 'ok'],
        ]));
    }

    /**
     * A blank LLM_EXTRACT_GATE must mean "default", not "off". filter_var reads
     * an empty string as a valid false, which would put the old cost back with
     * nothing to indicate why the bill never fell.
     */
    public function test_a_blank_gate_setting_leaves_the_gate_on(): void
    {
        $job    = new ExtractLeadFromTurn(1, 1, 1);
        $method = new \ReflectionMethod($job, 'gateEnabled');
        $method->setAccessible(true);

        foreach ([null, ''] as $blank) {
            config(['services.llm.extract_gate' => $blank]);
            $this->assertTrue($method->invoke($job), 'Blank must fall back to the default');
        }

        foreach (['false', '0', false] as $off) {
            config(['services.llm.extract_gate' => $off]);
            $this->assertFalse($method->invoke($job), 'An explicit false must switch it off');
        }

        foreach (['true', '1', true] as $on) {
            config(['services.llm.extract_gate' => $on]);
            $this->assertTrue($method->invoke($job));
        }
    }

    public function test_one_informative_message_carries_the_whole_span(): void
    {
        $this->assertTrue($this->gate([
            ['user', 'hi'],
            ['assistant', 'Hello!'],
            ['user', 'thanks'],
            ['user', 'ali@example.com'],
        ]));
    }
}
