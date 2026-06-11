@extends('layouts.master')

@section('content')
<style>
    /* ── Hero (matches data-sources/show.blade) ───────────────────── */
    .tva-acl-hero {
        background: var(--tva-gradient);
        color: #fff;
        border-radius: 14px;
        padding: 22px 26px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.4);
    }
    .tva-acl-hero__grid {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 22px;
        align-items: center;
    }
    @media (max-width: 640px) {
        .tva-acl-hero__grid { grid-template-columns: auto 1fr; }
        .tva-acl-hero__side { grid-column: 1/-1; text-align: left; }
    }
    .tva-acl-hero__icon {
        width: 60px; height: 60px; border-radius: 14px;
        background: rgba(255,255,255,.18); color: #fff;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid rgba(255,255,255,.35);
    }
    .tva-acl-hero__label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .85; font-weight: 600; }
    .tva-acl-hero__name  { font-size: 22px; font-weight: 700; margin-top: 4px; line-height: 1.2; }
    .tva-acl-hero__sub   { font-size: 13px; opacity: .85; margin-top: 4px; }
    .tva-acl-hero__pill  {
        display: inline-flex; align-items: center;
        padding: 6px 14px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .06em;
        background: rgba(255,255,255,.22); color: #fff;
        border: 1px solid rgba(255,255,255,.2);
    }
    .tva-acl-hero__pill i { width: 13px; height: 13px; margin-right: 6px; }

    /* ── Stat cards (matches show.blade) ──────────────────────────── */
    .tva-acl-stats { display: grid; gap: 14px; grid-template-columns: repeat(2, 1fr); margin-bottom: 22px; }
    @media (min-width: 768px) { .tva-acl-stats { grid-template-columns: repeat(4, 1fr); } }
    .tva-acl-stat {
        background: #fff; border-radius: 12px; padding: 14px 18px;
        border: 1px solid #e2e8f0; min-height: 84px;
        position: relative; overflow: hidden;
    }
    .tva-acl-stat::after {
        content: ''; position: absolute; top: 0; left: 0;
        width: 3px; height: 100%; background: var(--accent, #3b82f6);
    }
    .tva-acl-stat--ok      { --accent: #22c55e; }
    .tva-acl-stat--warn    { --accent: #f59e0b; }
    .tva-acl-stat--danger  { --accent: #ef4444; }
    .tva-acl-stat__label   { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
    .tva-acl-stat__value   { font-size: 22px; font-weight: 700; color: #0f172a; margin-top: 4px; line-height: 1.2; }
    .tva-acl-stat__sub     { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 4px; }

    /* ── Privacy callout ──────────────────────────────────────────── */
    .tva-acl-callout {
        display: flex; gap: 14px; align-items: flex-start;
        padding: 14px 18px;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 12px;
        margin-bottom: 22px;
    }
    .tva-acl-callout__icon {
        width: 38px; height: 38px; border-radius: 10px;
        background: #22c55e; color: #fff;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .tva-acl-callout__body {
        font-size: 13px; line-height: 1.55; color: #14532d;
    }
    .tva-acl-callout__body strong { color: #14532d; }
    .tva-acl-callout__body code {
        background: rgba(34,197,94,.15);
        color: #14532d;
        padding: 1px 6px; border-radius: 4px;
        font-size: 12px; font-family: ui-monospace, Consolas, monospace;
    }

    /* ── 2-column layout ──────────────────────────────────────────── */
    .tva-acl-cols { display: grid; gap: 22px; grid-template-columns: 1fr; }
    @media (min-width: 980px) { .tva-acl-cols { grid-template-columns: 1fr 380px; align-items: start; } }

    /* ── Section card (matches .tva-card from show.blade) ─────────── */
    .tva-acl-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
    }
    .tva-acl-card__head {
        display: flex; align-items: center; gap: 10px;
        padding: 14px 18px;
        border-bottom: 1px solid #e2e8f0;
    }
    .tva-acl-card__title {
        font-size: 14px; font-weight: 600; color: #0f172a;
        display: flex; align-items: center; gap: 8px;
        flex: 1;
    }
    .tva-acl-card__count {
        font-size: 11px; font-weight: 700;
        color: #475569; background: #f1f5f9;
        padding: 4px 10px; border-radius: 999px;
        letter-spacing: .02em;
    }

    /* ── Toolbar (above table list) ───────────────────────────────── */
    .tva-acl-toolbar {
        display: flex; align-items: center; gap: 10px;
        padding: 12px 18px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
    }
    .tva-acl-search { position: relative; flex: 1; min-width: 0; }
    /* Lucide replaces our <i> with an <svg> on init, so we have to
       target the svg too — otherwise the icon falls back to flow
       layout and ends up sitting BEFORE the input rather than inside it. */
    .tva-acl-search > i,
    .tva-acl-search > svg {
        position: absolute !important;
        left: 11px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        width: 15px;
        height: 15px;
        pointer-events: none;
        z-index: 1;
    }
    .tva-acl-search input {
        width: 100%;
        border: 1px solid #cbd5e1; border-radius: 8px;
        padding: 7px 10px 7px 34px;
        font-size: 13px; outline: none;
        background: #fff;
        transition: border-color .12s, box-shadow .12s;
    }
    .tva-acl-search input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,.15);
    }
    .tva-acl-toolbar__btn {
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 6px 12px;
        font-size: 12px; font-weight: 600;
        color: #475569;
        cursor: pointer;
        transition: background .12s, color .12s, border-color .12s;
        white-space: nowrap;
    }
    .tva-acl-toolbar__btn:hover { background: #f1f5f9; color: #1e293b; border-color: #94a3b8; }
    .tva-acl-toolbar__btn--primary {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }
    .tva-acl-toolbar__btn--primary:hover { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
    .tva-acl-toolbar__btn--ghost-danger {
        background: #fff;
        color: #b91c1c;
        border-color: #fecaca;
    }
    .tva-acl-toolbar__btn--ghost-danger:hover { background: #fef2f2; border-color: #fca5a5; }

    /* ── Table pill-cards (2-3 per row) ──────────────────────────── */
    .tva-acl-tables {
        max-height: 580px;
        overflow-y: auto;
        padding: 14px;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 10px;
        align-content: start;
        background: #f8fafc;   /* soft gray panel so white cards stand out */
    }
    @media (min-width: 1400px) {
        .tva-acl-tables { grid-template-columns: repeat(3, 1fr); }
    }
    .tva-acl-row {
        position: relative;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 12px 10px;
        cursor: pointer;
        transition: border-color .12s, box-shadow .12s, transform .08s, background .12s;
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-height: 84px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }
    .tva-acl-row:hover {
        border-color: #93c5fd;
        box-shadow: 0 4px 14px -6px rgba(59, 130, 246, .35);
        transform: translateY(-1px);
    }
    .tva-acl-row.is-open {
        border-color: #3b82f6;
        background: #eff6ff;
        box-shadow: 0 6px 18px -6px rgba(59, 130, 246, .5);
    }
    .tva-acl-row.is-allowed {
        border-left: 3px solid #22c55e;
        padding-left: 11px;
    }
    .tva-acl-row.is-off {
        background: #f1f5f9;
        opacity: .7;
    }
    .tva-acl-row.is-off .tva-acl-row__sub { color: #94a3b8; }
    .tva-acl-row__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    /* iOS-style switch (more theme-y than a bare checkbox) */
    .tva-acl-switch {
        position: relative;
        display: inline-block;
        width: 38px; height: 22px;
    }
    .tva-acl-switch input {
        opacity: 0; width: 0; height: 0;
    }
    .tva-acl-switch__slider {
        position: absolute; inset: 0;
        background: #cbd5e1;
        border-radius: 999px;
        cursor: pointer;
        transition: background .2s;
    }
    .tva-acl-switch__slider::before {
        content: '';
        position: absolute;
        height: 16px; width: 16px;
        left: 3px; top: 3px;
        background: #fff;
        border-radius: 50%;
        transition: transform .2s;
        box-shadow: 0 1px 3px rgba(0,0,0,.2);
    }
    .tva-acl-switch input:checked + .tva-acl-switch__slider {
        background: #22c55e;
    }
    .tva-acl-switch input:checked + .tva-acl-switch__slider::before {
        transform: translateX(16px);
    }

    .tva-acl-row__icon {
        width: 28px; height: 28px; border-radius: 8px;
        background: #f1f5f9; color: #64748b;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .tva-acl-row.is-allowed .tva-acl-row__icon { background: #dcfce7; color: #15803d; }

    .tva-acl-row__main { min-width: 0; flex: 1; }
    .tva-acl-row__name {
        font-weight: 600; font-size: 13px; color: #0f172a;
        font-family: ui-monospace, Consolas, monospace;
        display: flex; align-items: center; gap: 7px;
        flex-wrap: wrap;
        line-height: 1.3;
        word-break: break-all;
    }
    .tva-acl-row__sub {
        font-size: 11px; color: #64748b; margin-top: 2px;
        font-family: ui-monospace, Consolas, monospace;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        max-width: 100%;
    }
    .tva-acl-row__cols-preview {
        display: flex; flex-wrap: wrap; gap: 4px;
        margin-top: auto;
        padding-top: 4px;
    }
    .tva-acl-col-pill {
        display: inline-block;
        font-size: 10px;
        background: #f1f5f9;
        color: #475569;
        padding: 2px 7px;
        border-radius: 999px;
        font-family: ui-monospace, Consolas, monospace;
        line-height: 1.4;
    }
    .tva-acl-col-pill--more {
        background: transparent;
        color: #94a3b8;
        padding: 2px 3px;
    }
    .tva-acl-row.is-off .tva-acl-col-pill { opacity: .7; }

    .tva-acl-badge {
        display: inline-flex; align-items: center;
        font-size: 10px; font-weight: 700;
        padding: 2px 8px; border-radius: 4px;
        text-transform: uppercase; letter-spacing: .04em;
        font-family: 'Inter', system-ui, sans-serif;
    }
    .tva-acl-badge--warn   { background: #fef3c7; color: #92400e; }
    .tva-acl-badge--danger { background: #fee2e2; color: #b91c1c; }
    .tva-acl-badge--info   { background: #dbeafe; color: #1e40af; }

    /* Click-to-edit hint shown on the active card (replaces the old explicit button) */
    .tva-acl-row.is-open::after {
        content: '';
        position: absolute;
        top: 14px; right: 14px;
        width: 6px; height: 6px;
        border-top: 2px solid #3b82f6;
        border-right: 2px solid #3b82f6;
        transform: rotate(45deg);
        opacity: .7;
    }

    .tva-acl-empty {
        text-align: center; padding: 48px 20px;
        color: #94a3b8;
    }
    .tva-acl-empty i { color: #cbd5e1; }
    .tva-acl-empty__title { font-size: 14px; font-weight: 600; color: #475569; margin-top: 10px; }
    .tva-acl-empty__sub   { font-size: 12px; margin-top: 4px; }

    /* ── Side panel (column editor) ───────────────────────────────── */
    .tva-acl-side { position: sticky; top: 16px; }
    .tva-acl-side__card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
    }
    .tva-acl-side__head {
        padding: 16px 18px;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        border-bottom: 1px solid #e2e8f0;
    }
    .tva-acl-side__head--off {
        background: linear-gradient(135deg, #fef2f2 0%, #fef3f2 100%);
    }
    .tva-acl-side__head--placeholder {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }
    .tva-acl-side__title {
        display: flex; align-items: center; gap: 8px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .06em;
        color: #15803d;
    }
    .tva-acl-side__head--off .tva-acl-side__title { color: #b91c1c; }
    .tva-acl-side__head--placeholder .tva-acl-side__title { color: #475569; }
    .tva-acl-side__name {
        font-weight: 700; font-size: 16px; color: #0f172a;
        font-family: ui-monospace, Consolas, monospace;
        margin-top: 6px;
        word-break: break-all;
    }
    .tva-acl-side__sub {
        font-size: 12px; color: #475569; margin-top: 4px;
    }
    .tva-acl-side__sub strong { color: #0f172a; }
    .tva-acl-side__actions {
        display: flex; gap: 6px; margin-top: 12px;
    }
    .tva-acl-side__actions .tva-acl-toolbar__btn { padding: 4px 10px; font-size: 11px; }

    .tva-acl-cols-list {
        padding: 6px 0;
        max-height: 440px;
        overflow-y: auto;
    }
    .tva-acl-col {
        display: grid;
        grid-template-columns: 30px 1fr auto;
        align-items: center;
        gap: 10px;
        padding: 7px 18px;
        transition: background .1s;
        cursor: pointer;
        font-size: 13px;
    }
    .tva-acl-col:hover { background: #f8fafc; }
    .tva-acl-col input[type="checkbox"] {
        width: 16px; height: 16px;
        cursor: pointer;
        accent-color: #3b82f6;
    }
    .tva-acl-col__name {
        font-family: ui-monospace, Consolas, monospace; font-size: 12.5px;
        color: #0f172a;
        font-weight: 500;
        display: inline-flex; align-items: center; gap: 7px;
        min-width: 0;
        word-break: break-all;
    }
    .tva-acl-col__pk-badge {
        background: #fef3c7; color: #92400e;
        font-size: 9.5px; font-weight: 700;
        padding: 2px 6px; border-radius: 4px;
        text-transform: uppercase; letter-spacing: .04em;
        font-family: 'Inter', system-ui, sans-serif;
    }
    .tva-acl-col__type {
        font-size: 10.5px; color: #64748b;
        background: #f1f5f9; padding: 2px 7px; border-radius: 4px;
        font-family: ui-monospace, Consolas, monospace;
        white-space: nowrap;
    }

    .tva-acl-side__empty {
        padding: 48px 24px 36px;
        color: #94a3b8; font-size: 13px; text-align: center;
    }
    .tva-acl-side__empty-icon {
        width: 56px; height: 56px; border-radius: 14px;
        background: #f1f5f9; color: #94a3b8;
        display: inline-flex; align-items: center; justify-content: center;
        margin: 0 auto 14px;
    }
    .tva-acl-side__empty-title { font-size: 14px; font-weight: 600; color: #475569; margin-bottom: 6px; }

    /* ── Save bar ─────────────────────────────────────────────────── */
    .tva-acl-savebar {
        position: sticky;
        bottom: 12px;
        z-index: 5;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px 18px;
        margin-top: 22px;
        display: flex; align-items: center; gap: 14px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.2);
    }
    .tva-acl-savebar__hint {
        color: #64748b; font-size: 12.5px; flex: 1;
        display: flex; align-items: center; gap: 8px;
    }
    .tva-acl-savebar__btn {
        background: #22c55e; color: #fff;
        border: none; border-radius: 8px;
        padding: 10px 22px;
        font-size: 13px; font-weight: 700;
        cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px;
        transition: filter .12s, transform .08s, box-shadow .12s;
        box-shadow: 0 4px 14px -4px rgba(34,197,94,.5);
    }
    .tva-acl-savebar__btn:hover { filter: brightness(1.06); box-shadow: 0 6px 18px -4px rgba(34,197,94,.65); }
    .tva-acl-savebar__btn:active { transform: translateY(1px); }

    /* ── Dark mode (matches show.blade) ───────────────────────────── */
    html.dark .tva-acl-stat        { background: #1e293b; border-color: #334155; }
    html.dark .tva-acl-stat__label { color: #94a3b8; }
    html.dark .tva-acl-stat__value { color: #f1f5f9; }
    html.dark .tva-acl-stat__sub   { color: #94a3b8; }

    html.dark .tva-acl-callout       { background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.35); }
    html.dark .tva-acl-callout__body { color: #bbf7d0; }
    html.dark .tva-acl-callout__body strong { color: #dcfce7; }
    html.dark .tva-acl-callout__body code   { background: rgba(34,197,94,.2); color: #dcfce7; }

    html.dark .tva-acl-card                  { background: #1e293b; border-color: #334155; }
    html.dark .tva-acl-card__head            { border-bottom-color: #334155; }
    html.dark .tva-acl-card__title           { color: #f1f5f9; }
    html.dark .tva-acl-card__count           { background: #0f172a; color: #cbd5e1; }
    html.dark .tva-acl-toolbar               { background: #0f172a; border-bottom-color: #334155; }
    html.dark .tva-acl-search input          { background: #1e293b; border-color: #334155; color: #f1f5f9; }
    html.dark .tva-acl-search input:focus    { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.25); }
    html.dark .tva-acl-search i              { color: #64748b; }
    html.dark .tva-acl-toolbar__btn          { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    html.dark .tva-acl-toolbar__btn:hover    { background: #334155; color: #f1f5f9; border-color: #475569; }
    html.dark .tva-acl-toolbar__btn--primary { background: rgba(59,130,246,.15); color: #93c5fd; border-color: rgba(59,130,246,.4); }
    html.dark .tva-acl-toolbar__btn--ghost-danger { background: #1e293b; color: #fca5a5; border-color: rgba(239,68,68,.35); }

    html.dark .tva-acl-tables           { background: #0a0f1d; }
    html.dark .tva-acl-row              {
        background: #1e293b;
        border-color: #334155;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .25);
    }
    html.dark .tva-acl-row:hover        {
        background: #243049;
        border-color: #475569;
        box-shadow: 0 4px 14px -6px rgba(59, 130, 246, .45);
    }
    html.dark .tva-acl-row.is-open      {
        background: rgba(59,130,246,.18);
        border-color: #3b82f6;
        box-shadow: 0 6px 18px -6px rgba(59, 130, 246, .55);
    }
    html.dark .tva-acl-row.is-off       { background: #131c2f; }
    html.dark .tva-acl-row__icon        { background: #0f172a; color: #94a3b8; }
    html.dark .tva-acl-row.is-allowed .tva-acl-row__icon { background: rgba(34,197,94,.15); color: #86efac; }
    html.dark .tva-acl-row__name        { color: #f1f5f9; }
    html.dark .tva-acl-row__sub         { color: #94a3b8; }
    html.dark .tva-acl-col-pill         { background: #0f172a; color: #cbd5e1; }
    html.dark .tva-acl-col-pill--more   { background: transparent; color: #64748b; }

    html.dark .tva-acl-switch__slider   { background: #475569; }
    html.dark .tva-acl-switch input:checked + .tva-acl-switch__slider { background: #22c55e; }

    html.dark .tva-acl-side__card       { background: #1e293b; border-color: #334155; }
    html.dark .tva-acl-side__head--placeholder { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); }
    html.dark .tva-acl-side__head--off  { background: linear-gradient(135deg, rgba(239,68,68,.12) 0%, rgba(239,68,68,.08) 100%); }
    html.dark .tva-acl-side__head       { background: linear-gradient(135deg, rgba(34,197,94,.15) 0%, rgba(34,197,94,.08) 100%); border-bottom-color: #334155; }
    html.dark .tva-acl-side__name       { color: #f1f5f9; }
    html.dark .tva-acl-side__sub        { color: #cbd5e1; }
    html.dark .tva-acl-side__sub strong { color: #f1f5f9; }
    html.dark .tva-acl-side__empty      { color: #64748b; }
    html.dark .tva-acl-side__empty-icon { background: #0f172a; color: #475569; }
    html.dark .tva-acl-side__empty-title { color: #cbd5e1; }

    html.dark .tva-acl-col:hover        { background: #0f172a; }
    html.dark .tva-acl-col__name        { color: #f1f5f9; }
    html.dark .tva-acl-col__type        { background: #0f172a; color: #94a3b8; }
    html.dark .tva-acl-col__pk-badge    { background: rgba(245,158,11,.15); color: #fbbf24; }

    html.dark .tva-acl-savebar          { background: #1e293b; border-color: #334155; }
    html.dark .tva-acl-savebar__hint    { color: #94a3b8; }
</style>

@php
    $totalTables    = count($schema);
    $allowedCount   = is_null($allowedTables) ? $totalTables : count($allowedTables);
    $totalColumns   = 0;
    $hiddenColCount = 0;
    foreach ($schema as $tbl => $cols) {
        $totalColumns += count($cols);
        $tCols = \App\Services\DataSource\SchemaAclFilter::columnNames($cols);
        $aCols = $allowedColumns[$tbl] ?? null;
        if (is_array($aCols)) {
            $hiddenColCount += max(0, count($tCols) - count($aCols));
        }
    }
@endphp

<div class="content">
    {{-- ── Breadcrumb + title ───────────────────────────────────────── --}}
    <div class="intro-y flex items-center mt-6 mb-4 flex-wrap gap-2">
        <h2 class="text-lg font-medium mr-auto">
            <a href="{{ route('data-sources.index') }}" class="text-slate-400 hover:text-primary">
                <i data-lucide="chevron-left" class="w-4 h-4 inline -mt-1"></i> Data sources
            </a>
            <span class="text-slate-400 mx-1">/</span>
            <a href="{{ route('data-sources.show', ['id' => $source->id]) }}" class="text-slate-400 hover:text-primary">
                {{ $source->name }}
            </a>
            <span class="text-slate-400 mx-1">/</span>
            <span>AI access control</span>
        </h2>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- ── Hero ─────────────────────────────────────────────────────── --}}
    <div class="tva-acl-hero intro-y mb-6">
        <div class="tva-acl-hero__grid">
            <div class="tva-acl-hero__icon">
                <i data-lucide="shield-check" class="w-7 h-7"></i>
            </div>
            <div>
                <div class="tva-acl-hero__label">AI access control · privacy gate</div>
                <div class="tva-acl-hero__name">{{ $source->name }}</div>
                <div class="tva-acl-hero__sub">
                    The AI agent can only read tables + columns you allow here. Everything else stays invisible.
                </div>
            </div>
            <div class="tva-acl-hero__side text-right">
                <span class="tva-acl-hero__pill">
                    <i data-lucide="lock"></i> Locked by allowlist
                </span>
            </div>
        </div>
    </div>

    {{-- ── Stats ────────────────────────────────────────────────────── --}}
    <div class="tva-acl-stats intro-y">
        <div class="tva-acl-stat tva-acl-stat--ok">
            <div class="tva-acl-stat__label">Tables allowed</div>
            <div class="tva-acl-stat__value" id="aclStatAllowed">{{ $allowedCount }}</div>
            <div class="tva-acl-stat__sub">of {{ $totalTables }} total</div>
        </div>
        <div class="tva-acl-stat tva-acl-stat--danger">
            <div class="tva-acl-stat__label">Tables denied</div>
            <div class="tva-acl-stat__value" id="aclStatDenied">{{ max(0, $totalTables - $allowedCount) }}</div>
            <div class="tva-acl-stat__sub">never reachable</div>
        </div>
        <div class="tva-acl-stat tva-acl-stat--warn">
            <div class="tva-acl-stat__label">Columns hidden</div>
            <div class="tva-acl-stat__value" id="aclStatHidden">{{ $hiddenColCount }}</div>
            <div class="tva-acl-stat__sub">across {{ count((array) $allowedColumns) }} configured tables</div>
        </div>
        <div class="tva-acl-stat">
            <div class="tva-acl-stat__label">Total columns</div>
            <div class="tva-acl-stat__value">{{ $totalColumns }}</div>
            <div class="tva-acl-stat__sub">across {{ $totalTables }} tables</div>
        </div>
    </div>

    {{-- ── Privacy callout ──────────────────────────────────────────── --}}
    <div class="tva-acl-callout intro-y">
        <div class="tva-acl-callout__icon">
            <i data-lucide="shield-check" class="w-5 h-5"></i>
        </div>
        <div class="tva-acl-callout__body">
            <strong>How this works.</strong>
            Filtering happens before the schema reaches the AI brain — the LLM literally never sees the
            tables or columns you deny here, so it can't reveal them in any answer.
            Switch off whole tables (<code>ledger</code>, <code>payroll</code>, <code>purchasing</code>),
            then click any allowed table to hide individual columns (<code>purchase_price</code>,
            <code>ssn</code>, <code>credit_card</code>).
        </div>
    </div>

    <form id="aclForm" method="POST" action="{{ route('data-sources.update-access', ['id' => $source->id]) }}">
        @csrf

        <div class="tva-acl-cols intro-y">

            {{-- LEFT: table list --}}
            <div class="tva-acl-card">
                <div class="tva-acl-card__head">
                    <div class="tva-acl-card__title">
                        <i data-lucide="table-2" class="w-4 h-4" style="color:#3b82f6;"></i>
                        Database tables
                    </div>
                    <span class="tva-acl-card__count">
                        <span id="aclTablesCount">{{ $allowedCount }}</span> / {{ $totalTables }} allowed
                    </span>
                </div>

                <div class="tva-acl-toolbar">
                    <div class="tva-acl-search">
                        <i data-lucide="search" class="w-4 h-4"></i>
                        <input type="text" id="aclSearch" placeholder="Filter tables by name…" autocomplete="off">
                    </div>
                    <button type="button" class="tva-acl-toolbar__btn tva-acl-toolbar__btn--primary" id="aclAllowAll">
                        <i data-lucide="check-circle" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> Allow all
                    </button>
                    <button type="button" class="tva-acl-toolbar__btn tva-acl-toolbar__btn--ghost-danger" id="aclDenyAll">
                        <i data-lucide="x-circle" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> Deny all
                    </button>
                </div>

                <div class="tva-acl-tables" id="aclTables">
                    @forelse($schema as $table => $columns)
                        @php
                            $colNames = \App\Services\DataSource\SchemaAclFilter::columnNames($columns);
                            $isAllowed = is_null($allowedTables) || in_array($table, (array) $allowedTables, true);
                            $tableColsAllowed = $allowedColumns[$table] ?? null;
                            $colsHidden = is_array($tableColsAllowed)
                                ? max(0, count($colNames) - count($tableColsAllowed))
                                : 0;
                        @endphp
                        <div class="tva-acl-row {{ $isAllowed ? 'is-allowed' : 'is-off' }}"
                             data-table="{{ $table }}"
                             onclick="aclOpenColumns(this); event.stopPropagation();">

                            <div class="tva-acl-row__head">
                                <span class="tva-acl-row__icon">
                                    <i data-lucide="table" class="w-3.5 h-3.5"></i>
                                </span>
                                <div class="tva-acl-row__main">
                                    <div class="tva-acl-row__name">{{ $table }}</div>
                                    <div class="tva-acl-row__sub">{{ count($colNames) }} columns</div>
                                </div>
                                <label class="tva-acl-switch" onclick="event.stopPropagation();" title="Allow / deny this table">
                                    <input type="checkbox"
                                           name="allowed_tables[]"
                                           value="{{ $table }}"
                                           @checked($isAllowed)
                                           onchange="aclToggleTable(this)">
                                    <span class="tva-acl-switch__slider"></span>
                                </label>
                            </div>

                            <div class="tva-acl-row__cols-preview">
                                @foreach(collect($colNames)->take(3) as $cn)
                                    <span class="tva-acl-col-pill">{{ $cn }}</span>
                                @endforeach
                                @if(count($colNames) > 3)
                                    <span class="tva-acl-col-pill tva-acl-col-pill--more">+{{ count($colNames) - 3 }}</span>
                                @endif
                                @if($colsHidden > 0)
                                    <span class="tva-acl-badge tva-acl-badge--warn" style="margin-left:auto;">{{ $colsHidden }} hidden</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="tva-acl-empty">
                            <i data-lucide="database-zap" class="w-10 h-10 inline"></i>
                            <div class="tva-acl-empty__title">No tables introspected yet</div>
                            <div class="tva-acl-empty__sub">Re-sync this data source to refresh its schema.</div>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- RIGHT: column editor (sticky side panel) --}}
            <div class="tva-acl-side">
                <div class="tva-acl-side__card">
                    <div class="tva-acl-side__head tva-acl-side__head--placeholder" id="aclSideHead">
                        <div class="tva-acl-side__title">
                            <i data-lucide="columns-3" class="w-3.5 h-3.5"></i> Column access
                        </div>
                        <div class="tva-acl-side__name">No table selected</div>
                        <div class="tva-acl-side__sub">
                            Click any table on the left to view + edit its columns.
                        </div>
                    </div>
                    <div id="aclSideBody">
                        <div class="tva-acl-side__empty">
                            <div class="tva-acl-side__empty-icon">
                                <i data-lucide="mouse-pointer-2" class="w-6 h-6"></i>
                            </div>
                            <div class="tva-acl-side__empty-title">Click a table to begin</div>
                            <div>By default, every column of an allowed table is readable.</div>
                            <div style="margin-top: 6px;">Uncheck individual columns to hide them from the AI.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Save bar ─────────────────────────────────────────────── --}}
        <div class="tva-acl-savebar">
            <div class="tva-acl-savebar__hint">
                <i data-lucide="info" class="w-4 h-4" style="color:#3b82f6;"></i>
                <span>Changes apply on the next AI query — no restart needed.</span>
            </div>
            <button type="submit" class="tva-acl-savebar__btn">
                <i data-lucide="shield-check" class="w-4 h-4"></i> Save access rules
            </button>
        </div>
    </form>
</div>

<script>
    // Stored column-allowlist state, indexed by table → { allColumns, colMeta, allowed }
    // `allowed === null` means "no per-column rule" (= every column readable).
    const aclColumnState = {};
    @foreach($schema as $table => $columns)
        @php
            $colNames    = \App\Services\DataSource\SchemaAclFilter::columnNames($columns);
            $allowedCols = $allowedColumns[$table] ?? null;
            $colMeta = [];
            foreach ((array) $columns as $def) {
                $name = strtok((string) $def, " \t");
                if ($name === false || $name === '') continue;
                $rest = trim(substr((string) $def, strlen($name)));
                $colMeta[] = [
                    'name'  => $name,
                    'type'  => $rest,
                    'is_pk' => str_contains(strtoupper((string) $def), ' PK'),
                ];
            }
        @endphp
        aclColumnState[@json($table)] = {
            allColumns: @json($colNames),
            colMeta:    @json($colMeta),
            allowed:    @json($allowedCols),
        };
    @endforeach

    const TOTAL_TABLES = {{ $totalTables }};

    function aclRefreshStats() {
        const checked = document.querySelectorAll('input[name="allowed_tables[]"]:checked').length;
        document.getElementById('aclTablesCount').textContent  = checked;
        document.getElementById('aclStatAllowed').textContent  = checked;
        document.getElementById('aclStatDenied').textContent   = Math.max(0, TOTAL_TABLES - checked);

        let hidden = 0;
        Object.values(aclColumnState).forEach(s => {
            if (Array.isArray(s.allowed)) hidden += Math.max(0, s.allColumns.length - s.allowed.length);
        });
        document.getElementById('aclStatHidden').textContent = hidden;
    }

    function aclUpdateRowBadge(row, hiddenCount) {
        // Badge now lives in the cols-preview footer of the pill-card,
        // pushed to the right via margin-left:auto.
        const previewEl = row.querySelector('.tva-acl-row__cols-preview');
        if (!previewEl) return;
        const oldBadge = previewEl.querySelector('.tva-acl-badge');
        if (oldBadge) oldBadge.remove();
        if (hiddenCount > 0) {
            const badge = document.createElement('span');
            badge.className = 'tva-acl-badge tva-acl-badge--warn';
            badge.style.marginLeft = 'auto';
            badge.textContent = hiddenCount + ' hidden';
            previewEl.appendChild(badge);
        }
    }

    function aclToggleTable(cb) {
        const row = cb.closest('.tva-acl-row');
        if (cb.checked) {
            row.classList.add('is-allowed');
            row.classList.remove('is-off');
        } else {
            row.classList.remove('is-allowed');
            row.classList.add('is-off');
            const t = row.dataset.table;
            aclColumnState[t].allowed = null;       // drop any per-col rule
            aclUpdateRowBadge(row, 0);              // and its hidden badge
        }
        aclRefreshStats();
        const open = document.querySelector('.tva-acl-row.is-open');
        if (open === row) aclOpenColumns(row);
    }

    function aclOpenColumns(row) {
        document.querySelectorAll('.tva-acl-row.is-open').forEach(r => r.classList.remove('is-open'));
        row.classList.add('is-open');

        const table = row.dataset.table;
        const state = aclColumnState[table];
        const tableEnabled = row.querySelector('input[name="allowed_tables[]"]').checked;
        const allowedSet = new Set(state.allowed === null ? state.allColumns : state.allowed);
        const hiddenCount = state.allColumns.length - allowedSet.size;

        const head = document.getElementById('aclSideHead');
        const body = document.getElementById('aclSideBody');

        head.classList.remove('tva-acl-side__head--placeholder', 'tva-acl-side__head--off');
        if (!tableEnabled) head.classList.add('tva-acl-side__head--off');

        const safeTable = table.replace(/'/g, "\\'");
        const titleIcon = tableEnabled
            ? '<i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Column access'
            : '<i data-lucide="lock" class="w-3.5 h-3.5"></i> Table is disabled';
        const subText = tableEnabled
            ? `<strong>${allowedSet.size}</strong> of <strong>${state.allColumns.length}</strong> columns visible to AI` +
              (hiddenCount > 0 ? ` · <span style="color:#b91c1c; font-weight:600;">${hiddenCount} hidden</span>` : '')
            : 'Turn on the table on the left to use column-level rules.';

        head.innerHTML = `
            <div class="tva-acl-side__title">${titleIcon}</div>
            <div class="tva-acl-side__name">${table}</div>
            <div class="tva-acl-side__sub">${subText}</div>
            <div class="tva-acl-side__actions">
                <button type="button" class="tva-acl-toolbar__btn tva-acl-toolbar__btn--primary"
                        onclick="aclToggleAllCols('${safeTable}', true)" ${tableEnabled ? '' : 'disabled'}>
                    Select all
                </button>
                <button type="button" class="tva-acl-toolbar__btn"
                        onclick="aclToggleAllCols('${safeTable}', false)" ${tableEnabled ? '' : 'disabled'}>
                    Unselect all
                </button>
            </div>
        `;

        body.innerHTML = `
            <div class="tva-acl-cols-list">
                ${state.colMeta.map(meta => {
                    const isChecked = allowedSet.has(meta.name);
                    const safeCol   = meta.name.replace(/'/g, "\\'");
                    return `
                        <label class="tva-acl-col">
                            <input type="checkbox"
                                ${isChecked ? 'checked' : ''}
                                ${tableEnabled ? '' : 'disabled'}
                                onchange="aclToggleColumn('${safeTable}', '${safeCol}', this.checked)">
                            <span class="tva-acl-col__name">
                                ${meta.name}
                                ${meta.is_pk ? '<span class="tva-acl-col__pk-badge">pk</span>' : ''}
                            </span>
                            <span class="tva-acl-col__type">${meta.type || 'col'}</span>
                        </label>
                    `;
                }).join('')}
            </div>
        `;

        if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}
    }

    function aclToggleColumn(table, col, checked) {
        const state = aclColumnState[table];
        // First touch — materialise the allowlist from "everything"
        // so we can subtract from it.
        if (state.allowed === null) {
            state.allowed = state.allColumns.slice();
        }
        const i = state.allowed.indexOf(col);
        if (checked && i === -1) state.allowed.push(col);
        if (!checked && i !== -1) state.allowed.splice(i, 1);

        const row = document.querySelector(`.tva-acl-row[data-table="${CSS.escape(table)}"]`);
        if (row) {
            aclUpdateRowBadge(row, state.allColumns.length - state.allowed.length);
            aclOpenColumns(row);
        }
        aclRefreshStats();
    }

    function aclToggleAllCols(table, selectAll) {
        const state = aclColumnState[table];
        state.allowed = selectAll ? state.allColumns.slice() : [];
        const row = document.querySelector(`.tva-acl-row[data-table="${CSS.escape(table)}"]`);
        if (row) {
            aclUpdateRowBadge(row, state.allColumns.length - state.allowed.length);
            aclOpenColumns(row);
        }
        aclRefreshStats();
    }

    document.getElementById('aclAllowAll').addEventListener('click', function () {
        document.querySelectorAll('input[name="allowed_tables[]"]').forEach(cb => {
            cb.checked = true;
            const r = cb.closest('.tva-acl-row');
            r.classList.add('is-allowed');
            r.classList.remove('is-off');
        });
        aclRefreshStats();
    });

    document.getElementById('aclDenyAll').addEventListener('click', function () {
        document.querySelectorAll('input[name="allowed_tables[]"]').forEach(cb => {
            cb.checked = false;
            const r = cb.closest('.tva-acl-row');
            r.classList.remove('is-allowed');
            r.classList.add('is-off');
            const t = r.dataset.table;
            if (aclColumnState[t]) {
                aclColumnState[t].allowed = null;
                aclUpdateRowBadge(r, 0);
            }
        });
        aclRefreshStats();
    });

    // Live filter
    document.getElementById('aclSearch').addEventListener('input', function (e) {
        const q = e.target.value.toLowerCase().trim();
        document.querySelectorAll('.tva-acl-row').forEach(row => {
            const t = (row.dataset.table || '').toLowerCase();
            row.style.display = (!q || t.includes(q)) ? '' : 'none';
        });
    });

    // Serialise column-allowlists into hidden inputs on submit
    document.getElementById('aclForm').addEventListener('submit', function () {
        this.querySelectorAll('input[data-acl-cols]').forEach(n => n.remove());
        Object.entries(aclColumnState).forEach(([table, state]) => {
            if (state.allowed === null) return;  // null = no rule
            state.allowed.forEach(col => {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = `allowed_columns[${table}][]`;
                i.value = col;
                i.setAttribute('data-acl-cols', '1');
                this.appendChild(i);
            });
        });
    });

    aclRefreshStats();
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}
</script>
@endsection
