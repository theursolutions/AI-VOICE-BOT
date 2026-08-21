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
    /* Presented as a MODAL, not a page.
       The requested feature's page must not render — that is the whole point of
       the gate — but showing a bare card instead reads as though the app broke.
       A dimmed backdrop over the workspace shell says "your app is still there,
       this one thing needs a plan", which is both truer and easier to leave. */
    .pu-wrap {
        position:fixed; inset:0; z-index:60; display:flex;
        align-items:center; justify-content:center; padding:24px;
        background:rgba(15,23,42,.55); backdrop-filter:blur(3px);
        animation:puFade .18s ease-out;
    }
    @keyframes puFade { from { opacity:0 } to { opacity:1 } }
    @keyframes puRise { from { opacity:0; transform:translateY(10px) scale(.985) } to { opacity:1; transform:none } }

    .pu-card {
        max-width:560px; width:100%; text-align:center; background:#fff;
        border:1px solid rgba(255,255,255,.7); border-radius:20px; padding:38px 34px 32px;
        box-shadow:0 32px 70px -24px rgba(2,6,23,.55), 0 2px 6px rgba(2,6,23,.12);
        animation:puRise .22s cubic-bezier(.2,.8,.2,1) both;
        max-height:calc(100vh - 48px); overflow-y:auto;
    }
    .pu-badge {
        display:inline-flex; align-items:center; gap:7px;
        background:#eef2ff; color:#4338ca; font-size:11px; font-weight:800;
        letter-spacing:.12em; text-transform:uppercase;
        padding:6px 14px; border-radius:999px; margin-bottom:22px;
    }
    .pu-icon {
        position:relative;
        width:78px; height:78px; margin:0 auto 20px; border-radius:22px;
        display:flex; align-items:center; justify-content:center;
        background:linear-gradient(135deg,#eef2ff,#e0e7ff); color:#6366f1;
    }
    /* The padlock is drawn here rather than named as an icon: the built lucide
       bundle is older than the installed package and does not carry every name
       it does, which has already caused one wrong diagnosis. A mask needs no
       library at all. */
    .pu-icon::after {
        content:''; position:absolute; right:-6px; bottom:-6px;
        width:30px; height:30px; border-radius:50%;
        background:#4f46e5; border:3px solid #fff;
        box-shadow:0 4px 12px -4px rgba(79,70,229,.9);
        -webkit-mask:none; mask:none;
    }
    .pu-icon::before {
        content:''; position:absolute; right:1px; bottom:1px; z-index:1;
        width:16px; height:16px; background-color:#fff;
        -webkit-mask:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='11' width='18' height='11' rx='2'/><path d='M7 11V7a5 5 0 0 1 10 0v4'/></svg>") center/contain no-repeat;
                mask:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='11' width='18' height='11' rx='2'/><path d='M7 11V7a5 5 0 0 1 10 0v4'/></svg>") center/contain no-repeat;
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
    html.dark .pu-icon::after { border-color:#1e293b; }
    html.dark .pu-title, html.dark .pu-plan__name, html.dark .pu-plan__amt { color:#f1f5f9; }
    html.dark .pu-text { color:#94a3b8; }
    html.dark .pu-plan { background:#0f172a; border-color:#334155; }
    html.dark .pu-btn--ghost { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="pu-wrap">
    <div class="pu-card intro-y">
        <div class="pu-badge">
            <i data-lucide="wand" class="w-3.5 h-3.5"></i> Plan upgrade
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
                {{-- Straight to the checkout form for the plan that actually
                     unlocks this — a generic "see plans" link makes the customer
                     do the matching themselves. Only slug + interval travel.

                     A GET to our own checkout page rather than a POST to the
                     hosted-session starter: that route now redirects here
                     anyway, so posting to it only added a hop, and this is the
                     page the payment is completed on. --}}
                <a href="{{ route('billing.checkout', ['client' => $slug, 'plan' => $requiredPlan->slug, 'interval' => 'monthly']) }}"
                   class="pu-btn">
                    <i data-lucide="arrow-up-circle" class="w-4 h-4"></i>
                    Upgrade to {{ $requiredPlan->name }}
                </a>
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
