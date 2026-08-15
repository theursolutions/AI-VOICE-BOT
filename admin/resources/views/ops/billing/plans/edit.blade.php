@extends('layouts.ops')

@section('content')
@php $isNew = ! $plan->exists; @endphp

@include('ops.billing._styles')

<style>
    /* Feature editor: three fixed tracks so the name, the control and the
       wiring tags form clean columns instead of each row sizing itself. */
    .pe-feat { width:100%; border-collapse:collapse; font-size:13px; }
    .pe-feat th, .pe-feat td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .pe-feat th { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:800; text-align:left; }
    .pe-feat td:first-child, .pe-feat th:first-child { padding-left:0; }
    .pe-feat tbody tr:hover { background:#fcfcfd; }
    .pe-feat__name { font-weight:600; color:#0f172a; }
    .pe-feat__key { font-family:ui-monospace,monospace; font-size:10.5px; color:#94a3b8; margin-top:2px; }
    .pe-feat__grp td {
        background:#fffbeb; border-bottom:1px solid #fde68a;
        font-size:10.5px; font-weight:800; letter-spacing:.09em; text-transform:uppercase; color:#b45309;
    }
    .pe-feat input[type=text] { max-width:170px; }

    .pe-tag {
        font-size:9.5px; font-weight:700; padding:2px 6px; border-radius:5px;
        background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:3px; margin-right:4px;
    }
    .pe-tag--mod { background:#eef2ff; color:#4338ca; }
    .pe-tag--met { background:#ecfdf3; color:#067647; }

    .pe-bar {
        display:flex; gap:10px; justify-content:flex-end; align-items:center;
        position:sticky; bottom:0; background:#fff; border-top:1px solid #e2e8f0;
        padding:14px 0; margin-top:4px; z-index:3;
    }
    html.dark .pe-bar { background:#1e293b; border-color:#334155; }
    html.dark .pe-feat th, html.dark .pe-feat td { border-color:#334155; }
    html.dark .pe-feat tbody tr:hover { background:#0f172a; }
    html.dark .pe-feat__name { color:#f1f5f9; }
    html.dark .pe-feat__grp td { background:rgba(180,83,9,.15); border-color:rgba(253,230,138,.3); color:#fbbf24; }
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
                    Name, copy, trial and limits. Prices are managed on the Plans list, so price
                    versioning stays in one place.
                @endif
            </div>
        </div>
        <div class="ob-card__actions">
            <a href="{{ route('ops.billing.plans.index') }}" class="ob-btn">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back
            </a>
        </div>
    </div>

    <form method="POST"
          action="{{ $isNew ? route('ops.billing.plans.store') : route('ops.billing.plans.update', ['id' => $plan->id]) }}"
          class="mt-5">
        @csrf
        @unless ($isNew) @method('PATCH') @endunless

        @if ($errors->any())
            <div class="ob-note ob-note--err">
                <i data-lucide="alert-octagon" class="w-5 h-5"></i>
                <div>
                    <strong>Please fix the following:</strong>
                    <ul style="margin:6px 0 0;padding-left:18px">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- ── Identity ───────────────────────────────────────────── --}}
        <div class="ob-card">
            <div class="ob-card__head">
                <i data-lucide="tag" class="w-4 h-4" style="color:#c97a00"></i>
                <div class="ob-card__title">Identity</div>
            </div>

            <div class="ob-grid2">
                <div class="ob-field">
                    <label for="name">Plan name</label>
                    <input class="ob-input" id="name" name="name" required maxlength="100"
                           value="{{ old('name', $plan->name) }}" placeholder="Growth">
                </div>

                <div class="ob-field">
                    <label for="type">Type</label>
                    <select class="ob-select" id="type" name="type">
                        @foreach ([
                            'standard'   => 'Standard — paid, self-serve',
                            'free'       => 'Free — no Stripe price',
                            'enterprise' => 'Enterprise — contact-us CTA',
                            'custom'     => 'Custom — private, negotiated',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected(old('type', $plan->type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="ob-help">
                        <strong>Free</strong> can never have a price and is the only type that uses the free
                        window. <strong>Enterprise</strong> renders as a CTA band, not a price card.
                    </p>
                </div>
            </div>

            @if ($isNew)
                <div class="ob-field">
                    <label for="slug">Slug <span class="hint">optional — generated from the name</span></label>
                    <input class="ob-input" id="slug" name="slug" maxlength="100" value="{{ old('slug') }}" placeholder="growth">
                    <p class="ob-help">
                        Permanent. It’s the identifier checkout submits and it’s stamped into Stripe metadata,
                        so it can’t be changed later without breaking in-flight sessions.
                    </p>
                </div>
            @endif

            <div class="ob-field">
                <label for="tagline">Tagline</label>
                <input class="ob-input" id="tagline" name="tagline" maxlength="255"
                       value="{{ old('tagline', $plan->tagline) }}"
                       placeholder="Everything in one place, for a growing business">
                <p class="ob-help">One line under the plan name on the pricing card.</p>
            </div>

            <div class="ob-field" style="margin-bottom:0">
                <label for="description">Description</label>
                <textarea class="ob-textarea" id="description" name="description" maxlength="2000">{{ old('description', $plan->description) }}</textarea>
            </div>
        </div>

        {{-- ── Presentation ───────────────────────────────────────── --}}
        <div class="ob-card">
            <div class="ob-card__head">
                <i data-lucide="eye" class="w-4 h-4" style="color:#c97a00"></i>
                <div class="ob-card__title">Presentation</div>
            </div>

            <div class="ob-grid3">
                <div class="ob-field">
                    <label for="badge">Badge</label>
                    <input class="ob-input" id="badge" name="badge" maxlength="40"
                           value="{{ old('badge', $plan->badge) }}" placeholder="Most popular">
                </div>
                <div class="ob-field">
                    <label for="cta_label">Button label</label>
                    <input class="ob-input" id="cta_label" name="cta_label" maxlength="60"
                           value="{{ old('cta_label', $plan->cta_label) }}" placeholder="Get started">
                </div>
                <div class="ob-field">
                    <label for="sort_order">Display order</label>
                    <input class="ob-input ob-input--num" id="sort_order" name="sort_order" type="number"
                           min="0" max="9999" value="{{ old('sort_order', $plan->sort_order ?? 0) }}">
                </div>
            </div>

            <div class="ob-field">
                <label for="cta_url">Button URL <span class="hint">enterprise / custom only</span></label>
                <input class="ob-input" id="cta_url" name="cta_url" maxlength="255"
                       value="{{ old('cta_url', $plan->cta_url) }}" placeholder="/contact">
            </div>

            <div class="ob-grid2" style="margin-bottom:0">
                <label class="ob-check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active ?? true))>
                    <span><b>On sale</b>
                        <span class="sub">Off hides it from new signups. Existing subscribers keep their
                        subscription and keep being billed.</span></span>
                </label>

                <label class="ob-check">
                    <input type="hidden" name="is_public" value="0">
                    <input type="checkbox" name="is_public" value="1" @checked(old('is_public', $plan->is_public ?? true))>
                    <span><b>Show on the public pricing page</b>
                        <span class="sub">Off makes it link-only — how to run a negotiated deal without
                        listing it.</span></span>
                </label>
            </div>
        </div>

        {{-- ── Free window & trial ────────────────────────────────── --}}
        <div class="ob-card">
            <div class="ob-card__head">
                <i data-lucide="clock" class="w-4 h-4" style="color:#c97a00"></i>
                <div class="ob-card__title">Free window &amp; trial</div>
            </div>

            <div class="ob-grid2">
                <div class="ob-field">
                    <label for="free_window_days">Free window <span class="hint">days · free plans only</span></label>
                    <input class="ob-input ob-input--num" id="free_window_days" name="free_window_days" type="number"
                           min="0" max="365" value="{{ old('free_window_days', $plan->free_window_days) }}" placeholder="7">
                    <p class="ob-help">
                        Days of no-card access before the workspace degrades to
                        <strong>{{ config('billing.free.on_expiry') }}</strong>.
                        <strong>Leave blank for a permanent free tier.</strong>
                        Currently 7 — the approved model, where the free week replaces a paid trial.
                    </p>
                </div>

                <div class="ob-field">
                    <label for="trial_days">Trial <span class="hint">days · paid plans only</span></label>
                    <input class="ob-input ob-input--num" id="trial_days" name="trial_days" type="number"
                           min="0" max="365" value="{{ old('trial_days', $plan->trial_days ?? 0) }}">
                    <p class="ob-help">
                        0 under the approved model — the 7-day free window is the trial. Set a number to
                        switch a Stripe trial back on for this plan; no deploy needed.
                    </p>

                    <label class="ob-check" style="margin-top:12px">
                        <input type="hidden" name="trial_requires_payment_method" value="0">
                        <input type="checkbox" name="trial_requires_payment_method" value="1"
                               @checked(old('trial_requires_payment_method', $plan->trial_requires_payment_method ?? true))>
                        <span><b>Require a card to start the trial</b>
                            <span class="sub">Recommended when a trial gives away real cost. The card is also
                            the strongest abuse control — Stripe’s fingerprint is stable across
                            customers.</span></span>
                    </label>
                </div>
            </div>
        </div>

        {{-- ── Features & limits ──────────────────────────────────── --}}
        @unless ($isNew)
            <div class="ob-card">
                <div class="ob-card__head">
                    <i data-lucide="sliders" class="w-4 h-4" style="color:#c97a00"></i>
                    <div>
                        <div class="ob-card__title">Features &amp; limits</div>
                        <div class="ob-card__sub">What this plan grants. Blank means not included.</div>
                    </div>
                    <div class="ob-card__actions">
                        <a href="{{ route('ops.billing.features.index') }}" class="ob-btn ob-btn--sm">
                            <i data-lucide="grid" class="w-3.5 h-3.5"></i> All plans matrix
                        </a>
                    </div>
                </div>

                <div class="ob-tablewrap">
                    <table class="pe-feat">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                <th style="width:210px">Value for this plan</th>
                                <th style="width:190px">Wiring</th>
                            </tr>
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
                                            <div class="ob-help" style="margin-top:3px">{{ $feature->description }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($feature->value_type === 'boolean')
                                            {{-- Hidden 0 + checkbox: an unchecked box submits "0", which
                                                 syncFeatures() deletes, and a missing row means NOT granted. --}}
                                            <input type="hidden" name="features[{{ $feature->id }}]" value="0">
                                            <label class="ob-check">
                                                <input type="checkbox" name="features[{{ $feature->id }}]" value="1"
                                                       @checked(filter_var($current, FILTER_VALIDATE_BOOLEAN))>
                                                <span>Included</span>
                                            </label>
                                        @elseif ($feature->value_type === 'numeric')
                                            <input type="text" class="ob-input ob-input--sm ob-input--num"
                                                   name="features[{{ $feature->id }}]"
                                                   value="{{ $current }}" placeholder="e.g. 5000 or -1">
                                            <div class="ob-help">blank = not included · <code>-1</code> = unlimited</div>
                                        @elseif ($feature->value_type === 'unlimited')
                                            <input type="hidden" name="features[{{ $feature->id }}]" value="0">
                                            <label class="ob-check">
                                                <input type="checkbox" name="features[{{ $feature->id }}]" value="1"
                                                       @checked($current !== null)>
                                                <span>Unlimited</span>
                                            </label>
                                        @else
                                            <input type="text" class="ob-input ob-input--sm"
                                                   name="features[{{ $feature->id }}]"
                                                   value="{{ $current }}" placeholder="Priority email">
                                        @endif
                                    </td>
                                    <td>
                                        @if ($feature->module_key)
                                            <span class="pe-tag pe-tag--mod" title="Gates this admin module">
                                                <i data-lucide="lock" style="width:9px;height:9px"></i>{{ $feature->module_key }}
                                            </span>
                                        @endif
                                        @if ($feature->metric_key)
                                            <span class="pe-tag pe-tag--met" title="Enforced as a usage quota">
                                                <i data-lucide="gauge" style="width:9px;height:9px"></i>{{ $feature->metric_key }}
                                            </span>
                                        @endif
                                        @if (! $feature->module_key && ! $feature->metric_key)
                                            <span class="ob-help">display only</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="ob-help" style="margin-top:14px">
                    A blank value means the plan does <strong>not</strong> include the feature — that’s why
                    adding a new feature never silently grants it to every existing plan. Features flagged
                    <em>module</em> gate the matching admin section; features flagged <em>metric</em> become
                    an enforced usage cap.
                </p>
            </div>
        @endunless

        <div class="pe-bar">
            <a href="{{ route('ops.billing.plans.index') }}" class="ob-btn">Cancel</a>
            <button type="submit" class="ob-btn ob-btn--primary">
                <i data-lucide="save" class="w-4 h-4"></i>
                {{ $isNew ? 'Create plan' : 'Save changes' }}
            </button>
        </div>
    </form>
</div>
@endsection
