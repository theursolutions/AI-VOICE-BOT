<head>
    <meta charset="utf-8">
    <link href="dist/images/logo.svg" rel="shortcut icon">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Midone admin is super flexible, powerful, clean & modern responsive tailwind admin template with unlimited possibilities.">
    <meta name="keywords" content="admin template, Midone Admin Template, dashboard template, flat admin template, responsive admin template, web app">
    <meta name="author" content="LEFT4CODE">
    <meta name="csrf-token" id="csrf-token" content="{{ csrf_token() }}">
    <title>Ai NueraBot</title>
    <!-- BEGIN: CSS Assets-->
    <link rel="stylesheet" href="{{url('/assets/dist/css/app.css')}}" />
    <!-- END: CSS Assets-->

    {{-- Shared responsive rules for all custom admin pages.
         Targets the .tva-* utility classes used across telephony,
         brain-settings, leads, sessions, skills, bot-agents, etc. --}}
    <style>
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
    </style>
</head>