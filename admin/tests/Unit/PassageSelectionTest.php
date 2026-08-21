<?php

namespace Tests\Unit;

use App\Services\Conversation\MemoryBuilder;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Relevance trimming of retrieved passages.
 *
 * Passages are 500 tokens each and the largest block in the prompt, so this is
 * the most valuable thing to trim — and the most dangerous, because a dropped
 * chunk is a fact the reply then answers without. The fail-open cases below are
 * the important half of this file: whenever the scores cannot be trusted to
 * compare, everything within the cap must survive.
 */
class PassageSelectionTest extends TestCase
{
    /** @param array<int, mixed> $items */
    private function select(array $items, ?float $ratio = null, ?int $max = null): array
    {
        if ($ratio !== null) {
            config(['services.llm.passage_score_ratio' => $ratio]);
        }
        if ($max !== null) {
            config(['services.llm.max_passages' => $max]);
        }

        $method = new ReflectionMethod(MemoryBuilder::class, 'selectPassages');
        $method->setAccessible(true);

        return $method->invoke(new MemoryBuilder(), $items);
    }

    private function passage(float $score, string $text = 'chunk'): array
    {
        return ['text' => $text, 'score' => $score, 'citation' => []];
    }

    // ── Trimming ────────────────────────────────────────────────────────

    public function test_a_dominant_top_hit_drops_the_weak_tail(): void
    {
        $kept = $this->select([
            $this->passage(9.0, 'the answer'),
            $this->passage(2.0),
            $this->passage(1.5),
        ], ratio: 0.45);

        $this->assertCount(1, $kept);
        $this->assertSame('the answer', $kept[0]['text']);
    }

    public function test_a_genuine_tie_keeps_every_passage(): void
    {
        $kept = $this->select([
            $this->passage(8.0),
            $this->passage(7.6),
            $this->passage(7.1),
        ], ratio: 0.45);

        $this->assertCount(3, $kept, 'Three close matches are exactly when breadth is worth paying for');
    }

    public function test_it_keeps_passages_exactly_on_the_floor(): void
    {
        // 4.5 is precisely 0.45 of 10.0 — the boundary must be inclusive, or a
        // passage at the stated threshold is silently discarded.
        $kept = $this->select([
            $this->passage(10.0),
            $this->passage(4.5),
        ], ratio: 0.45);

        $this->assertCount(2, $kept);
    }

    public function test_the_flat_cap_still_applies_before_scoring(): void
    {
        $kept = $this->select([
            $this->passage(9.0),
            $this->passage(8.9),
            $this->passage(8.8),
            $this->passage(8.7),
            $this->passage(8.6),
        ], ratio: 0.45, max: 3);

        $this->assertCount(3, $kept);
    }

    public function test_it_ranks_on_the_best_score_present_not_the_first(): void
    {
        // A resolver that returns unranked results must not cause the strongest
        // passage to set the floor from second place and drop itself.
        $kept = $this->select([
            $this->passage(2.0),
            $this->passage(9.0, 'the answer'),
            $this->passage(1.0),
        ], ratio: 0.45);

        $this->assertCount(1, $kept);
        $this->assertSame('the answer', $kept[0]['text']);
    }

    // ── Failing open ────────────────────────────────────────────────────

    public function test_unscored_passages_are_never_trimmed(): void
    {
        $kept = $this->select([
            ['text' => 'one', 'citation' => []],
            ['text' => 'two', 'citation' => []],
            ['text' => 'three', 'citation' => []],
        ], ratio: 0.45);

        $this->assertCount(3, $kept, 'With nothing to rank on, keeping all is the only safe choice');
    }

    public function test_plain_string_passages_are_never_trimmed(): void
    {
        $kept = $this->select(['one', 'two', 'three'], ratio: 0.45);

        $this->assertCount(3, $kept);
    }

    public function test_one_unscored_passage_protects_the_whole_set(): void
    {
        $kept = $this->select([
            $this->passage(9.0),
            ['text' => 'no score here', 'citation' => []],
            $this->passage(0.2),
        ], ratio: 0.45);

        $this->assertCount(3, $kept);
    }

    public function test_a_non_positive_top_score_is_not_trimmed(): void
    {
        // BM25 can emit zero and negative values; a ratio of one is meaningless.
        $this->assertCount(3, $this->select([
            $this->passage(0.0),
            $this->passage(0.0),
            $this->passage(0.0),
        ], ratio: 0.45));

        $this->assertCount(2, $this->select([
            $this->passage(-1.0),
            $this->passage(-8.0),
        ], ratio: 0.45));
    }

    public function test_a_ratio_of_zero_disables_trimming(): void
    {
        $kept = $this->select([
            $this->passage(9.0),
            $this->passage(0.1),
            $this->passage(0.01),
        ], ratio: 0.0);

        $this->assertCount(3, $kept);
    }

    public function test_a_blank_ratio_falls_back_to_the_coded_default(): void
    {
        // The is_numeric guard: a blank LLM_PASSAGE_SCORE_RATIO in .env must not
        // cast to 0.0 and quietly switch trimming off.
        config(['services.llm.passage_score_ratio' => '', 'services.llm.max_passages' => 3]);
        $method = new ReflectionMethod(MemoryBuilder::class, 'selectPassages');
        $method->setAccessible(true);
        $kept = $method->invoke(new MemoryBuilder(), [
            $this->passage(9.0, 'the answer'),
            $this->passage(0.5),
        ]);

        $this->assertCount(1, $kept);
        $this->assertSame('the answer', $kept[0]['text']);
    }

    public function test_a_ratio_above_one_is_clamped_so_it_cannot_keep_only_the_top(): void
    {
        // At a literal 1.0 only exact ties survive, which turns a cost dial into
        // a silent quality cut. Clamped to 0.95, a near-tie still passes.
        $kept = $this->select([
            $this->passage(10.0),
            $this->passage(9.6),
        ], ratio: 5.0);

        $this->assertCount(2, $kept);
    }

    public function test_a_single_passage_is_returned_untouched(): void
    {
        $this->assertCount(1, $this->select([$this->passage(0.001)], ratio: 0.45));
    }

    public function test_an_empty_set_stays_empty(): void
    {
        $this->assertSame([], $this->select([], ratio: 0.45));
    }

    public function test_max_passages_of_zero_disables_reference_data(): void
    {
        $this->assertSame([], $this->select([
            $this->passage(9.0),
        ], ratio: 0.45, max: 0));
    }
}
