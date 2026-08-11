@extends('layouts.ops')

@section('content')
@php $isNew = ! $plan->exists; @endphp

<style>
    .pe-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; margin-bottom:16px; }
    .pe-card__title {
        font-size:12px; font-weight:800; color:#0f172a; text-transform:uppercase; letter-spacing:.07em;
        display:flex; align-items:center; gap:8px; margin-bottom:16px;
        padding-bottom:12px; border-bottom:1px solid #e2e8f0;
    }
    .pe-grid { display:grid; gap:16px; grid-template-columns:1fr; }
    @media (min-width:800px){ .pe-grid--2 { grid-template-columns:1fr 1fr; } .pe-grid--3 { grid-template-columns:repeat(3,1fr); } }

    .pe-group { margin-bottom:0; }
    .pe-label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:.06em; font-weight:700; margin-bottom:6px; display:block; }
    .pe-help  { font-size:11px; color:#94a3b8; margin-top:5px; line-height:1.5; }
    .pe-input, .pe-select, .pe-textarea {
        width:100%; border:1px solid #e2e8f0; border-radius:9px; padding:9px 11px;
        font-size:13.5px; background:#fff; color:#0f172a;
    }
    .pe-textarea { min-height:76px; resize:vertical; }
    .pe-check { display:flex; align-items:flex-start; gap:9px; font-size:13px; color:#334155; }
    .pe-check input { margin-top:3px; }

    .pe-feat { width:100%; border-collapse:collapse; font-size:13px; }
    .pe-feat th, .pe-feat td { text-align:left; padding:9px 10px; border-bottom:1px solid #f1f5f9; }
    .pe-feat th { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
    .pe-feat__name { font-weight:600; color:#0f172a; }
    .pe-feat__key { font-family:ui-monospace,monospace; font-size:10.5px; color:#94a3b8; }
    .pe-feat__grp td {
        background:#f8fafc; font-size:10.5px; font-weight:800; letter-spacing:.08em;
        text-transform:uppercase; color:#6366f1;
    }
    .pe-feat input[type=text], .pe-feat input[type=number] {
        width:150px; border:1px solid #e2e8f0; border-radius:8px; padding:6px 9px; font-size:12.5px;
        background:#fff; color:#0f172a;
    }
    .pe-tag { font-size:10px; font-weight:700; padding:2px 6px; border-radius:5px; background:#f1f5f9; color:#475569; }

    .pe-btn {
        display:inline-flex; align-items:center; gap:7px; cursor:pointer; text-decoration:none;
        font-size:13.5px; font-weight:600; padding:10px 18px; border-radius:10px;
        border:1px solid #e2e8f0; background:#fff; color:#334155;
    }
    .pe-btn--primary { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); color:#fff; border-color:transparent; }
    .pe-bar { display:flex; gap:10px; justify-content:flex-end; margin-top:4px; }

    html.dark .pe-card { background:#1e293b; border-color:#334155; }
    html.dark .pe-card__title, html.dark .pe-feat__name { color:#f1f5f9; }
    html.dark .pe-input, html.dark .pe-select, html.dark .pe-textarea,
    html.dark .pe-feat input { background:#0f172a; border-color:#334155; color:#e2e8f0; }
    html.dark .pe-feat th, html.dark .pe-feat td { border-color:#334155; }
    html.dark .pe-feat__grp td { background:#172033; }
    html.dark .pe-btn { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">{{ $isNew ? '✨' : '✏️' }}</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">{{ $isNew ? 'New plan' : 'Edit ' . $plan->name }}</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                @if ($isNew)
                    Create the plan first, then add its prices and set its limits.
                @else
                    Name, copy, trial and limits. Prices are managed on the
                    <a href="{{ route('ops.billing.plans.index') }}" style="color:#fff;text-decoration:underline">Plans list</a>
                    so price versioning stays in one place.
                @endif
            </div>
        </div>
        <a href="{{ route('ops.billing.plans.index') }}" class="pe-btn">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back
        </a>
    </div>

    <form method="POST"
          action="{{ $isNew ? route('ops.billing.plans.store') : route('ops.billing.plans.update', ['id' => $plan->id]) }}"
          class="mt-5">
        @csrf
        @unless ($isNew) @method('PATCH') @endunless

        {{-- ── Identity ───────────────────────────────────────────── --}}
        <div class="pe-card">
            <div class="pe-card__title"><i data-lucide="tag" class="w-4 h-4"></i> Identity</div>

            <div class="pe-grid pe-grid--2">
                <div class="pe-group">
                    <label class="pe-label" for="name">Plan name</label>
                    <input class="pe-input" id="name" name="name" required maxlength="100"
                           value="{{ old('name', $plan->name) }}" placeholder="Growth">
                </div>

                <div class="pe-group">
                    <label class="pe-label" for="type">Type</label>
                    <select class="pe-select" id="type" name="type">
                        @foreach (['standard' => 'Standard (paid, self-serve)',
                                   'free' => 'Free (no Stripe price)',
                                   'enterprise' => 'Enterprise (contact-us CTA)',
                                   'custom' => 'Custom (private, negotiated)'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('type', $plan->type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="pe-help">
                        <strong>Free</strong> can never have a price and is the only type that uses the free
                        window. <strong>Enterprise</strong> renders as a CTA band rather than a price card.
                    </p>
                </div>
            </div>

            @if ($isNew)
                <div class="pe-group" style="margin-top:16px">
                    <label class="pe-label" for="slug">Slug <span style="text-transform:none">(optional — generated from the name)</span></label>
                    <input class="pe-input" id="slug" name="slug" maxlength="100"
                           value="{{ old('slug') }}" placeholder="growth">
                    <p class="pe-help">
                        Permanent. It's the identifier checkout forms submit and it's stamped into Stripe
                        metadata, so it can't be changed later without breaking in-flight sessions.
                    </p>
                </div>
            @endif

            <div class="pe-group" style="margin-top:16px">
                <label class="pe-label" for="tagline">Tagline</label>
                <input class="pe-input" id="tagline" name="tagline" maxlength="255"
                       value="{{ old('tagline', $plan->tagline) }}"
                       placeholder="Everything in one place, for a growing business">
                <p class="pe-help">One line under the plan name on the pricing card.</p>
            </div>

            <div class="pe-group" style="margin-top:16px">
                <label class="pe-label" for="description">Description</label>
                <textarea class="pe-textarea" id="description" name="description" maxlength="2000">{{ old('description', $plan->description) }}</textarea>
            </div>
        </div>

        {{-- ── Presentation ───────────────────────────────────────── --}}
        <div class="pe-card">
            <div class="pe-card__title"><i data-lucide="eye" class="w-4 h-4"></i> Presentation</div>

            <div class="pe-grid pe-grid--3">
                <div class="pe-group">
                    <label class="pe-label" for="badge">Badge</label>
                    <input class="pe-input" id="badge" name="badge" maxlength="40"
                           value="{{ old('badge', $plan->badge) }}" placeholder="Most popular">
                </div>
                <div class="pe-group">
                    <label class="pe-label" for="cta_label">Button label</label>
                    <input class="pe-input" id="cta_label" name="cta_label" maxlength="60"
                           value="{{ old('cta_label', $plan->cta_label) }}" placeholder="Get started">
                </div>
                <div class="pe-group">
                    <label class="pe-label" for="sort_order">Display order</label>
                    <input class="pe-input" id="sort_order" name="sort_order" type="number" min="0" max="9999"
                           value="{{ old('sort_order', $plan->sort_order ?? 0) }}">
                </div>
            </div>

            <div class="pe-group" style="margin-top:16px">
                <label class="pe-label" for="cta_url">Button URL <span style="text-transform:none">(enterprise / custom only)</span></label>
                <input class="pe-input" id="cta_url" name="cta_url" maxlength="255"
                       value="{{ old('cta_url', $plan->cta_url) }}" placeholder="/contact">
            </div>

            <div class="pe-grid pe-grid--2" style="margin-top:18px">
                <label class="pe-check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active ?? true))>
                    <span>
                        <strong>On sale</strong>
                        <span class="pe-help" style="display:block;margin-top:2px">
                            Off hides it from new signups. Existing subscribers keep their subscription and
                            keep being billed.
                        </span>
                    </span>
                </label>

                <label class="pe-check">
                    <input type="hidden" name="is_public" value="0">
                    <input type="checkbox" name="is_public" value="1" @checked(old('is_public', $plan->is_public ?? true))>
                    <span>
                        <strong>Show on the public pricing page</strong>
                        <span class="pe-help" style="display:block;margin-top:2px">
                            Off makes it link-only — the way to run a negotiated deal without listing it.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        {{-- ── Free window & trial ────────────────────────────────── --}}
        <div class="pe-card">
            <div class="pe-card__title"><i data-lucide="clock" class="w-4 h-4"></i> Free window &amp; trial</div>

            <div class="pe-grid pe-grid--2">
                <div class="pe-group">
                    <label class="pe-label" for="free_window_days">Free window (days) — free plans only</label>
                    <input class="pe-input" id="free_window_days" name="free_window_days" type="number" min="0" max="365"
                           value="{{ old('free_window_days', $plan->free_window_days) }}" placeholder="7">
                    <p class="pe-help">
                        Days of no-card access before the workspace degrades to
                        <strong>{{ config('billing.free.on_expiry') }}</strong>.
                        <strong>Leave blank for a permanent free tier.</strong>
                        Currently 7 — the approved model, where the free week replaces a paid trial.
                    </p>
                </div>

                <div class="pe-group">
                    <label class="pe-label" for="trial_days">Trial (days) — paid plans only</label>
                    <input class="pe-input" id="trial_days" name="trial_days" type="number" min="0" max="365"
                           value="{{ old('trial_days', $plan->trial_days ?? 0) }}">
                    <p class="pe-help">
                        0 under the approved model — the 7-day free window is the trial. Set a number here to
                        switch a Stripe trial back on for this plan; no deploy needed.
                    </p>

                    <label class="pe-check" style="margin-top:12px">
                        <input type="hidden" name="trial_requires_payment_method" value="0">
                        <input type="checkbox" name="trial_requires_payment_method" value="1"
                               @checked(old('trial_requires_payment_method', $plan->trial_requires_payment_method ?? true))>
                        <span>
                            <strong>Require a card to start the trial</strong>
                            <span class="pe-help" style="display:block;margin-top:2px">
                                Recommended when a trial gives away real cost (voice minutes). The card is also
                                the strongest abuse control — Stripe's card fingerprint is stable across
                                customers.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        {{-- ── Features & limits ──────────────────────────────────── --}}
        @unless ($isNew)
            <div class="pe-card">
                <div class="pe-card__title"><i data-lucide="sliders" class="w-4 h-4"></i> Features &amp; limits</div>

                <table class="pe-feat">
                    <thead>
                        <tr><th>Feature</th><th style="width:190px">Value for this plan</th><th style="width:160px">Wiring</th></tr>
                    </thead>
                    <tbody>
                        @php $group = null; @endphp
                        @foreach ($features as $feature)
                            @if ($feature->group !== $group)
                                @php $group = $feature->group; @endphp
                                <tr class="pe-feat__grp"><td colspan="3">{{ $group ?: 'General' }}</td></tr>
                            @endif

                            @php $current = $values[$feature->id] ?? null; @endphp
                            <tr>
                                <td>
                                    <div class="pe-feat__name">{{ $feature->name }}</div>
                                    <div class="pe-feat__key">{{ $feature->key }}</div>
                                    @if ($feature->description)
                                        <div class="pe-help">{{ $feature->description }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($feature->value_type === 'boolean')
                                        {{-- Hidden 0 + checkbox: an unchecked box submits "0", which
                                             syncFeatures() deletes, and a missing row means NOT granted. --}}
                                        <input type="hidden" name="features[{{ $feature->id }}]" value="0">
                                        <label class="pe-check">
                                            <input type="checkbox" name="features[{{ $feature->id }}]" value="1"
                                                   @checked(filter_var($current, FILTER_VALIDATE_BOOLEAN))>
                                            <span>Included</span>
                                        </label>
                                    @elseif ($feature->value_type === 'numeric')
                                        <input type="text" name="features[{{ $feature->id }}]"
                                               value="{{ $current }}" placeholder="e.g. 5000 or -1">
                                        <div class="pe-help">Blank = not included · <code>-1</code> = unlimited</div>
                                    @elseif ($feature->value_type === 'unlimited')
                                        <input type="hidden" name="features[{{ $feature->id }}]" value="0">
                                        <label class="pe-check">
                                            <input type="checkbox" name="features[{{ $feature->id }}]" value="1"
                                                   @checked($current !== null)>
                                            <span>Unlimited</span>
                                        </label>
                                    @else
                                        <input type="text" name="features[{{ $feature->id }}]"
                                               value="{{ $current }}" placeholder="Priority email">
                                    @endif
                                </td>
                                <td>
                                    @if ($feature->module_key)
                                        <span class="pe-tag" title="Gates this admin module">
                                            <i data-lucide="lock" style="width:10px;height:10px;display:inline"></i>
                                            {{ $feature->module_key }}
                                        </span>
                                    @endif
                                    @if ($feature->metric_key)
                                        <span class="pe-tag" title="Enforced as a usage quota">
                                            <i data-lucide="gauge" style="width:10px;height:10px;display:inline"></i>
                                            {{ $feature->metric_key }}
                                        </span>
                                    @endif
                                    @if (! $feature->module_key && ! $feature->metric_key)
                                        <span class="pe-help">display only</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="pe-help" style="margin-top:14px">
                    A blank value means the plan does <strong>not</strong> include the feature — that's why adding a
                    new feature never silently grants it to every existing plan. Features flagged
                    <em>module</em> gate the matching admin section; features flagged <em>metric</em> become an
                    enforced usage cap.
                    Manage the catalogue itself in
                    <a href="{{ route('ops.billing.features.index') }}" style="color:#6366f1">Features &amp; limits</a>.
                </p>
            </div>
        @endunless

        <div class="pe-bar">
            <a href="{{ route('ops.billing.plans.index') }}" class="pe-btn">Cancel</a>
            <button type="submit" class="pe-btn pe-btn--primary">
                <i data-lucide="save" class="w-4 h-4"></i>
                {{ $isNew ? 'Create plan' : 'Save changes' }}
            </button>
        </div>
    </form>
</div>
@endsection
