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
        // Outputs are DYNAMIC for this node — the handle set is computed
        // per-node from data.options via dtmfOutputs() (see FlowNode). The
        // customer can define as many keypad options as they want. This
        // static list is only a fallback for tooling that reads the
        // registry directly; the canvas never uses it.
        outputs: [
            { id: '1', label: '1' },
            { id: 'timeout', label: 'timeout' },
        ],
        defaultData: {
            prompt_source: 'tts',   // 'tts' | 'audio'
            prompt: 'Press 1 for billing, 2 for sales, 0 for an agent.',
            prompt_audio_asset_id: null,
            language: '',
            timeout_secs: 8,
            max_digits: 1,
            // Menu options — UNLIMITED. Single source of truth for the
            // node's branches: each row is one keypad key (the branch
            // handle id, what phone callers press) + an optional web
            // button label. We also mirror these into `button_labels`
            // (below) so the webchat runner keeps working unchanged.
            //   options: [{ digit: '1', label: 'Billing' }, …]
            options: [
                { digit: '1', label: 'Billing' },
                { digit: '2', label: 'Sales' },
                { digit: '0', label: 'Agent' },
            ],
            // Derived from `options` on every edit (digit → label). Kept
            // for backward-compat with the backend web runner + old flows.
            button_labels: { '1': 'Billing', '2': 'Sales', '0': 'Agent' },
        },
        summary: (data) => {
            const src = data.prompt_source === 'audio'
                ? `▶ audio #${data.prompt_audio_asset_id ?? '—'}`
                : `“${(data.prompt || '').slice(0, 30)}${(data.prompt || '').length > 30 ? '…' : ''}”`;
            const n = dtmfOptions(data).filter((o) => String(o.digit || '').trim() !== '').length;
            return `${src} · ${n} option${n === 1 ? '' : 's'} · ${data.timeout_secs ?? 8}s`;
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
    datasource: {
        label: 'Data Source',
        icon: '📚',
        color: '#22c55e',
        outputs: [{ id: 'out', label: '' }],
        // source_ids: which data sources the AI should reference from here on.
        // Empty = clear the scope (back to automatic routing across all sources).
        defaultData: { label: 'Use knowledge', source_ids: [] },
        summary: (data) => (data.source_ids && data.source_ids.length)
            ? `scoped to ${data.source_ids.length} source(s)`
            : 'all sources (auto)',
    },
    collect_input: {
        label: 'Collect Input',
        icon: '📝',
        color: '#0ea5e9',
        outputs: [
            { id: 'collected', label: 'collected' },
            { id: 'timeout',   label: 'timeout' },
        ],
        // Asks one or more questions in sequence, validating + storing each
        // reply as {{ key }} on the session (reusable by later nodes / AI).
        defaultData: {
            fields: [
                { key: 'name',            prompt: 'What is your name?',            input_type: 'text'  },
                { key: 'whatsapp_number', prompt: 'What is your WhatsApp number?', input_type: 'phone' },
            ],
            language: '',
        },
        summary: (d) => {
            const n = (Array.isArray(d.fields) && d.fields.length) || (d.field_key ? 1 : 0);
            return n === 1 ? '1 question' : `${n} questions`;
        },
    },
    send_channel: {
        label: 'Send to Channel',
        icon: '📤',
        color: '#16a34a',
        outputs: [
            { id: 'sent',  label: 'sent' },
            { id: 'error', label: 'error' },
        ],
        // Sends to WhatsApp/Messenger/IG via the project's onboarded account.
        defaultData: {
            provider: 'whatsapp', recipient_field: 'whatsapp_number',
            payload_type: 'text', text: 'Here is our catalogue! 📚',
            media_type: 'document', media_url: '', caption: '',
            template_name: '', template_lang: 'en_US',
        },
        summary: (d) => `${d.provider || 'whatsapp'} · ${d.payload_type || 'text'} → {{ ${d.recipient_field || 'contact'} }}`,
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
// DTMF helpers — the Capture DTMF node supports an UNLIMITED number of
// keypad options. They live as `data.options` ([{digit,label}, …]) which
// is the single source of truth for the node's branch handles. Older
// flows (saved before this feature) only have `data.button_labels`
// ({digit: label}) plus the legacy fixed digit set, so we normalize both
// shapes here. The handle id MUST equal the digit — both runtimes route a
// keypress to the edge whose sourceHandle == the pressed digit.
// ────────────────────────────────────────────────────────────────────
function dtmfOptions(data) {
    const d = data || {};
    if (Array.isArray(d.options)) {
        // Stored verbatim (may include a blank digit while the user is
        // mid-typing a new row — the panel needs to keep showing it).
        return d.options.map((o) => ({
            digit: String(o.digit ?? o.id ?? ''),
            label: String(o.label ?? ''),
        }));
    }
    // Legacy fallback (no options[] yet): reconstruct from button_labels.
    // Always keep the original static digit set so handles that were wired
    // without a web label (phone-only paths) don't vanish + orphan their
    // edges; then append any extra labeled digits beyond that set.
    const labels = (d.button_labels && typeof d.button_labels === 'object') ? d.button_labels : {};
    const merged = ['1', '2', '3', '4', '0'];
    Object.keys(labels).forEach((dg) => { if (!merged.includes(String(dg))) merged.push(String(dg)); });
    return merged.map((dg) => ({ digit: String(dg), label: String(labels[dg] || '') }));
}

// The branch handles shown on the canvas card: one per non-blank, unique
// option digit, plus an always-present timeout branch.
function dtmfOutputs(data) {
    const seen = new Set();
    const outs = [];
    dtmfOptions(data).forEach((o) => {
        const digit = String(o.digit || '').trim();
        if (digit === '' || seen.has(digit)) return;   // skip blanks + dupes
        seen.add(digit);
        outs.push({ id: digit, label: o.label ? `${digit} · ${o.label}` : digit });
    });
    outs.push({ id: 'timeout', label: 'timeout' });
    return outs;
}

// ────────────────────────────────────────────────────────────────────
// Custom node card component — used for ALL node types. The header /
// color / outputs come from the registry; the body just renders
// summary text.
// ────────────────────────────────────────────────────────────────────
function FlowNode({ id, data, selected, type }) {
    const cfg = NODE_TYPES[type] || NODE_TYPES.say;
    const hasInput = type !== 'start';
    // capture_dtmf computes its handle set per-node from data.options;
    // every other node uses the static registry list.
    const outputs = type === 'capture_dtmf' ? dtmfOutputs(data) : cfg.outputs;

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
            {outputs.length === 1 && (
                <Handle type="source" position={Position.Bottom} id={outputs[0].id} className="fb-handle" />
            )}
            {outputs.length > 1 && (
                <div className="fb-node__outputs">
                    {outputs.map((o) => (
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
function PropertiesPanel({ node, updateNode, deleteNode, onClose, dataSources = [], renameHandle, removeHandle }) {
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

    // ── Capture DTMF: unlimited option rows ──────────────────────────
    // options[] is the source of truth; we mirror it into button_labels
    // (digit → label) so the backend web runner stays unchanged. Edge
    // wiring follows the digit (handle id), so renaming/removing a digit
    // remaps/drops the matching edges to keep the graph consistent.
    const writeDtmfOptions = (opts) => {
        const button_labels = {};
        opts.forEach((o) => {
            const digit = String(o.digit || '').trim();
            if (digit) button_labels[digit] = String(o.label || '');
        });
        updateNode(node.id, { ...node.data, options: opts, button_labels });
    };
    const setDtmfOption = (i, patch) => {
        const opts = dtmfOptions(node.data);
        if (!opts[i]) return;
        // A digit change is a handle rename — move any wired edges with it.
        if (Object.prototype.hasOwnProperty.call(patch, 'digit')) {
            const oldDigit = String(opts[i].digit || '').trim();
            const newDigit = String(patch.digit || '').trim();
            if (oldDigit !== newDigit) renameHandle?.(node.id, oldDigit, newDigit);
        }
        opts[i] = { ...opts[i], ...patch };
        writeDtmfOptions(opts);
    };
    const addDtmfOption = () => {
        const opts = dtmfOptions(node.data);
        // Suggest the next unused single digit (1-9, then 0).
        const used = new Set(opts.map((o) => String(o.digit || '').trim()));
        const next = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'].find((d) => !used.has(d)) || '';
        writeDtmfOptions([...opts, { digit: next, label: '' }]);
    };
    const removeDtmfOption = (i) => {
        const opts = dtmfOptions(node.data);
        if (!opts[i]) return;
        const digit = String(opts[i].digit || '').trim();
        if (digit) removeHandle?.(node.id, digit);
        opts.splice(i, 1);
        writeDtmfOptions(opts);
    };

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

                    {/* Menu options — UNLIMITED. Each row = a keypad key
                        (the branch handle id on the node card, what phone
                        callers press) + an optional web button label.
                        Add/remove as many as you like. */}
                    <Field label="Menu options">
                        <div className="fb-dtmf-opts">
                            {dtmfOptions(node.data).map((o, i) => (
                                <div key={i} className="fb-dtmf-opt">
                                    <input
                                        type="text"
                                        inputMode="numeric"
                                        className="fb-dtmf-opt__key"
                                        value={o.digit}
                                        maxLength={2}
                                        placeholder="#"
                                        title="Keypad key the caller presses (0-9, * or #)"
                                        onChange={(e) => setDtmfOption(i, { digit: e.target.value.replace(/[^0-9*#]/g, '') })}
                                    />
                                    <input
                                        type="text"
                                        className="fb-dtmf-opt__label"
                                        value={o.label}
                                        placeholder={`Web label, e.g. "Billing"`}
                                        onChange={(e) => setDtmfOption(i, { label: e.target.value })}
                                    />
                                    <button
                                        type="button"
                                        className="fb-dtmf-opt__del"
                                        title="Remove this option"
                                        onClick={() => removeDtmfOption(i)}
                                    >✕</button>
                                </div>
                            ))}
                        </div>
                        <button type="button" className="fb-dtmf-add" onClick={addDtmfOption}>
                            ＋ Add option
                        </button>
                        <Help>
                            Add as many options as you need — no limit. The <b>key</b> is what
                            phone callers press and becomes a branch handle on the node card
                            (drag from it to wire the next step). The <b>label</b> is the button
                            text shown in webchat; leave it blank to hide that branch on web
                            (phone still works). A <code>timeout</code> branch is always available.
                        </Help>
                    </Field>
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

            {node.type === 'datasource' && (
                <div className="fb-props__body">
                    <Field label="Scope to these data sources">
                        {(!dataSources || dataSources.length === 0) ? (
                            <Help>No data sources found for this project. Add them under “Data Sources” first, then they’ll appear here.</Help>
                        ) : (
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                                {dataSources.map((ds) => {
                                    const ids = node.data?.source_ids || [];
                                    const checked = ids.includes(ds.id);
                                    return (
                                        <label key={ds.id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, cursor: 'pointer' }}>
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={(e) => {
                                                    const cur = new Set(node.data?.source_ids || []);
                                                    if (e.target.checked) cur.add(ds.id); else cur.delete(ds.id);
                                                    set('source_ids', Array.from(cur));
                                                }}
                                            />
                                            <span>{ds.name} <span style={{ color: '#64748b' }}>({ds.type})</span></span>
                                        </label>
                                    );
                                })}
                            </div>
                        )}
                    </Field>
                    <Help>When the conversation passes through this node, the AI references ONLY the checked source(s) from here on. Check none to use automatic routing (all sources) — the default behavior.</Help>
                </div>
            )}

            {node.type === 'collect_input' && (() => {
                // Normalize to a fields[] list (supports the legacy single field).
                const fields = (Array.isArray(node.data?.fields) && node.data.fields.length)
                    ? node.data.fields
                    : [{ key: node.data?.field_key || 'value', prompt: node.data?.prompt || '', input_type: node.data?.input_type || 'text' }];
                const setFields = (next) => updateNode(node.id, { ...node.data, fields: next, field_key: undefined, prompt: undefined, input_type: undefined });
                const updField = (i, patch) => setFields(fields.map((f, j) => (j === i ? { ...f, ...patch } : f)));
                const addField = () => setFields([...fields, { key: '', prompt: '', input_type: 'text' }]);
                const delField = (i) => setFields(fields.filter((_, j) => j !== i));
                return (
                    <div className="fb-props__body">
                        {fields.map((f, i) => (
                            <div key={i} style={{ border: '1px solid #1e293b', borderRadius: 8, padding: 10, marginBottom: 10 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                                    <strong style={{ fontSize: 12, color: '#94a3b8' }}>Question {i + 1}</strong>
                                    {fields.length > 1 && (
                                        <button onClick={() => delField(i)} title="Remove this question"
                                            style={{ background: 'transparent', border: 'none', color: '#ef4444', cursor: 'pointer', fontSize: 14 }}>✕</button>
                                    )}
                                </div>
                                <Field label="Ask">
                                    <input value={f.prompt || ''} onChange={(e) => updField(i, { prompt: e.target.value })} placeholder="What is your name?"/>
                                </Field>
                                <Field label="Type (validation)">
                                    <select value={f.input_type || 'text'} onChange={(e) => updField(i, { input_type: e.target.value })}>
                                        <option value="text">Text (any)</option>
                                        <option value="phone">Phone / WhatsApp number</option>
                                        <option value="email">Email</option>
                                        <option value="number">Number</option>
                                    </select>
                                </Field>
                                <Field label="Save as (key)">
                                    <input value={f.key || ''} onChange={(e) => updField(i, { key: e.target.value.replace(/[^a-zA-Z0-9_]/g, '_') })} placeholder="name"/>
                                </Field>
                            </div>
                        ))}
                        <button onClick={addField}
                            style={{ width: '100%', padding: '8px', background: '#0ea5e9', color: '#fff', border: 'none', borderRadius: 6, cursor: 'pointer', fontSize: 13, marginBottom: 10 }}>
                            + Add another question
                        </button>
                        <LanguageField value={node.data?.language || ''} onChange={(v) => set('language', v)} />
                        <Help>Asks each question in order, validates + stores each reply as <code>&#123;&#123; key &#125;&#125;</code> (e.g. <code>&#123;&#123; name &#125;&#125;</code>, <code>&#123;&#123; whatsapp_number &#125;&#125;</code>). Reuse those in a Send node. Outputs: <b>collected</b> (all done) · <b>timeout</b> (no reply). Invalid answers re-ask the same question.</Help>
                    </div>
                );
            })()}

            {node.type === 'send_channel' && (
                <div className="fb-props__body">
                    <Field label="Channel">
                        <select value={node.data?.provider || 'whatsapp'} onChange={(e) => set('provider', e.target.value)}>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="messenger">Messenger</option>
                            <option value="instagram">Instagram</option>
                        </select>
                    </Field>
                    <Field label="Send to (field key)">
                        <input value={node.data?.recipient_field || ''} onChange={(e) => set('recipient_field', e.target.value.replace(/[^a-zA-Z0-9_]/g, '_'))} placeholder="whatsapp_number"/>
                        <Help>A field captured earlier (e.g. by a Collect Input node). Leave blank to use the current contact.</Help>
                    </Field>
                    <Field label="Message type">
                        <select value={node.data?.payload_type || 'text'} onChange={(e) => set('payload_type', e.target.value)}>
                            <option value="text">Text</option>
                            <option value="media">Media (catalogue PDF / image link)</option>
                            <option value="template">Template (required for cold WhatsApp sends)</option>
                        </select>
                    </Field>
                    {(node.data?.payload_type || 'text') === 'text' && (
                        <Field label="Text">
                            <textarea rows={3} value={node.data?.text || ''} onChange={(e) => set('text', e.target.value)} placeholder="Here is our catalogue! Supports {{ field }} placeholders."/>
                        </Field>
                    )}
                    {node.data?.payload_type === 'media' && (
                        <>
                            <Field label="Media type">
                                <select value={node.data?.media_type || 'document'} onChange={(e) => set('media_type', e.target.value)}>
                                    <option value="document">Document (PDF…)</option>
                                    <option value="image">Image</option>
                                    <option value="video">Video</option>
                                </select>
                            </Field>
                            <Field label="Media URL (public link)">
                                <input type="url" value={node.data?.media_url || ''} onChange={(e) => set('media_url', e.target.value)} placeholder="https://…/catalogue.pdf"/>
                            </Field>
                            <Field label="Caption (optional)">
                                <input value={node.data?.caption || ''} onChange={(e) => set('caption', e.target.value)}/>
                            </Field>
                        </>
                    )}
                    {node.data?.payload_type === 'template' && (
                        <>
                            <Field label="Template name (approved in Meta)">
                                <input value={node.data?.template_name || ''} onChange={(e) => set('template_name', e.target.value)} placeholder="catalogue_intro"/>
                            </Field>
                            <Field label="Template language">
                                <input value={node.data?.template_lang || 'en_US'} onChange={(e) => set('template_lang', e.target.value)} placeholder="en_US"/>
                            </Field>
                        </>
                    )}
                    <Help>Uses the project's onboarded {node.data?.provider || 'whatsapp'} account. ⚠️ WhatsApp: a business-initiated message to a number that hasn't messaged you in 24h must use an <b>approved template</b> — plain text/media will be rejected by Meta. Inside an active chat (within 24h), all types work. Outputs: <b>sent</b> / <b>error</b>.</Help>
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

function FlowCanvas({ flowId, projectId, csrf, clientSlug, baseUrl = '', dataSources = [] }) {
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
    // Data sources for the "Data Source" + "Send to Channel" nodes. Seeded
    // from the page attribute, then refreshed from the API on load (robust
    // against stale HTML / attribute-escaping issues).
    const [dsList, setDsList] = useState(Array.isArray(dataSources) ? dataSources : []);

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
                if (Array.isArray(body.data_sources)) setDsList(body.data_sources);
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

    // A DTMF option's digit is its branch handle id. When the user renames
    // a digit, follow any wired edges to the new handle so the connection
    // survives; when they delete an option, drop its edges.
    const renameHandle = useCallback((nodeId, oldHandle, newHandle) => {
        // Remap even through a blank intermediate (clear-then-retype) so the
        // wired edge follows the digit instead of being orphaned.
        if (oldHandle === newHandle) return;
        setEdges((eds) => eds.map((e) => (
            e.source === nodeId && e.sourceHandle === oldHandle
                ? { ...e, sourceHandle: newHandle }
                : e
        )));
    }, [setEdges]);
    const removeHandle = useCallback((nodeId, handle) => {
        setEdges((eds) => eds.filter((e) => !(e.source === nodeId && e.sourceHandle === handle)));
    }, [setEdges]);

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
                    dataSources={dsList}
                    renameHandle={renameHandle}
                    removeHandle={removeHandle}
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
    // Project data sources for the "Data Source" node's scope picker.
    let dataSources = [];
    try { dataSources = JSON.parse(root.dataset.dataSources || '[]'); } catch (_) { dataSources = []; }
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
                    dataSources={dataSources}
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
