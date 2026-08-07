@extends('layouts.master')

@section('content')
@php
    $label = $moduleLabel ?? 'This section';
    $client = request()->route('client');
    $slug   = is_object($client) ? $client->slug : ($client ?? null);
@endphp

<style>
    .ud-wrap {
        min-height: calc(100vh - 9rem);
        display:flex; align-items:center; justify-content:center; padding:24px;
    }
    .ud-card {
        max-width: 540px; width:100%; text-align:center;
        background:#fff; border:1px solid #e2e8f0; border-radius:18px;
        padding:42px 36px; box-shadow:0 18px 48px -20px rgba(2,6,23,.25);
    }
    .ud-badge {
        display:inline-flex; align-items:center; gap:7px;
        background: var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
        color:#fff; font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase;
        padding:6px 14px; border-radius:999px; margin-bottom:22px;
    }
    .ud-icon {
        width:88px; height:88px; margin:0 auto 22px; border-radius:24px;
        display:flex; align-items:center; justify-content:center;
        background: color-mix(in srgb, var(--tva-primary, #6366f1) 12%, transparent);
        color: var(--tva-primary, #6366f1);
    }
    .ud-title { font-size:24px; font-weight:800; color:#0f172a; margin-bottom:10px; }
    .ud-text  { font-size:14.5px; color:#64748b; line-height:1.6; margin-bottom:26px; }
    .ud-btn {
        display:inline-flex; align-items:center; gap:8px;
        background: var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
        color:#fff; font-weight:600; font-size:14px;
        padding:11px 22px; border-radius:12px; text-decoration:none;
        transition: filter .15s, transform .05s;
    }
    .ud-btn:hover { filter:brightness(1.07); }
    .ud-btn:active { transform: translateY(1px); }

    html.dark .ud-card { background:#1e293b; border-color:#334155; box-shadow:0 18px 48px -20px rgba(0,0,0,.6); }
    html.dark .ud-title { color:#f1f5f9; }
    html.dark .ud-text  { color:#94a3b8; }
</style>

<div class="ud-wrap">
    <div class="ud-card intro-y">
        <div class="ud-badge">
            <i data-lucide="hard-hat" class="w-3.5 h-3.5"></i> Coming soon
        </div>
        <div class="ud-icon">
            <i data-lucide="construction" class="w-11 h-11"></i>
        </div>
        <h1 class="ud-title">{{ $label }} is under development</h1>
        <p class="ud-text">
            We're putting the finishing touches on this section. It isn't available just yet —
            check back soon. Everything else in your workspace works as normal.
        </p>
        <a href="{{ $slug ? route('dashboard', ['client' => $slug]) : url('/dashboard') }}" class="ud-btn">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to dashboard
        </a>
    </div>
</div>
@endsection
