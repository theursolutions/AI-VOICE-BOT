@extends('layouts.master')

@section('content')
<style>
    /* ── Shell: fixed height, only the thread scrolls ── */
    .tva-chat { display:flex; height: calc(100vh - 160px); min-height:520px; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; background:#fff; margin-top:14px; }
    html.dark .tva-chat { background:#0f172a; border-color:#334155; }

    .tva-chat__list { width:330px; min-width:290px; border-right:1px solid #e2e8f0; display:flex; flex-direction:column; min-height:0; }
    html.dark .tva-chat__list { border-right-color:#334155; }
    .tva-chat__listhead { padding:12px 14px; border-bottom:1px solid #e2e8f0; flex:0 0 auto; }
    html.dark .tva-chat__listhead { border-bottom-color:#334155; }
    .tva-seg { display:flex; background:#f1f5f9; border-radius:10px; padding:3px; gap:2px; }
    html.dark .tva-seg { background:#0f172a; }
    .tva-seg button { flex:1; border:none; background:transparent; font-size:12px; font-weight:600; color:#64748b; padding:6px 6px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:5px; transition:.12s; }
    .tva-seg button.is-active { background:#fff; color:#4f46e5; box-shadow:0 1px 3px rgba(0,0,0,.12); }
    html.dark .tva-seg button.is-active { background:#334155; color:#fff; }
    .tva-search { position:relative; margin-top:9px; }
    .tva-search > i, .tva-search > svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:#94a3b8; pointer-events:none; z-index:1; }
    .tva-search input { padding-left:34px !important; }
    .tva-chat__convos { overflow-y:auto; flex:1 1 auto; min-height:0; }
    .tva-convo { display:flex; gap:10px; padding:11px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9; align-items:center; }
    html.dark .tva-convo { border-bottom-color:#1e293b; }
    .tva-convo:hover { background:#f8fafc; } html.dark .tva-convo:hover { background:#1e293b; }
    .tva-convo.is-active { background:#eef2ff; } html.dark .tva-convo.is-active { background:#1e293b; }
    .tva-convo__av { width:42px; height:42px; border-radius:50%; background:var(--tva-gradient); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; flex-shrink:0; overflow:hidden; }
    .tva-convo__av img { width:100%; height:100%; object-fit:cover; }
    .tva-convo__name { font-weight:600; font-size:13.5px; color:#0f172a; } html.dark .tva-convo__name { color:#f1f5f9; }
    .tva-convo__last { font-size:12px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:185px; }
    .tva-badge { font-size:9px; font-weight:700; padding:1px 6px; border-radius:999px; text-transform:uppercase; }
    .tva-badge--whatsapp { background:#dcfce7; color:#15803d; } .tva-badge--instagram { background:#fce7f3; color:#be185d; }
    .tva-badge--facebook,.tva-badge--messenger { background:#dbeafe; color:#1d4ed8; }
    .tva-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .tva-dot--unread { background:#6366f1; } .tva-dot--open { background:#22c55e; } .tva-dot--closed { background:#ef4444; }

    /* ── Main column ── */
    .tva-chat__main { flex:1 1 auto; display:flex; flex-direction:column; min-width:0; min-height:0; }
    .tva-chat__head { padding:11px 16px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:12px; flex:0 0 auto; }
    html.dark .tva-chat__head { border-bottom-color:#334155; }
    .tva-chat__thread { flex:1 1 auto; min-height:0; overflow-y:auto; padding:18px; background:#f6f7fb; display:flex; flex-direction:column; }
    html.dark .tva-chat__thread { background:#0b1220; }

    /* ── Bubbles: size to content, capped width ── */
    .tva-msg { width:fit-content; max-width:72%; min-width:54px; margin-bottom:9px; padding:7px 11px 5px; border-radius:13px; font-size:13.5px; line-height:1.42; word-wrap:break-word; overflow-wrap:anywhere; box-shadow:0 1px 1px rgba(0,0,0,.04); }
    .tva-msg--in { background:#fff; border:1px solid #e9edf3; margin-right:auto; border-bottom-left-radius:4px; color:#0f172a; }
    html.dark .tva-msg--in { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    .tva-msg--out { background:#dcfce7; margin-left:auto; border-bottom-right-radius:4px; color:#0f3d22; } html.dark .tva-msg--out { background:#14532d; color:#dcfce7; }
    .tva-msg--bot { background:#e0f2fe; margin-left:auto; border-bottom-right-radius:4px; color:#0c4a6e; } html.dark .tva-msg--bot { background:#0c4a6e; color:#e0f2fe; }
    .tva-msg__author { font-size:10px; font-weight:700; opacity:.75; margin-bottom:2px; }
    .tva-msg__time { font-size:10px; opacity:.55; margin-top:3px; text-align:right; white-space:nowrap; }
    .tva-msg__txt { white-space:pre-wrap; }

    /* ── Custom media ── */
    .tva-att-img { max-width:230px; border-radius:9px; display:block; margin-top:5px; cursor:pointer; }
    .tva-att-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px; margin-top:5px; max-width:236px; }
    .tva-att-grid .tva-att-img { max-width:100%; height:108px; object-fit:cover; margin-top:0; }
    .tva-att-doc { display:inline-flex; gap:8px; align-items:center; margin-top:5px; padding:8px 10px; background:rgba(0,0,0,.05); border-radius:8px; text-decoration:none; color:inherit; font-size:12.5px; }
    html.dark .tva-att-doc { background:rgba(255,255,255,.08); }

    .tva-audio { display:flex; align-items:center; gap:9px; margin-top:5px; min-width:200px; max-width:250px; }
    .tva-audio__play { width:34px; height:34px; border-radius:50%; border:none; background:rgba(0,0,0,.12); cursor:pointer; flex-shrink:0; font-size:13px; color:inherit; display:flex; align-items:center; justify-content:center; }
    html.dark .tva-audio__play { background:rgba(255,255,255,.15); }
    .tva-audio__bar { flex:1; height:4px; border-radius:2px; background:rgba(0,0,0,.18); cursor:pointer; position:relative; }
    html.dark .tva-audio__bar { background:rgba(255,255,255,.2); }
    .tva-audio__fill { height:100%; width:0; border-radius:2px; background:currentColor; opacity:.7; }
    .tva-audio__time { font-size:11px; opacity:.7; min-width:34px; text-align:right; }

    .tva-video { position:relative; max-width:240px; margin-top:5px; border-radius:9px; overflow:hidden; background:#000; }
    .tva-video video { display:block; width:100%; border-radius:9px; }
    .tva-video__ov { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; cursor:pointer; }
    .tva-video__ov span { width:48px; height:48px; border-radius:50%; background:rgba(0,0,0,.55); color:#fff; display:flex; align-items:center; justify-content:center; font-size:18px; }

    /* ── Composer (fixed at bottom) ── */
    .tva-chat__composer { border-top:1px solid #e2e8f0; padding:10px 12px; position:relative; flex:0 0 auto; }
    html.dark .tva-chat__composer { border-top-color:#334155; }
    .tva-composer-row { display:flex; align-items:flex-end; gap:7px; }
    .tva-composer-row textarea { flex:1; resize:none; border:1px solid #e2e8f0; border-radius:10px; padding:9px 12px; font-size:13.5px; max-height:120px; min-height:38px; }
    html.dark .tva-composer-row textarea { background:#1e293b; border-color:#334155; color:#f1f5f9; }
    .tva-iconbtn { width:38px; height:38px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; color:#475569; }
    html.dark .tva-iconbtn { background:#1e293b; border-color:#334155; color:#cbd5e1; }
    .tva-iconbtn:hover { background:#f1f5f9; } html.dark .tva-iconbtn:hover { background:#334155; }
    .tva-iconbtn.is-on { background:#fee2e2; color:#b91c1c; border-color:#fecaca; }
    .tva-send { background:var(--tva-gradient); color:#fff; border:none; }

    /* ── Live recording bar ── */
    .tva-recbar { display:flex; align-items:center; gap:10px; padding:4px 2px; }
    .tva-rec-dot { width:11px; height:11px; border-radius:50%; background:#ef4444; animation:tvaPulse 1s infinite; }
    @keyframes tvaPulse { 0%,100%{opacity:1;} 50%{opacity:.25;} }
    .tva-rec-wave { flex:1; height:24px; display:flex; align-items:center; gap:2px; overflow:hidden; }
    .tva-rec-wave i { width:3px; background:#ef4444; border-radius:2px; opacity:.55; animation:tvaWave 1s infinite ease-in-out; }
    @keyframes tvaWave { 0%,100%{height:5px;} 50%{height:20px;} }

    /* ── Popovers (emoji + template) — inside the chat container ── */
    .tva-pop { position:absolute; bottom:62px; left:12px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,.16); z-index:30; display:none; }
    html.dark .tva-pop { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    #emojiPicker { padding:8px; grid-template-columns:repeat(8,1fr); gap:2px; max-width:300px; }
    #emojiPicker span { cursor:pointer; font-size:20px; text-align:center; padding:2px; border-radius:6px; } #emojiPicker span:hover { background:#f1f5f9; }
    #tplPanel { width:340px; max-width:calc(100% - 24px); padding:12px; max-height:60vh; overflow:auto; }
    #tplPanel .tpl-item { display:block; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:6px; cursor:pointer; font-size:13px; }
    html.dark #tplPanel .tpl-item { border-color:#334155; }

    .tva-window-banner { padding:8px 12px; font-size:12px; border-radius:8px; margin-bottom:8px; display:flex; align-items:center; gap:8px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .tva-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; gap:8px; }
    .tva-chip { font-size:11px; padding:2px 9px; border-radius:999px; font-weight:600; }
    .tva-chip--open { background:#dcfce7; color:#15803d; } .tva-chip--closed { background:#fee2e2; color:#b91c1c; }
    .tva-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.85); display:none; align-items:center; justify-content:center; z-index:80; }
    .tva-lightbox img { max-width:90vw; max-height:90vh; border-radius:8px; }

    /* ── Per-message actions, quote, edit, gallery ── */
    .tva-msg { position:relative; }
    .tva-msg__more { position:absolute; top:1px; right:3px; width:20px; height:20px; border:none; background:transparent; cursor:pointer; opacity:0; font-size:15px; line-height:1; color:inherit; border-radius:4px; }
    .tva-msg:hover .tva-msg__more { opacity:.5; }
    .tva-msg__more:hover { opacity:1; background:rgba(0,0,0,.08); }
    .tva-quote { border-left:3px solid currentColor; padding:3px 8px; margin-bottom:4px; font-size:12px; opacity:.85; background:rgba(0,0,0,.06); border-radius:4px; }
    html.dark .tva-quote { background:rgba(255,255,255,.08); }
    .tva-quote b { display:block; font-size:10.5px; opacity:.9; }
    #msgMenu { position:fixed; z-index:90; background:#fff; border:1px solid #e2e8f0; border-radius:9px; box-shadow:0 10px 28px rgba(0,0,0,.18); display:none; min-width:140px; padding:4px; }
    html.dark #msgMenu { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    #msgMenu button { display:flex; gap:8px; align-items:center; width:100%; text-align:left; padding:7px 10px; border:none; background:transparent; cursor:pointer; border-radius:6px; font-size:13px; color:inherit; }
    #msgMenu button:hover { background:#f1f5f9; } html.dark #msgMenu button:hover { background:#334155; }
    .tva-reply-bar { display:flex; align-items:center; gap:8px; padding:6px 10px; margin-bottom:8px; background:#eef2ff; border-radius:8px; font-size:12.5px; border-left:3px solid #6366f1; }
    html.dark .tva-reply-bar { background:#1e293b; }
    .tva-gallery { position:fixed; inset:0; background:rgba(15,23,42,.96); z-index:85; display:none; flex-direction:column; padding:20px; }
    .tva-gallery__grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; overflow:auto; }
    .tva-gallery__grid img, .tva-gallery__grid video { width:100%; height:150px; object-fit:cover; border-radius:8px; cursor:pointer; background:#000; }

    /* ── Day separators + typing indicator ── */
    .tva-sep { align-self:center; margin:10px 0 8px; font-size:11px; font-weight:600; color:#64748b; background:rgba(0,0,0,.06); padding:3px 12px; border-radius:999px; }
    html.dark .tva-sep { background:rgba(255,255,255,.1); color:#cbd5e1; }
    .tva-typing { display:inline-flex; gap:4px; align-items:center; }
    .tva-typing i { width:7px; height:7px; border-radius:50%; background:currentColor; opacity:.4; animation:tvaBlink 1.2s infinite; }
    .tva-typing i:nth-child(2) { animation-delay:.2s; } .tva-typing i:nth-child(3) { animation-delay:.4s; }
    @keyframes tvaBlink { 0%,60%,100%{opacity:.25; transform:translateY(0);} 30%{opacity:1; transform:translateY(-3px);} }

    /* ── Message row (action button beside bubble, never overlapping) ── */
    .tva-row { display:flex; align-items:center; gap:6px; }
    .tva-row--in { justify-content:flex-start; }
    .tva-row--out { justify-content:flex-end; }
    .tva-row .tva-msg { margin:0 0 9px; }
    .tva-row__more { width:26px; height:26px; border:none; background:transparent; cursor:pointer; color:#94a3b8; border-radius:6px; opacity:0; flex-shrink:0; font-size:16px; line-height:1; }
    .tva-row:hover .tva-row__more { opacity:.8; } .tva-row__more:hover { background:rgba(0,0,0,.1); opacity:1; }
    .tva-row--in .tva-row__more { order:2; } .tva-row--out .tva-row__more { order:0; }

    /* ── Options menu (per-message + composer) ── */
    .tva-cmenu { min-width:210px; padding:6px; }
    .tva-cmenu button { display:flex; gap:10px; align-items:center; width:100%; text-align:left; padding:9px 10px; border:none; background:transparent; cursor:pointer; border-radius:8px; font-size:13px; color:inherit; }
    .tva-cmenu button:hover { background:#f1f5f9; } html.dark .tva-cmenu button:hover { background:#334155; }

    /* ── Toasts ── */
    .tva-toasts { position:fixed; top:18px; right:18px; z-index:120; display:flex; flex-direction:column; gap:8px; }
    .tva-toast { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #6366f1; border-radius:10px; padding:11px 14px; font-size:13px; box-shadow:0 10px 30px rgba(0,0,0,.14); min-width:220px; max-width:340px; transition:opacity .25s; }
    .tva-toast--success { border-left-color:#22c55e; } .tva-toast--error { border-left-color:#ef4444; }
    html.dark .tva-toast { background:#1e293b; border-color:#334155; color:#e2e8f0; }

    /* ── Dialog (confirm / prompt) ── */
    .tva-ov { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:110; display:none; align-items:center; justify-content:center; }
    .tva-ov.open { display:flex; }
    .tva-dlg { background:#fff; border-radius:16px; width:430px; max-width:calc(100vw - 32px); padding:22px; box-shadow:0 24px 60px rgba(0,0,0,.3); animation:tvaPop .16s; }
    html.dark .tva-dlg { background:#1e293b; color:#e2e8f0; }
    @keyframes tvaPop { from{opacity:0; transform:scale(.96);} to{opacity:1; transform:scale(1);} }
    .tva-dlg__title { font-size:16px; font-weight:700; margin-bottom:4px; }
    .tva-dlg__text { font-size:12.5px; color:#64748b; margin-bottom:12px; }
    .tva-dlg label { font-size:12px; font-weight:600; display:block; margin:9px 0 3px; }
    .tva-dlg input, .tva-dlg textarea { width:100%; border:1px solid #e2e8f0; border-radius:9px; padding:9px 11px; font-size:13.5px; }
    html.dark .tva-dlg input, html.dark .tva-dlg textarea { background:#0f172a; border-color:#334155; color:#f1f5f9; }
    .tva-dlg__foot { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }

    /* ── Lightbox navigation ── */
    .tva-lb-btn { position:absolute; top:50%; transform:translateY(-50%); width:46px; height:46px; border-radius:50%; background:rgba(255,255,255,.16); color:#fff; border:none; font-size:22px; cursor:pointer; z-index:81; }
    .tva-lb-btn:hover { background:rgba(255,255,255,.3); }
    .tva-lb-prev { left:22px; } .tva-lb-next { right:22px; } .tva-lb-close { top:22px; right:22px; transform:none; width:40px; height:40px; }
    /* Unread as a number, not a dot: "3 waiting" is actionable, "something
       new" is not. Capped at 99+ so the pill never resizes the row. */
    .tva-unread { display:inline-flex; align-items:center; justify-content:center;
                  min-width:17px; height:17px; padding:0 5px; border-radius:999px;
                  background:#dc2626; color:#fff; font-size:9.5px; font-weight:800;
                  line-height:1; font-variant-numeric:tabular-nums; }
    #hdrChannel a { color:inherit; text-decoration:none; display:inline-flex; align-items:center; }
    #hdrChannel a:hover { text-decoration:underline; }

    /* Timestamp is secondary information — it should be readable when looked
       for and invisible when scanning names and messages. */
    .tva-convo__time { font-size:9px; line-height:1; color:#94a3b8; white-space:nowrap;
                       font-variant-numeric:tabular-nums; letter-spacing:.01em; }
    html.dark .tva-convo__time { color:#64748b; }

    /* Channel shown as a mark, not a word — see channelIcon(). Square-ish so
       a row of them lines up regardless of provider name length. */
    .tva-badge--icon { display:inline-flex; align-items:center; justify-content:center;
                       padding:3px 6px; line-height:0; }
    .tva-badge__txt { font-size:9.5px; letter-spacing:.03em; text-transform:uppercase; }
    #hdrChannel { display:inline-flex; align-items:center; }
    #hdrName a { color:inherit; text-decoration:none; border-bottom:1px dashed currentColor; }
    #hdrName a:hover { opacity:.8; }
</style>

<div class="content">
    <div class="flex items-center gap-3 mt-4">
        <h2 class="text-lg font-semibold">Messages</h2>
        <select id="presenceSel" class="form-select form-select-sm ml-auto" title="Your availability" style="width:auto; display:none;">
            <option value="offline">⚫ Offline</option>
            <option value="away">🟡 Away</option>
            <option value="online">🟢 Online</option>
        </select>
        <form method="GET">
            <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="tva-chat">
        <div class="tva-chat__list">
            <div class="tva-chat__listhead">
                <div class="tva-seg" id="filterTabs">
                    <button data-f="all" class="is-active"><i data-lucide="inbox" class="w-3.5 h-3.5"></i> All</button>
                    <button data-f="mine"><i data-lucide="user" class="w-3.5 h-3.5"></i> Mine</button>
                    <button data-f="queue"><i data-lucide="clock" class="w-3.5 h-3.5"></i> Queue</button>
                </div>
                <div class="tva-search">
                    <i data-lucide="search"></i>
                    <input id="chatSearch" type="text" class="form-control form-control-sm" placeholder="Search conversations…">
                </div>
            </div>
            <div id="chatConvos" class="tva-chat__convos"></div>
        </div>

        <div class="tva-chat__main">
            <div id="chatEmpty" class="tva-empty">
                <i data-lucide="messages-square" class="w-10 h-10"></i>
                <div>Select a conversation</div>
            </div>

            <div id="chatPane" style="display:none; flex:1 1 auto; min-height:0; flex-direction:column;">
                <div class="tva-chat__head">
                    <div class="tva-convo__av" id="hdrAvatar"></div>
                    <div class="min-w-0">
                        <div class="tva-convo__name" id="hdrName"></div>
                        <div class="text-xs text-slate-500"><span id="hdrChannel"></span> · <span id="hdrAccount"></span></div>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <span id="hdrHandoff" class="tva-chip" style="display:none;"></span>
                        <button id="handoffBtn" class="btn btn-sm" style="display:none;"></button>
                        <span id="hdrWindow" class="tva-chip"></span>
                        <button id="galleryBtn" class="btn btn-sm btn-secondary" title="Shared media"><i data-lucide="image" class="w-3 h-3"></i></button>
                        <button id="botToggle" class="btn btn-sm btn-secondary" title="Pause/resume the AI for this chat">
                            <i data-lucide="bot" class="w-3 h-3 mr-1 inline"></i><span id="botToggleLabel">Bot on</span>
                        </button>
                    </div>
                </div>

                <div id="chatThread" class="tva-chat__thread"></div>

                <div class="tva-chat__composer">
                    <div id="replyBar" style="display:none;"></div>
                    <div id="windowBanner"></div>
                    <div id="emojiPicker" class="tva-pop"></div>
                    <div id="tplPanel" class="tva-pop"></div>
                    <div id="composerMenu" class="tva-pop tva-cmenu">
                        <button onclick="composerAction('attach')"><i data-lucide="paperclip" class="w-4 h-4"></i> Attach file</button>
                        <button onclick="composerAction('interactive')"><i data-lucide="list" class="w-4 h-4"></i> Quick-reply buttons</button>
                        <button class="wa-only" onclick="composerAction('flow')"><i data-lucide="clipboard-list" class="w-4 h-4"></i> Send a Flow form</button>
                        <button class="wa-only" onclick="composerAction('catalog')"><i data-lucide="shopping-bag" class="w-4 h-4"></i> Send catalog products</button>
                        <button class="wa-only" onclick="composerAction('template')"><i data-lucide="file-text" class="w-4 h-4"></i> Send template</button>
                    </div>

                    <div class="tva-composer-row" id="composerRow">
                        <button class="tva-iconbtn" id="btnMore" title="More options"><i data-lucide="plus" class="w-4 h-4"></i></button>
                        <button class="tva-iconbtn" id="btnEmoji" title="Emoji">😊</button>
                        <button class="tva-iconbtn" id="btnVoice" title="Record voice note"><i data-lucide="mic" class="w-4 h-4"></i></button>
                        <textarea id="chatInput" rows="1" placeholder="Type a message…"></textarea>
                        <button class="tva-iconbtn tva-send" id="btnSend" title="Send"><i data-lucide="send" class="w-4 h-4"></i></button>
                    </div>

                    <div class="tva-recbar" id="recBar" style="display:none;">
                        <button class="tva-iconbtn" id="recCancel" title="Cancel"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        <span class="tva-rec-dot"></span>
                        <span id="recTime" style="font-variant-numeric:tabular-nums;">0:00</span>
                        <div class="tva-rec-wave" id="recWave"></div>
                        <button class="tva-iconbtn tva-send" id="recSend" title="Send voice note"><i data-lucide="send" class="w-4 h-4"></i></button>
                    </div>

                    <input type="file" id="fileInput" style="display:none;" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx">
                </div>
            </div>
        </div>
    </div>

    <div class="tva-lightbox" id="lightbox">
        <button class="tva-lb-btn tva-lb-close" onclick="closeLightbox(event)">✕</button>
        <button class="tva-lb-btn tva-lb-prev" onclick="lbNav(event,-1)">‹</button>
        <img id="lightboxImg" src="">
        <button class="tva-lb-btn tva-lb-next" onclick="lbNav(event,1)">›</button>
    </div>
    <div id="msgMenu"></div>
    <div class="tva-toasts" id="tvaToasts"></div>
    <div class="tva-ov" id="tvaDlg"><div class="tva-dlg"></div></div>
    <div class="tva-gallery" id="galleryOverlay">
        <div class="flex items-center mb-3" style="color:#fff;">
            <b class="flex-1">Shared media</b>
            <button class="btn btn-sm btn-secondary" onclick="document.getElementById('galleryOverlay').style.display='none'">Close</button>
        </div>
        <div class="tva-gallery__grid" id="galleryGrid"></div>
    </div>
</div>

<script>
const CHAT = {
    projectId: '{{ hashid($projectId) }}',
    base: '{{ url('c/'.$client->slug.'/chat') }}',
    convosUrl: '{{ route('chat.conversations', ['client' => $client->slug]) }}',
    csrf: '{{ csrf_token() }}',
};
const EMOJIS = '😀 😁 😂 🤣 😊 😍 😘 👍 🙏 🙌 👏 🔥 ✅ ❌ ❤️ 🎉 😎 🤔 😅 😢 😡 🙂 👌 💯 📞 📦 🛒 💳 ⏰ 📅 ✨ 🚀'.split(' ');
let activeSid=null, lastMsgId=0, threadTimer=null;

function h(s){ return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function timeAgo(ts){ if(!ts) return ''; const d=Math.floor(Date.now()/1000)-ts; if(d<60)return 'now'; if(d<3600)return Math.floor(d/60)+'m'; if(d<86400)return Math.floor(d/3600)+'h'; return Math.floor(d/86400)+'d'; }
function fmtTime(ts){ if(!ts) return ''; const d=new Date(ts*1000); let hh=d.getHours(); const mm=d.getMinutes(); const ap=hh>=12?'PM':'AM'; hh=hh%12||12; return hh+':'+(mm<10?'0'+mm:mm)+' '+ap; }
function mmss(s){ s=Math.max(0,Math.floor(s)); return Math.floor(s/60)+':'+String(s%60).padStart(2,'0'); }
function dayKey(ts){ if(!ts) return ''; const d=new Date(ts*1000); return d.getFullYear()+'-'+d.getMonth()+'-'+d.getDate(); }
function fmtDay(ts){ const d=new Date(ts*1000); const now=Date.now()/1000; if(dayKey(ts)===dayKey(now)) return 'Today'; if(dayKey(ts)===dayKey(now-86400)) return 'Yesterday'; return d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'}); }
let currentBotPaused=false;
function refreshTyping(){
    const box=document.getElementById('chatThread'); if(!box) return;
    const ex=document.getElementById('typingRow'); if(ex) ex.remove();
    const last=window.MSGS && window.MSGS[lastMsgId];
    if(last && last.direction==='in' && !currentBotPaused){
        const near=box.scrollHeight-box.scrollTop-box.clientHeight < 150;
        box.insertAdjacentHTML('beforeend','<div class="tva-msg tva-msg--bot" id="typingRow" style="padding:10px 14px;"><div class="tva-msg__author">🤖 AI</div><span class="tva-typing"><i></i><i></i><i></i></span></div>');
        if(near) box.scrollTop=box.scrollHeight;
    }
}
function initials(n){ return (n||'?').trim().slice(0,2).toUpperCase(); }

/* Channel identity as a mark rather than a word.
   The full provider name ("facebook_page") ate a third of the row and told an
   operator nothing they couldn't see at a glance from the logo. Paths are the
   same brand marks App\Support\BrandIcons renders server-side, inlined here
   because this list is built in JavaScript. */
const CHANNEL_MARKS = {
    whatsapp:      'M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 2.1.55 4.15 1.6 5.96L2 22l4.26-1.68a9.9 9.9 0 0 0 5.78 1.85h.01c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm5.8 14.06c-.24.68-1.42 1.3-1.96 1.35-.5.05-.99.23-3.35-.7-2.82-1.11-4.6-3.97-4.74-4.16-.14-.19-1.13-1.5-1.13-2.86 0-1.36.71-2.03.96-2.31.25-.28.55-.35.73-.35h.52c.17 0 .4-.06.62.48.24.57.8 1.98.87 2.12.07.14.12.31.02.5-.09.19-.14.31-.28.47l-.42.49c-.14.14-.28.29-.12.57.16.28.72 1.18 1.54 1.91 1.06.94 1.95 1.23 2.23 1.37.28.14.44.12.6-.07.17-.19.7-.81.88-1.09.19-.28.37-.23.63-.14.26.09 1.66.78 1.94.93.28.14.47.21.54.33.07.12.07.68-.17 1.36z',
    instagram:     'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 5.68A4.16 4.16 0 1 0 16.16 12 4.16 4.16 0 0 0 12 7.84zm0 6.86A2.7 2.7 0 1 1 14.7 12 2.7 2.7 0 0 1 12 14.7zm5.3-7.1a.97.97 0 1 1-.97-.97.97.97 0 0 1 .97.97z',
    facebook_page: 'M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z',
};
CHANNEL_MARKS.messenger = CHANNEL_MARKS.facebook_page;

const CHANNEL_LABELS = {
    whatsapp: 'WhatsApp', instagram: 'Instagram',
    facebook_page: 'Facebook', messenger: 'Messenger',
    web: 'Web chat', twilio: 'Phone', plivo: 'Phone', api: 'API', internal: 'Internal',
};

function channelLabel(ch){ return CHANNEL_LABELS[ch] || (ch||'').replace(/_/g,' '); }

function channelIcon(ch){
    const d = CHANNEL_MARKS[ch];
    if(!d) return '<span class="tva-badge__txt">' + h(channelLabel(ch)) + '</span>';
    return '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="' + d + '"/></svg>';
}
async function api(url,opts={}){ opts.headers=Object.assign({'X-CSRF-TOKEN':CHAT.csrf,'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},opts.headers||{}); return fetch(url,opts); }
function msgUrl(p){ return `${CHAT.base}/${activeSid}/${p}`; }

// ── Toast + dialog (replaces native alert/prompt/confirm) ──
function tvaToast(msg, type='info'){
    const c=document.getElementById('tvaToasts'); const t=document.createElement('div');
    t.className='tva-toast tva-toast--'+type; t.textContent=msg; c.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),260); }, 3200);
}
function tvaPrompt({title, text, fields, confirmText='Send'}){
    return new Promise(res=>{
        const ov=document.getElementById('tvaDlg'); const dlg=ov.querySelector('.tva-dlg');
        dlg.innerHTML = `<div class="tva-dlg__title">${h(title||'')}</div>`+(text?`<div class="tva-dlg__text">${h(text)}</div>`:'')+
            (fields||[]).map(f=>`<label>${h(f.label)}</label>`+(f.type==='textarea'
                ? `<textarea data-f="${f.name}" rows="3" placeholder="${h(f.placeholder||'')}">${h(f.value||'')}</textarea>`
                : `<input data-f="${f.name}" placeholder="${h(f.placeholder||'')}" value="${h(f.value||'')}">`)).join('')+
            `<div class="tva-dlg__foot"><button class="btn btn-secondary btn-sm" data-act="cancel">Cancel</button><button class="btn btn-primary btn-sm" data-act="ok">${h(confirmText)}</button></div>`;
        ov.classList.add('open');
        const first=dlg.querySelector('[data-f]'); if(first) first.focus();
        const done=v=>{ ov.classList.remove('open'); res(v); };
        dlg.querySelector('[data-act=cancel]').onclick=()=>done(null);
        dlg.querySelector('[data-act=ok]').onclick=()=>{ const o={}; dlg.querySelectorAll('[data-f]').forEach(el=>o[el.dataset.f]=el.value); done(o); };
    });
}
async function tvaConfirm(opts){ const v=await tvaPrompt({...opts, fields:[]}); return v!==null; }

// ── Lightbox (with prev/next) ──
let lbIdx=0;
function lbImages(){ return (window.MEDIA||[]).filter(a=>a.type==='image'||a.type==='sticker').map(a=>a.proxy); }
function lightbox(src){ const imgs=lbImages(); lbIdx=Math.max(0,imgs.indexOf(src)); document.getElementById('lightboxImg').src=src; document.getElementById('lightbox').style.display='flex'; }
function lbNav(e,d){ if(e) e.stopPropagation(); const imgs=lbImages(); if(!imgs.length) return; lbIdx=(lbIdx+d+imgs.length)%imgs.length; document.getElementById('lightboxImg').src=imgs[lbIdx]; }
function closeLightbox(e){ if(e) e.stopPropagation(); document.getElementById('lightbox').style.display='none'; }

// ── Composer "+" menu ──
function composerAction(type){
    closePops();
    if(type==='attach') return document.getElementById('fileInput').click();
    if(type==='interactive') return sendInteractive();
    if(type==='flow') return sendFlowMsg();
    if(type==='catalog') return sendProductMsg();
    if(type==='template') return openTemplates();
}

// ── Conversation list ──
let currentFilter='all';
async function loadConvos(){
    try{
        const r=await api(CHAT.convosUrl+'?project_id='+CHAT.projectId+'&filter='+currentFilter);
        const d=await r.json(); renderConvos(d.conversations||[]); applyPresence(d.me);
    }catch(e){}
}
function applyPresence(me){
    const sel=document.getElementById('presenceSel');
    if(me){ sel.style.display=''; if(!sel.dataset.touched) sel.value=me.presence; }
    else { sel.style.display='none'; }
}
function handoffBadge(c){
    if(c.handoff==='queued') return '<span class="tva-badge" style="background:#fef3c7;color:#92400e;">⏳ QUEUE</span>';
    if(c.handoff==='assigned') return `<span class="tva-badge" style="background:#e0e7ff;color:#3730a3;">🙋 ${h(c.assigned_to||'Agent')}</span>`;
    return '';
}
function renderConvos(list){
    const q=(document.getElementById('chatSearch').value||'').toLowerCase();
    document.getElementById('chatConvos').innerHTML = list.filter(c=>!q||(c.name||'').toLowerCase().includes(q)).map(c=>`
        <div class="tva-convo ${c.id===activeSid?'is-active':''}" onclick="openThread(${c.id})">
            <div class="tva-convo__av">${c.avatar?`<img src="${h(c.avatar)}">`:h(initials(c.name))}</div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2"><span class="tva-convo__name flex-1 truncate">${h(c.name)}</span><span class="tva-badge tva-badge--${c.channel} tva-badge--icon" title="${h(channelLabel(c.channel))}">${channelIcon(c.channel)}</span></div>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="tva-convo__last flex-1">${h(c.last_message)}</span>
                    <span class="tva-convo__time">${timeAgo(c.last_at)}</span>
                    ${c.unread_count>0?`<span class="tva-unread">${c.unread_count>99?'99+':c.unread_count}</span>`:(c.unread?'<span class="tva-dot tva-dot--unread"></span>':'')}
                    <span class="tva-dot ${c.window_open?'tva-dot--open':'tva-dot--closed'}" title="${c.window_open?'24h window open':'window closed'}"></span>
                </div>
                ${handoffBadge(c)?`<div class="mt-1">${handoffBadge(c)}</div>`:''}
            </div>
        </div>`).join('') || '<div class="text-center text-xs text-slate-400 py-8">No conversations.</div>';
}
function applyHandoff(d){
    const badge=document.getElementById('hdrHandoff'); const btn=document.getElementById('handoffBtn'); const ho=d.handoff||'bot';
    if(ho==='assigned'){ badge.style.display=''; badge.style.background='#e0e7ff'; badge.style.color='#3730a3'; badge.textContent='🙋 '+(d.assigned_to||'Agent'); }
    else if(ho==='queued'){ badge.style.display=''; badge.style.background='#fef3c7'; badge.style.color='#92400e'; badge.textContent='⏳ Queued'; }
    else { badge.style.display='none'; }
    if(d.is_human_agent && ho==='assigned'){ btn.style.display=''; btn.className='btn btn-sm btn-secondary'; btn.textContent='Resolve'; btn.onclick=resolveChat; }
    else if(d.is_human_agent && (ho==='queued'||ho==='bot')){ btn.style.display=''; btn.className='btn btn-sm btn-primary'; btn.textContent='Take chat'; btn.onclick=claimChat; }
    else { btn.style.display='none'; }
}
async function claimChat(){ const r=await api(msgUrl('claim'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId})}); if(!r.ok){ tvaToast('Could not take chat.','error'); return; } tvaToast('Chat assigned to you','success'); loadThread(true); loadConvos(); }
async function resolveChat(){ const r=await api(msgUrl('resolve'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId})}); if(!r.ok){ tvaToast('Could not resolve.','error'); return; } tvaToast('Resolved — handed back to AI','success'); loadThread(true); loadConvos(); }

// ── Thread ──
async function openThread(sid){
    activeSid=sid; lastMsgId=0;
    window.MSGS={}; window.MEDIA=[]; window.lastDay=null; clearReply();
    document.getElementById('chatEmpty').style.display='none';
    document.getElementById('chatPane').style.display='flex';
    document.getElementById('chatThread').innerHTML='';
    closePops();
    await loadThread(true);
    if(threadTimer) clearInterval(threadTimer);
    threadTimer=setInterval(()=>loadThread(false),4000);
    loadConvos();
}
async function loadThread(full){
    try{
        const r=await api(msgUrl('messages')+'?project_id='+CHAT.projectId+'&after='+(full?0:lastMsgId));
        const d=await r.json();
        if(full){ applyHeader(d); applyHandoff(d); }
        appendMessages(d.messages||[]);
        applyWindow(d);
        currentBotPaused = !!d.bot_paused;
        refreshTyping();
    }catch(e){}
}
function applyHeader(d){
    const c=d.contact||{};
    var hdrName=document.getElementById('hdrName');
    // Only WhatsApp yields a real profile URL — Messenger/Instagram ids are
    // page-scoped and deliberately not resolvable. Render plain text there
    // rather than a link that would 404.
    hdrName.innerHTML = c.profile_url
        ? '<a href="' + h(c.profile_url) + '" target="_blank" rel="noopener" title="Open profile">' + h(c.name||'') + '</a>'
        : h(c.name||'');
    // Channel mark + the Page/number this conversation is on, linked where
    // Meta exposes a public URL (Page ids are public; customer PSIDs are not).
    var hdrCh=document.getElementById('hdrChannel');
    var chLabel = h(c.channel_name || channelLabel(c.channel));
    var chInner = channelIcon(c.channel) + '<span class="ml-1">' + chLabel + '</span>';
    hdrCh.innerHTML = c.channel
        ? (c.channel_url
            ? '<a href="' + h(c.channel_url) + '" target="_blank" rel="noopener" title="Open ' + chLabel + '">' + chInner + '</a>'
            : chInner)
        : '';
    document.getElementById('hdrAccount').textContent=c.account||'';
    document.getElementById('hdrAvatar').innerHTML=c.avatar?`<img src="${h(c.avatar)}">`:h(initials(c.name));
    setBot(d.bot_paused);
    document.querySelectorAll('.wa-only').forEach(b=> b.style.display=(c.channel==='whatsapp')?'flex':'none');
}
function applyWindow(d){
    const chip=document.getElementById('hdrWindow'), banner=document.getElementById('windowBanner'), ta=document.getElementById('chatInput');
    if(d.window_open){
        const left=d.window_expires?Math.max(0,Math.floor((d.window_expires-Date.now()/1000)/3600)):null;
        chip.className='tva-chip tva-chip--open'; chip.textContent=left!==null?`${left}h left`:'open'; banner.innerHTML=''; ta.disabled=false;
    }else{
        chip.className='tva-chip tva-chip--closed'; chip.textContent='Expired';
        banner.innerHTML='<div class="tva-window-banner"><i data-lucide="clock" class="w-4 h-4"></i><span>24-hour window closed. Reply via an approved <b>template</b> to start a new conversation.</span></div>';
        if(window.lucide) try{lucide.createIcons();}catch(_){}
    }
}
function appendMessages(msgs){
    const box=document.getElementById('chatThread'); const nearBottom = box.scrollHeight-box.scrollTop-box.clientHeight < 120;
    const tr=document.getElementById('typingRow'); if(tr) tr.remove();   // keep new content below the typing row
    msgs.forEach(m=>{
        if(m.id<=lastMsgId) return; lastMsgId=Math.max(lastMsgId,m.id);
        window.MSGS=window.MSGS||{}; window.MSGS[m.id]=m;
        const dk=dayKey(m.created_at);
        if(dk && dk!==window.lastDay){ box.insertAdjacentHTML('beforeend',`<div class="tva-sep">${h(fmtDay(m.created_at))}</div>`); window.lastDay=dk; }
        window.MEDIA=window.MEDIA||[];
        (m.attachments||[]).forEach(a=>{ if(['image','sticker','video','document','audio'].includes(a.type)) window.MEDIA.push(a); });
        const cls=m.direction==='in'?'tva-msg--in':(m.author==='bot'?'tva-msg--bot':'tva-msg--out');
        const author=m.author==='customer'?'':(m.author==='bot'?'<div class="tva-msg__author">🤖 AI</div>':'<div class="tva-msg__author">🙋 Agent</div>');
        const txt=m.content?`<div class="tva-msg__txt">${h(m.content)}</div>`:'';
        const atts=renderAtts(m.attachments||[]);
        const who=r=>r==='customer'?'Customer':(r==='bot'?'AI':'Agent');
        const quote=m.reply?`<div class="tva-quote"><b>${who(m.reply.author)}</b>${h(m.reply.preview||'')}</div>`:'';
        const edited=m.edited?' · edited':'';
        const canEdit=(m.author==='agent' && (Date.now()/1000 - m.created_at) < 900) ? 1 : 0;
        box.insertAdjacentHTML('beforeend',`<div class="tva-row tva-row--${m.direction==='in'?'in':'out'}">
            <button class="tva-row__more" onclick="openMsgMenu(event,${m.id},${canEdit})">⋮</button>
            <div class="tva-msg ${cls}" data-id="${m.id}">${quote}${author}${txt}${atts}<div class="tva-msg__time">${fmtTime(m.created_at)}${edited}</div></div>
        </div>`);
    });
    if(nearBottom) box.scrollTop=box.scrollHeight;
}
function renderAtts(atts){
    if(!atts.length) return '';
    const imgs=atts.filter(a=>a.type==='image'||a.type==='sticker');
    let html='';
    if(imgs.length>1){
        html+='<div class="tva-att-grid">'+imgs.map(a=>`<img class="tva-att-img" src="${h(a.proxy)}" loading="lazy" onclick="lightbox('${h(a.proxy)}')">`).join('')+'</div>';
    }
    atts.forEach(a=>{
        if((a.type==='image'||a.type==='sticker')){ if(imgs.length<=1) html+=`<img class="tva-att-img" src="${h(a.proxy)}" loading="lazy" onclick="lightbox('${h(a.proxy)}')">`; return; }
        if(a.type==='audio'){ html+=audioPlayer(a.proxy); return; }
        if(a.type==='video'){ html+=`<div class="tva-video" data-src="${h(a.proxy)}"><video src="${h(a.proxy)}" preload="metadata"></video><div class="tva-video__ov" onclick="toggleVideo(this)"><span>▶</span></div></div>`; return; }
        html+=`<a class="tva-att-doc" href="${h(a.proxy)}" target="_blank">📄 ${h(a.filename||'Document')}</a>`;
    });
    return html;
}

// ── Custom audio player ──
let audioSeq=0;
function audioPlayer(src){
    const id='aud'+(audioSeq++);
    return `<div class="tva-audio" id="${id}" data-src="${h(src)}">
        <button class="tva-audio__play" onclick="audioToggle('${id}')">▶</button>
        <div class="tva-audio__bar" onclick="audioSeek(event,'${id}')"><div class="tva-audio__fill"></div></div>
        <span class="tva-audio__time">0:00</span></div>`;
}
const _audios={};
function audioEl(id){
    if(_audios[id]) return _audios[id];
    const wrap=document.getElementById(id); const a=new Audio(wrap.dataset.src); _audios[id]=a;
    a.ontimeupdate=()=>{ const f=wrap.querySelector('.tva-audio__fill'); const t=wrap.querySelector('.tva-audio__time');
        if(a.duration){ f.style.width=(a.currentTime/a.duration*100)+'%'; } t.textContent=mmss(a.currentTime); };
    a.onended=()=>{ wrap.querySelector('.tva-audio__play').textContent='▶'; wrap.querySelector('.tva-audio__fill').style.width='0%'; };
    return a;
}
function audioToggle(id){
    const a=audioEl(id), btn=document.getElementById(id).querySelector('.tva-audio__play');
    Object.entries(_audios).forEach(([k,o])=>{ if(k!==id && !o.paused){ o.pause(); document.getElementById(k).querySelector('.tva-audio__play').textContent='▶'; }});
    if(a.paused){ a.play(); btn.textContent='⏸'; } else { a.pause(); btn.textContent='▶'; }
}
function audioSeek(e,id){ const a=audioEl(id); const r=e.currentTarget.getBoundingClientRect(); if(a.duration) a.currentTime=((e.clientX-r.left)/r.width)*a.duration; }
function toggleVideo(ov){ const v=ov.parentElement.querySelector('video'); if(v.paused){ v.play(); ov.style.display='none'; v.setAttribute('controls',''); } }
function lightbox(src){ document.getElementById('lightboxImg').src=src; document.getElementById('lightbox').style.display='flex'; }

// ── Per-message actions (reply / copy / edit) ──
let replyTarget=null;
function openMsgMenu(e,id,canEdit){
    e.stopPropagation();
    const m=window.MSGS[id]; const menu=document.getElementById('msgMenu');
    let items=`<button onclick="doReply(${id})"><i data-lucide="reply" class="w-4 h-4"></i> Reply</button>`;
    if(m.content) items+=`<button onclick="doCopy(${id})"><i data-lucide="copy" class="w-4 h-4"></i> Copy</button>`;
    if(canEdit) items+=`<button onclick="doEdit(${id})"><i data-lucide="pencil" class="w-4 h-4"></i> Edit</button>`;
    menu.innerHTML=items; menu.style.display='block';
    menu.style.left=Math.min(e.clientX, window.innerWidth-160)+'px';
    menu.style.top=Math.min(e.clientY, window.innerHeight-150)+'px';
    if(window.lucide) try{lucide.createIcons();}catch(_){}
}
document.addEventListener('click',e=>{ if(!e.target.closest('#msgMenu') && !e.target.closest('.tva-msg__more')) document.getElementById('msgMenu').style.display='none'; });
function doReply(id){ replyTarget=id; showReplyBar(window.MSGS[id]); document.getElementById('msgMenu').style.display='none'; document.getElementById('chatInput').focus(); }
function showReplyBar(m){
    const who=m.author==='customer'?'Customer':(m.author==='bot'?'AI':'Agent');
    const bar=document.getElementById('replyBar'); bar.style.display='flex'; bar.className='tva-reply-bar';
    bar.innerHTML=`<div class="flex-1 min-w-0"><b>Replying to ${who}</b><div class="truncate">${h((m.content||'📎 Attachment').slice(0,120))}</div></div><button class="tva-iconbtn" style="width:28px;height:28px;" onclick="clearReply()">✕</button>`;
}
function clearReply(){ replyTarget=null; const b=document.getElementById('replyBar'); if(b){ b.style.display='none'; b.innerHTML=''; } }
async function doCopy(id){ try{ await navigator.clipboard.writeText(window.MSGS[id].content||''); tvaToast('Copied to clipboard','success'); }catch(e){} document.getElementById('msgMenu').style.display='none'; }
async function doEdit(id){
    const m=window.MSGS[id]; document.getElementById('msgMenu').style.display='none';
    const v=await tvaPrompt({title:'Edit message', text:'Within 15 minutes. Corrects the console record — the WhatsApp API cannot change a message already on the customer’s phone.', fields:[{name:'text', label:'Message', type:'textarea', value:m.content||''}], confirmText:'Save'});
    if(!v || !v.text.trim()) return; const nv=v.text;
    const r=await api(msgUrl('edit'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,message_id:id,text:nv})});
    if(!r.ok){ const d=await r.json().catch(()=>({})); tvaToast(d.message||'Edit failed.','error'); return; }
    const d=await r.json(); window.MSGS[id]=d.message;
    const el=document.querySelector(`.tva-msg[data-id="${id}"]`);
    if(el){ const t=el.querySelector('.tva-msg__txt'); if(t) t.textContent=d.message.content; const tm=el.querySelector('.tva-msg__time'); if(tm && !tm.textContent.includes('edited')) tm.textContent+=' · edited'; }
}
function openGallery(){
    const grid=document.getElementById('galleryGrid');
    grid.innerHTML=(window.MEDIA||[]).map(a=>{
        if(a.type==='image'||a.type==='sticker') return `<img src="${h(a.proxy)}" onclick="lightbox('${h(a.proxy)}')">`;
        if(a.type==='video') return `<video src="${h(a.proxy)}" controls></video>`;
        return `<a href="${h(a.proxy)}" target="_blank" style="color:#fff;display:flex;align-items:center;justify-content:center;height:150px;border:1px solid #475569;border-radius:8px;text-align:center;padding:6px;">📄 ${h(a.filename||'Document')}</a>`;
    }).join('') || '<div style="color:#94a3b8;">No media shared yet.</div>';
    document.getElementById('galleryOverlay').style.display='flex';
}

// ── Sending ──
async function sendText(){
    const ta=document.getElementById('chatInput'); const text=ta.value.trim(); if(!text||!activeSid) return;
    ta.value=''; ta.style.height='auto';
    const r=await api(msgUrl('reply'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,text,reply_to:replyTarget})});
    if(r.status===409){ tvaToast('The 24-hour window has closed — send a template to re-open.','error'); return; }
    if(!r.ok){ tvaToast('Could not send.','error'); return; }
    appendMessages([(await r.json()).message]); clearReply();
}
async function sendFile(file){
    if(!file||!activeSid) return;
    const fd=new FormData(); fd.append('project_id',CHAT.projectId); fd.append('file',file);
    const r=await api(msgUrl('media'),{method:'POST',body:fd});
    if(r.status===409){ tvaToast('Window closed — use a template.','error'); return; }
    if(!r.ok){ tvaToast('Upload failed.','error'); return; }
    appendMessages([(await r.json()).message]);
}
async function setBotRequest(){ const r=await api(msgUrl('toggle-bot'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId})}); if(r.ok) setBot((await r.json()).bot_paused); }
function setBot(p){ currentBotPaused=!!p; document.getElementById('botToggleLabel').textContent=p?'Bot paused':'Bot on'; document.getElementById('botToggle').classList.toggle('is-on',!!p); refreshTyping(); }
async function sendInteractive(){
    const v=await tvaPrompt({title:'Quick-reply buttons', text:'Capture intent — the customer taps a button.', fields:[
        {name:'body', label:'Message', type:'textarea', placeholder:'Shall I place your order?'},
        {name:'buttons', label:'Buttons (comma-separated, max 3)', value:'Place order, Change, Cancel'},
    ]});
    if(!v || !v.body || !v.buttons) return;
    const buttons=v.buttons.split(',').map(s=>s.trim()).filter(Boolean).slice(0,3).map((t,i)=>({id:'btn_'+i,title:t.slice(0,20)}));
    const r=await api(msgUrl('interactive'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,body:v.body,buttons})});
    if(!r.ok){ tvaToast('Could not send (WhatsApp, within 24h).','error'); return; } appendMessages([(await r.json()).message]);
}
async function sendFlowMsg(){
    const v=await tvaPrompt({title:'Send a Flow form', text:'Send a published WhatsApp Flow to capture structured data.', fields:[
        {name:'flow_id', label:'Flow ID (from Meta Flow Builder)'},
        {name:'body', label:'Message shown with the form', type:'textarea', value:'Please fill in this quick form'},
        {name:'cta', label:'Button label', value:'Open form'},
        {name:'screen', label:'First screen id (optional)'},
    ]});
    if(!v || !v.flow_id || !v.body) return;
    const r=await api(msgUrl('flow'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,flow_id:v.flow_id,cta:v.cta||'Open form',body:v.body,screen:v.screen||null})});
    if(!r.ok){ tvaToast('Flow send failed (WhatsApp, within 24h).','error'); return; } appendMessages([(await r.json()).message]);
}
async function sendProductMsg(){
    const v=await tvaPrompt({title:'Send catalog products', fields:[
        {name:'catalog_id', label:'Catalog ID'},
        {name:'retailer_ids', label:'Product retailer IDs (comma-separated)'},
        {name:'body', label:'Caption (optional)'},
    ]});
    if(!v || !v.catalog_id || !v.retailer_ids) return;
    const retailer_ids=v.retailer_ids.split(',').map(s=>s.trim()).filter(Boolean);
    const r=await api(msgUrl('product'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,catalog_id:v.catalog_id,retailer_ids,body:v.body||null})});
    if(!r.ok){ tvaToast('Product send failed.','error'); return; } appendMessages([(await r.json()).message]);
}

// ── Template popover (inside chat) ──
let tplChosen=null;
function closePops(){ ['emojiPicker','tplPanel','composerMenu'].forEach(id=>{ const el=document.getElementById(id); if(el) el.style.display='none'; }); }
async function openTemplates(){
    const panel=document.getElementById('tplPanel'); const show=panel.style.display!=='block'; closePops();
    if(!show) return; panel.style.display='block'; tplChosen=null;
    panel.innerHTML='<div class="text-xs text-slate-500">Loading approved templates…</div>';
    try{
        const d=await(await api(msgUrl('templates')+'?project_id='+CHAT.projectId)).json(); const list=d.templates||[];
        if(!list.length){ panel.innerHTML='<div class="text-xs text-slate-500">'+h(d.note||'No approved templates found.')+'</div>'; return; }
        panel.innerHTML='<div class="font-semibold text-sm mb-2">Send a template</div>'+list.map((t,i)=>`<div class="tpl-item" onclick="pickTpl(${i})" id="tpl${i}"><b>${h(t.name)}</b> <span class="text-xs text-slate-400">${h(t.language)}${t.params?(' · '+t.params+' params'):''}</span></div>`).join('')+'<div id="tplParams"></div>';
        window._tpls=list;
    }catch(e){ panel.innerHTML='Could not load templates.'; }
}
function pickTpl(i){
    tplChosen=window._tpls[i]; const n=tplChosen.params||0;
    document.querySelectorAll('#tplPanel .tpl-item').forEach((el,idx)=>el.style.borderColor=idx===i?'#6366f1':'');
    const box=document.getElementById('tplParams');
    box.innerHTML=(n?Array.from({length:n}).map((_,k)=>`<input class="form-control form-control-sm mb-1" data-pi="${k}" placeholder="Parameter ${k+1}">`).join(''):'')+
        '<button class="btn btn-primary btn-sm w-full mt-1" onclick="sendChosenTemplate()">Send template</button>';
}
async function sendChosenTemplate(){
    if(!tplChosen) return;
    const params=Array.from(document.querySelectorAll('#tplParams [data-pi]')).map(el=>el.value);
    const r=await api(msgUrl('template'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,template:tplChosen.name,language:tplChosen.language,params})});
    if(!r.ok){ tvaToast('Template send failed.','error'); return; } appendMessages([(await r.json()).message]); closePops();
}

// ── Voice recording (live) ──
let mediaRec=null, chunks=[], recCancelled=false, recTimer=null, recStart=0;
function showRec(on){ document.getElementById('composerRow').style.display=on?'none':'flex'; document.getElementById('recBar').style.display=on?'flex':'none'; }
async function startRec(){
    try{
        const stream=await navigator.mediaDevices.getUserMedia({audio:true});
        mediaRec=new MediaRecorder(stream); chunks=[]; recCancelled=false;
        mediaRec.ondataavailable=e=>chunks.push(e.data);
        mediaRec.onstop=()=>{ stream.getTracks().forEach(t=>t.stop()); clearInterval(recTimer); showRec(false);
            if(!recCancelled){ const blob=new Blob(chunks,{type:'audio/webm'}); sendFile(new File([blob],'voice-note.webm',{type:'audio/webm'})); } };
        mediaRec.start(); showRec(true); recStart=Date.now();
        document.getElementById('recTime').textContent='0:00';
        document.getElementById('recWave').innerHTML=Array.from({length:28}).map((_,i)=>`<i style="animation-delay:${(i%10)*0.07}s"></i>`).join('');
        recTimer=setInterval(()=>{ document.getElementById('recTime').textContent=mmss((Date.now()-recStart)/1000); },250);
    }catch(e){ tvaToast('Microphone access denied.','error'); }
}
function stopRec(cancel){ if(!mediaRec) return; recCancelled=cancel; if(mediaRec.state!=='inactive') mediaRec.stop(); }

// ── Wire ──
document.getElementById('btnSend').onclick=sendText;
document.getElementById('chatInput').addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); sendText(); }});
document.getElementById('chatInput').addEventListener('input',e=>{ e.target.style.height='auto'; e.target.style.height=Math.min(e.target.scrollHeight,120)+'px'; });
document.getElementById('chatSearch').oninput=loadConvos;
document.getElementById('botToggle').onclick=setBotRequest;
document.getElementById('galleryBtn').onclick=openGallery;
document.getElementById('btnVoice').onclick=startRec;
document.getElementById('recSend').onclick=()=>stopRec(false);
document.getElementById('recCancel').onclick=()=>stopRec(true);
document.getElementById('fileInput').onchange=e=>{ if(e.target.files[0]) sendFile(e.target.files[0]); e.target.value=''; };
document.getElementById('lightbox').onclick=e=>{ if(e.target.id==='lightbox') closeLightbox(); };
document.getElementById('btnMore').onclick=()=>{ const m=document.getElementById('composerMenu'); const show=m.style.display!=='block'; closePops(); m.style.display=show?'block':'none'; if(show && window.lucide) try{lucide.createIcons();}catch(_){} };
document.querySelectorAll('#filterTabs button').forEach(b=>b.onclick=()=>{
    currentFilter=b.dataset.f;
    document.querySelectorAll('#filterTabs button').forEach(x=>x.classList.toggle('is-active', x===b));
    loadConvos();
});
document.getElementById('presenceSel').onchange=async function(){
    this.dataset.touched='1';
    const r=await api(CHAT.base+'/presence',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,status:this.value})});
    if(!r.ok){ tvaToast('Could not set presence — are you a human agent on this project?','error'); }
    else { tvaToast('You are now '+this.value,'success'); loadConvos(); }
};
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeLightbox(); document.getElementById('galleryOverlay').style.display='none'; const ov=document.getElementById('tvaDlg'); if(ov) ov.classList.remove('open'); }
    if(document.getElementById('lightbox').style.display==='flex'){ if(e.key==='ArrowLeft') lbNav(null,-1); if(e.key==='ArrowRight') lbNav(null,1); } });

const ep=document.getElementById('emojiPicker');
ep.innerHTML=EMOJIS.map(e=>`<span>${e}</span>`).join('');
ep.querySelectorAll('span').forEach(s=>s.onclick=()=>{ const ta=document.getElementById('chatInput'); ta.value+=s.textContent; ta.focus(); });
document.getElementById('btnEmoji').onclick=()=>{ const show=ep.style.display!=='grid'; closePops(); ep.style.display=show?'grid':'none'; };

// Chat defaults to the compact icon menu for space (runs after the global
// nav-collapse partial so it isn't overridden). The top-bar menu button
// toggles full ↔ icon for the whole app.
window.addEventListener('load',()=>document.body.classList.add('tva-nav-collapsed'));
loadConvos(); setInterval(loadConvos,6000);
if(window.lucide) try{ lucide.createIcons(); }catch(_){}
</script>
@endsection
