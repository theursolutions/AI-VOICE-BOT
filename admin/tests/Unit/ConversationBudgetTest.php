<?php

namespace Tests\Unit;

use App\Services\Conversation\ConversationBudget;
use Tests\TestCase;

/**
 * The per-conversation reply limit.
 *
 * This class is what makes "1,000 conversations × 20 messages = 20,000
 * messages" arithmetic rather than a hope, so its defaults are priced against.
 * Changing DEFAULT_LIMIT changes the margin on every tier, and clamp() is what
 * stands between a malformed setting and an AI that either never stops or never
 * starts.
 */
class ConversationBudgetTest extends TestCase
{
    /**
     * The published plans are priced against twenty replies per conversation.
     * This is not a UI preference, and a silent change here moves every margin.
     */
    public function test_the_default_is_the_figure_the_plans_are_priced_against(): void
    {
        $this->assertSame(20, ConversationBudget::DEFAULT_LIMIT);
    }

    /**
     * The one that matters most. A blank or non-numeric setting must fall back
     * to the default, NOT to zero — a limit of zero hands every conversation to
     * a human on its first message, so the assistant appears completely dead
     * and the cause is an empty string in a settings blob.
     */
    public function test_a_blank_or_junk_setting_falls_back_to_the_default(): void
    {
        foreach ([null, '', 'abc', [], false, ' '] as $junk) {
            $this->assertSame(
                ConversationBudget::DEFAULT_LIMIT,
                ConversationBudget::clamp($junk),
                'A setting of ' . var_export($junk, true) . ' must not become a limit of 0',
            );
        }
    }

    public function test_zero_and_negatives_are_raised_to_the_floor(): void
    {
        foreach ([0, -1, -999] as $value) {
            $this->assertSame(ConversationBudget::MIN_LIMIT, ConversationBudget::clamp($value));
        }
    }

    public function test_absurd_values_are_capped(): void
    {
        $this->assertSame(ConversationBudget::MAX_LIMIT, ConversationBudget::clamp(5000));
        $this->assertSame(ConversationBudget::MAX_LIMIT, ConversationBudget::clamp(PHP_INT_MAX));
    }

    public function test_values_in_range_are_kept_exactly(): void
    {
        foreach ([5, 12, 20, 30, 75, 200] as $value) {
            $this->assertSame($value, ConversationBudget::clamp($value));
        }
    }

    /** Numeric strings arrive from form input and must be honoured. */
    public function test_numeric_strings_are_accepted(): void
    {
        $this->assertSame(30, ConversationBudget::clamp('30'));
        $this->assertSame(30, ConversationBudget::clamp('30.7'));
    }

    public function test_the_range_brackets_the_default(): void
    {
        $this->assertLessThan(ConversationBudget::DEFAULT_LIMIT, ConversationBudget::MIN_LIMIT);
        $this->assertGreaterThan(ConversationBudget::DEFAULT_LIMIT, ConversationBudget::MAX_LIMIT);
    }

    /**
     * The floor must leave room for a real exchange. Below a greeting, a
     * question and an answer the AI escalates every conversation and reads as
     * broken rather than restrained.
     */
    public function test_the_floor_leaves_room_for_an_actual_exchange(): void
    {
        $this->assertGreaterThanOrEqual(3, ConversationBudget::MIN_LIMIT);
    }

    /**
     * The cap counts assistant replies — the same thing UsageRecorder meters.
     * If they ever count different things the plan arithmetic stops holding and
     * the symptom (allowances running out early) appears nowhere near the cause.
     */
    public function test_it_counts_the_same_unit_the_meter_counts(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ConversationBudget::class))->getFileName()
        );

        $this->assertStringContainsString("where('role', 'assistant')", $source);
    }

    /**
     * `reached()` must use >=, so a limit of 20 permits exactly 20 replies. With
     * > it would allow 21 and every plan would quietly run 5% over its costed
     * allowance.
     */
    public function test_the_boundary_is_inclusive(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ConversationBudget::class))->getFileName()
        );

        $body = substr($source, strpos($source, 'public function reached'));

        $this->assertStringContainsString('>=', $body);
        $this->assertStringNotContainsString(
            '> $this->limitFor',
            $body,
            'A strict > allows one reply more than the plan is costed for',
        );
    }
}
