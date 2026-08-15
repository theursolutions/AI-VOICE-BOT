<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * The export button's contract with its script.
 *
 * The attribute that matters is data-pages. The exporter reads the RENDERED
 * table, so on a paginated list it can only see the page you are looking at —
 * data-pages is what tells it to walk the rest before building the file.
 *
 * Get it wrong and nothing looks broken: a button still appears, a file still
 * downloads, and it quietly contains 25 of 4,000 rows. That is the failure
 * these cover, because it is the one nobody notices until the spreadsheet is
 * already being used for something.
 */
class TableExportTest extends TestCase
{
    private function render(array $data): string
    {
        return view('partials.table-export', $data)->render();
    }

    private function pages(string $html): ?string
    {
        return preg_match('/data-pages="([^"]*)"/', $html, $m) ? $m[1] : null;
    }

    public function test_it_points_at_the_table_it_exports(): void
    {
        $html = $this->render([
            'table'     => '#tva-t-leads',
            'filename'  => 'leads',
            'paginator' => null,
        ]);

        $this->assertStringContainsString('data-table="#tva-t-leads"', $html);
        $this->assertStringContainsString('data-filename="leads"', $html);
    }

    /** An unpaginated table is entirely on the page — one page to read. */
    public function test_no_paginator_means_a_single_page(): void
    {
        $html = $this->render(['table' => '#t', 'filename' => 'f', 'paginator' => null]);

        $this->assertSame('1', $this->pages($html));
    }

    /**
     * The whole point: 4,000 rows at 25 a page is 160 pages, and the export
     * has to know that or it silently ships the first 25.
     */
    public function test_a_paginator_reports_every_page(): void
    {
        $paginator = new LengthAwarePaginator([], 4000, 25, 1);

        $html = $this->render(['table' => '#t', 'filename' => 'f', 'paginator' => $paginator]);

        $this->assertSame('160', $this->pages($html));
    }

    public function test_a_single_page_of_results_stays_a_single_page(): void
    {
        $paginator = new LengthAwarePaginator([], 12, 25, 1);

        $html = $this->render(['table' => '#t', 'filename' => 'f', 'paginator' => $paginator]);

        $this->assertSame('1', $this->pages($html));
    }

    /**
     * An empty result set still has a page count of 1, not 0 — a 0 would make
     * the script skip its own page and export nothing but the header.
     */
    public function test_an_empty_result_set_still_reads_one_page(): void
    {
        $paginator = new LengthAwarePaginator([], 0, 25, 1);

        $html = $this->render(['table' => '#t', 'filename' => 'f', 'paginator' => $paginator]);

        $this->assertSame('1', $this->pages($html));
    }

    /**
     * Two exporters on one page (the ops overview has three) must not collide
     * on their element ids, or the second button would be unreachable.
     */
    public function test_two_buttons_on_a_page_get_different_ids(): void
    {
        $a = $this->render(['table' => '#one', 'filename' => 'recent-users',      'paginator' => null]);
        $b = $this->render(['table' => '#two', 'filename' => 'recent-workspaces', 'paginator' => null]);

        preg_match('/id="(tva-export-[^"]+)"/', $a, $ma);
        preg_match('/id="(tva-export-[^"]+)"/', $b, $mb);

        $this->assertNotEmpty($ma[1] ?? '');
        $this->assertNotSame($ma[1], $mb[1] ?? '');
    }
}
