<?php

namespace Tests\Feature;

use App\Meta\ComplianceGuard;
use Tests\TestCase;

/**
 * The matching here is safety-critical in both directions.
 *
 * A missed opt-out keeps messaging someone who asked us to stop — a policy
 * violation and the fastest route to a blocked number. A false positive
 * silently stops replying to a paying customer who happened to type "stop by
 * tomorrow", and nobody finds out until they complain.
 */
class ComplianceGuardTest extends TestCase
{
    private ComplianceGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ComplianceGuard();
    }

    public static function optOutPhrases(): array
    {
        return [
            'bare stop'        => ['STOP'],
            'lowercase'        => ['stop'],
            'padded'           => ['  Stop  '],
            'unsubscribe'      => ['unsubscribe'],
            'multi word'       => ['please remove me from this list'],
            'apostrophe'       => ["don't message me again"],
            'roman urdu'       => ['bhai band karo message'],
            'urdu script'      => ['براہ کرم بند کریں'],
        ];
    }

    /** @dataProvider optOutPhrases */
    public function test_an_opt_out_is_recognised(string $text): void
    {
        $this->assertTrue($this->guard->isOptOut($text), "Should have been read as an opt-out: {$text}");
    }

    public static function innocentPhrases(): array
    {
        return [
            'stop as a noun'   => ['is your shop near the bus stop?'],
            'stop in a phrase' => ['I will stop by tomorrow at 5'],
            'cancel an order'  => ['I want to cancel my order #1234'],
            'end of a word'    => ['do you sell stopwatches'],
            'ordinary message' => ['can you send me the price list'],
        ];
    }

    /**
     * @dataProvider innocentPhrases
     *
     * "cancel my order" is the one that matters commercially: reading it as
     * an opt-out would silence the bot on a live sale.
     */
    public function test_ordinary_messages_are_not_opt_outs(string $text): void
    {
        $this->assertFalse($this->guard->isOptOut($text), "Should NOT have been read as an opt-out: {$text}");
    }

    public function test_a_bare_command_only_counts_as_the_whole_message(): void
    {
        $this->assertTrue($this->guard->isOptOut('stop'));
        $this->assertFalse($this->guard->isOptOut('stop the order please'));
    }

    public function test_opting_back_in_is_recognised(): void
    {
        $this->assertTrue($this->guard->isOptIn('START'));
        $this->assertTrue($this->guard->isOptIn('start'));
        $this->assertFalse($this->guard->isOptIn('start my order'));
    }

    public static function humanRequests(): array
    {
        return [
            ['I want to talk to someone'],
            ['can I speak to a human'],
            ['agent'],
            ['connect me to customer service'],
            ['mujhe kisi insan se baat karni hai'],
        ];
    }

    /** @dataProvider humanRequests */
    public function test_asking_for_a_person_is_recognised(string $text): void
    {
        $this->assertTrue($this->guard->wantsHuman($text), "Should escalate: {$text}");
    }

    /**
     * Meta requires opt-out to be reversible, so the confirmation has to tell
     * the customer how — otherwise the only way back is to message a business
     * they have just been told will not reply.
     */
    public function test_the_opt_out_confirmation_explains_how_to_return(): void
    {
        $message = $this->guard->optOutConfirmation();

        $this->assertStringContainsStringIgnoringCase('start', $message);
        $this->assertStringContainsStringIgnoringCase("won't message you", $message);
    }
}
