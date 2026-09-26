{{--
    Explainer video — the 47-second ad, right after the hero.

    Two renders of the same timeline (English, Urdu), so switching language
    keeps the playhead where it was. The ten taglines beside the player are the
    ad's own script, and each is a chapter: it lights up while its scene plays
    and seeks there when clicked. Chapter times come from the scene windows in
    marketing/hero-video/source/ad.html — re-check them if the ad is re-cut.

    PLAYBACK RULES
      • Plays when the section is mostly on screen, pauses when it leaves.
      • Starts MUTED: browsers refuse autoplay with sound. "Tap for sound" says
        so rather than leaving the visitor wondering why it is silent.
      • A visitor who pauses it has decided — scrolling back does not restart it.
      • prefers-reduced-motion: never autoplays.

    LOADING / CACHING
      • preload="none" and no src until the section is near; then metadata
        only. The 8 MB body is fetched once it plays, never on page load.
      • Files live in public/media/ with a ?v=<mtime> stamp; nginx serves that
        folder with a one-year immutable Cache-Control, so a returning visitor
        plays it straight from their browser cache, and replacing a file still
        reaches everyone because the stamp changes.
--}}
@php
    $xvAsset = function (string $file) {
        $path = public_path('media/explainer/' . $file);

        return asset('media/explainer/' . $file) . (is_file($path) ? '?v=' . filemtime($path) : '');
    };

    // [start second, short EN, short UR, full EN, full UR]
    // The SHORT line is what the script column shows; the full line from the
    // ad's voice-over is kept as the tooltip and the accessible label.
    $xvChapters = [
        [0,    'Customers ask, every day.',              'گاہک روز پوچھتے ہیں',          'Every day, your customers are asking questions.',                                   'ہر روز آپ کے گاہک سوال پوچھتے ہیں۔'],
        [5.1,  'Some wait. Some leave.',                 'کچھ انتظار، کچھ رخصت',         'Some wait. Some ask the same thing twice. Some just… leave.',                     'کچھ انتظار کرتے ہیں۔ کچھ ایک ہی بات بار بار پوچھتے ہیں۔ اور کچھ… بس چلے جاتے ہیں۔'],
        [11.3, 'Your team can’t be everywhere.',         'ٹیم ہر جگہ نہیں ہو سکتی',      'Your team can’t be everywhere. And they shouldn’t have to be.',                   'آپ کی ٹیم ہر جگہ موجود نہیں ہو سکتی۔ اور اسے ہونا بھی نہیں چاہیے۔'],
        [15.2, 'An answer — even at midnight.',          'جواب، آدھی رات کو بھی',        'What if every customer got an answer… even at midnight?',                           'سوچیں، اگر ہر گاہک کو فوراً جواب ملے… آدھی رات کو بھی؟'],
        [19.3, 'Instant, in their language.',            'فوراً، ان کی اپنی زبان میں',   'serveAI answers from your own information — instantly, in your customer’s language.', 'serveAI آپ کی اپنی معلومات سے، فوراً، گاہک کی اپنی زبان میں جواب دیتا ہے۔'],
        [29.1, 'Every channel. Every lead.',             'ہر چینل، ہر لیڈ',              'On your website, WhatsApp, Instagram, Facebook and phone calls. It captures every lead, too.', 'ویب سائٹ، واٹس ایپ، انسٹاگرام، فیس بک اور فون کالز پر۔ اور ہر لیڈ خود محفوظ کرتا ہے۔'],
        [35.1, 'Hand-off to your team.',                 'ضرورت پر آپ کی ٹیم',           'When a conversation needs a person, it goes straight to your team.',               'جہاں انسان کی ضرورت ہو، بات سیدھی آپ کی ٹیم تک پہنچتی ہے۔'],
        [39.6, 'Your data, your database.',              'آپ کا ڈیٹا، آپ کے پاس',        'Your data stays in your own private database. And you see every conversation.',    'آپ کا ڈیٹا آپ کے اپنے پرائیویٹ ڈیٹا بیس میں۔ ہر گفتگو آپ کی نظر میں۔'],
        [41.6, 'AI for routine. Team for what matters.', 'روزمرہ AI، اہم کام ٹیم',       'Let AI handle the routine. Let your team handle what matters.',                     'روزمرہ کے سوال AI سنبھالے۔ اہم کام آپ کی ٹیم۔'],
        [43.0, 'Start free today.',                      'آج ہی مفت شروع کریں',          'Your customers are already asking. Let serveAI answer. Start free today.',         'گاہک پہلے ہی پوچھ رہے ہیں۔ جواب serveAI دے گا۔ آج ہی مفت شروع کریں۔'],
    ];
@endphp

{{-- Nastaliq for the Urdu lines. Non-blocking: the print-media swap means it
     never holds up the first paint of the page above it. --}}
<link rel="stylesheet" media="print" onload="this.media='all'"
      href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;600&display=swap">

<style>
    .xv { padding-top: 40px; }
    /* Wider than the page's 1240px .wrap on big screens: the video is the
       point of this section. Capped at 100vw - 200px so it never runs under
       the fixed scroll HUD pinned to the right edge of the page. */
    .xv > .wrap.xv__grid { max-width: max(1240px, min(1480px, calc(100vw - 200px))); }
    .xv__grid {
        display: grid; gap: 40px; align-items: center;
        grid-template-columns: repeat(12, minmax(0, 1fr));
    }
    .xv__stage { grid-column: span 8; min-width: 0; }
    .xv__copy  { grid-column: span 4; min-width: 0; }
    @media (max-width: 1024px) {
        .xv__grid { gap: 32px; }
        .xv__stage, .xv__copy { grid-column: 1 / -1; }
    }

    /* ── Frame: a lit bezel around the video ───────────────────────── */
    .xv__frame {
        position: relative; border-radius: 22px; padding: 1px;
        background: linear-gradient(140deg, rgba(96,165,250,.75), rgba(59,130,246,.08) 38%, rgba(139,92,246,.10) 62%, rgba(96,165,250,.55));
        box-shadow: 0 40px 90px -30px rgba(37, 99, 235, .45), 0 18px 40px -20px rgba(2, 6, 23, .55);
        isolation: isolate;
    }
    .xv__frame::before {           /* soft halo behind the frame */
        content: ''; position: absolute; inset: -26px; z-index: -1; border-radius: 40px;
        background: radial-gradient(60% 60% at 30% 20%, rgba(59,130,246,.30), transparent 70%),
                    radial-gradient(50% 50% at 85% 90%, rgba(139,92,246,.22), transparent 70%);
        filter: blur(18px); opacity: .9; pointer-events: none;
    }
    .xv__screen {
        position: relative; overflow: hidden; border-radius: 21px;
        background: #05070c; aspect-ratio: 16 / 9;
    }
    .xv__video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; background: #05070c; }
    .xv__screen.is-switching .xv__video { opacity: .35; transition: opacity .2s; }

    /* ── Centre play button ─────────────────────────────────────────── */
    .xv__big {
        position: absolute; inset: 0; margin: auto; width: 84px; height: 84px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; border: 0; cursor: pointer;
        background: rgba(255,255,255,.14); color: #fff;
        backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        box-shadow: 0 0 0 1px rgba(255,255,255,.28) inset, 0 12px 40px rgba(0,0,0,.45);
        transition: transform .2s, opacity .25s, background .2s;
    }
    .xv__big::after {               /* pulsing ring */
        content: ''; position: absolute; inset: -10px; border-radius: 50%;
        border: 1.5px solid rgba(147,197,253,.6); animation: xvRing 2.2s ease-out infinite;
    }
    .xv__big:hover { transform: scale(1.07); background: rgba(37,99,235,.85); }
    .xv__big svg { width: 30px; height: 30px; margin-left: 4px; }
    .xv.is-playing .xv__big { opacity: 0; pointer-events: none; transform: scale(.85); }
    @keyframes xvRing { from { transform: scale(.9); opacity: .9; } to { transform: scale(1.35); opacity: 0; } }

    /* ── "Tap for sound" ────────────────────────────────────────────── */
    .xv__sound {
        position: absolute; top: 14px; right: 14px; z-index: 3;
        display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
        padding: 8px 14px 8px 11px; border-radius: 999px; border: 0;
        font: 600 12.5px/1 'Inter', system-ui, sans-serif; color: #fff;
        background: rgba(15, 23, 42, .62); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        box-shadow: 0 0 0 1px rgba(255,255,255,.16) inset;
        transition: opacity .25s, transform .25s, background .2s;
    }
    .xv__sound:hover { background: rgba(37, 99, 235, .9); }
    .xv__sound svg { width: 16px; height: 16px; }
    .xv__sound[hidden] { display: none; }
    .xv__bars { display: inline-flex; gap: 2px; align-items: flex-end; height: 12px; }
    .xv__bars i { width: 2px; background: #93c5fd; border-radius: 1px; animation: xvBar 1s ease-in-out infinite; }
    .xv__bars i:nth-child(2) { animation-delay: .2s; } .xv__bars i:nth-child(3) { animation-delay: .4s; }
    @keyframes xvBar { 0%,100% { height: 3px; } 50% { height: 12px; } }

    /* ── End card ───────────────────────────────────────────────────── */
    .xv__end {
        position: absolute; inset: 0; z-index: 4; display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 14px; text-align: center; padding: 20px;
        background: radial-gradient(70% 70% at 50% 45%, rgba(15,23,42,.72), rgba(2,6,23,.92));
        backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
        opacity: 0; pointer-events: none; transition: opacity .35s;
    }
    .xv.is-ended .xv__end { opacity: 1; pointer-events: auto; }
    .xv__end-title { color: #fff; font-size: clamp(17px, 2.2vw, 24px); font-weight: 800; letter-spacing: -.01em; }
    .xv__end-row { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }
    .xv__btn {
        display: inline-flex; align-items: center; gap: 8px; cursor: pointer; text-decoration: none;
        padding: 11px 20px; border-radius: 12px; border: 0; font: 700 14px/1 'Inter', system-ui, sans-serif;
        background: #2563eb; color: #fff; box-shadow: 0 0 30px rgba(59,130,246,.45);
        transition: transform .15s, box-shadow .15s;
    }
    .xv__btn:hover { transform: translateY(-2px); box-shadow: 0 0 40px rgba(59,130,246,.65); }
    .xv__btn--ghost { background: rgba(255,255,255,.1); box-shadow: 0 0 0 1px rgba(255,255,255,.25) inset; }
    .xv__btn svg { width: 16px; height: 16px; }

    /* ── Control bar ────────────────────────────────────────────────── */
    .xv__bar {
        position: absolute; left: 0; right: 0; bottom: 0; z-index: 3;
        display: flex; align-items: center; gap: 10px; padding: 26px 14px 12px;
        background: linear-gradient(to top, rgba(2,6,23,.85), rgba(2,6,23,0));
        transition: opacity .3s, transform .3s;
    }
    .xv.is-playing.is-idle .xv__bar { opacity: 0; transform: translateY(8px); }
    .xv__ctl {
        width: 34px; height: 34px; flex: none; border-radius: 10px; border: 0; cursor: pointer;
        display: flex; align-items: center; justify-content: center; color: #fff; background: transparent;
        transition: background .15s;
    }
    .xv__ctl:hover { background: rgba(255,255,255,.14); }
    .xv__ctl svg { width: 18px; height: 18px; }
    .xv__time { font: 600 11.5px/1 'JetBrains Mono', ui-monospace, monospace; color: rgba(255,255,255,.85); flex: none; font-variant-numeric: tabular-nums; }

    .xv__track { position: relative; flex: 1; height: 22px; display: flex; align-items: center; cursor: pointer; touch-action: none; }
    .xv__rail { position: relative; width: 100%; height: 4px; border-radius: 4px; background: rgba(255,255,255,.18); overflow: hidden; transition: height .15s; }
    .xv__track:hover .xv__rail { height: 6px; }
    .xv__fill { position: absolute; inset: 0 auto 0 0; width: 0; background: linear-gradient(90deg, #60a5fa, #3b82f6); border-radius: 4px; }
    .xv__tick { position: absolute; top: 0; bottom: 0; width: 2px; background: rgba(2,6,23,.7); }
    .xv__knob {
        position: absolute; top: 50%; left: 0; width: 13px; height: 13px; margin: -6.5px 0 0 -6.5px; border-radius: 50%;
        background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,.35); transform: scale(0); transition: transform .15s;
    }
    .xv__track:hover .xv__knob, .xv__track.is-drag .xv__knob { transform: scale(1); }

    .xv__screen:fullscreen { border-radius: 0; aspect-ratio: auto; width: 100%; height: 100%; }
    .xv__screen:fullscreen .xv__video { object-fit: contain; }

    /* ── Script column ──────────────────────────────────────────────── */
    .xv__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
    .xv__head .section__eyebrow { margin: 0; }
    .xv h2 { font-size: clamp(24px, 2.3vw, 32px); margin: 0 0 18px; }

    .xv__lang {
        display: inline-flex; padding: 4px; gap: 4px; border-radius: 999px; flex: none;
        background: var(--panel-2); border: 1px solid var(--line);
    }
    .xv__lang button {
        border: 0; cursor: pointer; padding: 7px 14px; border-radius: 999px; background: transparent;
        font: 650 12.5px/1.2 'Inter', system-ui, sans-serif; color: var(--text-dim);
        transition: background .2s, color .2s, box-shadow .2s;
    }
    .xv__lang button[lang="ur"] { font-family: 'Noto Nastaliq Urdu', 'Inter', serif; font-size: 13px; line-height: 1.2; padding-top: 5px; padding-bottom: 9px; }
    .xv__lang button[aria-checked="true"] { background: var(--neon-btn); color: #fff; box-shadow: 0 0 18px rgba(59,130,246,.35); }
    .xv__lang button:focus-visible { outline: 2px solid var(--neon-2); outline-offset: 2px; }

    /* A script: timestamps down a rail, one short line per scene. The rail
       fills as the video plays; the current line is the only bright one. */
    .xv__chapters { list-style: none; margin: 0; padding: 0; position: relative; }
    .xv__chapters::before {             /* the rail */
        content: ''; position: absolute; top: 16px; bottom: 16px; inset-inline-start: 48px;
        width: 2px; border-radius: 2px; background: var(--line);
    }
    .xv__rail-fill {
        position: absolute; top: 16px; inset-inline-start: 48px; width: 2px; height: 0; border-radius: 2px;
        background: linear-gradient(var(--neon-2), var(--neon)); box-shadow: 0 0 10px rgba(59,130,246,.45);
    }
    .xv__chapter {
        position: relative; display: flex; align-items: center; width: 100%;
        padding: 6px 8px; border: 0; border-radius: 10px; cursor: pointer;
        background: transparent; text-align: start; color: var(--text-dim2);
        font: 500 14.5px/1.35 'Inter', system-ui, sans-serif; letter-spacing: -.005em;
        transition: background .25s, color .25s;
    }
    .xv__chapter:hover { color: var(--text); background: var(--panel-2); }
    .xv__chapter-n {                   /* timestamp */
        flex: none; width: 34px; font: 600 11px/1 'JetBrains Mono', ui-monospace, monospace;
        color: var(--text-dim2); opacity: .75; font-variant-numeric: tabular-nums; direction: ltr;
    }
    .xv__chapter-dot {
        flex: none; position: relative; z-index: 1; width: 10px; height: 10px; border-radius: 50%;
        margin-inline: 2px 16px; background: var(--bg); border: 2px solid var(--line-hot);
        transition: background .25s, border-color .25s, box-shadow .25s, transform .25s;
    }
    .xv__chapter-t { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .xv__chapter.is-done { color: var(--text-dim); }
    .xv__chapter.is-done .xv__chapter-dot { background: var(--neon-2); border-color: var(--neon-2); }
    .xv__chapter.is-active { color: var(--text); font-weight: 700; }
    .xv__chapter.is-active .xv__chapter-n { color: var(--neon-2); opacity: 1; }
    .xv__chapter.is-active .xv__chapter-dot {
        background: var(--neon); border-color: var(--neon-2); transform: scale(1.3);
        box-shadow: 0 0 0 4px rgba(59,130,246,.18), 0 0 14px rgba(59,130,246,.6);
    }
    .xv__chapter:focus-visible { outline: 2px solid var(--neon-2); outline-offset: 1px; }

    /* Urdu: right-to-left and Nastaliq, which needs the extra line height. */
    .xv__chapters[dir="rtl"] .xv__chapter { font-family: 'Noto Nastaliq Urdu', serif; font-size: 15px; line-height: 1.95; padding: 2px 8px 4px; }
    .xv__chapters[dir="rtl"] .xv__chapter-n { text-align: right; }

    .xv__cta { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: 20px; }
    .xv__cta-note { font-size: 12.5px; color: var(--text-dim2); }

    @media (max-width: 540px) {
        .xv__big { width: 64px; height: 64px; }
        .xv__big svg { width: 24px; height: 24px; }
        .xv__time { display: none; }
        .xv__bar { gap: 6px; padding: 22px 8px 8px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .xv__big::after, .xv__bars i { animation: none; }
    }
</style>

<section class="section xv" id="watch" aria-labelledby="xv-title" data-no-layer
         data-src-en="{{ $xvAsset('serveai-en.mp4') }}"  data-poster-en="{{ $xvAsset('poster-en.jpg') }}"
         data-src-ur="{{ $xvAsset('serveai-ur.mp4') }}"  data-poster-ur="{{ $xvAsset('poster-ur.jpg') }}">
    <div class="wrap xv__grid">

        {{-- ── Player ── --}}
        <div class="xv__stage">
            <div class="xv__frame">
                <div class="xv__screen" data-xv-screen tabindex="0" aria-label="Video player — press space to play or pause">
                    <video class="xv__video" data-xv-video
                           muted playsinline preload="none"
                           poster="{{ $xvAsset('poster-en.jpg') }}"
                           aria-label="serveAI in 47 seconds"></video>

                    <button type="button" class="xv__big" data-xv-toggle aria-label="Play video">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11.04-6.86a1 1 0 0 0 0-1.72L9.5 4.28A1 1 0 0 0 8 5.14z"/></svg>
                    </button>

                    <button type="button" class="xv__sound" data-xv-unmute hidden>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
                        <span data-xv-unmute-label>Tap for sound</span>
                        <span class="xv__bars" aria-hidden="true"><i></i><i></i><i></i></span>
                    </button>

                    <div class="xv__end" data-xv-end>
                        <div class="xv__end-title" data-xv-end-title>Your customers are already asking.</div>
                        <div class="xv__end-row">
                            <a href="{{ url('/register') }}" class="xv__btn" data-xv-end-cta>Start free today</a>
                            <button type="button" class="xv__btn xv__btn--ghost" data-xv-replay>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                                <span data-xv-replay-label>Watch again</span>
                            </button>
                        </div>
                    </div>

                    <div class="xv__bar">
                        <button type="button" class="xv__ctl" data-xv-toggle aria-label="Play">
                            <svg data-xv-icon-play viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11.04-6.86a1 1 0 0 0 0-1.72L9.5 4.28A1 1 0 0 0 8 5.14z"/></svg>
                            <svg data-xv-icon-pause viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="display:none"><rect x="6" y="4.5" width="4" height="15" rx="1.2"/><rect x="14" y="4.5" width="4" height="15" rx="1.2"/></svg>
                        </button>

                        <div class="xv__track" data-xv-track role="slider" tabindex="0"
                             aria-label="Seek" aria-valuemin="0" aria-valuemax="47" aria-valuenow="0">
                            <div class="xv__rail">
                                <div class="xv__fill" data-xv-fill></div>
                                @foreach (array_slice($xvChapters, 1) as $c)
                                    <span class="xv__tick" data-xv-tick="{{ $c[0] }}"></span>
                                @endforeach
                            </div>
                            <span class="xv__knob" data-xv-knob></span>
                        </div>

                        <span class="xv__time" data-xv-time>0:00 / 0:47</span>

                        <button type="button" class="xv__ctl" data-xv-mute aria-label="Unmute">
                            <svg data-xv-icon-muted viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
                            <svg data-xv-icon-sound viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
                        </button>

                        <button type="button" class="xv__ctl" data-xv-fullscreen aria-label="Full screen">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Taglines / chapters ── --}}
        <div class="xv__copy">
            <div class="xv__head">
                <div class="section__eyebrow reveal">See it in 47 seconds</div>
                <div class="xv__lang" role="radiogroup" aria-label="Video language">
                    <button type="button" role="radio" aria-checked="true"  data-xv-lang="en" lang="en">English</button>
                    <button type="button" role="radio" aria-checked="false" data-xv-lang="ur" lang="ur">اردو</button>
                </div>
            </div>

            <h2 id="xv-title" class="reveal">Your customers are already asking.</h2>

            <ol class="xv__chapters" data-xv-chapters dir="ltr" lang="en">
                <li class="xv__rail-fill" data-xv-rail aria-hidden="true"></li>
                @foreach ($xvChapters as $i => $c)
                    <li>
                        <button type="button" class="xv__chapter" data-xv-chapter="{{ $i }}" data-start="{{ $c[0] }}"
                                data-en="{{ $c[1] }}" data-ur="{{ $c[2] }}"
                                data-en-full="{{ $c[3] }}" data-ur-full="{{ $c[4] }}"
                                title="{{ $c[3] }}" aria-label="{{ $c[3] }}">
                            <span class="xv__chapter-n">{{ sprintf('%d:%02d', intdiv((int) $c[0], 60), (int) $c[0] % 60) }}</span>
                            <span class="xv__chapter-dot" aria-hidden="true"></span>
                            <span class="xv__chapter-t">{{ $c[1] }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>

            <div class="xv__cta">
                <a href="{{ url('/register') }}" class="xv__btn">Start free today</a>
                <span class="xv__cta-note">No credit card required</span>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    var root = document.getElementById('watch');
    if (!root) return;

    var video   = root.querySelector('[data-xv-video]');
    var screen  = root.querySelector('[data-xv-screen]');
    var list    = root.querySelector('[data-xv-chapters]');
    var rail    = root.querySelector('[data-xv-rail]');
    var chapters = Array.prototype.slice.call(root.querySelectorAll('[data-xv-chapter]'));
    var starts  = chapters.map(function (c) { return parseFloat(c.dataset.start); });
    var fill    = root.querySelector('[data-xv-fill]');
    var knob    = root.querySelector('[data-xv-knob]');
    var track   = root.querySelector('[data-xv-track]');
    var timeEl  = root.querySelector('[data-xv-time]');
    var unmute  = root.querySelector('[data-xv-unmute]');
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var UI = {
        en: { sound: 'Tap for sound', end: 'Your customers are already asking.', cta: 'Start free today', replay: 'Watch again' },
        ur: { sound: 'آواز کے لیے ٹیپ کریں', end: 'گاہک پہلے ہی پوچھ رہے ہیں۔', cta: 'آج ہی مفت شروع کریں', replay: 'دوبارہ دیکھیں' }
    };

    var lang = 'en';
    try { lang = localStorage.getItem('serveai_xv_lang') || ''; } catch (e) {}
    if (lang !== 'en' && lang !== 'ur') {
        lang = /^ur\b/i.test(navigator.language || '') ? 'ur' : 'en';
    }

    var userPaused = false;   // the visitor pressed pause: scrolling back must not override that
    var inView     = false;
    var loaded     = false;
    var active     = -1;

    // ── Source / language ────────────────────────────────────────────
    function srcFor(l) { return root.dataset['src' + (l === 'ur' ? 'Ur' : 'En')]; }
    function posterFor(l) { return root.dataset['poster' + (l === 'ur' ? 'Ur' : 'En')]; }

    // Metadata only (a few KB) until it is actually played: the section sits
    // right under the hero, so "near" is nearly every visit.
    function load() {
        if (loaded) return;
        loaded = true;
        video.preload = 'metadata';
        video.src = srcFor(lang);
    }

    function applyLangUI() {
        root.querySelectorAll('[data-xv-lang]').forEach(function (b) {
            b.setAttribute('aria-checked', b.dataset.xvLang === lang ? 'true' : 'false');
        });
        list.setAttribute('dir', lang === 'ur' ? 'rtl' : 'ltr');
        list.setAttribute('lang', lang);
        chapters.forEach(function (c) {
            var full = c.dataset[lang + 'Full'];
            c.querySelector('.xv__chapter-t').textContent = c.dataset[lang];
            c.title = full;
            c.setAttribute('aria-label', full);
        });
        root.querySelector('[data-xv-unmute-label]').textContent = UI[lang].sound;
        root.querySelector('[data-xv-end-title]').textContent = UI[lang].end;
        root.querySelector('[data-xv-end-cta]').textContent = UI[lang].cta;
        root.querySelector('[data-xv-replay-label]').textContent = UI[lang].replay;
        video.poster = posterFor(lang);
    }

    function setLang(next) {
        if (next === lang) return;
        lang = next;
        try { localStorage.setItem('serveai_xv_lang', lang); } catch (e) {}
        applyLangUI();

        if (!loaded) return;          // nothing playing yet — the next load picks it up

        // Same timeline in both renders, so carry the playhead across.
        var at = video.currentTime, wasPlaying = !video.paused;
        screen.classList.add('is-switching');
        video.src = srcFor(lang);
        video.addEventListener('loadedmetadata', function once() {
            video.removeEventListener('loadedmetadata', once);
            try { video.currentTime = Math.min(at, (video.duration || 47) - .1); } catch (e) {}
            screen.classList.remove('is-switching');
            if (wasPlaying) play();
        });
    }

    root.querySelectorAll('[data-xv-lang]').forEach(function (b) {
        b.addEventListener('click', function () { setLang(b.dataset.xvLang); });
    });

    // ── Play / pause ─────────────────────────────────────────────────
    function play() {
        load();
        video.preload = 'auto';
        root.classList.remove('is-ended');
        var p = video.play();
        if (p && p.catch) {
            p.catch(function () {
                // Unmuted play refused (no user gesture yet): fall back to muted.
                if (!video.muted) { video.muted = true; syncMute(); video.play().catch(function () {}); }
            });
        }
    }

    function toggle() {
        if (video.paused || video.ended) { userPaused = false; play(); }
        else { userPaused = true; video.pause(); }
    }

    root.querySelectorAll('[data-xv-toggle]').forEach(function (b) { b.addEventListener('click', toggle); });
    video.addEventListener('click', toggle);

    function syncPlaying() {
        var playing = !video.paused && !video.ended;
        root.classList.toggle('is-playing', playing);
        root.querySelector('[data-xv-icon-play]').style.display  = playing ? 'none' : '';
        root.querySelector('[data-xv-icon-pause]').style.display = playing ? '' : 'none';
        root.querySelectorAll('[data-xv-toggle]').forEach(function (b) {
            b.setAttribute('aria-label', playing ? 'Pause video' : 'Play video');
        });
    }
    video.addEventListener('play', syncPlaying);
    video.addEventListener('pause', syncPlaying);
    video.addEventListener('ended', function () { syncPlaying(); root.classList.add('is-ended'); });

    root.querySelector('[data-xv-replay]').addEventListener('click', function () {
        video.currentTime = 0; userPaused = false; play();
    });

    // ── Sound ────────────────────────────────────────────────────────
    function syncMute() {
        var muted = video.muted;
        root.querySelector('[data-xv-icon-muted]').style.display = muted ? '' : 'none';
        root.querySelector('[data-xv-icon-sound]').style.display = muted ? 'none' : '';
        root.querySelector('[data-xv-mute]').setAttribute('aria-label', muted ? 'Unmute' : 'Mute');
        unmute.hidden = !(muted && root.classList.contains('is-playing'));
    }
    function setMuted(m) { video.muted = m; syncMute(); if (!m && video.paused) { userPaused = false; play(); } }

    root.querySelector('[data-xv-mute]').addEventListener('click', function () { setMuted(!video.muted); });
    unmute.addEventListener('click', function () { setMuted(false); });
    video.addEventListener('volumechange', syncMute);
    video.addEventListener('play', syncMute);
    video.addEventListener('pause', syncMute);

    // ── Progress + chapters ──────────────────────────────────────────
    function fmt(s) { s = Math.max(0, Math.floor(s || 0)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }

    function duration() { return isFinite(video.duration) && video.duration > 0 ? video.duration : 47; }

    function render() {
        var t = video.currentTime || 0, d = duration(), pct = Math.min(100, t / d * 100);
        fill.style.width = pct + '%';
        knob.style.left  = pct + '%';
        timeEl.textContent = fmt(t) + ' / ' + fmt(d);
        track.setAttribute('aria-valuenow', Math.round(t));
        track.setAttribute('aria-valuemax', Math.round(d));

        var idx = 0;
        for (var i = 0; i < starts.length; i++) { if (t >= starts[i]) idx = i; }

        var started = t > 0 || !video.paused;
        chapters.forEach(function (c, i) {
            c.classList.toggle('is-active', i === idx && started);
            c.classList.toggle('is-done', i < idx);
        });

        // Rail: from the first dot to the current one, creeping toward the
        // next as the scene plays.
        var dotY = function (i) {
            var dot = chapters[i].querySelector('.xv__chapter-dot');
            return dot.getBoundingClientRect().top - list.getBoundingClientRect().top + dot.offsetHeight / 2;
        };
        var h = 0;
        if (started) {
            var end = idx + 1 < starts.length ? starts[idx + 1] : d;
            var p = Math.min(1, (t - starts[idx]) / Math.max(.1, end - starts[idx]));
            var from = dotY(idx), to = idx + 1 < chapters.length ? dotY(idx + 1) : from;
            h = from + (to - from) * p - dotY(0);
        }
        rail.style.top = dotY(0) + 'px';
        rail.style.height = Math.max(0, h) + 'px';
        active = idx;
    }

    video.addEventListener('timeupdate', render);
    video.addEventListener('seeked', render);
    video.addEventListener('loadedmetadata', function () {
        root.querySelectorAll('[data-xv-tick]').forEach(function (tk) {
            tk.style.left = (parseFloat(tk.dataset.xvTick) / duration() * 100) + '%';
        });
        render();
    });
    root.querySelectorAll('[data-xv-tick]').forEach(function (tk) {
        tk.style.left = (parseFloat(tk.dataset.xvTick) / 47 * 100) + '%';
    });

    // Smooth progress between the coarse timeupdate events.
    (function frame() { if (!video.paused) render(); requestAnimationFrame(frame); })();

    function seek(t) {
        load();
        var go = function () { video.currentTime = Math.max(0, Math.min(t, duration() - .05)); render(); };
        if (video.readyState >= 1) go();
        else video.addEventListener('loadedmetadata', function once() { video.removeEventListener('loadedmetadata', once); go(); });
    }

    chapters.forEach(function (c) {
        c.addEventListener('click', function () { seek(parseFloat(c.dataset.start) + .05); userPaused = false; play(); });
    });

    // Scrubbing
    function ratioFromEvent(e) {
        var r = track.getBoundingClientRect();
        return Math.max(0, Math.min(1, (e.clientX - r.left) / r.width));
    }
    track.addEventListener('pointerdown', function (e) {
        track.setPointerCapture(e.pointerId); track.classList.add('is-drag');
        seek(ratioFromEvent(e) * duration());
    });
    track.addEventListener('pointermove', function (e) {
        if (track.classList.contains('is-drag')) seek(ratioFromEvent(e) * duration());
    });
    track.addEventListener('pointerup', function () { track.classList.remove('is-drag'); });
    track.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') { seek(video.currentTime + 5); e.preventDefault(); }
        if (e.key === 'ArrowLeft')  { seek(video.currentTime - 5); e.preventDefault(); }
    });

    // ── Full screen ──────────────────────────────────────────────────
    root.querySelector('[data-xv-fullscreen]').addEventListener('click', function () {
        if (document.fullscreenElement) { document.exitFullscreen(); return; }
        if (screen.requestFullscreen) screen.requestFullscreen();
        else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen();   // iOS Safari
    });

    // Space / K toggles while the player has focus.
    screen.addEventListener('keydown', function (e) {
        if ((e.key === ' ' || e.key === 'k') && e.target === screen) { e.preventDefault(); toggle(); }
    });

    // Hide the control bar after a moment of stillness while playing.
    var idleTimer;
    function wake() {
        root.classList.remove('is-idle');
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () { root.classList.add('is-idle'); }, 2200);
    }
    screen.addEventListener('pointermove', wake);
    screen.addEventListener('pointerdown', wake);
    video.addEventListener('play', wake);

    // ── Autoplay on view / pause on leave ────────────────────────────
    if ('IntersectionObserver' in window) {
        // Start fetching a little before the visitor gets here.
        new IntersectionObserver(function (entries, obs) {
            if (entries[0].isIntersecting) { load(); obs.disconnect(); }
        }, { rootMargin: '900px 0px' }).observe(screen);

        new IntersectionObserver(function (entries) {
            var e = entries[0];
            if (e.intersectionRatio >= 0.55) {
                inView = true;
                if (!reduced && !userPaused && !root.classList.contains('is-ended')) play();
            } else if (e.intersectionRatio < 0.25) {
                inView = false;
                if (!video.paused) video.pause();   // automatic, so NOT userPaused
            }
        }, { threshold: [0, 0.25, 0.55, 0.8] }).observe(screen);
    } else {
        load();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden && !video.paused) video.pause();
        else if (!document.hidden && inView && !userPaused && !reduced && !root.classList.contains('is-ended')) play();
    });

    applyLangUI();
    syncPlaying();
    syncMute();
    render();
})();
</script>
