@extends('layouts.master')

@section('content')
@php
    $plan = $subscription?->plan;
    // The webhook is authoritative and may land a moment after this page.
    // Rather than claim success we can't see yet, we say "confirming" — which
    // is both honest and self-resolving on refresh.
    $confirmed = $subscription && in_array($subscription->status, ['active', 'trialing'], true);
@endphp

<style>
    .bs-wrap { min-height: calc(100vh - 12rem); display:flex; align-items:center; justify-content:center; padding:24px; }
    .bs-card {
        max-width:560px; width:100%; text-align:center; background:#fff;
        border:1px solid #e2e8f0; border-radius:18px; padding:44px 36px;
        box-shadow:0 18px 48px -20px rgba(2,6,23,.22);
    }
    .bs-icon {
        width:84px; height:84px; margin:0 auto 22px; border-radius:24px;
        display:flex; align-items:center; justify-content:center;
        background:#dcfce7; color:#16a34a;
    }
    .bs-icon--pending { background:#dbeafe; color:#2563eb; }
    .bs-title { font-size:24px; font-weight:800; color:#0f172a; margin-bottom:10px; }
    .bs-text { font-size:14.5px; color:#64748b; line-height:1.65; margin-bottom:12px; }
    .bs-plan {
        display:inline-flex; align-items:center; gap:8px; margin:6px 0 22px;
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:999px;
        padding:7px 16px; font-size:13.5px; font-weight:600; color:#334155;
    }
    .bs-btn {
        display:inline-flex; align-items:center; gap:8px; text-decoration:none;
        background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
        color:#fff; font-weight:600; font-size:14px; padding:12px 24px; border-radius:12px;
    }
    .bs-btn--ghost { background:#fff; border:1px solid #e2e8f0; color:#334155; }
    .bs-actions { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
    .bs-note { font-size:12px; color:#94a3b8; margin-top:20px; }

    html.dark .bs-card { background:#1e293b; border-color:#334155; }
    html.dark .bs-title { color:#f1f5f9; }
    html.dark .bs-text { color:#94a3b8; }
    html.dark .bs-plan { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .bs-btn--ghost { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="bs-wrap">
    <div class="bs-card intro-y">
        <div class="bs-icon {{ $confirmed ? '' : 'bs-icon--pending' }}">
            <i data-lucide="{{ $confirmed ? 'check' : 'loader' }}" class="w-10 h-10"></i>
        </div>

        @if ($confirmed)
            <h1 class="bs-title">You're all set</h1>

            @if ($plan)
                <div class="bs-plan">
                    <i data-lucide="zap" class="w-4 h-4"></i> {{ $plan->name }} plan is active
                </div>
            @endif

            <p class="bs-text">
                Payment received and your agent is live. Every channel you've connected is answering again —
                and anything you set up during your free days carried straight over.
            </p>

            @if ($subscription->nextBillingDate())
                <p class="bs-text">
                    Your next payment is {{ $subscription->nextBillingDate()->format('j M Y') }}.
                    A receipt is on its way by email.
                </p>
            @endif
        @else
            <h1 class="bs-title">Confirming your payment…</h1>
            <p class="bs-text">
                Thanks — Stripe has your payment. We're waiting on the final confirmation, which usually
                lands within a few seconds. Refresh this page in a moment and your plan will be active.
            </p>
            <p class="bs-text">
                Nothing more is needed from you, and you won't be charged twice.
            </p>
        @endif

        <div class="bs-actions">
            <a href="{{ route('dashboard', ['client' => $client->slug]) }}" class="bs-btn">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Go to dashboard
            </a>
            <a href="{{ route('billing.index', ['client' => $client->slug]) }}" class="bs-btn bs-btn--ghost">
                <i data-lucide="credit-card" class="w-4 h-4"></i> Billing details
            </a>
        </div>

        <p class="bs-note">Charged in USD · Cancel any time · Your data stays yours</p>
    </div>
</div>
@endsection
