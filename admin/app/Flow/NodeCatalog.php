<?php

namespace App\Flow;

/**
 * What a flow can actually do — the single source of truth for the AI flow
 * builder and its validator.
 *
 * Three things read this and must never disagree:
 *   1. FlowPlanner  — turns it into the capability list in the LLM prompt, so
 *                     the model can only propose nodes that exist.
 *   2. FlowValidator — checks every generated node against it before anything
 *                     is written to the database.
 *   3. The gap report — explains to the customer, in their own terms, which
 *                     parts of their request can't be built and what to do
 *                     instead.
 *
 * ── The support matrix is the important part ──────────────────────────────
 *
 * The editor's palette (resources/js/flow-editor/index.jsx → NODE_TYPES) shows
 * every node type on every flow, but the two runtimes do NOT implement the
 * same set, and they fail differently:
 *
 *   Voice (App\Services\Flow\FlowRunner) implements start, say, capture_dtmf,
 *   transfer_ai and end only. Any other node hits the `default:` branch and
 *   HANGS UP on the caller — the worst possible failure, silently.
 *
 *   Chat (App\Services\Flow\WebFlowRunner) additionally implements
 *   capture_speech, datasource, collect_input and send_channel. `wait`,
 *   `webhook`, `branch` and `transfer_human` fall through to a no-op that just
 *   advances to the next node — the flow keeps running but the step does
 *   nothing at all.
 *
 * So a node is described here with the channels it genuinely runs on. A flow
 * the AI writes for a phone number must stay inside the voice set; one written
 * for chat may use the wider set. Building something the runtime will drop on
 * the floor and calling it done is precisely the failure this catalog exists
 * to prevent.
 *
 * When you add a runtime implementation, update `channels` here in the same
 * commit — FlowCatalogParityTest fails if this list and the editor's palette
 * drift apart, but no test can tell you a runner learned a new node type.
 */
final class NodeCatalog
{
    public const CHANNEL_VOICE = 'voice';
    public const CHANNEL_CHAT  = 'chat';

    /** Node types that do nothing at runtime on any channel today. */
    public const INERT = ['wait', 'webhook', 'branch', 'transfer_human'];

    /**
     * Full node definitions.
     *
     * Each entry:
     *   label       — human name, matches the editor palette
     *   purpose     — one line the LLM uses to pick the right node
     *   channels    — runtimes that actually implement it
     *   terminal    — true when the node ends the conversation (no outputs)
     *   outputs     — static branch handle ids, or 'dynamic' when computed
     *   fields      — data keys: type, required, enum, default, note
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $both  = [self::CHANNEL_VOICE, self::CHANNEL_CHAT];
        $chat  = [self::CHANNEL_CHAT];

        return [
            'start' => [
                'label'    => 'Start',
                'purpose'  => 'Entry point. Exactly one per flow; every flow must have it.',
                'channels' => $both,
                'terminal' => false,
                'outputs'  => ['out'],
                'fields'   => [
                    'label' => ['type' => 'string', 'required' => false, 'default' => 'Call connects'],
                ],
            ],

            'say' => [
                'label'    => 'Say',
                'purpose'  => 'Speak (or show) a fixed message, then continue. Use for greetings, confirmations and instructions.',
                'channels' => $both,
                'terminal' => false,
                'outputs'  => ['out'],
                'fields'   => [
                    'source'          => ['type' => 'string', 'required' => true, 'enum' => ['tts', 'audio'], 'default' => 'tts'],
                    'text'            => ['type' => 'string', 'required' => true, 'note' => 'Required when source=tts. Supports {{ variable }} placeholders.'],
                    'audio_asset_id'  => ['type' => 'int|null', 'required' => false, 'note' => 'Only when source=audio. The AI must not invent asset ids — always use source=tts.'],
                    'language'        => ['type' => 'string', 'required' => false, 'note' => 'Blank inherits the flow language.'],
                ],
            ],

            'capture_dtmf' => [
                'label'    => 'Capture DTMF / Menu',
                'purpose'  => 'Ask a question and branch on the caller\'s keypad press (or a button click in chat). The main way to build a menu.',
                'channels' => $both,
                'terminal' => false,
                // One handle per option digit, plus 'timeout'. Computed per node.
                'outputs'  => 'dynamic',
                'fields'   => [
                    'prompt_source'         => ['type' => 'string', 'required' => true, 'enum' => ['tts', 'audio'], 'default' => 'tts'],
                    'prompt'                => ['type' => 'string', 'required' => true, 'note' => 'Read the options aloud — callers cannot see them.'],
                    'prompt_audio_asset_id' => ['type' => 'int|null', 'required' => false],
                    'language'              => ['type' => 'string', 'required' => false],
                    'timeout_secs'          => ['type' => 'int', 'required' => false, 'default' => 8],
                    'max_digits'            => ['type' => 'int', 'required' => false, 'default' => 1],
                    'options'               => [
                        'type' => 'array<{digit:string,label:string}>', 'required' => true,
                        'note' => 'Unlimited. `digit` is the keypad key AND the edge sourceHandle. `label` is the chat button text.',
                    ],
                    'button_labels'         => ['type' => 'object', 'required' => false, 'note' => 'Derived from options automatically — never hand-write it.'],
                ],
            ],

            'capture_speech' => [
                'label'    => 'Capture Speech',
                'purpose'  => 'Branch on what the customer says or types, matched against a phrase list.',
                'channels' => $chat,
                'terminal' => false,
                'outputs'  => ['match', 'no_match', 'timeout'],
                'fields'   => [
                    'prompt_source'         => ['type' => 'string', 'required' => true, 'enum' => ['tts', 'audio'], 'default' => 'tts'],
                    'prompt'                => ['type' => 'string', 'required' => true],
                    'prompt_audio_asset_id' => ['type' => 'int|null', 'required' => false],
                    'language'              => ['type' => 'string', 'required' => false],
                    'match_phrases'         => ['type' => 'string', 'required' => true, 'note' => 'Comma-separated keywords.'],
                    'timeout_secs'          => ['type' => 'int', 'required' => false, 'default' => 6],
                ],
            ],

            'transfer_ai' => [
                'label'    => 'Transfer to AI',
                'purpose'  => 'Hand the conversation to the AI agent for free-form Q&A. Terminal — the flow stops steering after this.',
                'channels' => $both,
                'terminal' => true,
                'outputs'  => [],
                'fields'   => [
                    'agent_id'         => ['type' => 'int|null', 'required' => false, 'note' => 'Must be an id from the project agent list, or null for the default agent.'],
                    'persona_override' => ['type' => 'string', 'required' => false],
                ],
            ],

            'datasource' => [
                'label'    => 'Data Source',
                'purpose'  => 'Scope the AI to specific knowledge sources from this point on. Place before a Transfer to AI.',
                'channels' => $chat,
                'terminal' => false,
                'outputs'  => ['out'],
                'fields'   => [
                    'label'      => ['type' => 'string', 'required' => false, 'default' => 'Use knowledge'],
                    'source_ids' => ['type' => 'array<int>', 'required' => true, 'note' => 'Must be ids from the project data-source list. Empty = all sources.'],
                ],
            ],

            'collect_input' => [
                'label'    => 'Collect Input',
                'purpose'  => 'Ask one or more questions in sequence and store each answer as a {{ variable }} for later nodes.',
                'channels' => $chat,
                'terminal' => false,
                'outputs'  => ['collected', 'timeout'],
                'fields'   => [
                    'fields'   => [
                        'type' => 'array<{key:string,prompt:string,input_type:string}>', 'required' => true,
                        'note' => 'input_type is one of text|email|phone|number. `key` becomes {{ key }} elsewhere.',
                    ],
                    'language' => ['type' => 'string', 'required' => false],
                ],
            ],

            'send_channel' => [
                'label'    => 'Send to Channel',
                'purpose'  => 'Send a WhatsApp / Messenger / Instagram message to the customer, using a number captured earlier.',
                'channels' => $chat,
                'terminal' => false,
                'outputs'  => ['sent', 'error'],
                'fields'   => [
                    'provider'        => ['type' => 'string', 'required' => true, 'enum' => ['whatsapp', 'messenger', 'instagram'], 'default' => 'whatsapp'],
                    'recipient_field' => ['type' => 'string', 'required' => true, 'note' => 'The collect_input key holding the number, e.g. whatsapp_number.'],
                    'payload_type'    => ['type' => 'string', 'required' => true, 'enum' => ['text', 'media', 'template'], 'default' => 'text'],
                    'text'            => ['type' => 'string', 'required' => false, 'note' => 'Required when payload_type=text.'],
                    'media_type'      => ['type' => 'string', 'required' => false, 'enum' => ['document', 'image', 'video', 'audio', 'file']],
                    'media_url'       => ['type' => 'string', 'required' => false, 'note' => 'Required when payload_type=media.'],
                    'caption'         => ['type' => 'string', 'required' => false],
                    'template_name'   => ['type' => 'string', 'required' => false, 'note' => 'Required when payload_type=template; must be an approved WhatsApp template.'],
                    'template_lang'   => ['type' => 'string', 'required' => false, 'default' => 'en_US'],
                ],
            ],

            'end' => [
                'label'    => 'End call',
                'purpose'  => 'Say a closing line and end the conversation. Terminal.',
                'channels' => $both,
                'terminal' => true,
                'outputs'  => [],
                'fields'   => [
                    'message' => ['type' => 'string', 'required' => true, 'default' => 'Thanks, goodbye!'],
                ],
            ],

            // ── Present in the palette, implemented by neither runtime ──────
            // Kept in the catalog so the planner can recognise a request that
            // needs them and say so, instead of quietly building something
            // that looks right and does nothing.

            'webhook' => [
                'label'    => 'Webhook',
                'purpose'  => 'Call an external HTTP API mid-flow.',
                'channels' => [],
                'terminal' => false,
                'outputs'  => ['ok', 'error'],
                'fields'   => [
                    'method'       => ['type' => 'string', 'required' => true, 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'default' => 'POST'],
                    'url'          => ['type' => 'string', 'required' => true],
                    'headers'      => ['type' => 'json-string', 'required' => false, 'default' => '{}'],
                    'body'         => ['type' => 'json-string', 'required' => false, 'default' => '{}'],
                    'timeout_secs' => ['type' => 'int', 'required' => false, 'default' => 6],
                ],
            ],

            'wait' => [
                'label'    => 'Wait',
                'purpose'  => 'Pause before the next step.',
                'channels' => [],
                'terminal' => false,
                'outputs'  => ['out'],
                'fields'   => [
                    'seconds' => ['type' => 'int', 'required' => true, 'default' => 3],
                ],
            ],

            'branch' => [
                'label'    => 'Branch (if/else)',
                'purpose'  => 'Split on a condition over a stored variable.',
                'channels' => [],
                'terminal' => false,
                'outputs'  => ['true', 'false'],
                'fields'   => [
                    'expression' => ['type' => 'string', 'required' => true, 'note' => 'e.g. {{ last_dtmf }} == "1"'],
                ],
            ],

            'transfer_human' => [
                'label'    => 'Transfer to Human',
                'purpose'  => 'Forward the call to a human on another number.',
                'channels' => [],
                'terminal' => true,
                'outputs'  => [],
                'fields'   => [
                    'phone'   => ['type' => 'string', 'required' => true],
                    'whisper' => ['type' => 'string', 'required' => false],
                ],
            ],
        ];
    }

    /** @return array<int,string> every known node type */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    /**
     * Node types that genuinely run on a channel.
     *
     * @return array<int,string>
     */
    public static function typesFor(string $channel): array
    {
        return array_keys(array_filter(
            self::all(),
            fn ($def) => in_array($channel, $def['channels'], true)
        ));
    }

    public static function supports(string $type, string $channel): bool
    {
        $def = self::get($type);

        return $def !== null && in_array($channel, $def['channels'], true);
    }

    /** True for node types no runtime implements yet. */
    public static function isInert(string $type): bool
    {
        $def = self::get($type);

        return $def !== null && $def['channels'] === [];
    }

    /**
     * Branch handle ids a node offers. capture_dtmf is computed from its own
     * options, mirroring dtmfOutputs() in the editor: one handle per unique
     * non-blank digit, plus 'timeout'.
     *
     * @return array<int,string>
     */
    public static function outputsFor(string $type, array $data = []): array
    {
        $def = self::get($type);
        if ($def === null) {
            return [];
        }

        if ($def['outputs'] !== 'dynamic') {
            return $def['outputs'];
        }

        $out  = [];
        $seen = [];
        foreach ((array) ($data['options'] ?? []) as $opt) {
            $digit = trim((string) ($opt['digit'] ?? ''));
            if ($digit === '' || in_array($digit, $seen, true)) {
                continue;
            }
            $seen[] = $digit;
            $out[]  = $digit;
        }
        $out[] = 'timeout';

        return $out;
    }

    /**
     * Compact capability description handed to the LLM. Only the node types
     * usable on the target channel are listed, so the model cannot pick one
     * the runtime would drop.
     */
    public static function promptSpec(string $channel): string
    {
        $lines = [];

        foreach (self::all() as $type => $def) {
            if (! in_array($channel, $def['channels'], true)) {
                continue;
            }

            $outputs = $def['outputs'] === 'dynamic'
                ? 'one per options[].digit, plus "timeout"'
                : (empty($def['outputs']) ? 'none (terminal)' : implode(', ', $def['outputs']));

            $fields = [];
            foreach ($def['fields'] as $name => $f) {
                $bits = $f['type'];
                if (! empty($f['enum'])) {
                    $bits .= ' one of [' . implode('|', $f['enum']) . ']';
                }
                $bits .= ($f['required'] ?? false) ? ' REQUIRED' : ' optional';
                if (! empty($f['note'])) {
                    $bits .= ' — ' . $f['note'];
                }
                $fields[] = "      {$name}: {$bits}";
            }

            $lines[] = "- {$type} ({$def['label']}): {$def['purpose']}\n"
                . "    outputs: {$outputs}\n"
                . "    data:\n" . implode("\n", $fields);
        }

        return implode("\n", $lines);
    }

    /**
     * The things a customer may ask for that this builder genuinely cannot do,
     * with the closest workable alternative. Handed to the LLM so its refusals
     * are concrete and actionable instead of "not supported".
     *
     * @return array<int,array{cannot:string,because:string,instead:string}>
     */
    public static function knownGaps(string $channel): array
    {
        $gaps = [
            [
                'cannot'  => 'Call an external API, CRM or booking system mid-flow',
                'because' => 'The Webhook node exists in the palette but no runtime executes it yet — it is skipped silently.',
                'instead' => 'Collect the details with Collect Input and hand off to the AI agent, then push to your system from the lead record.',
            ],
            [
                'cannot'  => 'Branch on a stored value with if/else logic',
                'because' => 'The Branch node is not executed by either runtime yet.',
                'instead' => 'Use a Capture DTMF menu so the customer picks the path explicitly, or Capture Speech to branch on keywords.',
            ],
            [
                'cannot'  => 'Forward the conversation to a human on another phone number',
                'because' => 'Transfer to Human is not implemented in either runtime.',
                'instead' => 'Collect a callback number and tell the customer a person will ring back, or transfer to the AI agent with a persona that promises a callback.',
            ],
            [
                'cannot'  => 'Pause for a number of seconds between steps',
                'because' => 'The Wait node is skipped by both runtimes.',
                'instead' => 'Fold the delay into the wording of the preceding Say node.',
            ],
            [
                'cannot'  => 'Send an email',
                'because' => 'There is no email node — Send to Channel covers WhatsApp, Messenger and Instagram only.',
                'instead' => 'Collect the email address so your team (or an automation on the lead record) can follow up.',
            ],
            [
                'cannot'  => 'Take a payment, or book a calendar slot directly',
                'because' => 'No payment or calendar node exists.',
                'instead' => 'Capture the details and send a payment or booking link with Send to Channel (chat flows), or have the AI agent share the link.',
            ],
        ];

        if ($channel === self::CHANNEL_VOICE) {
            // On a phone call these are worse than unsupported: the voice
            // runner hangs up when it meets a node it cannot render.
            $gaps[] = [
                'cannot'  => 'Ask open-ended questions and store the answers on a phone call',
                'because' => 'Collect Input, Capture Speech, Data Source and Send to Channel run in chat flows only. On a call the runtime hangs up when it reaches one.',
                'instead' => 'Use a Capture DTMF keypad menu to route the caller, then Transfer to AI — the agent can ask freely from there.',
            ];
            $gaps[] = [
                'cannot'  => 'Send a WhatsApp message from a voice flow',
                'because' => 'Send to Channel is not implemented in the voice runtime.',
                'instead' => 'Transfer to AI and let the agent follow up, or build this as a chat flow instead.',
            ];
        }

        return $gaps;
    }
}
