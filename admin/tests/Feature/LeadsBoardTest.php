<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\LeadWebController;
use App\Models\Lead;
use Tests\TestCase;

/**
 * The leads pipeline: which layout renders, and what the pipeline is made of.
 *
 * The layout choice has three inputs (the URL, a remembered cookie, and a
 * default) and one rule that matters more than the others: an unrecognised
 * value must never leave the page with no layout at all. That is the failure
 * a stale cookie or an old bookmark would otherwise cause, long after anyone
 * remembers this code.
 */
class LeadsBoardTest extends TestCase
{
    private function resolve(?string $requested, ?string $remembered): string
    {
        return LeadWebController::resolveView($requested, $remembered);
    }

    // ── Which layout ─────────────────────────────────────────────────

    public function test_the_board_is_the_default(): void
    {
        $this->assertSame('board', $this->resolve(null, null));
    }

    public function test_the_url_selects_a_layout(): void
    {
        $this->assertSame('table', $this->resolve('table', null));
        $this->assertSame('board', $this->resolve('board', null));
    }

    public function test_the_last_choice_is_remembered(): void
    {
        $this->assertSame('table', $this->resolve(null, 'table'));
        $this->assertSame('board', $this->resolve(null, 'board'));
    }

    public function test_the_url_beats_the_remembered_choice(): void
    {
        $this->assertSame('board', $this->resolve('board', 'table'));
        $this->assertSame('table', $this->resolve('table', 'board'));
    }

    /**
     * A link written as `?view=` — which is what http_build_query produces for
     * an empty value — must fall through to the cookie, not be taken as a
     * deliberate choice of nothing.
     */
    public function test_an_empty_url_value_falls_through_to_the_remembered_choice(): void
    {
        $this->assertSame('table', $this->resolve('', 'table'));
    }

    /**
     * The important one. A stale cookie or an old bookmark must land on a
     * real layout — never on a page rendering neither.
     */
    public function test_an_unrecognised_value_lands_on_the_board(): void
    {
        foreach (['kanban', 'grid', 'TABLE', '1', 'null'] as $junk) {
            $this->assertSame('board', $this->resolve($junk, null), "[$junk] must not leave the page empty.");
            $this->assertSame('board', $this->resolve(null, $junk), "[$junk] must not leave the page empty.");
        }
    }

    // ── The pipeline itself ──────────────────────────────────────────

    /**
     * The board renders one column per status and the update validator accepts
     * exactly this list, so a status added to the model must not need either
     * of them edited to work.
     */
    public function test_the_pipeline_is_defined_once(): void
    {
        $this->assertSame(
            ['new', 'contacted', 'qualified', 'converted', 'disqualified'],
            Lead::STATUSES
        );
    }

    /** Column order is the board's left-to-right order, so it is meaningful. */
    public function test_the_pipeline_runs_from_new_to_closed(): void
    {
        $this->assertSame('new', Lead::STATUSES[0], 'A lead starts here.');
        $this->assertContains('disqualified', Lead::STATUSES);
        $this->assertGreaterThan(
            array_search('qualified', Lead::STATUSES, true),
            array_search('converted', Lead::STATUSES, true),
            'Converted comes after qualified — the board reads left to right.'
        );
    }
}
