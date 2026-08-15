<head>
    <meta charset="utf-8">
    <link rel="icon" href="{{ serveai_icon() }}">
    <link rel="shortcut icon" href="{{ serveai_icon() }}">
    <link rel="apple-touch-icon" href="{{ serveai_icon() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Midone admin is super flexible, powerful, clean & modern responsive tailwind admin template with unlimited possibilities.">
    <meta name="keywords" content="admin template, Midone Admin Template, dashboard template, flat admin template, responsive admin template, web app">
    <meta name="author" content="LEFT4CODE">
    <meta name="csrf-token" id="csrf-token" content="{{ csrf_token() }}">
    <title>{{ tva_setting('content.brand_name', 'Serve AI') }}</title>
    <!-- BEGIN: CSS Assets-->
    <link rel="stylesheet" href="{{url('/assets/dist/css/app.css')}}" />
    <!-- END: CSS Assets-->

    {{-- Shared responsive rules for all custom admin pages.
         Targets the .tva-* utility classes used across telephony,
         brain-settings, leads, sessions, skills, bot-agents, etc. --}}
    <style>
        /* ── Scrollbars, app-wide ─────────────────────────────────────
           Thin, on-theme, and only visible where something actually
           scrolls. The browser default is a 15px light-grey trough that
           cuts a bright stripe through every dark panel and steals width
           from the content beside it.

           Firefox gets the standard two properties; Chromium and Safari
           need the -webkit- pseudo-elements. Both are declared rather than
           picking one, because they are not interchangeable. */
        * { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
        html.dark * { scrollbar-color: #475569 transparent; }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1; border-radius: 99px;
            /* Inset via a transparent border so the thumb reads as slimmer
               than its hit area — easier to grab, lighter to look at. */
            border: 2px solid transparent; background-clip: content-box;
        }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; background-clip: content-box; }
        ::-webkit-scrollbar-corner { background: transparent; }
        html.dark ::-webkit-scrollbar-thumb { background: #475569; background-clip: content-box; }
        html.dark ::-webkit-scrollbar-thumb:hover { background: #64748b; background-clip: content-box; }

        /* Stat-grid: 4 cols → 2 cols on tablet → 1 col on phone. */
        @media (max-width: 1024px) {
            .tva-stat-grid { grid-template-columns: repeat(2, 1fr) !important; }
        }
        @media (max-width: 540px) {
            .tva-stat-grid { grid-template-columns: 1fr !important; gap: 8px !important; }
            .tva-stat { padding: 12px !important; }
            .tva-stat__value { font-size: 18px !important; }
        }

        /* DataTable toolbar wraps cleanly + search becomes full-width on phone. */
        @media (max-width: 720px) {
            .tva-dt-toolbar { padding: 12px !important; gap: 8px !important; }
            .tva-dt-toolbar .tva-dt-search { max-width: 100% !important; min-width: 0 !important; flex: 1 0 100% !important; }
            .tva-dt-toolbar select { font-size: 13px !important; min-width: 0 !important; flex: 1 1 calc(50% - 4px) !important; }
            .tva-dt-toolbar .ml-auto { margin-left: 0 !important; width: 100%; justify-content: flex-end; }
            .tva-dt-table thead { display: none; }
            .tva-dt-table, .tva-dt-table tbody, .tva-dt-table tr, .tva-dt-table td { display: block; width: 100%; }
            .tva-dt-table tbody tr { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; }
            .tva-dt-table tbody td {
                padding: 6px 0; border: none; display: flex; justify-content: space-between;
                align-items: center; gap: 10px; font-size: 12.5px;
            }
            .tva-dt-table tbody td::before {
                content: attr(data-label);
                font-size: 10px; text-transform: uppercase; letter-spacing: .04em;
                color: #94a3b8; font-weight: 700;
            }
            .tva-dt-table tbody td:first-child::before { display: none; }
        }
        html.dark .tva-dt-table tbody tr { border-bottom-color: #334155; }

        /* Hero pad/font shrinks on phones. */
        @media (max-width: 540px) {
            .tva-dt-hero, .tva-tel-hero, .tva-ag-hero, .tva-skill-hero, .tva-brain-hero {
                padding: 16px 18px !important; gap: 12px;
            }
            .tva-dt-hero__icon, .tva-tel-hero__icon, .tva-ag-hero__icon,
            .tva-skill-hero__icon, .tva-brain-hero__icon {
                width: 42px !important; height: 42px !important; font-size: 22px !important;
            }
        }

        /* Modals: pad less, fill width on small screens. */
        @media (max-width: 640px) {
            .tva-modal { padding: 12px !important; }
            .tva-modal__panel { max-width: 100% !important; max-height: calc(100vh - 24px) !important; }
            .tva-modal__body { padding: 14px 16px !important; }
            .tva-modal__head, .tva-modal__foot { padding: 12px 16px !important; }
            .tva-modal__body .grid.grid-cols-2 { grid-template-columns: 1fr !important; }
        }

        /* Telephony number tiles stack vertically on phone. */
        @media (max-width: 540px) {
            .tva-num-tile { flex-wrap: wrap; }
            .tva-num-tile > .flex-1 { min-width: 0; flex: 1 1 calc(100% - 56px); }
        }

        /* Pagination is always visible — wraps under row counter. */
        @media (max-width: 540px) {
            .tva-dt-footer { justify-content: center !important; }
            .tva-dt-footer__info { width: 100%; text-align: center; }
            .tva-pag a, .tva-pag span { min-width: 28px !important; height: 28px !important; padding: 0 6px !important; }
        }

        /* Default content padding shrinks on phones (Midone's .content has p-5). */
        @media (max-width: 540px) {
            .content { padding-left: 12px !important; padding-right: 12px !important; }
        }

        /* ── Beautiful native file inputs, project-wide ──────────────────
           Restyles every <input type="file"> and its "Choose file" button
           so the default OS control matches the admin theme. Pure CSS, no
           JS, stays fully accessible + keyboard-friendly. The selectors
           include `.form-control` to out-specify the theme's input class,
           and inline `display:none` (used by custom upload zones such as
           Voices) still wins, so hidden inputs stay hidden. */
        input[type="file"]:not(.hidden),
        input[type="file"].form-control:not(.hidden) {
            display: block;
            width: 100%;
            font-size: 13px;
            color: #64748b;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 6px;
            cursor: pointer;
            transition: border-color .15s, box-shadow .15s;
        }
        input[type="file"]:not(.hidden):hover { border-color: var(--tva-primary, #6366f1); }
        input[type="file"]:not(.hidden):focus,
        input[type="file"]:not(.hidden):focus-visible {
            outline: none;
            border-color: var(--tva-primary, #6366f1);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .18);
        }
        input[type="file"]::file-selector-button,
        input[type="file"]::-webkit-file-upload-button {
            margin-right: 14px;
            padding: 8px 16px;
            border: 0;
            border-radius: 8px;
            background: var(--tva-gradient, linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%));
            color: #fff;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: .01em;
            cursor: pointer;
            transition: filter .15s, transform .05s, box-shadow .15s;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .12);
        }
        input[type="file"]::file-selector-button:hover { filter: brightness(1.08); }
        input[type="file"]::-webkit-file-upload-button:hover { filter: brightness(1.08); }
        input[type="file"]::file-selector-button:active { transform: translateY(1px); }
        html.dark input[type="file"]:not(.hidden),
        html.dark input[type="file"].form-control:not(.hidden) {
            background: #1b2433;
            border-color: #334155;
            color: #94a3b8;
        }
        html.dark input[type="file"]:not(.hidden):hover { border-color: var(--tva-primary, #818cf8); }
    </style>

    {{-- Themed alert/confirm system (replaces native alert()/confirm()). --}}
    @include('partials.sweet-alert')
</head>