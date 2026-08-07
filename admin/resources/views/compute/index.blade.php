@extends('layouts.master')

@section('content')
<style>
    .cm-wrap { color:#e2e8f0; }
    .cm-cards { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin:14px 0 16px; }
    @media (max-width:1100px){ .cm-cards { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    .cm-card { background:#0f172a; border:1px solid #1e293b; border-radius:13px; padding:14px 16px; }
    .cm-card__label { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; }
    .cm-card__val { font-size:26px; font-weight:800; color:#f1f5f9; line-height:1.1; margin-top:4px; }
    .cm-card__val small { font-size:13px; font-weight:600; color:#94a3b8; }
    .cm-card__sub { font-size:11px; color:#64748b; margin-top:3px; }
    .cm-stage { background:radial-gradient(circle at 50% 40%, #131c33 0%, #0b1220 70%); border:1px solid #1e293b; border-radius:16px; padding:8px; position:relative; overflow:hidden; }
    .cm-stage__head { display:flex; align-items:center; gap:10px; padding:10px 12px 4px; }
    .cm-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; }
    .cm-badge--on { background:#052e1b; color:#4ade80; border:1px solid #16653433; }
    .cm-badge--off { background:#3f1d1d; color:#f87171; }
    .cm-svg { width:100%; height:auto; display:block; }

    .mesh-link { stroke:#334155; stroke-width:1.4; fill:none; opacity:.45; }
    .mesh-link.flow { stroke:#6366f1; stroke-width:1.8; stroke-dasharray:3 7; opacity:.9; animation:cmflow .8s linear infinite; }
    .mesh-link.flow.voice { stroke:#a855f7; }
    @keyframes cmflow { to { stroke-dashoffset:-20; } }
    .mesh-node text { font-family:system-ui,sans-serif; }
    .mesh-ico { font-size:18px; }
    .mesh-lbl { font-size:11px; font-weight:700; fill:#e2e8f0; }
    .mesh-sub { font-size:9.5px; fill:#94a3b8; }
    .mesh-node.busy .mesh-main { animation:cmpulse 1.5s ease-in-out infinite; }
    @keyframes cmpulse { 0%,100%{ filter:none; } 50%{ filter:drop-shadow(0 0 8px currentColor); } }
    .mesh-new { animation:cmpop .4s ease; }
    @keyframes cmpop { from { opacity:0; transform:scale(.5); } to { opacity:1; } }
    /* expanding ripple on active nodes */
    .mesh-ripple { animation:cmripple 2.1s ease-out infinite; }
    @keyframes cmripple { 0%{ r:22; opacity:.45; } 100%{ r:46; opacity:0; } }
    /* small status core inside each node */
    .mesh-core { animation:cmcore 1.7s ease-in-out infinite; }
    @keyframes cmcore { 0%,100%{ r:4.5; opacity:.85; } 50%{ r:6.5; opacity:1; } }
    .mesh-node.busy .mesh-dot { animation:cmdot 1.1s ease-in-out infinite; }
    @keyframes cmdot { 0%,100%{ opacity:1; transform:scale(1);} 50%{ opacity:.4; } }
    .mesh-particle { filter:drop-shadow(0 0 3px currentColor); }
    .cm-note { font-size:12px; color:#64748b; margin-top:10px; }
</style>

<div class="content cm-wrap">
    <div class="flex items-center gap-3 mt-4">
        <h2 class="text-lg font-semibold" style="color:#f1f5f9;">Compute Mesh</h2>
        <span id="cmPulse" class="cm-badge cm-badge--off">connecting…</span>
        <form method="GET" class="ml-auto">
            <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="cm-cards">
        <div class="cm-card"><div class="cm-card__label">Queue depth</div><div class="cm-card__val" id="cmQueue">0</div><div class="cm-card__sub" id="cmQueueSub">driver: —</div></div>
        <div class="cm-card"><div class="cm-card__label">Active conversations</div><div class="cm-card__val" id="cmConv">0</div><div class="cm-card__sub" id="cmConvSub">msgs/min: 0</div></div>
        <div class="cm-card"><div class="cm-card__label">Live voice calls</div><div class="cm-card__val" id="cmCalls">0</div><div class="cm-card__sub" id="cmEngine">engine: —</div></div>
        <div class="cm-card"><div class="cm-card__label">LLM calls / min</div><div class="cm-card__val" id="cmLlm">0</div><div class="cm-card__sub" id="cmLlmSub">—</div></div>
        <div class="cm-card"><div class="cm-card__label">Worker fleet (desired)</div><div class="cm-card__val" id="cmWorkers">1 <small>workers</small></div><div class="cm-card__sub">auto-scales with queue + throughput</div></div>
        <div class="cm-card"><div class="cm-card__label">Voice fleet (desired)</div><div class="cm-card__val" id="cmVoice">1 <small>instances</small></div><div class="cm-card__sub">auto-scales with concurrent calls</div></div>
        <div class="cm-card"><div class="cm-card__label">Failed jobs</div><div class="cm-card__val" id="cmFailed">0</div><div class="cm-card__sub">needs retry if &gt; 0</div></div>
        <div class="cm-card"><div class="cm-card__label">Updated</div><div class="cm-card__val" id="cmUpdated" style="font-size:18px;">—</div><div class="cm-card__sub">live · every 3s</div></div>
    </div>

    <div class="cm-stage">
        <div class="cm-stage__head">
            <i data-lucide="cpu" class="w-4 h-4" style="color:#818cf8;"></i>
            <b style="color:#f1f5f9;">Live fleet topology</b>
            <span class="cm-note" style="margin:0;">nodes scale with live load</span>
        </div>
        <svg id="cmSvg" class="cm-svg" viewBox="0 0 1000 540" preserveAspectRatio="xMidYMid meet"></svg>
    </div>
    <div class="cm-note">
        Shows the <b>desired</b> fleet derived from live load (≈ 1 worker / {{ 5 }} queued or msgs-per-min; 1 voice instance / {{ 4 }} concurrent calls).
        Actual instances are provisioned by your orchestrator (Supervisor / k8s) — see <code>docs/SCALING.md</code>.
    </div>
</div>

<script>
const CM = {
    url: '{{ route('compute.metrics', ['client' => $client->slug]) }}?project_id={{ hashid($projectId) }}',
    prev: { workers: 0, voice: 0 },
};
function cmEsc(s){ return (''+s).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }

function clusterPositions(cx, cy, n){
    if (n <= 1) return [{x:cx, y:cy}];
    const r = Math.min(120, 34 + n*7);
    const pts = [];
    for (let i=0;i<n;i++){ const a = (i/n)*2*Math.PI - Math.PI/2; pts.push({x:cx+Math.cos(a)*r, y:cy+Math.sin(a)*(r*0.7)}); }
    return pts;
}
function node(p, label, sub, color, icon, busy, isNew){
    const ripple = busy ? `<circle class="mesh-ripple" r="22" fill="none" stroke="${color}" stroke-width="2"></circle>` : '';
    return `<g class="mesh-node ${busy?'busy':''} ${isNew?'mesh-new':''}" style="color:${color}" transform="translate(${p.x.toFixed(0)},${p.y.toFixed(0)})">
        ${ripple}
        <circle class="mesh-main" r="22" fill="${color}22" stroke="${color}" stroke-width="2"></circle>
        <text class="mesh-ico" text-anchor="middle" dy="6">${icon}</text>
        <circle class="mesh-dot" cx="16" cy="-16" r="4.5" fill="${busy?'#4ade80':'#475569'}" stroke="#0b1220" stroke-width="2"></circle>
        <text class="mesh-lbl" text-anchor="middle" y="40">${cmEsc(label)}</text>
        ${sub?`<text class="mesh-sub" text-anchor="middle" y="53">${cmEsc(sub)}</text>`:''}
    </g>`;
}
function link(a, b, flow, voice){ return `<line class="mesh-link ${flow?'flow':''} ${voice?'voice':''}" x1="${a.x.toFixed(0)}" y1="${a.y.toFixed(0)}" x2="${b.x.toFixed(0)}" y2="${b.y.toFixed(0)}"></line>`; }
// Flowing particles travelling along an active connection.
function particles(a, b, voice){
    const col = voice ? '#c4b5fd' : '#a5b4fc';
    const path = `M${a.x.toFixed(0)},${a.y.toFixed(0)} L${b.x.toFixed(0)},${b.y.toFixed(0)}`;
    let s = '';
    for (let k=0;k<2;k++){
        s += `<circle class="mesh-particle" r="3" fill="${col}" style="color:${col}">
            <animateMotion dur="1.5s" begin="-${(k*0.75).toFixed(2)}s" repeatCount="indefinite" path="${path}"></animateMotion>
        </circle>`;
    }
    return s;
}

function renderMesh(m){
    const W = Math.min(m.scale.workers, 10), V = Math.min(m.scale.voice, 6);
    const textBusy = (m.load.msgs_per_min > 0) || (m.queue.pending > 0);
    const callBusy = m.load.active_calls > 0;

    const src   = {x:70,  y:170};
    const csrc  = {x:70,  y:400};
    const gw    = {x:255, y:270};
    const queue = {x:255, y:95};
    const llm   = {x:840, y:380};
    const db    = {x:530, y:500};
    const wpos  = clusterPositions(540, 250, W);
    const vpos  = clusterPositions(840, 150, V);

    const newW = m.scale.workers > CM.prev.workers;
    const newV = m.scale.voice  > CM.prev.voice;

    let links = '', dots = '';
    const addLink = (a, b, flow, voice) => { links += link(a, b, flow, voice); if (flow) dots += particles(a, b, voice); };
    addLink(src, gw, textBusy);
    addLink(gw, queue, textBusy);
    wpos.forEach(p => { addLink(queue, p, textBusy); addLink(p, llm, textBusy); addLink(p, db, textBusy); });
    vpos.forEach(p => { addLink(csrc, p, callBusy, true); addLink(p, llm, callBusy, true); });

    let nodes = '';
    nodes += node(src,  'Inbound chat', m.load.msgs_per_min + '/min', '#38bdf8', '💬', textBusy);
    nodes += node(csrc, 'Inbound calls', m.load.active_calls + ' live', '#a855f7', '📞', callBusy);
    nodes += node(gw,   'Gateway', 'Laravel', '#818cf8', '🌐', textBusy);
    nodes += node(queue,'Queue', m.queue.pending + ' jobs', '#f59e0b', '📥', m.queue.pending>0);
    wpos.forEach((p,i)=> nodes += node(p, 'Worker', i===0?('×'+m.scale.workers):'', '#22c55e', '⚙️', textBusy, newW));
    vpos.forEach((p,i)=> nodes += node(p, 'Voice', i===0?('×'+m.scale.voice):'', '#a855f7', '🎙️', callBusy, newV));
    nodes += node(llm,  'LLM', (m.llm.provider||'')+(m.llm.fallback?(' → '+m.llm.fallback):''), '#ec4899', '🧠', textBusy||callBusy);
    nodes += node(db,   'Tenant DB', '', '#64748b', '🗄️', false);

    document.getElementById('cmSvg').innerHTML = links + dots + nodes;
    CM.prev = { workers: m.scale.workers, voice: m.scale.voice };
}

function renderStats(m){
    document.getElementById('cmQueue').textContent = m.queue.pending;
    document.getElementById('cmQueueSub').textContent = 'driver: ' + m.queue.driver + (m.queue.driver==='sync'?' (inline — no worker pool)':'');
    document.getElementById('cmConv').textContent = m.load.active_sessions;
    document.getElementById('cmConvSub').textContent = 'msgs/min: ' + m.load.msgs_per_min + ' · 5min: ' + m.load.msgs_5min;
    document.getElementById('cmCalls').textContent = m.load.active_calls;
    document.getElementById('cmEngine').textContent = m.engine.reachable ? ('engine online · STT ' + (m.engine.stt?'✓':'✗') + ' TTS ' + (m.engine.tts?'✓':'✗')) : 'engine offline';
    document.getElementById('cmLlm').textContent = m.llm.calls_per_min;
    document.getElementById('cmLlmSub').textContent = (m.llm.provider||'—') + (m.llm.fallback?(' → '+m.llm.fallback):' · no fallback');
    document.getElementById('cmWorkers').innerHTML = m.scale.workers + ' <small>workers</small>';
    document.getElementById('cmVoice').innerHTML = m.scale.voice + ' <small>instances</small>';
    document.getElementById('cmFailed').textContent = m.queue.failed;
    document.getElementById('cmFailed').style.color = m.queue.failed>0 ? '#f87171' : '#f1f5f9';
    const d = new Date(m.ts*1000); document.getElementById('cmUpdated').textContent = d.toLocaleTimeString();
    const p = document.getElementById('cmPulse'); p.className = 'cm-badge cm-badge--on'; p.textContent = '● live';
}

async function cmPoll(){
    try {
        const r = await fetch(CM.url, {headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
        const m = await r.json();
        renderStats(m); renderMesh(m);
    } catch(e) {
        const p = document.getElementById('cmPulse'); p.className='cm-badge cm-badge--off'; p.textContent='disconnected';
    }
}
cmPoll();
setInterval(cmPoll, 3000);
if (window.lucide) try { lucide.createIcons(); } catch(_) {}
</script>
@endsection
