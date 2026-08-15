<?php

namespace Tests\Feature;

use App\Flow\NodeCatalog;
use App\Services\Flow\FlowValidator;
use Tests\TestCase;

/**
 * The AI flow builder trusts App\Flow\NodeCatalog completely: it is both the
 * menu of node types shown to the model and the rulebook the generated graph
 * is checked against. If it drifts from the editor's palette, the builder
 * starts producing flows the editor can't render — or refuses ones it could.
 *
 * These tests pin the two together, and pin down the behaviour that actually
 * protects customers: a node the voice runtime cannot execute must be an
 * ERROR on a voice flow, because that runtime hangs up rather than skipping.
 */
class FlowCatalogParityTest extends TestCase
{
    /** Node type keys declared in the React editor's NODE_TYPES registry. */
    private function editorNodeTypes(): array
    {
        $source = file_get_contents(resource_path('js/flow-editor/index.jsx'));
        $this->assertNotFalse($source, 'Could not read the flow editor source.');

        // Slice out `const NODE_TYPES = { … };` and take the top-level keys.
        $start = strpos($source, 'const NODE_TYPES = {');
        $this->assertNotFalse($start, 'NODE_TYPES not found in the editor source.');

        $body  = substr($source, $start);
        $depth = 0;
        $end   = 0;
        for ($i = strpos($body, '{'); $i < strlen($body); $i++) {
            if ($body[$i] === '{') $depth++;
            if ($body[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }
        $block = substr($body, 0, $end + 1);

        // Keys at nesting depth 1 only — `defaultData` sub-keys sit deeper.
        preg_match_all('/^    ([a-z_][a-z0-9_]*):\s*\{/m', $block, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_catalog_covers_exactly_the_editor_palette(): void
    {
        $editor  = $this->editorNodeTypes();
        $catalog = NodeCatalog::types();

        sort($editor);
        sort($catalog);

        $this->assertSame(
            $editor,
            $catalog,
            "NodeCatalog and the editor's NODE_TYPES have drifted apart. "
            . 'Missing from catalog: ' . implode(', ', array_diff($editor, $catalog)) . '. '
            . 'Extra in catalog: ' . implode(', ', array_diff($catalog, $editor)) . '.'
        );
    }

    public function test_voice_flows_reject_nodes_the_voice_runtime_cannot_render(): void
    {
        // FlowRunner's switch has no case for collect_input, so it falls to
        // `default:` and hangs up. Generating one for a phone flow would put
        // a dead call in front of a real customer.
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => []],
                ['id' => 'ci', 'type' => 'collect_input', 'data' => [
                    'fields' => [['key' => 'name', 'prompt' => 'Your name?', 'input_type' => 'text']],
                ]],
                ['id' => 'e', 'type' => 'end', 'data' => ['message' => 'Bye']],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'ci'],
                ['source' => 'ci', 'target' => 'e', 'sourceHandle' => 'collected'],
                ['source' => 'ci', 'target' => 'e', 'sourceHandle' => 'timeout'],
            ],
        ];

        $voice = (new FlowValidator)->validate($graph, ['channel' => NodeCatalog::CHANNEL_VOICE]);
        $chat  = (new FlowValidator)->validate($graph, ['channel' => NodeCatalog::CHANNEL_CHAT]);

        $this->assertFalse($voice['ok'], 'collect_input must be rejected on a voice flow.');
        $this->assertTrue($chat['ok'], 'collect_input is valid on a chat flow: ' . implode(' ', $chat['errors']));
    }

    public function test_inert_nodes_warn_rather_than_fail(): void
    {
        // wait/webhook/branch/transfer_human are skipped by the runtimes, not
        // fatal — the flow still completes, so this is a warning.
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => []],
                ['id' => 'w', 'type' => 'wait', 'data' => ['seconds' => 3]],
                ['id' => 'e', 'type' => 'end', 'data' => ['message' => 'Bye']],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'w'],
                ['source' => 'w', 'target' => 'e', 'sourceHandle' => 'out'],
            ],
        ];

        $r = (new FlowValidator)->validate($graph, ['channel' => NodeCatalog::CHANNEL_CHAT]);

        $this->assertTrue($r['ok']);
        $this->assertNotEmpty(
            array_filter($r['warnings'], fn ($w) => str_contains($w, 'no runtime executes it')),
            'An inert node should produce a warning.'
        );
    }

    public function test_edges_must_use_handles_the_source_node_actually_has(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'data' => []],
                ['id' => 'm', 'type' => 'capture_dtmf', 'data' => [
                    'prompt'  => 'Press 1 or 2',
                    'options' => [['digit' => '1', 'label' => 'Sales'], ['digit' => '2', 'label' => 'Support']],
                ]],
                ['id' => 'e', 'type' => 'end', 'data' => ['message' => 'Bye']],
            ],
            'edges' => [
                ['source' => 'start', 'target' => 'm'],
                ['source' => 'm', 'target' => 'e', 'sourceHandle' => '9'],   // no such option
            ],
        ];

        $r = (new FlowValidator)->validate($graph, ['channel' => NodeCatalog::CHANNEL_CHAT]);

        $this->assertFalse($r['ok']);
        $this->assertNotEmpty(array_filter($r['errors'], fn ($e) => str_contains($e, 'branch "9"')));
    }

    public function test_prompt_spec_never_offers_a_node_the_channel_cannot_run(): void
    {
        foreach ([NodeCatalog::CHANNEL_VOICE, NodeCatalog::CHANNEL_CHAT] as $channel) {
            $spec = NodeCatalog::promptSpec($channel);

            foreach (NodeCatalog::types() as $type) {
                if (NodeCatalog::supports($type, $channel)) {
                    $this->assertStringContainsString("- {$type} (", $spec,
                        "{$type} runs on {$channel} but is missing from the prompt spec.");
                } else {
                    $this->assertStringNotContainsString("- {$type} (", $spec,
                        "{$type} does not run on {$channel} but the model is being offered it.");
                }
            }
        }
    }
}
