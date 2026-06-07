@extends('layouts.master')

@section('content')
<style>
    /* ── Layout ────────────────────────────────────────────────────── */
    .tva-ws-cols { display:grid; gap:24px; grid-template-columns: 1fr; }
    @media (min-width: 1000px) { .tva-ws-cols { grid-template-columns: 1fr 1fr; align-items: start; } }

    .tva-ws-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        padding: 22px;
    }
    .tva-ws-card__title {
        font-size:14px; font-weight:600; color:#0f172a;
        display:flex; align-items:center; gap:8px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }

    /* ── Form controls ─────────────────────────────────────────────── */
    .tva-form-group { margin-bottom: 16px; }
    .tva-form-label {
        font-size:11px; color:#64748b; text-transform:uppercase;
        letter-spacing:.05em; font-weight:600; margin-bottom: 6px;
        display:block;
    }
    .tva-form-help { font-size:11px; color:#94a3b8; margin-top: 4px; }

    /* color row */
    .tva-color-row { display:flex; align-items:center; gap:10px; }
    .tva-color-row input[type=color] {
        width: 50px; height: 40px; border-radius: 8px;
        border: 1px solid #e2e8f0; cursor: pointer;
        padding: 2px; background:#fff;
    }
    .tva-color-row input[type=text] { flex:1; font-family: ui-monospace, monospace; }

    /* position toggle */
    .tva-toggle-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .tva-toggle-card {
        border:1px solid #e2e8f0; border-radius:10px;
        padding: 14px 12px; text-align:center; cursor:pointer;
        background:#f8fafc; transition: all .15s;
        font-size:13px; font-weight:600; color:#334155;
    }
    .tva-toggle-card.is-selected { border-color:#6366f1; background:#eef2ff; color:#3730a3; box-shadow: 0 0 0 1px #6366f1; }
    .tva-toggle-card__diag { width: 56px; height: 36px; margin: 0 auto 8px; position: relative; border-radius:6px; background:#e2e8f0; }
    .tva-toggle-card__dot  { position:absolute; width:14px; height:14px; border-radius:50%; background:#6366f1; bottom:4px; }
    .tva-toggle-card__dot--right { right:4px; }
    .tva-toggle-card__dot--left  { left:4px; }

    /* show-voice switch */
    .tva-switch { display:flex; align-items:center; gap:10px; padding: 10px 12px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; }
    /* Right-column (half-width) card → 2-up by default, 3-up only on
       wide screens where the column itself is wide enough. */
    .tva-toggle-grid { display:grid; gap:12px; grid-template-columns: repeat(2, 1fr); }
    @media (min-width: 1500px) { .tva-toggle-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 560px)  { .tva-toggle-grid { grid-template-columns: 1fr; } }
    .tva-switch__label { flex:1; font-size:13px; font-weight:600; color:#0f172a; }
    .tva-switch__hint  { font-size:11px; color:#64748b; font-weight:400; margin-top:1px; }
    .tva-switch__input { width:42px; height:24px; appearance:none; background:#cbd5e1; border-radius:999px; cursor:pointer; position:relative; transition: background .15s; }
    .tva-switch__input::before { content:''; width:18px; height:18px; background:#fff; border-radius:50%; position:absolute; top:3px; left:3px; transition: left .15s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
    .tva-switch__input:checked { background:#10b981; }
    .tva-switch__input:checked::before { left:21px; }

    /* ── Logo upload preview tile ──────────────────────────────────── */
    .tva-logo-row { display:flex; align-items:flex-start; gap:14px; }
    .tva-logo-preview {
        width:72px; height:72px; border-radius: 14px;
        background: #f1f5f9; border: 1px solid #e2e8f0;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0; overflow:hidden;
        font-size: 32px;
    }
    .tva-logo-preview img { width:100%; height:100%; object-fit: cover; }

    /* ── Live preview ──────────────────────────────────────────────── */
    .tva-preview-wrap {
        position: relative;
        background: linear-gradient(135deg,#f1f5f9 0%,#e0e7ff 100%);
        border-radius: 12px;
        min-height: 740px;
        padding: 22px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
    }
    .tva-preview-wrap::before {
        /* fake browser content behind the widget */
        content:'';
        position:absolute; top:22px; left:22px; right:22px;
        height: 14px; border-radius:7px;
        background: rgba(15,23,42,.08);
    }
    .tva-preview-lines {
        position:absolute; top:50px; left:22px; right:22px;
    }
    .tva-preview-lines div {
        height: 10px; border-radius:5px; background: rgba(15,23,42,.06);
        margin-bottom: 8px; max-width: 480px;
    }
    .tva-preview-lines div:nth-child(2) { width: 80%; }
    .tva-preview-lines div:nth-child(3) { width: 60%; }
    .tva-preview-lines div:nth-child(4) { width: 70%; }

    /* the mock widget — every selector is namespaced with .tva-mw so
       when this same CSS ships inside the injectable widget it can't
       collide with any class names on the host site. */
    .tva-mw, .tva-mw * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important; }
    .tva-mw {
        position: absolute; bottom: 22px;
        width: 340px; max-width: calc(100% - 44px);
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 20px 40px -10px rgba(15,23,42,.3);
        background: #fff;
        display:flex; flex-direction:column;
        z-index: 2;
        --primary: #1a365d;
        --accent:  #3b82f6;
    }
    .tva-mw.is-right { right: 22px; }
    .tva-mw.is-left  { left:  22px; }
    .tva-mw__head {
        background: linear-gradient(135deg, var(--primary), var(--accent));
        color:#fff !important; padding: 14px 16px;
        display:flex; align-items:center; gap:10px;
    }
    .tva-mw__avatar {
        width: 38px; height: 38px; border-radius: 50%;
        background: rgba(255,255,255,.2);
        display:flex; align-items:center; justify-content:center;
        font-size: 18px;
        overflow:hidden;
    }
    .tva-mw__avatar img { width:100%; height:100%; object-fit: cover; }
    .tva-mw__title { font-size: 14px; font-weight: 700; line-height: 1.2; }
    .tva-mw__status { font-size: 10px; opacity: .85; display:flex; align-items:center; gap:5px; }
    .tva-mw__status::before { content:''; width:6px; height:6px; border-radius:50%; background:#10b981; box-shadow: 0 0 6px #10b981; }
    .tva-mw__close { margin-left:auto; opacity:.8; cursor:pointer; }

    .tva-mw__body {
        background: #f8fafc;
        padding: 16px; height: 440px; overflow-y: auto;
    }
    .tva-mw__bot-bubble {
        background:#fff; border:1px solid #e2e8f0;
        border-radius: 14px 14px 14px 4px;
        padding: 10px 12px;
        max-width: 80%;
        font-size: 12px; color:#1e293b;
        margin-bottom: 8px;
        line-height: 1.45;
    }
    .tva-mw__bot-bubble.heading {
        background: linear-gradient(135deg, var(--primary), var(--accent));
        color:#fff; border-color: transparent;
        font-weight: 600;
    }

    .tva-mw__foot {
        border-top: 1px solid #e2e8f0;
        padding: 10px 12px;
        display: flex; align-items: center; gap: 8px;
        background: #fff;
    }
    .tva-mw__input {
        flex: 1; font-size: 12px;
        border: 1px solid #e2e8f0; border-radius: 999px;
        padding: 8px 12px; outline: none;
        color:#1e293b; background:#f8fafc;
    }
    .tva-mw__send, .tva-mw__voice {
        width: 32px; height: 32px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: var(--primary); color: #fff;
        cursor: pointer; flex-shrink: 0;
    }
    .tva-mw__voice { background: #e2e8f0; color: #475569; }
    .tva-mw__voice.is-hidden { display: none; }

    /* the floating launcher button */
    .tva-mw-launcher {
        position: absolute; bottom: 22px;
        width: 60px; height: 60px; border-radius: 50%;
        background: linear-gradient(135deg, var(--launcher-primary), var(--launcher-accent));
        display:flex; align-items:center; justify-content:center;
        color:#fff; font-size: 26px;
        cursor: pointer;
        box-shadow: 0 12px 24px -6px rgba(0,0,0,.3);
        z-index: 1;
        --launcher-primary: #1a365d;
        --launcher-accent:  #3b82f6;
    }
    .tva-mw-launcher.is-right { right: 22px; }
    .tva-mw-launcher.is-left  { left:  22px; }
    .tva-mw-launcher img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }

    /* ── Embed snippet ─────────────────────────────────────────────── */
    .tva-embed-box {
        background: #0f172a; color: #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        font-size: 12px;
        line-height: 1.55;
        white-space: pre;
        overflow-x: auto;
        position: relative;
    }
    .tva-embed-copy {
        position: absolute; top: 8px; right: 8px;
        background: #6366f1; color:#fff;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: 11px; font-weight: 600;
        cursor: pointer; border: none;
        font-family: inherit;
    }
    .tva-embed-copy.is-copied { background:#10b981; }

    /* ── Dark mode (.dark on <html>) ───────────────────────────────── */
    html.dark .tva-ws-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-ws-card__title { color:#f1f5f9; border-bottom-color:#334155; }
    html.dark .tva-form-label { color:#94a3b8; }
    html.dark .tva-form-help { color:#64748b; }
    html.dark .tva-toggle-card { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .tva-toggle-card.is-selected { background:#312e81; border-color:#6366f1; color:#c7d2fe; }
    html.dark .tva-toggle-card__diag { background:#334155; }
    html.dark .tva-switch { background:#0f172a; border-color:#334155; }
    html.dark .tva-switch__label { color:#f1f5f9; }
    html.dark .tva-switch__hint  { color:#94a3b8; }

    html.dark .tva-preview-wrap { background: linear-gradient(135deg,#0f172a 0%,#1e1b4b 100%); border-color:#334155; }
    html.dark .tva-preview-wrap::before { background: rgba(255,255,255,.1); }
    html.dark .tva-preview-lines div { background: rgba(255,255,255,.06); }
</style>

@php
    $configJson = json_encode($config);
@endphp

<div class="content">
    <div class="intro-y flex items-center mt-6 mb-4">
        <h2 class="text-lg font-medium mr-auto">
            Widget settings — {{ $client?->name }}
        </h2>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- Project picker --}}
    <div class="intro-y box p-3 mb-5">
        <form method="GET">
            <label class="tva-form-label">Project</label>
            <select name="project_id" class="form-select w-full md:w-1/3" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <form method="POST" action="{{ route('widget-settings.update', ['client' => $client->slug]) }}" id="tvaWsForm" enctype="multipart/form-data">
        @csrf
        @method('PATCH')
        <input type="hidden" name="project_id" value="{{ $projectId }}">

        <div class="tva-ws-cols">
            {{-- ── LEFT: settings form ──────────────────────────── --}}
            <div>
                <div class="tva-ws-card mb-5">
                    <div class="tva-ws-card__title">
                        <i data-lucide="palette" class="w-4 h-4"></i> Branding
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Primary color</label>
                        <div class="tva-color-row">
                            <input type="color" id="ws_primary_color_picker" value="{{ $config['primary_color'] }}">
                            <input type="text" name="primary_color" id="ws_primary_color" value="{{ $config['primary_color'] }}" class="form-control" pattern="^#[0-9A-Fa-f]{3,6}$">
                        </div>
                        <div class="tva-form-help">Header background + buttons</div>
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Accent color</label>
                        <div class="tva-color-row">
                            <input type="color" id="ws_accent_color_picker" value="{{ $config['accent_color'] }}">
                            <input type="text" name="accent_color" id="ws_accent_color" value="{{ $config['accent_color'] }}" class="form-control" pattern="^#[0-9A-Fa-f]{3,6}$">
                        </div>
                        <div class="tva-form-help">Highlights + gradient end</div>
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Logo</label>
                        <div class="tva-logo-row">
                            <div class="tva-logo-preview" id="ws_logo_preview">
                                @if (!empty($config['logo_url']))
                                    <img src="{{ $config['logo_url'] }}" alt="Logo" id="ws_logo_img">
                                @else
                                    <span id="ws_logo_emoji">{{ $config['avatar_emoji'] }}</span>
                                @endif
                            </div>
                            <div style="flex:1;">
                                <input type="file" name="logo" id="ws_logo_input" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" class="hidden" style="display:none;">
                                <label for="ws_logo_input" class="btn btn-outline-primary w-full" style="cursor:pointer;">
                                    <i data-lucide="upload" class="w-4 h-4 mr-2 inline"></i> Choose image
                                </label>
                                @if (!empty($config['logo_url']))
                                    <label class="flex items-center mt-2 text-xs text-slate-500">
                                        <input type="checkbox" name="remove_logo" value="1" class="mr-2"> Remove current logo
                                    </label>
                                @endif
                                <div class="tva-form-help">PNG, JPG, SVG, WebP · square works best · max 2 MB</div>
                            </div>
                        </div>
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Fallback avatar (emoji)</label>
                        <input type="text" name="avatar_emoji" id="ws_avatar" value="{{ $config['avatar_emoji'] }}" class="form-control" maxlength="8" placeholder="🤖">
                        <div class="tva-form-help">Used when no logo is uploaded</div>
                    </div>
                </div>

                <div class="tva-ws-card mb-5">
                    <div class="tva-ws-card__title">
                        <i data-lucide="message-circle" class="w-4 h-4"></i> Messages
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Bot name</label>
                        <input type="text" name="bot_name" id="ws_bot_name" value="{{ $config['bot_name'] }}" class="form-control" maxlength="60">
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Welcome heading</label>
                        <input type="text" name="welcome_title" id="ws_welcome_title" value="{{ $config['welcome_title'] }}" class="form-control" maxlength="120">
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">First message (greeting)</label>
                        <textarea name="welcome_message" id="ws_welcome_message" class="form-control" rows="3" maxlength="500">{{ $config['welcome_message'] }}</textarea>
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Input placeholder</label>
                        <input type="text" name="placeholder" id="ws_placeholder" value="{{ $config['placeholder'] }}" class="form-control" maxlength="120">
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Opening hours</label>
                        <input type="text" name="opening_hours" id="ws_hours" value="{{ $config['opening_hours'] }}" class="form-control" maxlength="80" placeholder="e.g. Mon–Fri 9am–6pm">
                        <div class="tva-form-help">Shown in the header next to bot name</div>
                    </div>
                </div>

                <div class="tva-ws-card mb-5">
                    <div class="tva-ws-card__title">
                        <i data-lucide="layout" class="w-4 h-4"></i> Placement & behaviour
                    </div>

                    <div class="tva-form-group">
                        <label class="tva-form-label">Position on page</label>
                        <div class="tva-toggle-grid" id="ws_position_toggle">
                            <label class="tva-toggle-card {{ $config['position'] === 'bottom-right' ? 'is-selected' : '' }}" data-pos="bottom-right">
                                <div class="tva-toggle-card__diag"><div class="tva-toggle-card__dot tva-toggle-card__dot--right"></div></div>
                                Bottom-right
                                <input type="radio" name="position" value="bottom-right" @checked($config['position']==='bottom-right') hidden>
                            </label>
                            <label class="tva-toggle-card {{ $config['position'] === 'bottom-left' ? 'is-selected' : '' }}" data-pos="bottom-left">
                                <div class="tva-toggle-card__diag"><div class="tva-toggle-card__dot tva-toggle-card__dot--left"></div></div>
                                Bottom-left
                                <input type="radio" name="position" value="bottom-left" @checked($config['position']==='bottom-left') hidden>
                            </label>
                        </div>
                    </div>

                </div>

                {{-- CORS card (full width of left column) --}}
                <div class="tva-ws-card mt-5">
                    <div class="tva-ws-card__title">
                        <i data-lucide="shield" class="w-4 h-4"></i> Allowed websites (CORS)
                    </div>
                    <div class="tva-form-group">
                        <label class="tva-form-label">Allowed origins</label>
                        <textarea name="allowed_origins" rows="4"
                                  class="form-control w-full"
                                  style="font-family: ui-monospace, Consolas, monospace; font-size: 12px;"
                                  placeholder="https://acme.com&#10;https://www.acme.com&#10;https://app.acme.com"
                        >{{ is_array($config['allowed_origins'] ?? null) ? implode("\n", $config['allowed_origins']) : '' }}</textarea>
                        <div class="tva-form-help">
                            One per line. Only requests coming from these origins are accepted.
                            Format: scheme + host (no path), e.g. <code>https://acme.com</code>.
                            <b>Leave blank to allow any origin</b> (dev only — restrict before production).
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary w-full shadow-md mt-5" type="submit">
                    <i data-lucide="save" class="w-4 h-4 mr-2 inline"></i> Save widget settings
                </button>

            </div>

            {{-- ── RIGHT: real-widget preview (iframe) ──────────────── --}}
            @php
                // Resolve the widget folder URL. Order:
                //   1. WIDGET_BASE_URL env (explicit override)
                //   2. dirname($_SERVER['SCRIPT_NAME']) — always
                //      /AI-CRM-AGENT/admin/public for a live request,
                //      regardless of how APP_URL is configured. Swap
                //      /admin/public → /widget for the sibling folder.
                $widgetBase = rtrim((string) config('services.widget.base_url'), '/');
                if ($widgetBase === '') {
                    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
                    $candidate = preg_replace('#/admin/public/?$#', '/widget', $scriptDir);
                    if ($candidate && $candidate !== $scriptDir) {
                        $widgetBase = request()->getSchemeAndHttpHost() . rtrim($candidate, '/');
                    }
                }
                $previewUrl = ($widgetBase && $project)
                    ? $widgetBase . '/webchat-app.php?key=' . urlencode($project->project_api_key) . '&embed=1&_=' . time()
                    : null;
            @endphp
            <div>
                <div class="tva-ws-card mb-5">
                    <div class="tva-ws-card__title">
                        <i data-lucide="eye" class="w-4 h-4"></i> Live widget preview
                        <button type="button" id="ws_preview_refresh" class="ml-auto text-xs"
                                style="color: var(--tva-accent, #3b82f6); background:none; border:none; cursor:pointer; font-family:inherit;">
                            <i data-lucide="refresh-cw" class="w-3 h-3 inline -mt-0.5"></i> Refresh
                        </button>
                    </div>

                    <div class="tva-form-help" style="margin-bottom:10px;">
                        This is the actual widget your visitors see, loaded from <code>/widget/webchat-app.php</code>.
                        <b>Save your changes</b> and hit <i>Refresh</i> to update the preview.
                    </div>

                    @if ($previewUrl)
                        <div style="position:relative; background:#0f1a2e; border-radius:10px; overflow:hidden; height:640px; border:1px solid #e2e8f0;">
                            <iframe id="ws_preview_iframe"
                                    src="{{ $previewUrl }}"
                                    style="width:100%; height:100%; border:0; background:transparent;"
                                    title="Widget preview"
                                    loading="lazy"></iframe>
                        </div>
                        <div class="tva-form-help" style="margin-top:8px;">
                            Iframe URL:
                            <a href="{{ $previewUrl }}" target="_blank" rel="noopener" style="color: var(--tva-accent, #3b82f6); font-family: ui-monospace, monospace; word-break: break-all;">{{ $previewUrl }}</a>
                            <span style="color:#94a3b8;">— click to open the real widget in a new tab.</span>
                        </div>
                    @else
                        <div style="padding:24px; background:#fef3c7; border:1px solid #fcd34d; border-radius:10px; font-size:13px; color:#92400e;">
                            <b>Preview unavailable.</b>
                            Set <code>WIDGET_BASE_URL</code> in <code>admin/.env</code> to the public URL of the
                            <code>widget/</code> folder (e.g. <code>http://localhost/AI-CRM-AGENT/widget</code>) and reload.
                        </div>
                    @endif
                </div>

                @if ($embedSnippet)
                <div class="tva-ws-card mb-5">
                    <div class="tva-ws-card__title">
                        <i data-lucide="code-2" class="w-4 h-4"></i> Embed on customer's site
                    </div>
                    <p class="text-xs text-slate-500 mb-3">Paste this just before <code>&lt;/body&gt;</code> on the customer's website. The widget reads the project's settings automatically via the API key.</p>
                    <div class="tva-embed-box" id="ws_embed_code">{{ $embedSnippet }}</div>
                    <button type="button" class="tva-embed-copy" id="ws_copy_btn" onclick="event.stopPropagation();">Copy</button>
                </div>
                @endif

                {{-- ── Visible elements (right column, under preview) ── --}}
                <div class="tva-ws-card">
                    <div class="tva-ws-card__title">
                        <i data-lucide="eye" class="w-4 h-4"></i> Visible elements
                    </div>
                    <div class="tva-form-help" style="margin-bottom:14px;">
                        Toggle individual buttons + sections on or off. The widget rebuilds itself instantly when visitors next open it.
                    </div>

                    @php
                        $toggles = [
                            ['show_voice',         'Voice / mic button',       'Lets visitors talk to the bot via microphone'],
                            ['show_emoji',         'Emoji picker',             'Smiley button next to the text input'],
                            ['show_attach',        'Attachment button',        'Paperclip button to send images, video, audio or files'],
                            ['show_theme_toggle',  'Theme switch (light/dark)','Sun/moon toggle in the widget header'],
                            ['show_reply_toggle',  'Voice-reply switch',       'Header toggle for audio-vs-text bot replies'],
                            ['show_expand_button', 'Expand button',            'Lets visitors expand the widget to fullscreen'],
                            ['show_visitor_modes', 'New / Returning tiles',    'Big buttons on the home screen'],
                            ['show_history_tab',   'History tab',              'Bottom-nav tab listing past conversations'],
                            ['show_powered_by',    'Powered-by footer',        'Small "Powered by NueraBot" line at the bottom'],
                        ];
                    @endphp
                    <div class="tva-toggle-grid">
                        @foreach ($toggles as [$key, $label, $hint])
                            <div class="tva-switch">
                                <div style="min-width:0; flex:1;">
                                    <div class="tva-switch__label">{{ $label }}</div>
                                    <div class="tva-switch__hint">{{ $hint }}</div>
                                </div>
                                <input type="hidden" name="{{ $key }}" value="0">
                                <input type="checkbox" class="tva-switch__input" name="{{ $key }}" id="ws_{{ $key }}" value="1" @checked($config[$key] ?? true)>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }

    // Widget-settings page interactions. The big "live preview" that
    // used to mirror form inputs into mock DOM nodes is gone — the
    // right column now iframes the real widget, so we only need a few
    // small bits of glue here.
    (function () {
        var $ = function (id) { return document.getElementById(id); };

        // 1) Color picker ↔ hex text input sync
        function syncColor(textId, pickerId) {
            var text = $(textId), picker = $(pickerId);
            if (!text || !picker) return;
            text.addEventListener('input',   function () { picker.value = text.value; });
            picker.addEventListener('input', function () { text.value   = picker.value; });
        }
        syncColor('ws_primary_color', 'ws_primary_color_picker');
        syncColor('ws_accent_color',  'ws_accent_color_picker');

        // 2) Position tiles — keep the visual selection in sync with
        //    the hidden radio so the form submits the right value.
        document.querySelectorAll('#ws_position_toggle .tva-toggle-card').forEach(function (card) {
            card.addEventListener('click', function () {
                document.querySelectorAll('#ws_position_toggle .tva-toggle-card')
                    .forEach(function (c) { c.classList.remove('is-selected'); });
                card.classList.add('is-selected');
                var radio = card.querySelector('input[type=radio]');
                if (radio) radio.checked = true;
            });
        });

        // 3) Logo upload thumbnail (live, FileReader)
        var logoInput = $('ws_logo_input');
        var logoTile  = $('ws_logo_preview');
        if (logoInput && logoTile) {
            logoInput.addEventListener('change', function (e) {
                var file = e.target.files && e.target.files[0];
                if (!file) return;
                var reader = new FileReader();
                reader.onload = function (ev) {
                    logoTile.innerHTML = '<img src="' + ev.target.result + '" alt="Logo">';
                };
                reader.readAsDataURL(file);
            });
        }

        // 4) Refresh the iframe (bust cache via _ timestamp)
        var refreshBtn = $('ws_preview_refresh');
        var frame      = $('ws_preview_iframe');
        if (refreshBtn && frame) {
            refreshBtn.addEventListener('click', function () {
                var url = frame.src.replace(/[?&]_=\d+/, '');
                frame.src = url + (url.indexOf('?') >= 0 ? '&' : '?') + '_=' + Date.now();
            });
        }

        // 5) Copy embed snippet button
        var copyBtn = $('ws_copy_btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var code = (document.getElementById('ws_embed_code') || {}).textContent || '';
                navigator.clipboard.writeText(code).then(function () {
                    copyBtn.textContent = 'Copied!';
                    copyBtn.classList.add('is-copied');
                    setTimeout(function () {
                        copyBtn.textContent = 'Copy';
                        copyBtn.classList.remove('is-copied');
                    }, 1500);
                });
            });
        }
    })();
</script>
@endsection
