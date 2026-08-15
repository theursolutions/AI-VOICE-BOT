<?php

namespace App\Services\Flow;

/**
 * Assigns canvas coordinates to a generated graph.
 *
 * The model is asked for structure, not geometry — coordinates are a thing it
 * gets uselessly wrong (every node at 0,0, or overlapping stacks), and they
 * carry no meaning the runtime cares about. So positions are computed here
 * instead: a left-to-right layered layout, depth from the start node giving
 * the column and sibling order giving the row.
 *
 * Good enough to open in the editor and read without dragging anything. It is
 * not a graph-drawing library and doesn't try to avoid edge crossings.
 */
class FlowAutoLayout
{
    private const COL_WIDTH  = 280;
    private const ROW_HEIGHT = 150;
    private const ORIGIN_X   = 80;
    private const ORIGIN_Y   = 60;

    public function apply(array $definition): array
    {
        $nodes = array_values(array_filter((array) ($definition['nodes'] ?? []), 'is_array'));
        $edges = array_values(array_filter((array) ($definition['edges'] ?? []), 'is_array'));

        if ($nodes === []) {
            return $definition;
        }

        $adj = [];
        foreach ($edges as $e) {
            $adj[(string) ($e['source'] ?? '')][] = (string) ($e['target'] ?? '');
        }

        $start = null;
        foreach ($nodes as $n) {
            if (($n['type'] ?? '') === 'start') {
                $start = (string) $n['id'];
                break;
            }
        }
        $start ??= (string) $nodes[0]['id'];

        // BFS depth = column. First visit wins, so a node shared by two
        // branches sits at its shallowest depth rather than drifting right.
        $depth = [$start => 0];
        $queue = [$start];
        while ($queue !== []) {
            $cur = array_shift($queue);
            foreach ($adj[$cur] ?? [] as $next) {
                if (! array_key_exists($next, $depth)) {
                    $depth[$next] = $depth[$cur] + 1;
                    $queue[] = $next;
                }
            }
        }

        // Anything unreachable goes in a column past the deepest real one, so
        // it's visible and obviously detached rather than sitting on top of
        // the main path.
        $maxDepth = $depth === [] ? 0 : max($depth);
        foreach ($nodes as $n) {
            $depth[(string) $n['id']] ??= $maxDepth + 1;
        }

        $rowInColumn = [];
        $positioned  = [];

        foreach ($nodes as $n) {
            $id  = (string) $n['id'];
            $col = $depth[$id];
            $row = $rowInColumn[$col] = ($rowInColumn[$col] ?? -1) + 1;

            $n['position'] = [
                'x' => self::ORIGIN_X + $col * self::COL_WIDTH,
                'y' => self::ORIGIN_Y + $row * self::ROW_HEIGHT,
            ];

            $positioned[] = $n;
        }

        $definition['nodes'] = $positioned;

        return $definition;
    }
}
