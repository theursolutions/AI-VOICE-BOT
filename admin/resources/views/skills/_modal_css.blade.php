{{-- Reusable modal styles — included once per page (skills, agents, etc.). --}}
<style>
    .tva-modal {
        position: fixed; inset: 0; z-index: 1000;
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
    }
    .tva-modal[hidden] { display: none; }
    .tva-modal__backdrop {
        position: absolute; inset: 0;
        background: rgba(15,23,42,.65);
        backdrop-filter: blur(2px);
    }
    .tva-modal__panel {
        position: relative; z-index: 1;
        background: #fff; color: #0f172a;
        border-radius: 14px; box-shadow: 0 24px 48px -12px rgba(0,0,0,.4);
        width: 100%; max-width: 560px;
        max-height: 90vh; overflow: hidden;
        display: flex; flex-direction: column;
        animation: tva-modal-in .2s ease;
    }
    @keyframes tva-modal-in {
        from { opacity: 0; transform: translateY(10px) scale(.97); }
        to   { opacity: 1; transform: translateY(0)    scale(1); }
    }
    .tva-modal__head {
        padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
        font-size: 15px; font-weight: 600;
        display: flex; align-items: center;
    }
    .tva-modal__head [data-tva-modal-close] {
        background: transparent; border: none; cursor: pointer;
        color: #94a3b8; padding: 4px;
    }
    .tva-modal__head [data-tva-modal-close]:hover { color: #0f172a; }
    .tva-modal__body { padding: 18px 20px; overflow-y: auto; }
    .tva-modal__foot {
        padding: 14px 20px; border-top: 1px solid #e2e8f0;
        background: #f8fafc;
        display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;
    }

    html.dark .tva-modal__panel { background:#1e293b; color:#f1f5f9; }
    html.dark .tva-modal__head, html.dark .tva-modal__foot { border-color:#334155; }
    html.dark .tva-modal__foot { background:#0f172a; }
    html.dark .tva-modal__head [data-tva-modal-close]:hover { color:#f1f5f9; }

    /* TomSelect multi-select readability inside modals (light + dark). */
    .tva-modal__body .ts-wrapper.multi .ts-control {
        background: #fff; border:1px solid #e2e8f0; min-height: 38px;
    }
    .tva-modal__body .ts-wrapper.multi .ts-control > .item {
        background: #ede9fe; color:#5b21b6; border:none;
        padding: 3px 8px; border-radius: 6px; margin: 2px;
        font-size: 12px; font-weight: 600;
    }
    .tva-modal__body .ts-dropdown {
        background:#fff; color:#0f172a; border:1px solid #e2e8f0;
    }
    .tva-modal__body .ts-dropdown .option:hover,
    .tva-modal__body .ts-dropdown .option.active { background:#f1f5f9; color:#0f172a; }

    html.dark .tva-modal__body .ts-wrapper.multi .ts-control {
        background:#0f172a; border-color:#334155; color:#f1f5f9;
    }
    html.dark .tva-modal__body .ts-wrapper.multi .ts-control > .item {
        background:#4c1d95; color:#e9d5ff;
    }
    html.dark .tva-modal__body .ts-wrapper .ts-control input { color:#f1f5f9 !important; }
    html.dark .tva-modal__body .ts-dropdown {
        background:#1e293b; color:#f1f5f9; border-color:#334155;
    }
    html.dark .tva-modal__body .ts-dropdown .option { color:#f1f5f9; }
    html.dark .tva-modal__body .ts-dropdown .option:hover,
    html.dark .tva-modal__body .ts-dropdown .option.active { background:#334155; color:#fff; }
</style>
