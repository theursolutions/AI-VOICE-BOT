<?php

namespace App\Services\Flow;

use App\Flow\NodeCatalog;

/**
 * Checks a flow graph against the node catalog before it is allowed near the
 * database, and repairs the things that are safe to repair.
 *
 * Written for AI-generated graphs, where the failure modes are specific and
 * predictable: a node type that doesn't exist, an edge pointing at a branch
 * handle the node doesn't have, a required field left out, a `datasource`
 * scoped to an id this project doesn't own. Every one of those produces a flow
 * that saves cleanly and then misbehaves at runtime in front of a customer, so
 * they are caught here instead.
 *
 * Two severities, and the distinction matters:
 *   errors   — the flow is broken. Never saved; fed back to the model for one
 *              repair attempt.
 *   warnings — the flow works but something is worth telling the customer
 *              (an inert node, an unreachable branch, a dead end).
 *
 * `normalise()` fixes only what has one obviously-correct answer: deriving
 * button_labels from options, filling defaults, dropping edges to nowhere.
 * Anything requiring a judgement call is reported, not guessed.
 */
class FlowValidator
{
    /** @var array<int,string> */
    private array $errors = [];

    /** @var array<int,string> */
    private array $warnings = [];

    /**
     * @param  array  $definition  {nodes:[], edges:[], settings:{}}
     * @param  array{channel?:string,source_ids?:array<int>,agent_ids?:array<int>}  $context
     * @return array{ok:bool,errors:array<int,string>,warnings:array<int,string>,definition:array}
     */
    public function validate(array $definition, array $context = []): array
    {
        $this->errors = [];
        $this->warnings = [];

        $channel = $context['channel'] ?? NodeCatalog::CHANNEL_CHAT;

        $nodes = array_values(array_filter((array) ($definition['nodes'] ?? []), 'is_array'));
        $edges = array_values(array_filter((array) ($definition['edges'] ?? []), 'is_array'));

        if ($nodes === []) {
            $this->errors[] = 'The flow has no nodes.';

            return $this->result($definition);
        }

        $nodes = $this->checkNodes($nodes, $channel, $context);
        $ids   = array_column($nodes, 'id');

        $edges = $this->checkEdges($edges, $nodes, $ids);
        $this->checkStructure($nodes, $edges, $ids);

        $definition['nodes'] = $nodes;
        $definition['edges'] = $edges;
        $definition['settings'] = ($definition['settings'] ?? []) + [
            'language'     => 'en',
            'timeout_secs' => 8,
            'max_retries'  => 2,
        ];

        return $this->result($definition);
    }

    /** Per-node checks: known type, channel support, required fields, enums. */
    private function checkNodes(array $nodes, string $channel, array $context): array
    {
        $seenIds = [];
        $out     = [];

        foreach ($nodes as $i => $node) {
            $id   = (string) ($node['id'] ?? '');
            $type = (string) ($node['type'] ?? '');

            if ($id === '') {
                $id = $node['id'] = 'n' . ($i + 1);
                $this->warnings[] = "A node had no id; assigned \"{$id}\".";
            }
            if (in_array($id, $seenIds, true)) {
                $this->errors[] = "Duplicate node id \"{$id}\" — ids must be unique.";
                continue;
            }
            $seenIds[] = $id;

            if (! NodeCatalog::exists($type)) {
                $this->errors[] = "Node \"{$id}\" uses unknown type \"{$type}\". Valid types: "
                    . implode(', ', NodeCatalog::types()) . '.';
                continue;
            }

            $def  = NodeCatalog::get($type);
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            // Channel support — the whole reason this validator exists.
            if (! NodeCatalog::supports($type, $channel)) {
                if (NodeCatalog::isInert($type)) {
                    $this->warnings[] = "\"{$def['label']}\" (node {$id}) is in the palette but no runtime executes it yet — "
                        . 'it will be skipped at runtime.';
                } elseif ($channel === NodeCatalog::CHANNEL_VOICE) {
                    // Not a warning: the voice runner hangs up here.
                    $this->errors[] = "\"{$def['label']}\" (node {$id}) does not run on phone calls — the call would be "
                        . 'disconnected when it reaches this step. Use Capture DTMF and Transfer to AI instead.';
                } else {
                    $this->errors[] = "\"{$def['label']}\" (node {$id}) is not supported on {$channel} flows.";
                }
            }

            $node['data'] = $this->checkFields($id, $type, $def, $data, $context);

            if (! isset($node['position']) || ! is_array($node['position'])) {
                $node['position'] = ['x' => 0, 'y' => 0];   // FlowAutoLayout fixes this
            }

            $out[] = $node;
        }

        return $out;
    }

    /** Required fields, enum values, and project-scoped id references. */
    private function checkFields(string $id, string $type, array $def, array $data, array $context): array
    {
        foreach ($def['fields'] as $name => $spec) {
            $value   = $data[$name] ?? null;
            $present = $value !== null && $value !== '' && $value !== [];

            if (! $present && ($spec['required'] ?? false)) {
                // Conditionally-required fields depend on a sibling's value, so
                // they're only an error when that condition actually holds.
                if ($this->conditionallyExempt($type, $name, $data)) {
                    continue;
                }
                if (array_key_exists('default', $spec)) {
                    $data[$name] = $spec['default'];
                    continue;
                }
                $this->errors[] = "Node \"{$id}\" ({$def['label']}) is missing required field \"{$name}\".";
                continue;
            }

            if ($present && ! empty($spec['enum']) && ! in_array($value, $spec['enum'], true)) {
                $this->errors[] = "Node \"{$id}\" field \"{$name}\" is \"{$value}\"; must be one of: "
                    . implode(', ', $spec['enum']) . '.';
            }

            if (! $present && array_key_exists('default', $spec) && ! array_key_exists($name, $data)) {
                $data[$name] = $spec['default'];
            }
        }

        return $this->checkNodeSpecifics($id, $type, $data, $context);
    }

    /**
     * A field marked required that is only required for certain sibling
     * values — e.g. `text` matters when source=tts, not when source=audio.
     */
    private function conditionallyExempt(string $type, string $field, array $data): bool
    {
        return match (true) {
            $type === 'say' && $field === 'text'
                => ($data['source'] ?? 'tts') === 'audio',
            $type === 'capture_dtmf' && $field === 'prompt'
                => ($data['prompt_source'] ?? 'tts') === 'audio',
            $type === 'capture_speech' && $field === 'prompt'
                => ($data['prompt_source'] ?? 'tts') === 'audio',
            $type === 'datasource' && $field === 'source_ids'
                => true,   // empty legitimately means "all sources"
            default => false,
        };
    }

    /** Rules that don't fit the generic field spec. */
    private function checkNodeSpecifics(string $id, string $type, array $data, array $context): array
    {
        switch ($type) {
            case 'capture_dtmf':
                $options = array_values(array_filter(
                    (array) ($data['options'] ?? []),
                    fn ($o) => is_array($o) && trim((string) ($o['digit'] ?? '')) !== ''
                ));

                if ($options === []) {
                    $this->errors[] = "Menu node \"{$id}\" has no options — add at least one keypad choice.";
                    break;
                }

                $digits = array_map(fn ($o) => trim((string) $o['digit']), $options);
                if (count($digits) !== count(array_unique($digits))) {
                    $this->errors[] = "Menu node \"{$id}\" repeats a keypad digit — each must be unique.";
                }
                foreach ($digits as $d) {
                    if (! preg_match('/^[0-9*#]$/', $d)) {
                        $this->errors[] = "Menu node \"{$id}\" has option \"{$d}\" — keypad options must be a single "
                            . 'digit 0-9, * or #.';
                    }
                }

                // The editor keeps these in lockstep; regenerate rather than
                // trusting whatever the model wrote.
                $data['options'] = $options;
                $data['button_labels'] = [];
                foreach ($options as $o) {
                    $data['button_labels'][trim((string) $o['digit'])] = (string) ($o['label'] ?? '');
                }
                break;

            case 'collect_input':
                $fields = array_values(array_filter((array) ($data['fields'] ?? []), 'is_array'));
                if ($fields === []) {
                    $this->errors[] = "Collect Input node \"{$id}\" has no questions.";
                    break;
                }
                $keys = [];
                foreach ($fields as $f) {
                    $key = trim((string) ($f['key'] ?? ''));
                    if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                        $this->errors[] = "Collect Input node \"{$id}\" has field key \"{$key}\" — use lower_snake_case "
                            . 'starting with a letter.';
                    }
                    if (in_array($key, $keys, true)) {
                        $this->errors[] = "Collect Input node \"{$id}\" repeats the field key \"{$key}\".";
                    }
                    $keys[] = $key;

                    $it = (string) ($f['input_type'] ?? 'text');
                    if (! in_array($it, ['text', 'email', 'phone', 'number'], true)) {
                        $this->errors[] = "Collect Input node \"{$id}\" field \"{$key}\" has input_type \"{$it}\"; "
                            . 'must be text, email, phone or number.';
                    }
                    if (trim((string) ($f['prompt'] ?? '')) === '') {
                        $this->errors[] = "Collect Input node \"{$id}\" field \"{$key}\" has no prompt.";
                    }
                }
                break;

            case 'datasource':
                $allowed = array_map('intval', $context['source_ids'] ?? []);
                $wanted  = array_map('intval', (array) ($data['source_ids'] ?? []));
                $unknown = array_diff($wanted, $allowed);
                if ($unknown !== []) {
                    $this->errors[] = "Data Source node \"{$id}\" references source id(s) "
                        . implode(', ', $unknown) . ' that this project does not have.';
                }
                break;

            case 'transfer_ai':
                $agentId = $data['agent_id'] ?? null;
                if ($agentId !== null && $agentId !== '') {
                    $allowed = array_map('intval', $context['agent_ids'] ?? []);
                    if (! in_array((int) $agentId, $allowed, true)) {
                        $this->errors[] = "Transfer to AI node \"{$id}\" references agent #{$agentId}, which this "
                            . 'project does not have. Use null for the default agent.';
                    }
                }
                break;

            case 'send_channel':
                $ptype = (string) ($data['payload_type'] ?? 'text');
                if ($ptype === 'text' && trim((string) ($data['text'] ?? '')) === '') {
                    $this->errors[] = "Send to Channel node \"{$id}\" has payload_type=text but no message text.";
                }
                if ($ptype === 'media' && trim((string) ($data['media_url'] ?? '')) === '') {
                    $this->errors[] = "Send to Channel node \"{$id}\" has payload_type=media but no media_url.";
                }
                if ($ptype === 'template' && trim((string) ($data['template_name'] ?? '')) === '') {
                    $this->errors[] = "Send to Channel node \"{$id}\" has payload_type=template but no template_name.";
                }
                break;
        }

        return $data;
    }

    /** Edges must connect real nodes through handles those nodes actually have. */
    private function checkEdges(array $edges, array $nodes, array $ids): array
    {
        $byId = [];
        foreach ($nodes as $n) {
            $byId[$n['id']] = $n;
        }

        $out  = [];
        $seen = [];

        foreach ($edges as $i => $edge) {
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');

            if (! in_array($source, $ids, true) || ! in_array($target, $ids, true)) {
                $this->errors[] = "An edge connects \"{$source}\" → \"{$target}\", but one of those nodes does not exist.";
                continue;
            }
            if ($source === $target) {
                $this->errors[] = "Node \"{$source}\" is wired to itself, which would loop forever.";
                continue;
            }

            $node    = $byId[$source];
            $handles = NodeCatalog::outputsFor((string) $node['type'], $node['data'] ?? []);
            $handle  = (string) ($edge['sourceHandle'] ?? '');

            if ($handles === []) {
                $label = NodeCatalog::get($node['type'])['label'] ?? $node['type'];
                $this->errors[] = "\"{$label}\" (node {$source}) ends the conversation, so nothing can follow it.";
                continue;
            }

            if ($handle === '') {
                // Single-outlet nodes have one obvious answer.
                if (count($handles) === 1) {
                    $handle = $handles[0];
                } else {
                    $this->errors[] = "The edge from \"{$source}\" does not say which branch it leaves by. "
                        . 'Expected one of: ' . implode(', ', $handles) . '.';
                    continue;
                }
            }

            if (! in_array($handle, $handles, true)) {
                $this->errors[] = "The edge from \"{$source}\" leaves by branch \"{$handle}\", which that node "
                    . 'does not have. Available: ' . implode(', ', $handles) . '.';
                continue;
            }

            // One edge per (source, handle) — a branch can't fork.
            $key = $source . '|' . $handle;
            if (in_array($key, $seen, true)) {
                $this->errors[] = "Branch \"{$handle}\" of node \"{$source}\" has more than one outgoing edge.";
                continue;
            }
            $seen[] = $key;

            $out[] = [
                'id'           => (string) ($edge['id'] ?? ('e' . ($i + 1) . '-' . $source . '-' . $target)),
                'source'       => $source,
                'target'       => $target,
                'sourceHandle' => $handle,
            ];
        }

        return $out;
    }

    /** Whole-graph checks: one start, reachability, dead ends. */
    private function checkStructure(array $nodes, array $edges, array $ids): void
    {
        $starts = array_values(array_filter($nodes, fn ($n) => ($n['type'] ?? '') === 'start'));

        if ($starts === []) {
            $this->errors[] = 'The flow has no start node.';

            return;
        }
        if (count($starts) > 1) {
            $this->errors[] = 'The flow has ' . count($starts) . ' start nodes — there must be exactly one.';
        }

        // Reachability from the start.
        $adj = [];
        foreach ($edges as $e) {
            $adj[$e['source']][] = $e['target'];
        }

        $reached = [];
        $queue   = [$starts[0]['id']];
        while ($queue !== []) {
            $cur = array_shift($queue);
            if (in_array($cur, $reached, true)) {
                continue;
            }
            $reached[] = $cur;
            foreach ($adj[$cur] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        foreach ($nodes as $n) {
            if (! in_array($n['id'], $reached, true)) {
                $label = NodeCatalog::get($n['type'])['label'] ?? $n['type'];
                $this->warnings[] = "\"{$label}\" (node {$n['id']}) can't be reached from the start — nothing links to it.";
            }
        }

        // Dead ends: a non-terminal node with an unwired branch leaves the
        // customer hanging mid-conversation.
        foreach ($nodes as $n) {
            $type = (string) ($n['type'] ?? '');
            $def  = NodeCatalog::get($type);
            if ($def === null || $def['terminal']) {
                continue;
            }

            $handles = NodeCatalog::outputsFor($type, $n['data'] ?? []);
            $wired   = array_column(array_filter($edges, fn ($e) => $e['source'] === $n['id']), 'sourceHandle');
            $missing = array_diff($handles, $wired);

            if ($missing !== [] && in_array($n['id'], $reached, true)) {
                $this->warnings[] = "\"{$def['label']}\" (node {$n['id']}) has nothing wired to its "
                    . implode(', ', array_map(fn ($h) => "\"{$h}\"", $missing))
                    . ' branch' . (count($missing) === 1 ? '' : 'es') . ' — the conversation would stop there.';
            }
        }
    }

    private function result(array $definition): array
    {
        return [
            'ok'         => $this->errors === [],
            'errors'     => array_values(array_unique($this->errors)),
            'warnings'   => array_values(array_unique($this->warnings)),
            'definition' => $definition,
        ];
    }
}
