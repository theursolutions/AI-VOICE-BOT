@extends('layouts.master')

@section('content')
@php $slug = $client->slug; @endphp

<style>
    /* Light first. Every colour on this page routes through these six
       tokens, so the dark block below is the only thing that changes — and
       the hardcoded hexes further down have been replaced with the tokens
       for the same reason. --panel-2/-3 and --line-2 were the values that
       used to be typed inline. */
    .jv {
        --bg:#ffffff; --panel:#f9fafb; --panel-2:#f3f5f9; --panel-3:#eef1f6;
        --line:#e4e7ec; --line-2:#dfe3ea; --txt:#16202e; --muted:#667085;
        --accent:#1d4ed8; --accent2:#2563eb;
        --overlay:rgba(255,255,255,.72);
        --danger-bg:#fef3f2; --danger-line:#fecdca;
        --avatar-1:#bfdbfe;
    }
    /* The original palette, kept intact — only its trigger changed. */
    html.dark .jv {
        --bg:#070b16; --panel:#0b1120; --panel-2:#101a31; --panel-3:#16203a;
        --line:#1e293b; --line-2:#24324f; --txt:#e2e8f0; --muted:#94a3b8;
        --accent:#3b82f6; --accent2:#60a5fa;
        --overlay:rgba(2,6,16,.72);
        --danger-bg:#1b1014; --danger-line:#7f1d1d;
        --avatar-1:#1e3a8a;
    }
    html.dark .jv {
        background:radial-gradient(1200px 600px at 50% -10%, rgba(59,130,246,.10), transparent 60%), var(--bg);
    }
    .jv {
        position:relative; height:calc(100vh - 120px); min-height:600px; margin-top:18px;
        border:1px solid var(--line); border-radius:16px; overflow:hidden;
        background:var(--bg);
        color:var(--txt); font-family:'Inter',system-ui,sans-serif; box-shadow:0 12px 32px -14px rgba(16,24,40,.16);
        display:flex; flex-direction:column;
    }

    /* Themed scrollbars — replaces the chunky default OS scrollbar everywhere
       inside the Ask AI page (response panel, tables, chat stream, drawer). */
    .jv *::-webkit-scrollbar { width:10px; height:10px; }
    .jv *::-webkit-scrollbar-track { background:var(--panel-2); border-radius:10px; }
    .jv *::-webkit-scrollbar-thumb {
        background:linear-gradient(180deg,var(--accent),#2563eb);
        border-radius:10px; border:2px solid var(--panel); background-clip:padding-box;
    }
    .jv *::-webkit-scrollbar-thumb:hover { background:linear-gradient(180deg,var(--accent2),var(--accent)); border-color:var(--panel); background-clip:padding-box; }
    .jv *::-webkit-scrollbar-corner { background:transparent; }
    .jv * { scrollbar-width:thin; scrollbar-color:var(--accent) transparent; }
    /* Print/export popups inherit the page font; their default scrollbar is fine. */

    /* Top bar */
    .jv-top { display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--line); z-index:4; }
    .jv-iconbtn { background:var(--panel); color:var(--muted); border:1px solid var(--line); border-radius:9px; width:38px; height:38px;
        display:flex; align-items:center; justify-content:center; cursor:pointer; transition:.15s; }
    .jv-iconbtn:hover { color:var(--txt); border-color:var(--line-2); }
    .jv-iconbtn.on { color:#fff; background:linear-gradient(135deg,var(--accent),#2563eb); border-color:var(--accent); }
    /* mic muted (listening stopped) — red so it reads as "off" at a glance */
    #jv-micbtn.on { background:linear-gradient(135deg,#22c55e,#16a34a); border-color:#16a34a; }
    #jv-micbtn.muted { color:#fca5a5; background:var(--danger-bg); border-color:var(--danger-line); animation:none; }
    #jv-micbtn.on::after { content:''; }
    .jv-title { font-size:14px; font-weight:600; display:flex; align-items:center; gap:8px; }
    .jv-title .asst-dot { width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px #22c55e; }
    .jv-tools { margin-left:auto; display:flex; align-items:center; gap:8px; }
    .jv-project { background:var(--panel);color:var(--txt);border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;outline:none; }

    /* Stage */
    .jv-stage { flex:1; min-height:0; position:relative; }

    /* ───────── Voice mode — orb centered, slides left into a split when a response arrives ───────── */
    .jv-voice { position:absolute; inset:0; display:flex; align-items:stretch; }
    .jv-voice.hidden { display:none; }
    .jv-left { flex:1 1 100%; min-width:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; padding:20px;
        transition:flex-basis .55s cubic-bezier(.4,0,.2,1); }
    .jv-right { flex:0 0 0%; width:0; min-width:0; opacity:0; overflow:hidden; display:flex; flex-direction:column;
        border-left:1px solid transparent; transition:flex-basis .55s cubic-bezier(.4,0,.2,1), opacity .4s, border-color .5s; }
    .jv-voice.split .jv-left  { flex:0 0 40%; }
    .jv-voice.split .jv-right { flex:0 0 60%; width:auto; opacity:1; border-left-color:var(--line); }
    @media (max-width:860px){ .jv-voice.split .jv-left{ flex-basis:32%; } .jv-voice.split .jv-right{ flex-basis:68%; } }

    /* ── Three.js wireframe globe (matches the landing hero) — big, shrinks in split ── */
    .orb { position:relative; width:300px; height:300px; flex-shrink:0; cursor:pointer;
        display:flex; align-items:center; justify-content:center; transition:width .55s cubic-bezier(.4,0,.2,1), height .55s cubic-bezier(.4,0,.2,1); }
    .jv-voice.split .orb { width:190px; height:190px; }
    .orb canvas { display:block; width:100% !important; height:100% !important; }
    .orb__glow { position:absolute; inset:-14%; border-radius:50%; z-index:-1;
        background:radial-gradient(circle at 50% 50%, rgba(59,130,246,.30), transparent 60%); filter:blur(34px); transition:background .3s; }
    /* CSS fallback globe if WebGL/Three.js unavailable */
    .orb__fallback { position:absolute; inset:14%; border-radius:50%;
        background:radial-gradient(circle at 30% 30%, rgba(59,130,246,.38), transparent 65%),
                   conic-gradient(from 0deg, rgba(59,130,246,.2), rgba(59,130,246,.03), rgba(59,130,246,.2));
        box-shadow:0 0 60px rgba(59,130,246,.4), inset 0 0 46px rgba(59,130,246,.22); animation:orbSpinF 20s linear infinite; }
    .orb__fallback::after { content:''; position:absolute; inset:14%; border-radius:50%; border:2px solid rgba(59,130,246,.5); }
    .orb.has-webgl .orb__fallback { display:none; }
    @keyframes orbSpinF { to { transform:rotate(360deg); } }
    /* label ring */
    .orb__ring { position:absolute; inset:0; pointer-events:none; }
    .orb__dot { position:absolute; width:7px; height:7px; border-radius:50%; background:#60a5fa; box-shadow:0 0 10px #60a5fa; }
    .orb__lbl { position:absolute; font-size:9px; color:#60a5fa; text-transform:uppercase; letter-spacing:.12em; font-weight:600; opacity:.5; transition:.2s; white-space:nowrap; }
    .orb.is-listening .lbl-listen { opacity:1; color:#22c55e; text-shadow:0 0 8px rgba(34,197,94,.6); }
    .orb.is-speaking  .lbl-speak  { opacity:1; color:#93c5fd; text-shadow:0 0 8px rgba(96,165,250,.6); }
    .orb.is-listening .orb__glow { background:radial-gradient(circle at 50% 50%, rgba(34,197,94,.30), transparent 60%); }

    .jv-state { font-size:12px; letter-spacing:.16em; text-transform:uppercase; color:var(--accent2); font-family:ui-monospace,monospace; min-height:15px; }
    .jv-state.listening { color:#22c55e; } .jv-state.speaking { color:var(--accent2); } .jv-state.idle { color:var(--muted); }
    .jv-transcript { width:100%; max-width:560px; flex-shrink:0; }
    .jv-transcript input { width:100%; text-align:center; background:var(--panel-2); border:1px solid var(--line);
        border-radius:12px; padding:12px 16px; color:var(--txt); font-size:15px; outline:none; }
    .jv-transcript input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(59,130,246,.15); }
    .jv-hint { font-size:11px; color:var(--muted); text-align:center; max-width:520px; }

    /* Right response panel */
    .jv-right__head { display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1px solid var(--line); flex-shrink:0; }
    .jv-right__title { font-size:13px; font-weight:600; display:flex; align-items:center; gap:7px; color:var(--txt); }
    .jv-right__head .jv-iconbtn { margin-left:auto; width:34px; height:34px; }
    .jv-right__body { flex:1; min-height:0; overflow-y:auto; padding:18px 20px; display:flex; flex-direction:column; gap:14px; }
    .jv-answer__text { background:var(--panel-2); border:1px solid var(--line); border-radius:14px; padding:14px 16px; font-size:14.5px; line-height:1.65; }

    /* Colorful skeleton loader */
    .jv-skel { display:flex; flex-direction:column; gap:12px; animation:jvSkelIn .3s ease; }
    .jv-skel__line, .jv-skel__card {
        border-radius:10px;
        background:linear-gradient(100deg, var(--panel-3) 28%, #1e3a8a 42%, #3b82f6 50%, #8b5cf6 58%, var(--panel-3) 72%);
        background-size:240% 100%; animation:jvShimmer 1.25s linear infinite; }
    .jv-skel__line { height:14px; }
    .jv-skel__line.w90{width:90%} .jv-skel__line.w70{width:70%} .jv-skel__line.w55{width:55%} .jv-skel__line.w40{width:40%}
    .jv-skel__card { height:150px; margin-top:6px; }
    @keyframes jvShimmer { to { background-position:-240% 0; } }
    @keyframes jvSkelIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

    /* Fallback "open page" link (shown when a pop-up is blocked) */
    .jv-navlink { display:inline-flex; align-items:center; gap:8px; align-self:flex-start; margin-top:6px;
        padding:10px 16px; border-radius:10px; font-size:13px; font-weight:600; text-decoration:none; color:#fff;
        background:linear-gradient(135deg,var(--accent),#2563eb); border:1px solid var(--accent); transition:.15s; }
    .jv-navlink:hover { filter:brightness(1.1); transform:translateY(-1px); }

    /* Right-panel conversation thread (voice mode) */
    .jv-rstream { display:flex; flex-direction:column; gap:16px; }
    .jv-rrow { display:flex; gap:10px; align-items:flex-start; max-width:100%; animation:jvSkelIn .25s ease; }
    .jv-rrow.user { flex-direction:row-reverse; }
    .jv-rav { width:30px; height:30px; flex-shrink:0; border-radius:9px; display:flex; align-items:center; justify-content:center; }
    .jv-rav.bot { background:linear-gradient(135deg,var(--accent),#2563eb); color:#fff; }
    .jv-rav.user { background:var(--line); color:var(--txt); border:1px solid var(--line); }
    .jv-rcol { display:flex; flex-direction:column; gap:6px; min-width:0; max-width:88%; }
    .jv-rrow.user .jv-rcol { align-items:flex-end; }
    .jv-rbubble { padding:11px 14px; border-radius:13px; font-size:14px; line-height:1.55; white-space:pre-wrap; word-break:break-word; }
    .jv-rrow.bot .jv-rbubble { background:var(--panel-2); border:1px solid var(--line); color:var(--txt); border-top-left-radius:4px; }
    .jv-rrow.user .jv-rbubble { background:linear-gradient(135deg,var(--accent),#2563eb); color:#fff; border-top-right-radius:4px; }
    .jv-rmeta, .jv-tmeta { font-size:10.5px; color:var(--muted); padding:0 4px; }
    .jv-rrow.user .jv-rmeta { text-align:right; }
    .jv-rsrc { font-size:10px; color:var(--muted); margin-top:6px; font-family:ui-monospace,monospace; opacity:.85; }

    /* ───────── Text mode ───────── */
    .jv-text { position:absolute; inset:0; display:none; flex-direction:column; }
    .jv-text.active { display:flex; }
    .jv-msgs { flex:1; overflow-y:auto; padding:24px 0; }
    .jv-stream { max-width:880px; margin:0 auto; padding:0 24px; display:flex; flex-direction:column; gap:18px; }
    .jv-row { display:flex; gap:12px; align-items:flex-start; } .jv-row.user { flex-direction:row-reverse; }
    .jv-av { width:30px;height:30px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center; }
    .jv-av.bot{ background:radial-gradient(circle at 30% 25%,var(--avatar-1),var(--panel)); border:1px solid var(--line-2); color:var(--accent2); }
    .jv-av.user{ background:linear-gradient(135deg,var(--accent),#2563eb); color:#fff; }
    .jv-col { max-width:88%; display:flex; flex-direction:column; gap:10px; } .jv-row.user .jv-col{ align-items:flex-end; }
    .jv-bubble { padding:12px 15px; border-radius:14px; font-size:13.6px; line-height:1.6; white-space:pre-wrap; word-wrap:break-word; }
    .jv-row.bot .jv-bubble{ background:var(--panel-3); border:1px solid var(--line); border-top-left-radius:4px; }
    .jv-row.user .jv-bubble{ background:linear-gradient(135deg,var(--accent),#2563eb); color:#fff; border-top-right-radius:4px; }
    .jv-composer { padding:16px 24px 22px; border-top:1px solid var(--line); background:transparent; }
    .jv-inputbar {
        max-width:880px; margin:0 auto; display:flex; gap:10px; align-items:flex-end;
        background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:7px 7px 7px 18px;
        transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
    }
    .jv-inputbar:hover { border-color:var(--line-2); }
    /* ONE focus treatment lives on the bar — a single accent ring + soft glow. */
    .jv-inputbar:focus-within {
        border-color:var(--accent); background:var(--bg-2, #fff);
        box-shadow:0 0 0 4px rgba(59,130,246,.16), 0 10px 28px -14px rgba(59,130,246,.55);
    }
    /* The textarea must carry NO chrome of its own. @tailwindcss/forms (bundled
       in app.css) otherwise paints a focus box-shadow ring on the <textarea>,
       which stacked on the bar's ring and read as a "double border". Reset the
       border/outline AND the box-shadow + forms-plugin ring variables. */
    .jv-input {
        flex:1; background:transparent; border:0; outline:none; box-shadow:none;
        -webkit-appearance:none; appearance:none;
        color:var(--txt); font-size:14.5px; font-family:inherit; resize:none;
        max-height:160px; line-height:1.55; padding:9px 2px;
    }
    .jv-input:focus, .jv-input:focus-visible {
        border:0; outline:none; box-shadow:none;
        --tw-ring-shadow:0 0 #0000; --tw-ring-offset-shadow:0 0 #0000;
    }
    .jv-input::placeholder{ color:var(--muted); }
    .jv-send {
        flex-shrink:0; width:42px; height:42px; border:none; border-radius:12px; cursor:pointer;
        background:linear-gradient(135deg,var(--accent),#2563eb); color:#fff;
        display:flex; align-items:center; justify-content:center;
        transition:transform .12s ease, filter .15s ease, box-shadow .15s ease;
        box-shadow:0 4px 12px -4px rgba(59,130,246,.6);
    }
    .jv-send:hover { filter:brightness(1.1); transform:translateY(-1px); box-shadow:0 7px 18px -5px rgba(59,130,246,.75); }
    .jv-send:active { transform:translateY(0) scale(.96); }
    .jv-send:disabled { opacity:.5; cursor:default; box-shadow:none; transform:none; filter:none; }

    /* Data widget */
    .jv-widget { background:var(--panel); border:1px solid var(--line-2); border-radius:12px; overflow:hidden; }
    .jv-widget__bar { display:flex; align-items:center; gap:10px; padding:9px 12px; border-bottom:1px solid var(--line); background:var(--panel-2); position:sticky; top:0; z-index:3; }
    .jv-widget__title { font-size:12px; font-weight:600; color:var(--txt); }
    .jv-widget__count { font-size:10.5px; color:var(--muted); font-family:ui-monospace,monospace; }
    .jv-widget__acts { margin-left:auto; display:flex; gap:6px; }
    .jv-wbtn { background:var(--panel-3); color:var(--txt); border:1px solid var(--line-2); border-radius:7px; padding:5px 10px; font-size:11.5px; cursor:pointer; display:flex; align-items:center; gap:5px; }
    .jv-wbtn:hover { background:var(--panel-3); border-color:var(--accent); color:var(--txt); }
    .jv-tblwrap { max-height:240px; overflow:auto; }
    .jv-tbl { width:100%; border-collapse:collapse; font-size:12.3px; }
    .jv-tbl th,.jv-tbl td { text-align:left; padding:8px 12px; border-bottom:1px solid var(--line); white-space:nowrap; }
    .jv-tbl th { position:sticky; top:0; background:var(--panel-2); color:var(--muted); font-weight:600; font-family:ui-monospace,monospace; font-size:11px; }
    .jv-tbl tbody tr:hover { background:var(--panel-2); }

    /* History drawer (hidden by default) */
    .jv-overlay { position:absolute; inset:0; background:var(--overlay); z-index:8; opacity:0; pointer-events:none; transition:opacity .2s; }
    .jv-overlay.show { opacity:1; pointer-events:auto; }
    .jv-drawer { position:absolute; top:0; left:0; bottom:0; width:280px; z-index:9; background:var(--panel); border-right:1px solid var(--line);
        transform:translateX(-100%); transition:transform .25s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; }
    .jv-drawer.open { transform:translateX(0); }
    .jv-drawer__top { display:flex; align-items:center; gap:8px; padding:14px; border-bottom:1px solid var(--line); }
    .jv-newchat { flex:1; display:flex; align-items:center; justify-content:center; gap:8px; background:linear-gradient(135deg,var(--accent),#2563eb);
        color:#fff; border:none; border-radius:10px; padding:10px; font-size:13px; font-weight:600; cursor:pointer; }
    .jv-threads { flex:1; overflow-y:auto; padding:8px; }
    .jv-thread { display:flex; align-items:center; gap:8px; padding:9px 10px; margin-bottom:4px; border-radius:9px; cursor:pointer; color:var(--txt); font-size:13px; border:1px solid transparent; }
    .jv-thread:hover { background:var(--panel-2); } .jv-thread.is-active { background:var(--panel-3); border-color:var(--line-2); }
    .jv-thread__title { flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .jv-thread__del { opacity:0; color:var(--muted); font-size:14px; padding:2px; } .jv-thread:hover .jv-thread__del { opacity:1; } .jv-thread__del:hover{ color:#f87171; }
    .jv-drawer__foot { padding:12px 14px; border-top:1px solid var(--line); font-size:11px; color:var(--muted); }
    .jv-typing span { display:inline-block; width:6px;height:6px;margin-right:3px;border-radius:50%;background:var(--accent2); animation:jvp 1.2s infinite ease-in-out; }
    .jv-typing span:nth-child(2){animation-delay:.2s} .jv-typing span:nth-child(3){animation-delay:.4s}
    @keyframes jvp { 0%,80%,100%{opacity:.3;transform:translateY(0)} 40%{opacity:1;transform:translateY(-3px)} }
</style>

<div class="jv" id="jv">
    <div class="jv-top">
        <button class="jv-iconbtn" id="jv-hist" title="History"><i data-lucide="menu" style="width:18px;height:18px"></i></button>
        <div class="jv-title"><span class="asst-dot"></span> Assistant</div>
        <div class="jv-tools">
            <button class="jv-iconbtn on" id="jv-micbtn" title="Stop listening"><i data-lucide="mic" style="width:18px;height:18px"></i></button>
            <button class="jv-iconbtn on" id="jv-voicebtn" title="Read replies aloud: on/off"><i data-lucide="volume-2" style="width:18px;height:18px"></i></button>
            <select id="jv-voicesel" class="jv-project" title="Assistant voice" style="max-width:160px"></select>
            <select id="jv-langsel" class="jv-project" title="Response language" style="max-width:140px">
                <option value="en">English</option>
                <option value="ur">اردو (Urdu)</option>
                <option value="roman">Roman Urdu</option>
                <option value="ar">العربية (Arabic)</option>
                <option value="hi">हिन्दी (Hindi)</option>
            </select>
            <select id="jv-convmode" class="jv-project" title="How you want to talk to the assistant" style="max-width:150px">
                <option value="qa">Q&amp;A</option>
                <option value="discussion">Discussion</option>
            </select>
            <button class="jv-iconbtn" id="jv-modebtn" title="Switch to text chat"><i data-lucide="keyboard" style="width:18px;height:18px"></i></button>
            <select id="jv-project" class="jv-project" title="Answers come from this project's data">
                @forelse ($projects as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @empty
                    <option value="">No accessible projects</option>
                @endforelse
            </select>
        </div>
    </div>

    <div class="jv-stage">
        {{-- Voice mode --}}
        <div class="jv-voice" id="jv-voice">
            {{-- LEFT: orb + live transcript (centered until a response arrives) --}}
            <div class="jv-left" id="jv-left">
                <div class="orb is-idle" id="jv-orb">
                    <div class="orb__glow"></div>
                    <div class="orb__fallback"></div>
                    <div class="orb__ring">
                        <span class="orb__dot" style="top:5%;left:50%;transform:translateX(-50%)"></span>
                        <span class="orb__lbl lbl-listen" style="top:-3%;left:50%;transform:translateX(-50%)">Listening</span>
                        <span class="orb__dot" style="top:50%;right:3%;transform:translateY(-50%)"></span>
                        <span class="orb__lbl" style="top:50%;right:-8px;transform:translateY(-50%)">STT</span>
                        <span class="orb__dot" style="bottom:5%;left:50%;transform:translateX(-50%)"></span>
                        <span class="orb__lbl lbl-speak" style="bottom:-3%;left:50%;transform:translateX(-50%)">Speaking</span>
                        <span class="orb__dot" style="top:50%;left:3%;transform:translateY(-50%)"></span>
                        <span class="orb__lbl" style="top:50%;left:-8px;transform:translateY(-50%)">TTS</span>
                    </div>
                </div>
                <div class="jv-state idle" id="jv-state">Tap the orb to start</div>
                <div class="jv-transcript"><input id="jv-vinput" type="text" placeholder="Speak or type your question…" autocomplete="off"></div>
                <div class="jv-hint">Always listening · ask a question, say “open the leads page” to jump there, or “full screen” to hide this panel.</div>
            </div>

            {{-- RIGHT: response panel (slides in when an answer/skeleton is shown) --}}
            <div class="jv-right" id="jv-right">
                <div class="jv-right__head">
                    <span class="jv-right__title"><i data-lucide="wand" style="width:14px;height:14px"></i> Conversation</span>
                    <button class="jv-iconbtn" id="jv-rmin" title="Close panel (full-screen orb)"><i data-lucide="x" style="width:16px;height:16px"></i></button>
                </div>
                <div class="jv-right__body" id="jv-answer"></div>
            </div>
        </div>

        {{-- Text mode --}}
        <div class="jv-text" id="jv-text">
            <div class="jv-msgs" id="jv-msgs"></div>
            <div class="jv-composer">
                <form class="jv-inputbar" id="jv-form">
                    <textarea id="jv-tinput" class="jv-input" rows="1" placeholder="Type your question…"></textarea>
                    <button type="submit" class="jv-send"><i data-lucide="arrow-up" style="width:18px;height:18px"></i></button>
                </form>
            </div>
        </div>

        {{-- History drawer (hidden by default) --}}
        <div class="jv-overlay" id="jv-overlay"></div>
        <aside class="jv-drawer" id="jv-drawer">
            <div class="jv-drawer__top">
                <button class="jv-newchat" id="jv-newchat"><i data-lucide="plus" style="width:15px;height:15px"></i> New chat</button>
                <button class="jv-iconbtn" id="jv-drawerclose" title="Close"><i data-lucide="x" style="width:16px;height:16px"></i></button>
            </div>
            <div class="jv-threads" id="jv-threads"></div>
            <div class="jv-drawer__foot"><i data-lucide="shield" style="width:12px;height:12px"></i> Scoped to your access · {{ $client->name }}</div>
        </aside>
    </div>
</div>

<script>
(function () {
    var askUrl=@json(route('assistant.ask', ['client' => $slug])), navUrl=@json(route('assistant.navigate', ['client' => $slug])), csrf=@json(csrf_token()),
        clientId=@json((string)$client->id), userName=@json($userName),
        projects=@json($projects->map(fn($p)=>['id'=>(int)$p->id,'name'=>$p->name])->values()),
        NAV=@json($navItems ?? []),
        STORE='tva_asst_threads_'+clientId;

    var $=function(id){return document.getElementById(id);};
    var orb=$('jv-orb'), state=$('jv-state'), vinput=$('jv-vinput'), answerBox=$('jv-answer'),
        voiceEl=$('jv-voice'), textEl=$('jv-text'), msgs=$('jv-msgs'), form=$('jv-form'), tinput=$('jv-tinput'),
        proj=$('jv-project'), voiceBtn=$('jv-voicebtn'), modeBtn=$('jv-modebtn'), voiceSel=$('jv-voicesel'), langSel=$('jv-langsel'), convSel=$('jv-convmode'),
        micBtn=$('jv-micbtn'), rmin=$('jv-rmin'),
        drawer=$('jv-drawer'), overlay=$('jv-overlay');

    var threads=load(), activeId=null, mode='voice', curState='idle';
    var voiceOn=(localStorage.getItem('tva_asst_voice')!=='0'), started=false, suppressRestart=false, busy=false, micEnabled=true;

    /* ── language + voice ── the top-bar language dropdown is the single source
       of truth: it sets the LLM reply language, the mic recognition language and
       the TTS voice. Default English; remembered across visits via localStorage. */
    var LANG_STT = { en:'en-US', ur:'ur-PK', roman:'en-US', ar:'ar-SA', hi:'hi-IN' };
    var LANG_TTS = { en:'en',    ur:'ur',    roman:'ur',    ar:'ar',    hi:'hi'    };
    var selectedLang = localStorage.getItem('tva_asst_lang') || 'en';

    var voices=[], selectedVoice=localStorage.getItem('tva_asst_voicename')||'';
    function isUrduVoice(v){ return /^ur(-|$)/i.test((v&&v.lang)||''); }
    function bestUrduVoice(){
        var urs=voices.filter(isUrduVoice);
        if(!urs.length) return null;
        var pk=urs.filter(function(v){return /pk/i.test(v.lang||'');});
        var pool=pk.length?pk:urs;
        return pool.find(function(v){return /uzma|gul|zara|female|woman/i.test(v.name||'');}) || pool[0];
    }
    // Choose a TTS voice for a language code ('en','ur','ar','hi'). Honours the
    // user's explicit voice pick when it matches the language; for Urdu falls back
    // to a Hindi voice (same spoken language) when the browser has no Urdu voice.
    function pickVoice(want){
        if(!voices.length) return null;
        if(selectedVoice){ var sv=voices.find(function(x){return x.name===selectedVoice;});
            if(sv && new RegExp('^'+want,'i').test(sv.lang||'')) return sv; }
        if(want==='ur'){ return bestUrduVoice() || voices.find(function(v){return /^hi/i.test(v.lang||'');}) || null; }
        var m=voices.filter(function(v){return new RegExp('^'+want,'i').test(v.lang||'');});
        return m[0] || null;
    }
    function loadVoices(){
        if(!window.speechSynthesis) { voiceSel.innerHTML='<option>Default</option>'; return; }
        voices=window.speechSynthesis.getVoices()||[];
        var en=voices.filter(function(v){return /^en/i.test(v.lang);}), rest=voices.filter(function(v){return !/^en/i.test(v.lang);});
        var ordered=en.concat(rest);
        if(!ordered.length){ voiceSel.innerHTML='<option>Default</option>'; return; }
        voiceSel.innerHTML=ordered.map(function(v){return '<option value="'+esc(v.name)+'">'+esc(v.name)+(v.lang?(' · '+v.lang):'')+'</option>';}).join('');
        if(!selectedVoice){ var d=pickVoice(LANG_TTS[selectedLang]||'en')||en[0]; if(d) selectedVoice=d.name; }
        if(selectedVoice) voiceSel.value=selectedVoice;
    }
    if(window.speechSynthesis){ loadVoices(); window.speechSynthesis.onvoiceschanged=loadVoices; }
    voiceSel.addEventListener('change',function(){ selectedVoice=voiceSel.value; localStorage.setItem('tva_asst_voicename',selectedVoice); speak(L('sample')); });

    // Language dropdown → drives reply language + mic + TTS. Restores last choice.
    if(langSel){ langSel.value=selectedLang;
        langSel.addEventListener('change',function(){
            selectedLang=langSel.value||'en';
            localStorage.setItem('tva_asst_lang',selectedLang);
            sttLang=LANG_STT[selectedLang]||'en-US';
            // restart recognition so the new language applies immediately
            if(started && micEnabled && mode==='voice' && curState!=='speaking'){ try{rec.stop();}catch(_){} }
        });
    }

    // Conversation-mode dropdown: 'qa' (concise answers) vs 'discussion' (real
    // human-like back-and-forth). Sent with each question; remembered across visits.
    var convMode = localStorage.getItem('tva_asst_convmode') || 'qa';
    if(convSel){ convSel.value=convMode;
        convSel.addEventListener('change',function(){ convMode=convSel.value||'qa'; localStorage.setItem('tva_asst_convmode',convMode); if(convMode==='discussion' && mode==='voice'){ closeRight(); } });
    }

    /* Localized SPOKEN/UI phrases — everything the assistant says on its own
       (greeting, please-wait acks, "on screen", errors, voice preview) must
       match the selected language, not always English. Falls back to English. */
    var PHRASES = {
        en: { greet:'Hi', help:'How can I help you today?',
              acks:['Bear with me, I\'m on it.','One moment — still working on that.','Hang tight, almost there.','Thanks for your patience, nearly done.','Just a few more seconds.'],
              onscreen:'Sure — your details are on the screen.',
              norepeat:'I don\'t have anything to repeat yet.',
              toolong:'The full details are on the screen — too much to read out, but you can view and download them.',
              noans:'Sorry, I couldn\'t answer that.', connerr:'Connection error — please try again.', sample:'This is how I will sound.' },
        ur: { greet:'السلام علیکم', help:'میں آپ کی کیا مدد کر سکتی ہوں؟',
              acks:['ذرا انتظار کیجیے، میں دیکھ رہی ہوں۔','ایک لمحہ، ابھی کر رہی ہوں۔','بس تھوڑی دیر، تقریباً ہو گیا۔','آپ کے صبر کا شکریہ، بس مکمل ہونے والا ہے۔','بس چند سیکنڈ اور۔'],
              onscreen:'تفصیلات اسکرین پر موجود ہیں۔',
              norepeat:'ابھی میرے پاس دہرانے کے لیے کچھ نہیں ہے۔',
              toolong:'مکمل تفصیلات اسکرین پر موجود ہیں — پڑھ کر سنانے کے لیے بہت زیادہ ہیں، آپ انہیں دیکھ اور ڈاؤن لوڈ کر سکتے ہیں۔',
              noans:'معذرت، میں اس کا جواب نہیں دے سکی۔', connerr:'کنکشن میں مسئلہ — دوبارہ کوشش کیجیے۔', sample:'میں اس طرح بولوں گی۔' },
        roman:{ greet:'Assalam o alaikum', help:'Main aap ki kya madad kar sakti hun?',
              acks:['Zara intezar kijiye, main dekh rahi hun.','Ek lamha, abhi kar rahi hun.','Bas thodi der, taqreeban ho gaya.','Aap ke sabr ka shukriya, bas hone wala hai.','Bas chand second aur.'],
              onscreen:'Tafseelat screen par mojood hain.',
              norepeat:'Abhi mere paas dohraane ke liye kuch nahi hai.',
              toolong:'Poori tafseelat screen par hain — parh kar sunane ke liye bahut zyada, aap unhe dekh aur download kar sakte hain.',
              noans:'Maazrat, main iska jawab nahi de saki.', connerr:'Connection mein masla — dobara koshish kijiye.', sample:'Main is tarah bolungi.' },
        ar: { greet:'السلام عليكم', help:'كيف يمكنني مساعدتك اليوم؟',
              acks:['لحظة من فضلك، أنا أعمل على ذلك.','انتظر قليلاً، أوشكت على الانتهاء.','شكراً لصبرك، اقتربت من الانتهاء.','بضع ثوانٍ أخرى فقط.'],
              onscreen:'التفاصيل موجودة على الشاشة.',
              norepeat:'ليس لديّ ما أكرره بعد.',
              toolong:'التفاصيل الكاملة على الشاشة — كثيرة جداً لقراءتها، لكن يمكنك عرضها وتنزيلها.',
              noans:'آسفة، لم أستطع الإجابة على ذلك.', connerr:'خطأ في الاتصال — حاول مرة أخرى.', sample:'هكذا سيكون صوتي.' },
        hi: { greet:'नमस्ते', help:'मैं आपकी क्या मदद कर सकती हूँ?',
              acks:['एक पल रुकिए, मैं देख रही हूँ।','बस थोड़ी देर, लगभग हो गया।','आपके धैर्य के लिए शुक्रिया, बस पूरा होने वाला है।','बस कुछ ही सेकंड और।'],
              onscreen:'विवरण स्क्रीन पर मौजूद हैं।',
              norepeat:'अभी मेरे पास दोहराने के लिए कुछ नहीं है।',
              toolong:'पूरा विवरण स्क्रीन पर है — पढ़कर सुनाने के लिए बहुत ज़्यादा है, आप इसे देख और डाउनलोड कर सकते हैं।',
              noans:'क्षमा करें, मैं इसका उत्तर नहीं दे पाई।', connerr:'कनेक्शन में समस्या — फिर से कोशिश करें।', sample:'मैं इस तरह बोलूँगी।' }
    };
    function L(key){ var p=PHRASES[selectedLang]||PHRASES.en; return (p&&p[key]!=null)?p[key]:PHRASES.en[key]; }
    function greetLine(){ return L('greet')+' '+userName+' — '+L('help'); }

    function load(){ try{return JSON.parse(localStorage.getItem(STORE)||'[]');}catch(_){return[];} }
    function persist(){ try{localStorage.setItem(STORE,JSON.stringify(threads.slice(0,50)));}catch(_){} }
    function uid(){ return 't'+Math.random().toString(36).slice(2,9); }
    function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }
    function icon(n,sz){ return '<i data-lucide="'+n+'" style="width:'+(sz||16)+'px;height:'+(sz||16)+'px"></i>'; }
    // NOTE: this bundle's lucide.createIcons() THROWS when called with no
    // args (Object.values(icons).length===0), so dynamically-injected icons
    // never rendered. Pass the full icon set + the data-lucide attribute.
    function ricons(){ try{ var L=window.lucide; if(L&&L.createIcons){ L.createIcons({ icons:(L.icons||{}), nameAttr:'data-lucide' }); } }catch(_){} }
    function active(){ return threads.find(function(t){return t.id===activeId;}); }
    function greeting(){ var h=new Date().getHours(); return h<12?'Good morning':h<18?'Good afternoon':'Good evening'; }

    // While thinking, cycle the label through progress phrases so it reads as
    // active work rather than a frozen "Thinking…".
    var THINK_WORDS=['Thinking…','Understanding your question…','Looking through the data…','Working on it…','Crunching the numbers…','Putting it together…','Almost there…'];
    var thinkTimer=null, thinkIx=0;
    function startThinking(){ stopThinking(); thinkIx=0; state.textContent=THINK_WORDS[0];
        thinkTimer=setInterval(function(){
            if(curState!=='thinking'){ stopThinking(); return; }
            thinkIx=(thinkIx+1)%THINK_WORDS.length; state.textContent=THINK_WORDS[thinkIx];
        }, 1700); }
    function stopThinking(){ if(thinkTimer){ clearInterval(thinkTimer); thinkTimer=null; } }

    function setState(s){ curState=s;
        orb.classList.remove('is-idle','is-listening','is-thinking','is-speaking'); orb.classList.add('is-'+s);
        if(window.jvOrbState) window.jvOrbState(s);
        state.className='jv-state '+s;
        if(s==='thinking'){ startThinking(); return; }   // label is driven by the cycling timer
        stopThinking();
        state.textContent={idle:'Tap the orb to start',listening:'Listening…',speaking:'Speaking…'}[s]||''; }

    /* ── voice out ── */
    var _utterRefs=[];   // hold refs — Chrome GCs utterances mid-speech and then never fires onend
    var speakingText='', curAbort=null, slowAck=null, lastSubmitted='', botTalking=false;
    // Echo guard — while the assistant is speaking, ignore mic text that is just
    // its own voice being picked up (it overlaps what it is reading aloud).
    function isEcho(heard){ var s=(speakingText||'').toLowerCase(); if(!s)return false;
        var w=heard.toLowerCase().split(/\s+/).filter(Boolean); if(!w.length)return true;
        var hit=0; w.forEach(function(x){ if(s.indexOf(x)>=0)hit++; }); return (hit/w.length)>=0.6; }
    // Barge-in — user spoke while the assistant was thinking or reading: stop TTS,
    // abort the in-flight reply, and take the new question right away.
    function bargeIn(q){ try{ if(window.speechSynthesis)window.speechSynthesis.cancel(); }catch(_){}
        try{ if(curAbort)curAbort.abort(); }catch(_){}
        if(slowAck){ clearTimeout(slowAck); slowAck=null; }
        stopProc(); busy=false; speakingText=''; heardText='';
        submit(q); }
    // Urdu/Arabic-script detection — true when the reply is written in Urdu.
    function isUrduText(s){ return /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]/.test(s||''); }
    // Strip emojis/pictographs so TTS never reads them aloud ("smiling face", "laughing emoji", …).
    function stripSpeech(s){ return (s||'').replace(/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{2190}-\u{21FF}\u{2B00}-\u{2BFF}\u{FE00}-\u{FE0F}\u{1F1E6}-\u{1F1FF}\u{200D}\u{2122}\u{2139}]/gu,'').replace(/\s{2,}/g,' ').trim(); }
    function speak(text, arg){
        var opts = (typeof arg==='function') ? {then:arg} : (arg||{});
        var say=stripSpeech(text);   // never read emojis aloud
        // Voice off/unsupported, or nothing left after removing emojis: no TTS, but still resume listening.
        if(!voiceOn || !window.speechSynthesis || !say){
            if(opts.then)opts.then();
            if(!opts.noResume && mode==='voice' && !busy && micEnabled) startMic();
            return;
        }
        try{
            botTalking=true;   // HARD block: the recognizer must ignore ALL input for the whole time we speak (a mic that doesn't stop instantly would otherwise hear our own voice and treat it as a user question)
            stopMic(true);     // also stop the mic — browser STT has no echo cancellation
            window.speechSynthesis.cancel();
            var u=new SpeechSynthesisUtterance(say);
            speakingText=say;
            // Voice follows the SELECTED language (dropdown), not text sniffing:
            // en→English, ur→Urdu (Hindi fallback), ar→Arabic, hi→Hindi.
            var want=LANG_TTS[selectedLang]||'en', chosen=pickVoice(want);
            if(!chosen && selectedVoice){ chosen=voices.find(function(x){return x.name===selectedVoice;}); }
            if(chosen){ u.voice=chosen; if(chosen.lang) u.lang=chosen.lang; }
            u.rate=(want==='ur'||want==='hi')?0.98:1.05;   // these read clearer a touch slower
            _utterRefs.push(u);
            var hasStarted=false;
            u.onstart=function(){ hasStarted=true; if(!opts.keepState) setState('speaking'); };
            var fired=false, done=function(){ if(fired)return; fired=true; speakingText='';
                var ix=_utterRefs.indexOf(u); if(ix>=0)_utterRefs.splice(ix,1);
                if(opts.then)opts.then();
                // Resume listening only AFTER a short cooldown, so the tail of our own
                // audio isn't captured as a new question. Keep botTalking true until then.
                if(!opts.noResume && mode==='voice' && !busy){
                    if(micEnabled){ setTimeout(function(){ botTalking=false; if(!busy && mode==='voice' && micEnabled) startMic(); }, 300); }
                    else { botTalking=false; setState('idle'); }
                } else { botTalking=false; } };
            u.onend=done; u.onerror=done;   // some Chrome builds fire onerror (interrupted/canceled) instead of onend
            window.speechSynthesis.speak(u);
            // onend is unreliable (Chrome/Edge) — that lag before it listened again was
            // this. Poll speechSynthesis.speaking and resume the INSTANT it finishes
            // (only after it has truly started, so network voices aren't cut off early).
            var poll=setInterval(function(){
                if(fired){ clearInterval(poll); return; }
                if(window.speechSynthesis.speaking){ hasStarted=true; }
                else if(hasStarted){ clearInterval(poll); done(); }
            }, 120);
            // Hard backstop if the voice never actually starts (silent failure).
            setTimeout(function(){ if(!fired){ clearInterval(poll); done(); } }, Math.min(2000 + text.length*80, 15000));
        }catch(_){ botTalking=false; if(opts.then)opts.then(); if(!opts.noResume && mode==='voice' && !busy && micEnabled) startMic(); }
    }
    /* Brief "still working" lines — spoken ONLY when a reply is slow (>5s),
       pulled from the localized phrase set so they match the selected language. */
    var lastAck='';
    function pickAck(){ var arr=(PHRASES[selectedLang]&&PHRASES[selectedLang].acks)||PHRASES.en.acks; var a; do { a=arr[Math.floor(Math.random()*arr.length)]; } while(arr.length>1 && a===lastAck); lastAck=a; return a; }
    function wordCount(s){ return (s||'').trim().split(/\s+/).filter(Boolean).length; }
    function isRepeat(q){ return /\b(repeat|say (that|it) again|again please|tell me again|read (it|that|this)|speak (it|that|this|again)|what did you say)\b/i.test(q||''); }
    function lastAssistant(){ var t=active(); if(!t)return null; for(var i=t.messages.length-1;i>=0;i--){ if(t.messages[i].role==='assistant')return t.messages[i]; } return null; }
    voiceBtn.addEventListener('click',function(){ voiceOn=!voiceOn; localStorage.setItem('tva_asst_voice',voiceOn?'1':'0');
        voiceBtn.classList.toggle('on',voiceOn); voiceBtn.innerHTML=icon(voiceOn?'volume-2':'volume-x',18); ricons();
        if(!voiceOn&&window.speechSynthesis)window.speechSynthesis.cancel(); });
    voiceBtn.classList.toggle('on',voiceOn);

    /* ── continuous mic with end-of-speech (silence) detection ──
       Background noise can keep the recognizer "open" so a final result
       never fires and the orb is stuck on "Listening" while the user is
       actually waiting. We finalize the turn ourselves once the heard
       transcript stops *changing* for a short pause (SILENCE_MS), and we
       ignore the mic entirely while the bot is thinking or speaking so
       its own voice / room noise can't be mistaken for a new question. */
    var SR=window.SpeechRecognition||window.webkitSpeechRecognition, rec=null;
    var hush=null, heardText='', maxTurn=null, SILENCE_MS=1300, MAXTURN_MS=15000;
    // Speech INPUT language follows the top-bar language dropdown (default en-US).
    // Web Speech recognition takes a single language code at a time.
    var sttFallback='en-US', sttLang=(LANG_STT[selectedLang]||'en-US');
    function clearHush(){ if(hush){clearTimeout(hush);hush=null;} }
    function clearMaxTurn(){ if(maxTurn){clearTimeout(maxTurn);maxTurn=null;} }
    function finalizeTurn(){
        clearHush(); clearMaxTurn();
        var q=(heardText||'').trim(); heardText='';
        if(busy || botTalking || curState==='speaking') return;
        if(q.length<2 || !wordCount(q)){ if(curState==='listening') vinput.value=''; return; } // ignore noise blips
        submit(q);
    }
    function armHush(){ clearHush(); hush=setTimeout(finalizeTurn, SILENCE_MS); }
    if(SR){ rec=new SR(); rec.lang=sttLang; rec.continuous=true; rec.interimResults=true;
        rec.onstart=function(){ heardText=''; clearMaxTurn(); };
        rec.onresult=function(e){
            if(botTalking || curState==='speaking') return;   // never process while WE are talking (browser can't echo-cancel)
            var interim='',final='';
            for(var i=e.resultIndex;i<e.results.length;i++){ var r=e.results[i]; if(r.isFinal)final+=r[0].transcript; else interim+=r[0].transcript; }
            var heard=(final+' '+interim).replace(/\s+/g,' ').trim();
            // LISTEN DURING GENERATION: no speech audio plays while we prepare the reply,
            // so if the user talks, cancel the pending reply and take the new question.
            if(busy){
                var ft=final.trim();
                if(!ft || wordCount(ft)<2) return;                            // wait for a real, final utterance
                if(ft.toLowerCase()===(lastSubmitted||'').toLowerCase()) return; // tail of the same question
                bargeIn(ft);
                return;
            }
            if(heard!==heardText){
                heardText=heard; vinput.value=heard;
                if(curState!=='listening') setState('listening');
                armHush();                                       // reset pause timer only when speech actually changes
                if(!maxTurn) maxTurn=setTimeout(finalizeTurn, MAXTURN_MS);
            }
            if(final.trim()){ clearHush(); hush=setTimeout(finalizeTurn, 350); } // natural end → finalize fast
        };
        rec.onspeechend=function(){ if(heardText.trim()) armHush(); };
        rec.onend=function(){ clearHush(); if(started && micEnabled && mode==='voice' && !suppressRestart && curState!=='speaking'){ try{rec.start();}catch(_){} } };
        rec.onerror=function(e){ var err=e&&e.error;
            console.warn('[STT] error:', err, '| lang:', rec.lang);
            if(err==='not-allowed'||err==='service-not-allowed'){ micEnabled=false; suppressRestart=true; setState('idle'); syncMicBtn(); return; }
            // Recognizer can't do the chosen language → fall back to English so the
            // assistant still RESPONDS (the reply text stays Urdu regardless). The
            // onend handler then restarts recognition with the new language.
            if((err==='language-not-supported'||err==='bad-grammar') && sttLang!==sttFallback){
                console.warn('[STT] "'+sttLang+'" not supported by this browser — falling back to '+sttFallback);
                sttLang=sttFallback; try{ rec.lang=sttLang; }catch(_){}
            } };
    }
    function startMic(){ if(!rec||mode!=='voice'||!micEnabled)return; suppressRestart=false; heardText='';
        try{ rec.lang=sttLang; }catch(_){}   // keep the recognizer on the chosen language
        try{ rec.start(); }catch(_){}     // throws if already running — that's fine, we're listening
        setState('listening'); }          // always reflect listening: this is only called when we INTEND to listen
    function stopMic(suppress){ if(!rec)return; suppressRestart=!!suppress; clearHush(); clearMaxTurn(); try{rec.stop();}catch(_){} }

    /* explicit listen on/off — top-bar button + orb tap share this */
    function syncMicBtn(){ if(!micBtn)return; micBtn.classList.toggle('on',micEnabled); micBtn.classList.toggle('muted',!micEnabled); micBtn.innerHTML=icon(micEnabled?'mic':'mic-off',18); micBtn.title=micEnabled?'Stop listening':'Start listening'; ricons(); }
    function setListening(on){ micEnabled=on; if(on){ if(!started){ begin(); } else { startMic(); } } else { stopMic(true); setState('idle'); vinput.value=''; } syncMicBtn(); }
    if(micBtn) micBtn.addEventListener('click', function(){ setListening(!micEnabled); });
    if(rmin) rmin.addEventListener('click', function(){ closeRight(); });

    /* tap orb → start (mic permission needs a gesture) / pause-resume */
    orb.addEventListener('click',function(){
        if(!started){ begin(); return; }
        // If the assistant is speaking, a tap STOPS it instantly (reliable + echo-free —
        // a spoken "stop" can't work because the mic is muted while it talks over you).
        if(botTalking){ try{ if(window.speechSynthesis)window.speechSynthesis.cancel(); }catch(_){} return; }
        setListening(!micEnabled);
    });

    function begin(){
        started=true; micEnabled=true; ensureAudio(); syncMicBtn();
        var hi=greetLine();
        speak(hi);   // speak() resumes the mic when it ends (orb turns green); with voice off it resumes immediately
    }

    /* ── data widgets ── */
    function tableInner(t){ var h='<table class="jv-tbl"><thead><tr>'+t.columns.map(function(c){return '<th>'+esc(c)+'</th>';}).join('')+'</tr></thead><tbody>';
        t.rows.forEach(function(r){ h+='<tr>'+t.columns.map(function(c){return '<td>'+esc(r[c])+'</td>';}).join('')+'</tr>'; }); return h+'</tbody></table>'; }
    function widget(t,key){ return '<div class="jv-widget" data-key="'+key+'"><div class="jv-widget__bar"><span class="jv-widget__title">'+esc(t.title)+
        '</span><span class="jv-widget__count">'+t.rows.length+' row'+(t.rows.length===1?'':'s')+' · '+t.columns.length+' cols</span><span class="jv-widget__acts">'+
        '<button class="jv-wbtn" data-act="print">'+icon('printer',13)+' Print</button>'+
        '<button class="jv-wbtn" data-act="csv">'+icon('download',13)+' CSV</button>'+
        '<button class="jv-wbtn" data-act="xls">'+icon('sheet',13)+' Excel</button>'+
        '</span></div><div class="jv-tblwrap">'+tableInner(t)+'</div></div>'; }
    var TBL={};
    function cell(v){ return (v==null)?'':(typeof v==='object'?JSON.stringify(v):String(v)); }
    function toCsv(t){ var q=function(v){v=cell(v);return /[",\n]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v;};
        var o=[t.columns.map(q).join(',')]; t.rows.forEach(function(r){o.push(t.columns.map(function(c){return q(r[c]);}).join(','));}); return o.join('\n'); }
    function dlCsv(t){ var b=new Blob(['﻿'+toCsv(t)],{type:'text/csv;charset=utf-8'}),a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download=fname(t,'csv'); a.click(); setTimeout(function(){URL.revokeObjectURL(a.href);},500); }
    /* .xls via an HTML table — Excel opens it natively, no library needed. */
    function toXls(t){ var h='<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1"><thead><tr>'+
        t.columns.map(function(c){return '<th style="background:#f1f5f9;font-weight:bold">'+esc(c)+'</th>';}).join('')+'</tr></thead><tbody>'+
        t.rows.map(function(r){return '<tr>'+t.columns.map(function(c){return '<td>'+esc(cell(r[c]))+'</td>';}).join('')+'</tr>';}).join('')+'</tbody></table></body></html>'; return h; }
    function dlXls(t){ var b=new Blob(['﻿'+toXls(t)],{type:'application/vnd.ms-excel'}),a=document.createElement('a'); a.href=URL.createObjectURL(b); a.download=fname(t,'xls'); a.click(); setTimeout(function(){URL.revokeObjectURL(a.href);},500); }
    function fname(t,ext){ return (t.title||'data').replace(/[^\w]+/g,'_').replace(/^_+|_+$/g,'').toLowerCase()+'.'+ext; }
    function printT(t){ var w=window.open('','_blank'); if(!w)return; w.document.write('<html><head><title>'+esc(t.title)+'</title><style>body{font-family:system-ui;padding:24px}h3{margin:0 0 14px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{border:1px solid #cbd5e1;padding:7px 11px;text-align:left}th{background:#f1f5f9}</style></head><body><h3>'+esc(t.title)+'</h3>'+tableInner(t)+'</body></html>'); w.document.close(); w.focus(); setTimeout(function(){w.print();},250); }
    function bindWidgets(scope){ Array.prototype.forEach.call(scope.querySelectorAll('.jv-widget'),function(w){ var t=TBL[w.getAttribute('data-key')]; if(!t)return;
        var p=w.querySelector('[data-act=print]'); if(p)p.addEventListener('click',function(){printT(t);});
        var c=w.querySelector('[data-act=csv]'); if(c)c.addEventListener('click',function(){dlCsv(t);});
        var x=w.querySelector('[data-act=xls]'); if(x)x.addEventListener('click',function(){dlXls(t);}); }); }

    /* If the model still answers with a markdown table (despite being told to
       defer to the widget), turn it into a real exportable table so the user
       gets Print / CSV / Excel instead of raw "| a | b |" text. */
    function parseMarkdownTable(text){
        if(!text || text.indexOf('|')<0) return null;
        var lines=text.split(/\r?\n/), start=-1;
        var sep=/^\s*\|?[\s:|]*-{2,}[\s:|-]*\|?\s*$/;
        for(var i=0;i<lines.length-1;i++){
            if(lines[i].indexOf('|')>=0 && sep.test(lines[i+1])){ start=i; break; }
        }
        if(start<0) return null;
        var split=function(row){ return row.trim().replace(/^\|/,'').replace(/\|\s*$/,'').split('|').map(function(s){return s.trim();}); };
        var columns=split(lines[start]).filter(function(c){return c!=='';});
        if(columns.length<2) return null;
        var rows=[], end=start+2;
        for(var j=start+2;j<lines.length;j++){
            if(lines[j].indexOf('|')<0) break;
            var cells=split(lines[j]);
            if(cells.length===1 && cells[0]==='') break;
            var o={}; columns.forEach(function(c,k){ o[c]=cells[k]!==undefined?cells[k]:''; }); rows.push(o); end=j+1;
        }
        if(rows.length<1) return null;
        return {table:{title:'Results',columns:columns,rows:rows}, start:start, end:end};
    }
    function tableizeAnswer(d){
        var answer=(d&&d.answer)?d.answer:'';
        var tables=(d&&d.tables)?d.tables.slice():[];
        if(!tables.length){
            var md=parseMarkdownTable(answer);
            if(md){
                tables.push(md.table);
                var lines=answer.split(/\r?\n/);
                lines.splice(md.start, md.end-md.start);                 // drop the raw md table lines
                answer=lines.join('\n').replace(/\n{3,}/g,'\n\n').trim() || 'Here is the data — see the table below.';
            }
        }
        return {answer:answer, tables:tables};
    }

    /* ── right response panel: a full conversation thread (newest at bottom) ── */
    function openRight(){ voiceEl.classList.add('split'); }
    function closeRight(){ voiceEl.classList.remove('split'); answerBox.innerHTML=''; if(mode==='voice' && started && micEnabled && !busy) startMic(); }
    function fmtTime(ts){ if(!ts) return ''; try{ return new Date(ts).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}); }catch(_){ return ''; } }
    function rbubble(m){
        var who = m.role==='user' ? 'user' : 'bot';
        var av  = '<div class="jv-rav '+who+'">'+icon(who==='user'?'user':'bot',14)+'</div>';
        var w=''; if(m.tables){ m.tables.forEach(function(t,i){ var key='r'+(m._k||(m._k=Math.random().toString(36).slice(2,7)))+'_'+i; TBL[key]=t; w+=widget(t,key); }); }
        var src = (m.sources&&m.sources.length) ? '<div class="jv-rsrc">sources: '+esc(m.sources.join(', '))+'</div>' : '';
        var tm  = m.at ? '<div class="jv-rmeta">'+esc(fmtTime(m.at))+'</div>' : '';
        return '<div class="jv-rrow '+who+'">'+av+'<div class="jv-rcol"><div class="jv-rbubble">'+esc(m.content)+src+'</div>'+w+tm+'</div></div>';
    }
    function skelBubble(){ return '<div class="jv-rrow bot"><div class="jv-rav bot">'+icon('bot',14)+'</div>'+
        '<div class="jv-rcol" style="width:min(340px,82%)"><div class="jv-skel"><div class="jv-skel__line w90"></div><div class="jv-skel__line w70"></div><div class="jv-skel__line w55"></div></div></div></div>'; }
    function renderRight(pending){
        openRight();
        var t=active(), arr=(t?t.messages:[]);
        var html='<div class="jv-rstream">';
        arr.forEach(function(m){ html+=rbubble(m); });
        if(pending) html+=skelBubble();
        html+='</div>';
        answerBox.innerHTML=html; ricons(); bindWidgets(answerBox); answerBox.scrollTop=answerBox.scrollHeight;
    }
    /* back-compat: skeleton = a pending bubble at the end of the thread */
    function showSkeleton(){ renderRight(true); }
    function showAnswerText(){ renderRight(false); }

    /* ── robotic processing sound (Web Audio, no asset) ── */
    var audioCtx=null, proc=null;
    function ensureAudio(){ try{ if(!audioCtx) audioCtx=new (window.AudioContext||window.webkitAudioContext)(); if(audioCtx.state==='suspended') audioCtx.resume(); }catch(_){} }
    function startProc(){ ensureAudio(); if(!audioCtx) return; stopProc();
        var t=audioCtx.currentTime;
        var osc=audioCtx.createOscillator(); osc.type='sawtooth'; osc.frequency.value=120;
        var lfo=audioCtx.createOscillator(); lfo.type='sine'; lfo.frequency.value=7;
        var lg=audioCtx.createGain(); lg.gain.value=26; lfo.connect(lg); lg.connect(osc.frequency);
        var flt=audioCtx.createBiquadFilter(); flt.type='bandpass'; flt.frequency.value=620; flt.Q.value=9;
        var g=audioCtx.createGain(); g.gain.value=0; g.gain.linearRampToValueAtTime(0.045, t+0.12);
        osc.connect(flt); flt.connect(g); g.connect(audioCtx.destination); osc.start(); lfo.start();
        proc={osc:osc,lfo:lfo,g:g};
    }
    function stopProc(){ if(!proc||!audioCtx) return; var t=audioCtx.currentTime; try{ proc.g.gain.cancelScheduledValues(t); proc.g.gain.linearRampToValueAtTime(0,t+0.18); proc.osc.stop(t+0.22); proc.lfo.stop(t+0.22); }catch(_){} proc=null; }

    /* ── layout voice commands ── */
    function isViewCommand(q){ return /\b(full ?screen|full ?page|hide (the )?(right|response|panel|info)|close (the )?(panel|right|response)|center( view)?|minimi[sz]e|go full)\b/i.test(q||''); }

    /* ── navigation voice commands: "open the leads page" → open in a new tab ── */
    var NAV_VERB=/\b(open(?:\s+up)?|go\s*to|goto|navigate\s+to|take\s+me\s+to|launch|switch\s+to|bring\s+up|jump\s+to)\b/i;
    function matchNav(q){
        q=(q||'').toLowerCase().replace(/[^\w\s&]/g,' ').replace(/\s+/g,' ').trim();
        if(!q) return null;
        // strip the verb + filler words so "open the leads page" → "leads"
        var c=q.replace(NAV_VERB,' ')
               .replace(/\b(the|a|an|my|me|to|please|kindly|up|in|on|new|tab|window|page|screen|section|menu|view|panel|settings)\b/g,' ')
               .replace(/\s+/g,' ').trim();
        if(!c){ c=q.replace(NAV_VERB,' ').replace(/\s+/g,' ').trim(); }
        if(!c) return null;
        var best=null, bestScore=0;
        NAV.forEach(function(it){ (it.aliases||[]).forEach(function(a){ a=(''+a).toLowerCase();
            var s=0, esc=a.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
            if(c===a) s=100;
            else if(new RegExp('\\b'+esc+'\\b').test(c)) s=a.length+5;     // alias appears as words in the phrase
            else if(c.length>=3 && a.indexOf(c)===0) s=c.length;          // phrase is a prefix of the alias
            if(s>bestScore){ bestScore=s; best=it; }
        }); });
        return bestScore>=3 ? best : null;
    }
    function openUrl(url, label){
        label = label || 'it';
        var win=null; try{ win=window.open(url,'_blank'); }catch(_){}
        if(win){ try{ win.opener=null; }catch(_){} speak('Opening '+label+'.'); return; }
        // Popup blocked (voice replies have no fresh user gesture) — drop a link to click instead.
        openRight();
        answerBox.innerHTML='<div class="jv-rstream"><div class="jv-rrow bot"><div class="jv-rav bot">'+icon('bot',14)+'</div>'+
            '<div class="jv-rcol"><div class="jv-rbubble">Your browser blocked the pop-up. Tap to open <strong>'+esc(label)+'</strong>:</div>'+
            '<a class="jv-navlink" href="'+esc(url)+'" target="_blank" rel="noopener">'+icon('external-link',15)+' Open '+esc(label)+'</a></div></div></div>';
        ricons();
        speak('I’ve put a link on the right — tap it to open.');
    }
    function doNav(it){ openUrl(it.url, it.label); }

    /* Deep navigation: "open the demo ivr flow in edit mode" → resolve the
       specific record server-side (RBAC + project scoped) and open its page. */
    var DEEP_KW=/\b(flows?|data ?sources?|datasources?|knowledge ?base|documents?|leads?|customers?|contacts?)\b/i;
    function isDeepNav(q, navHit){
        if(!DEEP_KW.test(q)) return false;
        var c=(q||'').toLowerCase().replace(NAV_VERB,' ');
        if(navHit){ (navHit.aliases||[]).forEach(function(a){ c=c.replace(new RegExp('\\b'+(''+a).replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'\\b','g'),' '); }); }
        c=c.replace(DEEP_KW,' ')
           .replace(/\bin\b.*?\bmode\b/g,' ')
           .replace(/\b(edit|editing|view|viewing|builder|mode|detail|details|page|screen|the|a|an|my|please|to|of|for|open|show|me|named|called)\b/g,' ')
           .replace(/[^\w\s]/g,' ').replace(/\s+/g,' ').trim();
        return c.length>=2;   // a leftover token = a named record, not just the section
    }
    function resolveDeepNav(q, fallback){
        vinput.value=''; tinput.value='';
        var pid=parseInt(proj.value,10)||0;
        if(!pid){ if(fallback) doNav(fallback); return; }
        fetch(navUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'application/json'},
            body:JSON.stringify({project_id:pid, query:q})})
        .then(function(r){return r.json();})
        .then(function(d){
            if(d && d.url){ openUrl(d.url, d.label||(fallback?fallback.label:'')); }
            else if(fallback){ speak("I couldn’t find that one — opening "+fallback.label+" instead."); doNav(fallback); }
            else { speak("I couldn’t find that to open."); }
        })
        .catch(function(){ if(fallback) doNav(fallback); else speak("Sorry, I couldn’t open that."); });
    }

    /* ── text-mode chat render ── */
    function msgHtml(m){ var av=m.role==='user'?'<div class="jv-av user">'+icon('user',15)+'</div>':'<div class="jv-av bot">'+icon('bot',15)+'</div>';
        var w=''; if(m.tables){ m.tables.forEach(function(t,i){ var key='m'+(m._k||(m._k=Math.random().toString(36).slice(2,7)))+'_'+i; TBL[key]=t; w+=widget(t,key); }); }
        var src=m.sources&&m.sources.length?'<div style="font-size:10.5px;color:#64748b;margin-top:7px;font-family:ui-monospace">sources: '+esc(m.sources.join(', '))+'</div>':'';
        var tm=m.at?'<div class="jv-tmeta">'+esc(fmtTime(m.at))+'</div>':'';
        return '<div class="jv-row '+m.role+'">'+av+'<div class="jv-col"><div class="jv-bubble">'+esc(m.content)+src+'</div>'+w+tm+'</div></div>'; }
    function renderText(){ var t=active(); var html='<div class="jv-stream">'; (t?t.messages:[]).forEach(function(m){ html+=msgHtml(m); }); html+='</div>';
        msgs.innerHTML=html; ricons(); bindWidgets(msgs); msgs.scrollTop=msgs.scrollHeight; }

    /* ── history drawer ── */
    function openDrawer(){ renderThreads(); drawer.classList.add('open'); overlay.classList.add('show'); }
    function closeDrawer(){ drawer.classList.remove('open'); overlay.classList.remove('show'); }
    $('jv-hist').addEventListener('click',openDrawer);
    $('jv-drawerclose').addEventListener('click',closeDrawer);
    overlay.addEventListener('click',closeDrawer);
    function renderThreads(){ var box=$('jv-threads'); box.innerHTML='';
        threads.forEach(function(t){ var d=document.createElement('div'); d.className='jv-thread'+(t.id===activeId?' is-active':'');
            d.innerHTML=icon('message-square',14)+'<span class="jv-thread__title">'+esc(t.title||'New chat')+'</span><span class="jv-thread__del">&times;</span>';
            d.addEventListener('click',function(){ activeId=t.id; if(t.projectId)proj.value=t.projectId; renderThreads(); renderText(); refreshVoiceLast(); closeDrawer(); });
            d.querySelector('.jv-thread__del').addEventListener('click',function(e){ e.stopPropagation(); threads=threads.filter(function(x){return x.id!==t.id;}); if(activeId===t.id)activeId=threads.length?threads[0].id:null; if(!threads.length)newChat(); else{persist();renderThreads();renderText();} });
            box.appendChild(d); });
        ricons();
    }
    function newChat(){ var t={id:uid(),title:'New chat',projectId:projects.length?projects[0].id:null,messages:[],updatedAt:Date.now()}; threads.unshift(t); activeId=t.id; persist(); if(t.projectId)proj.value=t.projectId; renderThreads(); renderText(); closeRight(); /* fresh slate → back to centered orb */ }
    $('jv-newchat').addEventListener('click',function(){ newChat(); closeDrawer(); });

    /* when a thread is opened from the drawer: if the conversation panel is
       already showing, re-render it for the newly-selected thread. */
    function refreshVoiceLast(){ if(mode==='voice' && voiceEl.classList.contains('split')) renderRight(); }

    /* ── submit (shared by voice + text) ── */
    function submit(q){
        q=(q||'').trim(); if(!q||busy)return;

        // "Repeat / say it again" → re-speak the last answer (only if short),
        // never re-query. Large answers stay on screen.
        if(isRepeat(q)){
            vinput.value=''; tinput.value='';
            var last=lastAssistant();
            if(!last){ speak(L('norepeat')); return; }
            if(mode==='voice') renderRight();
            if(wordCount(last.content)<=50){ speak(last.content); }
            else { speak(L('toolong')); }
            return;
        }

        // Voice control of the layout: "full screen" / "hide the panel" etc.
        if(isViewCommand(q)){ vinput.value=''; tinput.value='';
            var wasSplit=voiceEl.classList.contains('split'); closeRight();
            speak(/hide|close|minimi/i.test(q) ? (wasSplit?'Okay, hiding the panel.':'The panel is already hidden.') : 'Going full screen.');
            return; }

        // Navigation: "open the leads page", "go to dashboard" → open in a new tab.
        // Deep nav ("open the demo ivr flow in edit mode") resolves the specific record first.
        if(NAV_VERB.test(q)){
            var navHit = matchNav(q);
            if(isDeepNav(q, navHit)){ resolveDeepNav(q, navHit); return; }
            if(navHit){ vinput.value=''; tinput.value=''; doNav(navHit); return; }
        }

        var pid=parseInt(proj.value,10); if(!pid)return;
        // Keep the mic LISTENING during generation (no speech audio plays now, only
        // the processing tone), so the user can interrupt with a new question.
        busy=true; clearHush(); clearMaxTurn(); setState('thinking'); lastSubmitted=q;
        startProc();                              // processing tone (a drone — won't transcribe as words)
        var t=active(); if(!t){ newChat(); t=active(); }
        t.projectId=pid; if(!t.messages.length)t.title=q.slice(0,42);
        // Append the question to the thread FIRST, then show a pending bubble
        // below it — so the panel reads as a growing conversation, not a
        // single replaced response.
        t.messages.push({role:'user',content:q,at:Date.now()}); t.updatedAt=Date.now(); persist();
        vinput.value=''; tinput.value='';

        // DISCUSSION stays in the full-screen speak-&-listen view (no right panel);
        // the turn is still saved to the thread + DB. Q&A shows the conversation panel.
        if(mode==='voice'){ if(convMode==='discussion'){ closeRight(); } else { renderRight(true); } }
        else { renderText();
            var stream=msgs.querySelector('.jv-stream'); if(stream){ var ty=document.createElement('div'); ty.className='jv-row bot'; ty.innerHTML='<div class="jv-av bot">'+icon('bot',15)+'</div><div class="jv-col"><div class="jv-bubble"><span class="jv-typing"><span></span><span></span><span></span></span></div></div>'; stream.appendChild(ty); ricons(); msgs.scrollTop=msgs.scrollHeight; } }

        var history=t.messages.slice(-11,-1).map(function(m){return {role:m.role,content:m.content};});
        // No upfront "let me check that" filler — greetings and quick answers
        // come back directly. Only if the reply is genuinely slow (>5s) do we
        // speak ONE brief, varied please-wait line so the user isn't left hanging.
        // No spoken "please wait" — it would play WHILE we're listening during
        // generation (and be heard as input). The visual thinking cue + tone cover it.
        curAbort=('AbortController' in window)?new AbortController():null;   // so barge-in can cancel this reply
        /* Post-processing for a completed answer — shared by the streaming
           `final` frame and the non-streaming fallback. */
        function handleAnswer(d){
            clearTimeout(slowAck); stopProc();
            var tz=tableizeAnswer(d);                 // convert any markdown table → exportable widget
            var answer=tz.answer||L('noans');
            var tables=tz.tables;
            var srcs=(d&&d.sources)?d.sources.map(function(s){return s.type;}):[];
            t.messages.push({role:'assistant',content:answer,sources:srcs,tables:tables,at:Date.now()}); t.updatedAt=Date.now(); persist();
            if(mode==='text'){ renderText(); }
            else if(convMode!=='discussion'){ renderRight(); }   // Q&A shows the panel; discussion stays full-screen (still saved)
            // Speak the full answer when it's short; for tables or long
            // answers say a short line and let the screen show the detail.
            var spoken=answer;
            // Q&A/data mode: long answers & tables live on screen, so only summarise
            // them aloud. DISCUSSION mode: always speak the actual reply — it's a
            // conversation, never "the details are on the screen".
            if(convMode!=='discussion' && (tables.length || wordCount(answer)>50)){ spoken=L('onscreen'); }
            busy=false;
            if(voiceOn){ speak(spoken); } else if(mode==='voice'){ startMic(); }
        }

        function failAnswer(){
            clearTimeout(slowAck); stopProc();
            t.messages.push({role:'assistant',content:L('connerr'),at:Date.now()}); persist();
            if(mode==='text')renderText(); else if(convMode!=='discussion')renderRight();
            busy=false; if(mode==='voice'&&voiceOn===false)startMic();
        }

        /* STREAMING: the reply arrives as Server-Sent Events so the first words
           appear in ~1-2s even when the model itself takes 30s+ to finish. The
           closing `final` frame carries the authoritative envelope (answer +
           tables + sources), so rendering is identical to the buffered path. */
        var streamed='', ph=null;
        function dropPlaceholder(){
            if(!ph) return;
            var i=t.messages.indexOf(ph); if(i>-1) t.messages.splice(i,1);
            ph=null;
        }

        fetch(askUrl,{method:'POST',credentials:'same-origin',
            headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf,'Accept':'text/event-stream'},
            signal:curAbort?curAbort.signal:undefined,
            body:JSON.stringify({project_id:pid,question:q,history:history,conversation_id:(t&&t.id)?t.id:'',language:selectedLang,mode:convMode,stream:true})})
        .then(function(r){
            if(!r.ok || !r.body || !r.body.getReader){ throw new Error('stream unavailable'); }
            var reader=r.body.getReader(), dec=new TextDecoder(), buf='';
            function pump(){
                return reader.read().then(function(res){
                    if(res.done) return;
                    buf+=dec.decode(res.value,{stream:true});
                    var i;
                    while((i=buf.indexOf('\n\n'))!==-1){
                        var rec=buf.slice(0,i); buf=buf.slice(i+2);
                        rec.split('\n').forEach(function(line){
                            if(line.indexOf('data:')!==0) return;
                            var f; try{ f=JSON.parse(line.slice(5).trim()); }catch(e){ return; }
                            if(f.type==='delta'){
                                if(!ph){
                                    // First token: stop the spinner and start a live bubble.
                                    clearTimeout(slowAck); stopProc();
                                    ph={role:'assistant',content:'',at:Date.now(),streaming:true};
                                    t.messages.push(ph);
                                }
                                streamed+=(f.text||''); ph.content=streamed;
                                if(mode==='text') renderText();
                            } else if(f.type==='final'){
                                dropPlaceholder();
                                handleAnswer(f);
                            } else if(f.type==='error'){
                                dropPlaceholder();
                                handleAnswer({answer:f.message||L('connerr'),tables:[],sources:[]});
                            }
                        });
                    }
                    return pump();
                });
            }
            return pump();
        })
        .catch(function(err){ if(err&&err.name==='AbortError')return; dropPlaceholder(); failAnswer(); });
    }

    /* voice input field: allow typing + Enter to send */
    vinput.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); stopMic(true); submit(vinput.value); } });

    /* ── mode switching ── */
    function setMode(m){
        mode=m;
        if(m==='text'){ voiceEl.classList.add('hidden'); textEl.classList.add('active'); stopMic(true); if(window.speechSynthesis)window.speechSynthesis.cancel(); modeBtn.innerHTML=icon('mic',18); modeBtn.title='Switch to voice'; renderText(); tinput.focus(); }
        else { textEl.classList.remove('active'); voiceEl.classList.remove('hidden'); modeBtn.innerHTML=icon('keyboard',18); modeBtn.title='Switch to text chat'; ricons();
               if(!started){ begin(); } else { micEnabled=true; syncMicBtn(); var hi='Voice mode. '+greeting()+', '+userName+'.'; speak(hi,function(){startMic();}); if(!voiceOn)startMic(); } }
        ricons();
    }
    modeBtn.addEventListener('click',function(){ setMode(mode==='voice'?'text':'voice'); });

    /* text composer */
    form.addEventListener('submit',function(e){ e.preventDefault(); submit(tinput.value); });
    tinput.addEventListener('input',function(){ tinput.style.height='auto'; tinput.style.height=Math.min(tinput.scrollHeight,150)+'px'; });
    tinput.addEventListener('keydown',function(e){ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); submit(tinput.value); } });
    proj.addEventListener('change',function(){ var t=active(); if(t){ t.projectId=parseInt(proj.value,10); persist(); } });

    /* boot */
    if(!threads.length) newChat(); else { activeId=threads[0].id; if(threads[0].projectId)proj.value=threads[0].projectId; }
    refreshVoiceLast(); ricons();
    // Try to greet + listen immediately; browsers may need a gesture for mic —
    // the orb shows "Tap the orb to start" until then.
    setTimeout(function(){ try{ begin(); }catch(_){} }, 400);
})();
</script>

{{-- Three.js wireframe globe (same as the landing hero). Falls back to the
     CSS orb if the CDN/WebGL is unavailable. --}}
<script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
<script>
(function () {
    var mount = document.getElementById('jv-orb');
    if (!mount || typeof THREE === 'undefined') return;   // CSS fallback stays
    var orbState='idle'; window.jvOrbState=function(s){ orbState=s; };
    var w = mount.clientWidth||208, h = mount.clientHeight||208;
    var scene=new THREE.Scene();
    var camera=new THREE.PerspectiveCamera(50, w/h, 0.1, 100); camera.position.z=4.4;
    var renderer=new THREE.WebGLRenderer({alpha:true, antialias:true});
    renderer.setPixelRatio(Math.min(window.devicePixelRatio,2)); renderer.setSize(w,h);
    mount.appendChild(renderer.domElement); mount.classList.add('has-webgl');

    var orbMat=new THREE.MeshBasicMaterial({color:0x3b82f6, wireframe:true, transparent:true, opacity:0.85});
    var orb=new THREE.Mesh(new THREE.IcosahedronGeometry(1.35,2), orbMat); scene.add(orb);
    /* Inner core. It gives the orb depth against the dark page, but on white
       it is a grey faceted lump showing through the wireframe — every flat
       face legible. Hidden in light and kept in sync with the theme toggle,
       which a canvas cannot pick up from CSS. Same treatment as the hero orb
       on the marketing site. */
    var core=new THREE.Mesh(new THREE.IcosahedronGeometry(1.0,1), new THREE.MeshBasicMaterial({color:0x1e3a8a, transparent:true, opacity:0.25}));
    scene.add(core);
    function syncCore(){ core.visible=document.documentElement.classList.contains('dark'); }
    syncCore();
    window.addEventListener('tva:theme', syncCore);
    var ringMat=new THREE.MeshBasicMaterial({color:0x3b82f6, side:THREE.DoubleSide, transparent:true, opacity:0.35});
    var ring=new THREE.Mesh(new THREE.RingGeometry(1.75,1.78,64), ringMat); ring.rotation.x=Math.PI/2.4; scene.add(ring);

    var pGeom=new THREE.BufferGeometry(), pCount=200, pos=new Float32Array(pCount*3);
    for(var i=0;i<pCount;i++){ var r=2.2+Math.random()*1.4, th=Math.random()*Math.PI*2, ph=Math.acos(2*Math.random()-1);
        pos[i*3]=r*Math.sin(ph)*Math.cos(th); pos[i*3+1]=r*Math.sin(ph)*Math.sin(th); pos[i*3+2]=r*Math.cos(ph); }
    pGeom.setAttribute('position', new THREE.BufferAttribute(pos,3));
    scene.add(new THREE.Points(pGeom, new THREE.PointsMaterial({color:0x60a5fa, size:0.04, transparent:true, opacity:0.7})));

    var lastW=w, lastH=h;
    function fit(){ var nw=mount.clientWidth, nh=mount.clientHeight; if(!nw||!nh||(nw===lastW&&nh===lastH))return;
        lastW=nw; lastH=nh; camera.aspect=nw/nh; camera.updateProjectionMatrix(); renderer.setSize(nw,nh); }
    var t=0;
    function animate(){
        requestAnimationFrame(animate);
        fit();   // the orb shrinks 300→190px during the split transition (no resize event fires) — track it per frame
        var spd = orbState==='thinking'?0.02 : orbState==='listening'?0.0085 : orbState==='speaking'?0.006 : 0.0035;
        orb.rotation.y+=spd; orb.rotation.x+=spd*0.5; ring.rotation.z+=0.0012;
        var col = orbState==='listening'?0x22c55e : (orbState==='speaking'?0x60a5fa : 0x3b82f6);
        orbMat.color.setHex(col); ringMat.color.setHex(col);
        t+=0.09; var sc = orbState==='speaking' ? (1+Math.sin(t)*0.06) : 1; orb.scale.set(sc,sc,sc);
        renderer.render(scene,camera);
    }
    animate();
    window.addEventListener('resize', fit);
})();
</script>
@endsection
