@extends('layouts.master')

@section('content')
@php
    $label = $moduleLabel ?? 'This feature';
    $slug  = $client->slug ?? null;

    // The cheapest plan that unlocks this module, with its monthly price, so
    // the page can name a plan and a number instead of saying "upgrade".
    $monthly = $requiredPlan?->priceFor('monthly');
@endphp

<style>
    .pu-wrap { min-height:calc(100vh - 9rem); display:flex; align-items:center; justify-content:center; padding:24px; }
    .pu-card {
        max-width:580px; width:100%; text-align:center; background:#fff;
        border:1px solid #e2e8f0; border-radius:18px; padding:42px 36px;
        box-shadow:0 18px 48px -20px rgba(2,6,23,.22);
    }
    .pu-badge {
        display:inline-flex; align-items:center; gap:7px;
        background:#eef2ff; color:#4338ca; font-size:11px; font-weight:800;
        letter-spacing:.12em; text-transform:uppercase;
        padding:6px 14px; border-radius:999px; margin-bottom:22px;
    }
    .pu-icon {
        width:84px; height:84px; margin:0 auto 22px; border-radius:24px;
        display:flex; align-items:center; justify-content:center;
        background:#eef2ff; color:#6366f1;
    }
    .pu-title { font-size:23px; font-weight:800; color:#0f172a; margin-bottom:10px; }
    .pu-text { font-size:14.5px; color:#64748b; line-height:1.65; margin-bottom:24px; }
    .pu-plan {
        border:1px solid #e2e8f0; background:#f8fafc; border-radius:14px;
        padding:18px; margin-bottom:24px; text-align:left;
        display:flex; align-items:center; gap:16px; justify-content:space-between; flex-wrap:wrap;
    }
    .pu-plan__name { font-size:16px; font-weight:800; color:#0f172a; }
    .pu-plan__meta { font-size:12.5px; color:#64748b; margin-top:2px; }
    .pu-plan__amt { font-size:22px; font-weight:800; color:#0f172a; }
    .pu-plan__amt small { font-size:12px; font-weight:600; color:#64748b; }
    .pu-btn {
        display:inline-flex; align-items:center; gap:8px; text-decoration:none; cursor:pointer;
        background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
        color:#fff; font-weight:600; font-size:14px; padding:12px 22px;
        border:0; border-radius:12px;
    }
    .pu-btn--ghost { background:#fff; border:1px solid #e2e8f0; color:#334155; }
    .pu-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

    html.dark .pu-card { background:#1e293b; border-color:#334155; }
    html.dark .pu-title, html.dark .pu-plan__name, html.dark .pu-plan__amt { color:#f1f5f9; }
    html.dark .pu-text { color:#94a3b8; }
    html.dark .pu-plan { background:#0f172a; border-color:#334155; }
    html.dark .pu-btn--ghost { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="pu-wrap">
    <div class="pu-card intro-y">
        <div class="pu-badge">
            <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> Plan upgrade
        </div>
        <div class="pu-icon">
            <i data-lucide="lock" class="w-10 h-10"></i>
        </div>

        <h1 class="pu-title">{{ $label }} isn't in your plan yet</h1>

        <p class="pu-text">
            @if ($currentPlan)
                You're on <strong>{{ $currentPlan->name }}</strong>, which doesn't include {{ strtolower($label) }}.
            @else
                Your current plan doesn't include {{ strtolower($label) }}.
            @endif
            Everything else in your workspace keeps working as normal.
        </p>

        @if ($requiredPlan)
            <div class="pu-plan">
                <div>
                    <div class="pu-plan__name">{{ $requiredPlan->name }}</div>
                    <div class="pu-plan__meta">Includes {{ strtolower($label) }}{{ $requiredPlan->tagline ? ' · ' . $requiredPlan->tagline : '' }}</div>
                </div>
                @if ($monthly)
                    <div style="text-align:right">
                        <div class="pu-plan__amt">{{ $monthly->formatted() }}<small>/mo</small></div>
                        <div class="pu-plan__meta">charged in USD</div>
                    </div>
                @endif
            </div>
        @endif

        <div class="pu-actions">
            @if ($requiredPlan && $requiredPlan->isPurchasable())
                {{-- Straight to checkout for the plan that actually unlocks
                     this — a generic "see plans" link makes the customer do
                     the matching themselves. Only slug + interval travel. --}}
                <form method="POST" action="{{ route('billing.checkout.store', ['client' => $slug]) }}">
                    @csrf
                    <input type="hidden" name="plan" value="{{ $requiredPlan->slug }}">
                    <input type="hidden" name="interval" value="monthly">
                    <button type="submit" class="pu-btn">
                        <i data-lucide="arrow-up-circle" class="w-4 h-4"></i>
                        Upgrade to {{ $requiredPlan->name }}
                    </button>
                </form>
            @endif

            <a href="{{ $slug ? route('billing.index', ['client' => $slug]) : url('/pricing') }}" class="pu-btn pu-btn--ghost">
                <i data-lucide="list" class="w-4 h-4"></i> Compare plans
            </a>

            <a href="{{ $slug ? route('dashboard', ['client' => $slug]) : url('/dashboard') }}" class="pu-btn pu-btn--ghost">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to dashboard
            </a>
        </div>
    </div>
</div>
@endsection
