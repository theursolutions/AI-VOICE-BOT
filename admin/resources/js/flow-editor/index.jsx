/**
 * Flow Builder — React Flow micro-app.
 *
 * Mounts on /c/{slug}/flows/{id}/editor. Reads mount-point data
 * attributes (flow id, project id, csrf), fetches the flow definition,
 * renders a draggable canvas with custom node types, and saves the
 * graph back via PUT /flows/{id}/definition.
 *
 * Six node types in this MVP:
 *   • start              — entry point (one per flow)
 *   • say                — TTS or pre-recorded audio
 *   • capture_dtmf       — wait for keypad press, branches by digit
 *   • capture_speech     — wait for utterance, branches by phrase match
 *   • transfer_ai        — hand off to free-form AI agent
 *   • end                — hang up
 *
 * Each node renders its own card with a header, summary lines pulled
 * from `data`, and the appropriate handles (input/output/per-branch).
 *
 * Properties panel on the right swaps content based on the selected
 * node — every field is two-way bound to the React Flow state.
 */
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
    ReactFlow,
    ReactFlowProvider,
    Background,
    Controls,
    MiniMap,
    Handle,
    Position,
    addEdge,
    useNodesState,
    useEdgesState,
    useReactFlow,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import './styles.css';

// ────────────────────────────────────────────────────────────────────
// Node type registry — single source of truth for node config.
//
// To add a brand-new node type, do TWO things:
//
//   1) Drop a new entry below with:
//        - label        : sidebar + properties heading
//        - icon         : single emoji or short string shown on the chip
//        - color        : top border color + chip background
//        - outputs      : array of { id, label } — one handle per branch.
//                         Single-output nodes can use [{id:'out', label:''}].
//        - defaultData  : the shape stored under node.data when spawned
//        - summary(d)   : returns a short string for the canvas card
//
//   2) Add a properties-panel section inside <PropertiesPanel> with
//      `node.type === 'your_new_type'` that renders the editor fields.
//
// That's it. The toolbox + canvas renderer pick it up automatically.
// Custom runtime behaviour is added later in Phase 1C inside the
// Python FlowRunner — but the editor doesn't need to know about that.
// ────────────────────────────────────────────────────────────────────
const NODE_TYPES = {
    start: {
        label: 'Start',
        icon: '▶',
        color: '#10b981',
        outputs: [{ id: 'out', label: '' }],
        defaultData: { label: 'Call connects' },
        summary: () => 'Entry point',
    },
    say: {
        label: 'Say',
        icon: '🗣️',
        color: '#3b82f6',
        outputs: [{ id: 'out', label: '' }],
        defaultData: { source: 'tts', text: 'Hello, thanks for calling.', audio_asset_id: null, language: '' },
        summary: (data) => (data.source === 'audio'
            ? `▶ uploaded audio (#${data.audio_asset_id ?? '—'})`
            : `“${(data.text || '').slice(0, 60)}${(data.text || '').length > 60 ? '…' : ''}”`),
    },
    capture_dtmf: {
        label: 'Capture DTMF / Menu',
        icon: '☎',
        color: '#f59e0b',
        outputs: [
            { id: '1', label: '1' },
            { id: '2', label: '2' },
            { id: '3', label: '3' },
            { id: '4', label: '4' },
            { id: '0', label: '0' },
            { id: 'timeout', label: 'timeout' },
        ],
        defaultData: {
            prompt_source: 'tts',   // 'tts' | 'audio'
            prompt: 'Press 1 for billing, 2 for sales, 0 for an agent.',
            prompt_audio_asset_id: null,
            language: '',
            timeout_secs: 8,
            max_digits: 1,
            // Per-output button labels — used in webchat to render quick-reply
            // buttons. Phone ignores these (uses the digit handle id).
            // Map: { "1": "Billing", "2": "Sales", "0": "Agent" }
            button_labels: {},
        },
        summary: (data) => {
            const src = data.prompt_source === 'audio'
                ? `▶ audio #${data.prompt_audio_asset_id ?? '—'}`
                : `“${(data.prompt || '').slice(0, 30)}${(data.prompt || '').length > 30 ? '…' : ''}”`;
            return `${src} · ${data.timeout_secs ?? 8}s`;
        },
    },
    capture_speech: {
        label: 'Capture Speech',
        icon: '🎙️',
        color: '#8b5cf6',
        outputs: [
            { id: 'match',   label: 'match' },
            { id: 'no_match',label: 'no match' },
            { id: 'timeout', label: 'timeout' },
        ],
        defaultData: {
            prompt_source: 'tts',
            prompt: 'How can I help you today?',
            prompt_audio_asset_id: null,
            language: '',
            match_phrases: 'billing, payment, invoice',
            timeout_secs: 6,
        },
        summary: (data) => `“${(data.match_phrases || '').slice(0, 40)}${(data.match_phrases || '').length > 40 ? '…' : ''}”`,
    },
    transfer_ai: {
        label: 'Transfer to AI',
        icon: '🤖',
        color: '#06b6d4',
        outputs: [],
        defaultData: { agent_id: null, persona_override: '' },
        summary: (data) => data.agent_id ? `→ agent #${data.agent_id}` : 'free-form AI (default agent)',
    },
    end: {
        label: 'End call',
        icon: '⏹',
        color: '#ef4444',
        outputs: [],
        defaultData: { message: 'Thanks, goodbye!' },
        summary: (data) => (data.message ? `“${data.message.slice(0, 50)}”` : 'hang up'),
    },

    // ── New utility nodes — added to demonstrate the extension pattern.

    webhook: {
        label: 'Webhook',
        icon: '🔗',
        color: '#0ea5e9',
        outputs: [
            { id: 'ok',    label: 'ok'    },
            { id: 'error', label: 'error' },
        ],
        defaultData: {
            method: 'POST',
            url: 'https://example.com/api/lead',
            headers: '{}',       // JSON string
            body: '{}',          // JSON string
            timeout_secs: 6,
        },
        summary: (data) => `${data.method || 'POST'} ${(data.url || '').replace(/^https?:\/\//, '').slice(0, 36)}`,
    },

    wait: {
        label: 'Wait',
        icon: '⏳',
        color: '#facc15',
        outputs: [{ id: 'out', label: '' }],
        defaultData: { seconds: 3 },
        summary: (data) => `pause ${data.seconds ?? 3}s`,
    },

    branch: {
        label: 'Branch (if/else)',
        icon: '🧭',
        color: '#a855f7',
        outputs: [
            { id: 'true',  label: 'true'  },
            { id: 'false', label: 'false' },
        ],
        defaultData: {
            // simple expression syntax we'll evaluate at runtime:
            //   {{ caller_phone }} startsWith "+92"
            //   {{ last_dtmf }} == "1"
            //   {{ session.attempt }} > 2
            expression: '{{ last_dtmf }} == "1"',
        },
        summary: (data) => `if ${data.expression || '—'}`,
    },

    transfer_human: {
        label: 'Transfer to Human',
        icon: '☎️',
        color: '#14b8a6',
        outputs: [],
        defaultData: { phone: '+1', whisper: '' },
        summary: (data) => `→ ${data.phone || '—'}`,
    },
};

// ────────────────────────────────────────────────────────────────────
// Custom node card component — used for ALL node types. The header /
// color / outputs come from the registry; the body just renders
// summary text.
// ────────────────────────────────────────────────────────────────────
function FlowNode({ id, data, selected, type }) {
    const cfg = NODE_TYPES[type] || NODE_TYPES.say;
    const hasInput = type !== 'start';

    return (
        <div className={`fb-node ${selected ? 'is-selected' : ''}`} style={{ borderTopColor: cfg.color }}>
            {hasInput && (
                <Handle type="target" position={Position.Top} id="in" className="fb-handle" />
            )}
            <div className="fb-node__head">
                <span className="fb-node__icon" style={{ background: cfg.color }}>{cfg.icon}</span>
                <span className="fb-node__title">{cfg.label}</span>
            </div>
            <div className="fb-node__summary">{cfg.summary(data || {})}</div>

            {/* Output handles. Multiple outputs → labelled (DTMF, speech). */}
            {cfg.outputs.length === 1 && (
                <Handle type="source" position={Position.Bottom} id={cfg.outputs[0].id} className="fb-handle" />
            )}
            {cfg.outputs.length > 1 && (
                <div className="fb-node__outputs">
                    {cfg.outputs.map((o) => (
                        <div key={o.id} className="fb-node__out-row">
                            <span className="fb-node__out-label">{o.label}</span>
                            {/* Anchored absolute to the row's right edge via CSS
                                so each output handle sits flush with its label
                                row instead of stacking at the node's right edge. */}
                            <Handle
                                type="source"
                                position={Position.Right}
                                id={o.id}
                                className="fb-handle fb-handle--row"
                            />
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

// All custom types map to the same renderer; React Flow looks up by string.
const nodeTypes = Object.fromEntries(
    Object.keys(NODE_TYPES).map((k) => [k, FlowNode])
);

// ────────────────────────────────────────────────────────────────────
// Toolbox (left rail) — draggable templates.
// User drags an icon onto the canvas; we read the dataTransfer payload
// in onDrop and spawn a new node at the cursor position.
// ────────────────────────────────────────────────────────────────────
function Toolbox() {
    const onDragStart = (e, nodeType) => {
        e.dataTransfer.setData('application/reactflow', nodeType);
        e.dataTransfer.effectAllowed = 'move';
    };

    return (
        <aside className="fb-toolbox">
            <div className="fb-toolbox__title">Nodes</div>
            <div className="fb-toolbox__hint">Drag onto canvas</div>
            {Object.entries(NODE_TYPES).map(([key, cfg]) => (
                key === 'start' ? null : (
                    <div
                        key={key}
                        className="fb-toolbox__item"
                        draggable
                        onDragStart={(e) => onDragStart(e, key)}
                        style={{ borderLeftColor: cfg.color }}
                    >
                        <span className="fb-toolbox__icon" style={{ background: cfg.color }}>{cfg.icon}</span>
                        <span>{cfg.label}</span>
                    </div>
                )
            ))}
        </aside>
    );
}

// ────────────────────────────────────────────────────────────────────
// Properties panel (right) — content depends on selected node type.
// All fields are bound via onChange → update the node's data via the
// `updateNode` prop. Closes when nothing is selected.
// ────────────────────────────────────────────────────────────────────
function PropertiesPanel({ node, updateNode, deleteNode, onClose }) {
    if (!node) {
        return (
            <aside className="fb-props">
                <div className="fb-props__head">
                    <span className="fb-props__title" style={{ color: '#94a3b8', fontWeight: 500 }}>Properties</span>
                    <button className="fb-props__close" onClick={onClose} title="Close properties panel (Esc)">✕</button>
                </div>
                <div className="fb-props__empty">
                    <div style={{ fontSize: '28px', marginBottom: '8px' }}>🪶</div>
                    <div>Select a node to edit it.</div>
                </div>
            </aside>
        );
    }

    const cfg = NODE_TYPES[node.type];
    const set = (key, value) => updateNode(node.id, { ...node.data, [key]: value });

    return (
        <aside className="fb-props">
            <div className="fb-props__head">
                <span className="fb-toolbox__icon" style={{ background: cfg.color }}>{cfg.icon}</span>
                <span className="fb-props__title">{cfg.label}</span>
                {node.type !== 'start' && (
                    <button className="fb-props__delete" onClick={() => deleteNode(node.id)} title="Delete node">
                        🗑
                    </button>
                )}
                <button className="fb-props__close" onClick={onClose} title="Close (Esc)">✕</button>
            </div>

            {node.type === 'start' && (
                <div className="fb-props__body">
                    <Field label="Label">
                        <input value={node.data?.label || ''} onChange={(e) => set('label', e.target.value)} />
                    </Field>
                    <Help>Calls enter the flow at this node. There can only be one Start.</Help>
                </div>
            )}

            {node.type === 'say' && (
                <div className="fb-props__body">
                    <Field label="Source">
                        <select value={node.data?.source || 'tts'} onChange={(e) => set('source', e.target.value)}>
                            <option value="tts">Text → TTS (cloned voice)</option>
                            <option value="audio">Pre-recorded audio</option>
                        </select>
                    </Field>
                    {node.data?.source === 'audio' ? (
                        <Field label="Audio asset ID">
                            <input
                                type="number"
                                value={node.data?.audio_asset_id ?? ''}
                                onChange={(e) => set('audio_asset_id', e.target.value ? parseInt(e.target.value, 10) : null)}
                                placeholder="upload UI lands in 1B+"
                            />
                            <Help>Audio upload UI ships next pass. For now type an ID after uploading via API.</Help>
                        </Field>
                    ) : (
                        <Field label="Text to speak">
                            <textarea
                                rows={4}
                                value={node.data?.text || ''}
                                onChange={(e) => set('text', e.target.value)}
                                placeholder="Hello, thanks for calling…"
                            />
                        </Field>
                    )}
                    <LanguageField value={node.data?.language || ''} onChange={(v) => set('language', v)} />
                </div>
            )}

            {node.type === 'capture_dtmf' && (
                <div className="fb-props__body">
                    <Field label="Prompt source">
                        <select value={node.data?.prompt_source || 'tts'} onChange={(e) => set('prompt_source', e.target.value)}>
                            <option value="tts">Text → TTS</option>
                            <option value="audio">Pre-recorded audio</option>
                        </select>
                    </Field>
                    {node.data?.prompt_source === 'audio' ? (
                        <Field label="Audio asset ID">
                            <input
                                type="number"
                                value={node.data?.prompt_audio_asset_id ?? ''}
                                onChange={(e) => set('prompt_audio_asset_id', e.target.value ? parseInt(e.target.value, 10) : null)}
                                placeholder="upload via API for now"
                            />
                            <Help>Upload UI lands next pass — for now upload via the /flow-assets API and paste the ID here.</Help>
                        </Field>
                    ) : (
                        <Field label="Prompt text (TTS)">
                            <textarea rows={3} value={node.data?.prompt || ''} onChange={(e) => set('prompt', e.target.value)} placeholder="Press 1 for…"/>
                        </Field>
                    )}
                    <LanguageField value={node.data?.language || ''} onChange={(v) => set('language', v)} />
                    <Field label="Timeout (seconds)">
                        <input type="number" min="2" max="30" value={node.data?.timeout_secs ?? 8} onChange={(e) => set('timeout_secs', parseInt(e.target.value, 10) || 8)}/>
                    </Field>
                    <Field label="Max digits">
                        <input type="number" min="1" max="20" value={node.data?.max_digits ?? 1} onChange={(e) => set('max_digits', parseInt(e.target.value, 10) || 1)}/>
                    </Field>

                    {/* Per-output button labels — only shown on outputs that
                        are actually wired up (have an outgoing edge). Phone
                        ignores these; webchat shows them as quick-reply
                        button text instead of bare "1"/"2"/"0". */}
                    <Field label="Web button labels (optional)">
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                            {NODE_TYPES.capture_dtmf.outputs
                                .filter((o) => o.id !== 'timeout')
                                .map((o) => (
                                    <div key={o.id} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <code style={{ background: '#1e293b', padding: '2px 7px', borderRadius: 4, minWidth: 26, textAlign: 'center' }}>{o.id}</code>
                                        <input
                                            type="text"
                                            placeholder={`e.g. "Billing" for ${o.id}`}
                                            value={(node.data?.button_labels && node.data.button_labels[o.id]) || ''}
                                            onChange={(e) => set('button_labels', { ...(node.data?.button_labels || {}), [o.id]: e.target.value })}
                                            style={{ flex: 1 }}
                                        />
                                    </div>
                                ))}
                        </div>
                        <Help>Used as button text in the webchat widget. Phone uses the digit ("press 1") regardless. Leave blank to skip rendering that branch on web.</Help>
                    </Field>
                    <Help>One output per digit + a timeout output. Drag from each handle on the right side of the node card.</Help>
                </div>
            )}

            {node.type === 'capture_speech' && (
                <div className="fb-props__body">
                    <Field label="Prompt source">
                        <select value={node.data?.prompt_source || 'tts'} onChange={(e) => set('prompt_source', e.target.value)}>
                            <option value="tts">Text → TTS</option>
                            <option value="audio">Pre-recorded audio</option>
                        </select>
                    </Field>
                    {node.data?.prompt_source === 'audio' ? (
                        <Field label="Audio asset ID">
                            <input
                                type="number"
                                value={node.data?.prompt_audio_asset_id ?? ''}
                                onChange={(e) => set('prompt_audio_asset_id', e.target.value ? parseInt(e.target.value, 10) : null)}
                            />
                        </Field>
                    ) : (
                        <Field label="Prompt text (TTS)">
                            <textarea rows={3} value={node.data?.prompt || ''} onChange={(e) => set('prompt', e.target.value)}/>
                        </Field>
                    )}
                    <LanguageField value={node.data?.language || ''} onChange={(v) => set('language', v)} />
                    <Field label="Match phrases (comma-separated)">
                        <textarea rows={3} value={node.data?.match_phrases || ''} onChange={(e) => set('match_phrases', e.target.value)} placeholder="billing, payment, invoice"/>
                    </Field>
                    <Field label="Timeout (seconds)">
                        <input type="number" min="2" max="30" value={node.data?.timeout_secs ?? 6} onChange={(e) => set('timeout_secs', parseInt(e.target.value, 10) || 6)}/>
                    </Field>
                    <Help>Match if any phrase appears in the caller's transcript (case-insensitive). Else takes "no match".</Help>
                </div>
            )}

            {node.type === 'transfer_ai' && (
                <div className="fb-props__body">
                    <Field label="Agent ID (optional)">
                        <input
                            type="number"
                            value={node.data?.agent_id ?? ''}
                            onChange={(e) => set('agent_id', e.target.value ? parseInt(e.target.value, 10) : null)}
                            placeholder="leave blank for project default"
                        />
                    </Field>
                    <Field label="Persona override">
                        <textarea
                            rows={4}
                            value={node.data?.persona_override || ''}
                            onChange={(e) => set('persona_override', e.target.value)}
                            placeholder="Override the agent's default persona for this branch"
                        />
                    </Field>
                    <Help>Hands the call to the AI agent system. From here the existing /ws/turn loop handles the conversation.</Help>
                </div>
            )}

            {node.type === 'end' && (
                <div className="fb-props__body">
                    <Field label="Goodbye message (TTS)">
                        <textarea rows={3} value={node.data?.message || ''} onChange={(e) => set('message', e.target.value)}/>
                    </Field>
                </div>
            )}

            {node.type === 'webhook' && (
                <div className="fb-props__body">
                    <Field label="Method">
                        <select value={node.data?.method || 'POST'} onChange={(e) => set('method', e.target.value)}>
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="DELETE">DELETE</option>
                        </select>
                    </Field>
                    <Field label="URL">
                        <input type="url" value={node.data?.url || ''} onChange={(e) => set('url', e.target.value)} placeholder="https://api.example.com/leads"/>
                    </Field>
                    <Field label="Headers (JSON)">
                        <textarea rows={3} value={node.data?.headers || '{}'} onChange={(e) => set('headers', e.target.value)} style={{ fontFamily: 'ui-monospace, monospace', fontSize: '12px' }}/>
                    </Field>
                    <Field label="Body (JSON)">
                        <textarea rows={4} value={node.data?.body || '{}'} onChange={(e) => set('body', e.target.value)} style={{ fontFamily: 'ui-monospace, monospace', fontSize: '12px' }}/>
                    </Field>
                    <Field label="Timeout (seconds)">
                        <input type="number" min="1" max="30" value={node.data?.timeout_secs ?? 6} onChange={(e) => set('timeout_secs', parseInt(e.target.value, 10) || 6)}/>
                    </Field>
                    <Help>Supports template vars: <code>&#123;&#123; caller_phone &#125;&#125;</code>, <code>&#123;&#123; last_dtmf &#125;&#125;</code>, <code>&#123;&#123; session_id &#125;&#125;</code>. Takes the "error" branch on non-2xx or timeout.</Help>
                </div>
            )}

            {node.type === 'wait' && (
                <div className="fb-props__body">
                    <Field label="Pause (seconds)">
                        <input type="number" min="1" max="60" value={node.data?.seconds ?? 3} onChange={(e) => set('seconds', parseInt(e.target.value, 10) || 3)}/>
                    </Field>
                    <Help>Plays Twilio's silent pause. Useful for natural-feeling beats between prompts.</Help>
                </div>
            )}

            {node.type === 'branch' && (
                <div className="fb-props__body">
                    <Field label="Expression">
                        <textarea rows={3} value={node.data?.expression || ''} onChange={(e) => set('expression', e.target.value)}
                                  style={{ fontFamily: 'ui-monospace, monospace', fontSize: '12.5px' }}
                                  placeholder='{{ last_dtmf }} == "1"'/>
                    </Field>
                    <Help>
                        Variables: <code>&#123;&#123; caller_phone &#125;&#125;</code>, <code>&#123;&#123; last_dtmf &#125;&#125;</code>, <code>&#123;&#123; last_speech &#125;&#125;</code>, <code>&#123;&#123; session.attempt &#125;&#125;</code>.<br/>
                        Operators: <code>==</code> <code>!=</code> <code>&gt;</code> <code>&lt;</code> <code>startsWith</code> <code>contains</code>.
                    </Help>
                </div>
            )}

            {node.type === 'transfer_human' && (
                <div className="fb-props__body">
                    <Field label="Forward to phone number">
                        <input type="tel" value={node.data?.phone || ''} onChange={(e) => set('phone', e.target.value)} placeholder="+1 415 555 0100"/>
                    </Field>
                    <Field label="Whisper (optional)">
                        <textarea rows={2} value={node.data?.whisper || ''} onChange={(e) => set('whisper', e.target.value)}
                                  placeholder="Hi, the caller pressed 0 from the billing menu."/>
                        <Help>The agent hears this message before the caller is connected.</Help>
                    </Field>
                </div>
            )}
        </aside>
    );
}

function Field({ label, children }) {
    return (
        <label className="fb-field">
            <span className="fb-field__label">{label}</span>
            {children}
        </label>
    );
}
function Help({ children }) {
    return <div className="fb-help">💡 {children}</div>;
}

// Shared language picker — supported set spans the languages Coqui
// XTTS and ElevenLabs cover well. Blank value = inherit from flow.
function LanguageField({ value, onChange }) {
    return (
        <Field label="Language">
            <select value={value || ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">— inherit from flow —</option>
                <option value="en">English</option>
                <option value="ur">Urdu</option>
                <option value="hi">Hindi</option>
                <option value="es">Spanish</option>
                <option value="ar">Arabic</option>
                <option value="fr">French</option>
                <option value="de">German</option>
                <option value="pt">Portuguese</option>
                <option value="zh">Chinese (Mandarin)</option>
            </select>
        </Field>
    );
}

// ────────────────────────────────────────────────────────────────────
// Top toolbar — Save button, status badge, dirty indicator.
// ────────────────────────────────────────────────────────────────────
function Toolbar({ onSave, saving, dirty, lastSavedAt, settings, onSettingsChange, onTestToggle, testOpen }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="fb-toolbar">
            <button
                className={`fb-save-btn ${dirty ? 'is-dirty' : ''}`}
                onClick={onSave}
                disabled={saving}
            >
                {saving ? 'Saving…' : (dirty ? '● Save changes' : '✓ Saved')}
            </button>
            {lastSavedAt && !dirty && (
                <span className="fb-toolbar__hint">last saved {lastSavedAt}</span>
            )}
            <button
                className={`fb-test-btn ${testOpen ? 'is-active' : ''}`}
                onClick={onTestToggle}
                title="Run this flow in a sandboxed test session — nodes light up as they fire."
            >
                {testOpen ? '◼ Stop test' : '▶ Test flow'}
            </button>
            <div className="fb-settings">
                <button className="fb-settings__btn" onClick={() => setOpen((o) => !o)}>
                    ⚙ Flow settings
                </button>
                {open && (
                    <div className="fb-settings__panel">
                        <Field label="Default language for this flow">
                            <select
                                value={settings?.language || 'en'}
                                onChange={(e) => onSettingsChange({ ...settings, language: e.target.value })}
                            >
                                <option value="en">English</option>
                                <option value="ur">Urdu</option>
                                <option value="hi">Hindi</option>
                                <option value="es">Spanish</option>
                                <option value="ar">Arabic</option>
                                <option value="fr">French</option>
                                <option value="de">German</option>
                                <option value="pt">Portuguese</option>
                                <option value="zh">Chinese (Mandarin)</option>
                            </select>
                            <Help>Used by any TTS node that doesn't set its own language override.</Help>
                        </Field>
                        <Field label="Default timeout (seconds)">
                            <input
                                type="number" min="2" max="60"
                                value={settings?.timeout_secs ?? 8}
                                onChange={(e) => onSettingsChange({ ...settings, timeout_secs: parseInt(e.target.value, 10) || 8 })}
                            />
                        </Field>
                        <Field label="Max retries on no-input">
                            <input
                                type="number" min="0" max="5"
                                value={settings?.max_retries ?? 2}
                                onChange={(e) => onSettingsChange({ ...settings, max_retries: parseInt(e.target.value, 10) || 0 })}
                            />
                        </Field>
                    </div>
                )}
            </div>
        </div>
    );
}

// ────────────────────────────────────────────────────────────────────
// Main canvas + state. Drives nodes/edges + persists to backend.
// ────────────────────────────────────────────────────────────────────
// ────────────────────────────────────────────────────────────────────
// Test panel — n8n-style live-run preview docked to the canvas's
// bottom-right corner. Sends each visitor action to /flows/{id}/test/*
// and animates the highlight through the returned execution_path via
// FlowCanvas's animatePath().
// ────────────────────────────────────────────────────────────────────
function TestPanel({ messages, expecting, running, sessionId, onStart, onChoice, onText, onReset, onClose }) {
    const [draft, setDraft] = useState('');
    const scrollRef = useRef(null);

    // Auto-scroll on new bot/user bubbles.
    useEffect(() => {
        const el = scrollRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, [messages.length]);

    const submit = () => {
        const t = draft.trim();
        if (!t) return;
        setDraft('');
        onText(t);
    };

    return (
        <aside className="fb-test-panel">
            <div className="fb-test-panel__head">
                <span className="fb-test-panel__title">
                    <span className="fb-test-panel__dot" /> Test run
                </span>
                <button className="fb-test-panel__action" onClick={onReset} title="Restart from Start node">⟲</button>
                <button className="fb-test-panel__action" onClick={onClose} title="Close test mode (Esc)">✕</button>
            </div>

            <div className="fb-test-panel__messages" ref={scrollRef}>
                {messages.length === 0 && !running && (
                    <div className="fb-test-panel__empty">
                        Press <b>▶ Test flow</b> in the toolbar to start.
                        <br /><br />
                        Each node will light up as it executes so you can watch the path live.
                    </div>
                )}
                {messages.map((m, i) => (
                    <div key={i} className={`fb-test-msg fb-test-msg--${m.role}`}>
                        {m.kind === 'menu' ? (
                            <>
                                {m.prompt && <div className="fb-test-msg__text">{m.prompt}</div>}
                                <div className="fb-test-msg__options">
                                    {(m.options || []).map((opt) => (
                                        <button
                                            key={opt.id}
                                            className="fb-test-msg__opt"
                                            disabled={running || i !== messages.length - 1 || expecting !== 'menu_choice'}
                                            onClick={() => onChoice(opt)}
                                        >
                                            {opt.label}
                                        </button>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <div className="fb-test-msg__text">{m.text}</div>
                        )}
                    </div>
                ))}
                {running && (
                    <div className="fb-test-msg fb-test-msg--bot">
                        <div className="fb-test-msg__text fb-test-msg__typing">
                            <span /><span /><span />
                        </div>
                    </div>
                )}
            </div>

            <div className="fb-test-panel__input">
                <input
                    type="text"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') submit(); }}
                    placeholder={expecting === 'menu_choice' ? 'Click an option above…' : 'Type a reply…'}
                    disabled={running || !sessionId || expecting === 'none'}
                />
                <button
                    onClick={submit}
                    disabled={running || !sessionId || !draft.trim()}
                    title="Send"
                >➤</button>
            </div>
        </aside>
    );
}

function FlowCanvas({ flowId, projectId, csrf, clientSlug, baseUrl = '' }) {
    const [nodes, setNodes, onNodesChange] = useNodesState([]);
    const [edges, setEdges, onEdgesChange] = useEdgesState([]);
    const [settings, setSettings] = useState({ language: 'en', timeout_secs: 8, max_retries: 2 });
    const [selectedId, setSelectedId] = useState(null);
    // Properties panel is collapsed by default so the canvas gets the
    // full width. Double-click on a node opens it; ✕ in the panel
    // (or Esc) closes it again.
    const [propsOpen, setPropsOpen] = useState(false);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [lastSavedAt, setLastSavedAt] = useState(null);

    // Test mode — small chat preview docked to the canvas, executes the
    // flow against a sandboxed (channel='test') session in the tenant DB
    // and animates highlights through the execution_path as nodes fire.
    // n8n-style "watch it run" loop, scoped to this editor only.
    const [testOpen, setTestOpen]               = useState(false);
    const [testSessionId, setTestSessionId]     = useState(null);
    const [testMessages, setTestMessages]       = useState([]);  // [{role,kind,text,options?}]
    const [testExpecting, setTestExpecting]     = useState('none');
    const [testRunning, setTestRunning]         = useState(false);
    // runStatus: map of node-id → 'active' | 'visited'. Decorates the
    // ReactFlow nodes with classNames so CSS can pulse/tint them.
    const [runStatus, setRunStatus]             = useState({});

    const wrapperRef = useRef(null);
    const { screenToFlowPosition } = useReactFlow();

    // Mark dirty whenever nodes/edges/settings change AFTER initial load.
    const initialLoadDone = useRef(false);
    useEffect(() => {
        if (initialLoadDone.current) setDirty(true);
    }, [nodes, edges, settings]);

    // Load definition from API on mount.
    useEffect(() => {
        const url = `${baseUrl}/c/${clientSlug}/flows/${flowId}/definition?project_id=${projectId}`;
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((body) => {
                const def = body.definition || { nodes: [], edges: [] };
                setNodes(def.nodes || []);
                setEdges(def.edges || []);
                if (def.settings) setSettings({ ...settings, ...def.settings });
                setLoading(false);
                requestAnimationFrame(() => { initialLoadDone.current = true; });
            })
            .catch((err) => {
                console.error('flow load failed', err);
                setLoading(false);
                initialLoadDone.current = true;
            });
    }, [flowId, projectId, clientSlug, setNodes, setEdges]);

    // Save to backend (debounced auto-save + manual).
    const save = useCallback(async () => {
        if (saving) return;
        setSaving(true);
        try {
            const res = await fetch(`${baseUrl}/c/${clientSlug}/flows/${flowId}/definition`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    project_id: projectId,
                    definition: { nodes, edges, settings },
                }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            setDirty(false);
            const now = new Date();
            setLastSavedAt(now.toLocaleTimeString());
        } catch (err) {
            console.error('flow save failed', err);
            alert('Save failed — check console for details.');
        } finally {
            setSaving(false);
        }
    }, [clientSlug, flowId, projectId, csrf, nodes, edges, settings, saving]);

    // ── Test mode helpers ─────────────────────────────────────────────
    // Animate node highlights through the execution_path returned by
    // the server. Each visited node gets a brief "active" pulse, then
    // settles to "visited"; the LAST node in the path stays active
    // (it's where the flow is currently waiting for input).
    const animatePath = useCallback(async (path) => {
        if (!Array.isArray(path) || path.length === 0) return;
        for (let i = 0; i < path.length; i++) {
            const nodeId = path[i];
            const isLast = i === path.length - 1;
            setRunStatus((prev) => {
                const next = { ...prev };
                // Demote any current 'active' to 'visited'
                Object.keys(next).forEach((k) => {
                    if (next[k] === 'active') next[k] = 'visited';
                });
                next[nodeId] = isLast ? 'active' : 'active';
                return next;
            });
            // Hold each step long enough to read
            await new Promise((r) => setTimeout(r, 380));
            if (!isLast) {
                setRunStatus((prev) => ({ ...prev, [nodeId]: 'visited' }));
            }
        }
    }, []);

    const startTest = useCallback(async () => {
        setTestRunning(true);
        setTestMessages([]);
        setRunStatus({});
        try {
            const res = await fetch(`${baseUrl}/c/${clientSlug}/flows/${flowId}/test/start`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ project_id: projectId }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const body = await res.json();
            setTestSessionId(body.test_session_id || null);
            setTestExpecting(body.expecting || 'none');
            setTestMessages((body.messages || []).map((m) => ({ ...m, role: 'bot' })));
            animatePath(body.execution_path || []);
        } catch (err) {
            console.error('test/start failed', err);
            setTestMessages([{ role: 'bot', kind: 'text', text: 'Test run failed. Check the console for details.' }]);
        } finally {
            setTestRunning(false);
        }
    }, [baseUrl, clientSlug, flowId, projectId, csrf, animatePath]);

    const stepTest = useCallback(async (input) => {
        if (!testSessionId) return;
        setTestRunning(true);
        try {
            const res = await fetch(`${baseUrl}/c/${clientSlug}/flows/${flowId}/test/step`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    project_id: projectId,
                    session_id: testSessionId,
                    choice_id:  input.choice_id || '',
                    text:       input.text || '',
                }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const body = await res.json();
            setTestExpecting(body.expecting || 'none');
            setTestMessages((prev) => {
                const next = prev.slice();
                if (input.choice_id || input.text) {
                    next.push({ role: 'user', kind: 'text', text: input.label || input.text || input.choice_id });
                }
                (body.messages || []).forEach((m) => next.push({ ...m, role: 'bot' }));
                if (body.handoff) {
                    next.push({ role: 'system', kind: 'text', text: '→ flow would hand off to AI here (test sandbox stops here).' });
                }
                if (body.ended) {
                    next.push({ role: 'system', kind: 'text', text: '✓ flow ended.' });
                }
                return next;
            });
            animatePath(body.execution_path || []);
        } catch (err) {
            console.error('test/step failed', err);
        } finally {
            setTestRunning(false);
        }
    }, [baseUrl, clientSlug, flowId, projectId, csrf, testSessionId, animatePath]);

    const resetTest = useCallback(() => {
        setTestSessionId(null);
        setTestMessages([]);
        setTestExpecting('none');
        setRunStatus({});
    }, []);

    // Decorate nodes with className based on runStatus so React Flow's
    // node wrapper picks up `.is-running` / `.is-visited` styling.
    const decoratedNodes = useMemo(() => nodes.map((n) => ({
        ...n,
        className: runStatus[n.id] === 'active'  ? 'is-running'
                  : runStatus[n.id] === 'visited' ? 'is-visited'
                  : '',
    })), [nodes, runStatus]);

    // Cmd/Ctrl+S = save. Esc = close properties panel.
    useEffect(() => {
        const onKey = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 's') {
                e.preventDefault();
                save();
            }
            if (e.key === 'Escape') setPropsOpen(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [save]);

    const onConnect = useCallback(
        (params) => setEdges((eds) => addEdge({ ...params, animated: true }, eds)),
        [setEdges]
    );

    const onDragOver = useCallback((e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }, []);

    const onDrop = useCallback((e) => {
        e.preventDefault();
        const type = e.dataTransfer.getData('application/reactflow');
        if (!type || !NODE_TYPES[type]) return;
        const position = screenToFlowPosition({ x: e.clientX, y: e.clientY });
        const newNode = {
            id: `${type}-${Date.now()}`,
            type,
            position,
            data: { ...NODE_TYPES[type].defaultData },
        };
        setNodes((nds) => nds.concat(newNode));
    }, [screenToFlowPosition, setNodes]);

    const updateNode = useCallback((id, newData) => {
        setNodes((nds) => nds.map((n) => (n.id === id ? { ...n, data: newData } : n)));
    }, [setNodes]);

    const deleteNode = useCallback((id) => {
        if (id === 'start') { alert('The Start node cannot be deleted.'); return; }
        setNodes((nds) => nds.filter((n) => n.id !== id));
        setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id));
        setSelectedId(null);
    }, [setNodes, setEdges]);

    const selectedNode = useMemo(() => nodes.find((n) => n.id === selectedId) || null, [nodes, selectedId]);

    if (loading) {
        return <div className="fb-loading">Loading flow…</div>;
    }

    return (
        <div className={`fb-shell ${propsOpen ? 'props-open' : 'props-closed'}`} ref={wrapperRef}>
            <Toolbox />

            <div className="fb-canvas-wrap" onDrop={onDrop} onDragOver={onDragOver}>
                <Toolbar
                    onSave={save}
                    saving={saving}
                    dirty={dirty}
                    lastSavedAt={lastSavedAt}
                    settings={settings}
                    onSettingsChange={setSettings}
                    testOpen={testOpen}
                    onTestToggle={() => {
                        setTestOpen((o) => {
                            const opening = !o;
                            if (!opening) {
                                resetTest();
                            } else if (!testSessionId) {
                                // First open → kick off a run
                                setTimeout(() => startTest(), 50);
                            }
                            return opening;
                        });
                    }}
                />
                <ReactFlow
                    nodes={decoratedNodes}
                    edges={edges}
                    onNodesChange={onNodesChange}
                    onEdgesChange={onEdgesChange}
                    onConnect={onConnect}
                    onNodeClick={(_, n) => setSelectedId(n.id)}
                    onNodeDoubleClick={(_, n) => { setSelectedId(n.id); setPropsOpen(true); }}
                    onPaneClick={() => setSelectedId(null)}
                    nodeTypes={nodeTypes}
                    fitView
                    proOptions={{ hideAttribution: true }}
                >
                    <Background gap={24} size={1} color="#1e293b" />
                    <Controls position="bottom-right" />
                    <MiniMap
                        position="bottom-left"
                        pannable zoomable
                        nodeStrokeColor={(n) => NODE_TYPES[n.type]?.color || '#475569'}
                        nodeColor={(n) => NODE_TYPES[n.type]?.color || '#475569'}
                        maskColor="rgba(15,23,42,.7)"
                    />
                </ReactFlow>

                {/* Floating "Open properties" tab — appears when the panel is
                    closed AND a node is selected. Single click on it opens
                    the panel; same effect as double-clicking the node. */}
                {!propsOpen && selectedId && (
                    <button
                        className="fb-props-tab"
                        onClick={() => setPropsOpen(true)}
                        title="Open properties (or double-click any node)"
                    >
                        ✎ Edit “{selectedNode?.data?.label || NODE_TYPES[selectedNode?.type]?.label || 'node'}”
                    </button>
                )}

                {/* Test panel — docks to the bottom-right of the canvas
                    while a test run is active. Closes via the toolbar
                    Stop test button. */}
                {testOpen && (
                    <TestPanel
                        messages={testMessages}
                        expecting={testExpecting}
                        running={testRunning}
                        sessionId={testSessionId}
                        onStart={startTest}
                        onChoice={(opt) => stepTest({ choice_id: opt.id, label: opt.label })}
                        onText={(text) => stepTest({ text })}
                        onReset={() => { resetTest(); startTest(); }}
                        onClose={() => { setTestOpen(false); resetTest(); }}
                    />
                )}
            </div>

            {propsOpen && (
                <PropertiesPanel
                    node={selectedNode}
                    updateNode={updateNode}
                    deleteNode={deleteNode}
                    onClose={() => setPropsOpen(false)}
                />
            )}
        </div>
    );
}

// ────────────────────────────────────────────────────────────────────
// Mount.
// ────────────────────────────────────────────────────────────────────
function bootstrap() {
    const root = document.getElementById('flow-editor-root');
    if (!root) return;
    const flowId      = parseInt(root.dataset.flowId, 10);
    const projectId   = parseInt(root.dataset.projectId, 10);
    const clientSlug  = root.dataset.clientSlug;
    const csrf        = root.dataset.csrf;
    // Honour Laragon's sub-path docroot (e.g. /AI-CRM-AGENT/admin/public).
    // Without this, absolute fetch('/c/...') resolves against the docroot
    // and 404s when the app is served from a sub-directory.
    const baseUrl     = root.dataset.baseUrl || '';
    // Empty the placeholder before mounting.
    root.innerHTML = '';
    createRoot(root).render(
        <React.StrictMode>
            <ReactFlowProvider>
                <FlowCanvas
                    flowId={flowId}
                    projectId={projectId}
                    csrf={csrf}
                    clientSlug={clientSlug}
                    baseUrl={baseUrl}
                />
            </ReactFlowProvider>
        </React.StrictMode>
    );
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
} else {
    bootstrap();
}
