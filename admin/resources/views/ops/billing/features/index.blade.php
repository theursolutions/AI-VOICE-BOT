@extends('layouts.ops')

@section('content')
<style>
    .ft-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-bottom:16px; }
    .ft-card__title {
        font-size:12px; font-weight:800; color:#0f172a; text-transform:uppercase; letter-spacing:.07em;
        display:flex; align-items:center; gap:8px; margin-bottom:16px;
        padding-bottom:12px; border-bottom:1px solid #e2e8f0;
    }
    .ft-scroll { overflow-x:auto; }
    .ft-matrix { width:100%; border-collapse:collapse; font-size:13px; min-width:760px; }
    .ft-matrix th, .ft-matrix td { padding:9px 10px; border-bottom:1px solid #f1f5f9; text-align:left; vertical-align:top; }
    .ft-matrix thead th {
        font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8;
        font-weight:700; position:sticky; top:0; background:#fff; z-index:1;
    }
    .ft-matrix td:not(:first-child), .ft-matrix thead th:not(:first-child) { text-align:center; }
    .ft-grp td {
        background:#f8fafc; font-size:10.5px; font-weight:800; letter-spacing:.08em;
        text-transform:uppercase; color:#6366f1;
    }
    .ft-name { font-weight:600; color:#0f172a; }
    .ft-key  { font-family:ui-monospace,monospace; font-size:10.5px; color:#94a3b8; }
    .ft-matrix input[type=text] {
        width:96px; border:1px solid #e2e8f0; border-radius:7px; padding:5px 8px;
        font-size:12.5px; text-align:center; background:#fff; color:#0f172a;
    }
    .ft-tag { font-size:9.5px; font-weight:700; padding:2px 6px; border-radius:5px; background:#f1f5f9; color:#475569; }

    .ft-btn {
        display:inline-flex; align-items:center; gap:6px; cursor:pointer; text-decoration:none;
        font-size:12.5px; font-weight:600; padding:8px 14px; border-radius:9px;
        border:1px solid #e2e8f0; background:#fff; color:#334155;
    }
    .ft-btn--primary { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); color:#fff; border-color:transparent; }
    .ft-btn--danger { border-color:#fecaca; color:#b91c1c; background:#fff; }

    .ft-form-grid { display:grid; gap:14px; grid-template-columns:1fr; }
    @media (min-width:900px){ .ft-form-grid { grid-template-columns:repeat(4,1fr); } }
    .ft-label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:.06em; font-weight:700; margin-bottom:5px; display:block; }
    .ft-input, .ft-select {
        width:100%; border:1px solid #e2e8f0; border-radius:9px; padding:8px 10px;
        font-size:13px; background:#fff; color:#0f172a;
    }
    .ft-help { font-size:11px; color:#94a3b8; margin-top:10px; line-height:1.55; }

    html.dark .ft-card { background:#1e293b; border-color:#334155; }
    html.dark .ft-card__title, html.dark .ft-name { color:#f1f5f9; }
    html.dark .ft-matrix th, html.dark .ft-matrix td { border-color:#334155; }
    html.dark .ft-matrix thead th { background:#1e293b; }
    html.dark .ft-grp td { background:#172033; }
    html.dark .ft-matrix input, html.dark .ft-input, html.dark .ft-select { background:#0f172a; border-color:#334155; color:#e2e8f0; }
    html.dark .ft-btn { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🎚️</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Features &amp; limits</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                One grid for every plan's entitlements and quotas. A blank cell means the plan does
                <strong>not</strong> include that feature.
            </div>
        </div>
        <a href="{{ route('ops.billing.plans.index') }}" class="ft-btn">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Plans
        </a>
    </div>

    {{-- ── The matrix ─────────────────────────────────────────────── --}}
    <form method="POST" action="{{ route('ops.billing.features.matrix') }}" class="mt-5">
        @csrf
        <div class="ft-card">
            <div class="ft-card__title"><i data-lucide="grid" class="w-4 h-4"></i> Plan × feature matrix</div>

            <div class="ft-scroll">
                <table class="ft-matrix">
                    <thead>
                        <tr>
                            <th style="min-width:230px">Feature</th>
                            @foreach ($plans as $plan)
                                <th>
                                    {{ $plan->name }}
                                    @unless ($plan->is_active)
                                        <div style="font-weight:600;color:#b91c1c;text-transform:none;letter-spacing:0">hidden</div>
                                    @endunless
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php $group = null; @endphp
                        @foreach ($features as $feature)
                            @if ($feature->group !== $group)
                                @php $group = $feature->group; @endphp
                                <tr class="ft-grp"><td colspan="{{ 1 + $plans->count() }}">{{ $group ?: 'General' }}</td></tr>
                            @endif

                            <tr>
                                <td>
                                    <div class="ft-name">{{ $feature->name }}</div>
                                    <div class="ft-key">{{ $feature->key }}</div>
                                    <div style="margin-top:4px">
                                        @if ($feature->module_key)
                                            <span class="ft-tag" title="Gates this admin module">🔒 {{ $feature->module_key }}</span>
                                        @endif
                                        @if ($feature->metric_key)
                                            <span class="ft-tag" title="Enforced as a usage quota">📊 {{ $feature->metric_key }}</span>
                                        @endif
                                        <span class="ft-tag">{{ $valueTypes[$feature->value_type] ?? $feature->value_type }}</span>
                                    </div>
                                </td>

                                @foreach ($plans as $plan)
                                    @php $current = $matrix[$feature->id][$plan->id] ?? null; @endphp
                                    <td>
                                        @if ($feature->value_type === 'boolean' || $feature->value_type === 'unlimited')
                                            {{-- Hidden 0 first: an unchecked box still submits, and the
                                                 service deletes "0" rows. Missing row = not granted. --}}
                                            <input type="hidden" name="values[{{ $plan->id }}][{{ $feature->id }}]" value="0">
                                            <input type="checkbox" name="values[{{ $plan->id }}][{{ $feature->id }}]" value="1"
                                                   @checked($feature->value_type === 'unlimited' ? $current !== null : filter_var($current, FILTER_VALIDATE_BOOLEAN))>
                                        @else
                                            <input type="text" name="values[{{ $plan->id }}][{{ $feature->id }}]"
                                                   value="{{ $current }}"
                                                   placeholder="{{ $feature->value_type === 'numeric' ? '—' : 'text' }}">
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach

                        @if ($features->isEmpty())
                            <tr>
                                <td colspan="{{ 1 + $plans->count() }}" style="color:#94a3b8;padding:20px">
                                    No features defined yet. Add one below, or run
                                    <code>php artisan db:seed --class=BillingSeeder</code>.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <p class="ft-help">
                Numeric cells: a number is the ceiling, <code>-1</code> means unlimited, and blank means the
                plan doesn't include it at all. <strong>Blank and 0 are different</strong> — 0 grants the
                feature with a zero allowance (which is how “telephony, but no minutes” would be expressed),
                blank withholds it entirely.
            </p>

            <div style="display:flex;justify-content:flex-end;margin-top:14px">
                <button type="submit" class="ft-btn ft-btn--primary">
                    <i data-lucide="save" class="w-4 h-4"></i> Save all limits
                </button>
            </div>
        </div>
    </form>

    {{-- ── Add a feature ──────────────────────────────────────────── --}}
    <div class="ft-card">
        <div class="ft-card__title"><i data-lucide="plus-circle" class="w-4 h-4"></i> Add a feature</div>

        <form method="POST" action="{{ route('ops.billing.features.store') }}">
            @csrf
            <div class="ft-form-grid">
                <div>
                    <label class="ft-label" for="f-name">Display name</label>
                    <input class="ft-input" id="f-name" name="name" required maxlength="150" placeholder="AI conversations">
                </div>
                <div>
                    <label class="ft-label" for="f-type">Value type</label>
                    <select class="ft-select" id="f-type" name="value_type">
                        @foreach ($valueTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ft-label" for="f-unit">Unit</label>
                    <input class="ft-input" id="f-unit" name="unit" maxlength="40" placeholder="conversations / seats / GB">
                </div>
                <div>
                    <label class="ft-label" for="f-group">Group heading</label>
                    <input class="ft-input" id="f-group" name="group" maxlength="80" placeholder="Volume">
                </div>

                <div>
                    <label class="ft-label" for="f-module">Gate a module <span style="text-transform:none">(optional)</span></label>
                    <select class="ft-select" id="f-module" name="module_key">
                        <option value="">— none —</option>
                        @foreach ($moduleKeys as $key => $cfg)
                            <option value="{{ $key }}">{{ $cfg['label'] ?? $key }} ({{ $key }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ft-label" for="f-metric">Cap a usage meter <span style="text-transform:none">(optional)</span></label>
                    <select class="ft-select" id="f-metric" name="metric_key">
                        <option value="">— none —</option>
                        @foreach ($metricKeys as $key => $meta)
                            <option value="{{ $key }}">{{ $meta['label'] ?? $key }} ({{ $key }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ft-label" for="f-sort">Sort order</label>
                    <input class="ft-input" id="f-sort" name="sort_order" type="number" min="0" max="9999" value="0">
                </div>
                <div style="display:flex;flex-direction:column;gap:8px;justify-content:flex-end">
                    <label style="font-size:12.5px;display:flex;gap:7px;align-items:center">
                        <input type="hidden" name="is_visible" value="0">
                        <input type="checkbox" name="is_visible" value="1" checked> In comparison table
                    </label>
                    <label style="font-size:12.5px;display:flex;gap:7px;align-items:center">
                        <input type="hidden" name="is_headline" value="0">
                        <input type="checkbox" name="is_headline" value="1"> Bullet on the plan card
                    </label>
                </div>
            </div>

            <div style="margin-top:14px">
                <label class="ft-label" for="f-desc">Description</label>
                <input class="ft-input" id="f-desc" name="description" maxlength="500"
                       placeholder="Shown under the feature name in the admin matrix.">
            </div>

            <p class="ft-help">
                <strong>Gate a module</strong> makes this feature control access to that admin section
                (the same keys the Roles matrix uses, so entitlements and permissions can't drift).
                <strong>Cap a usage meter</strong> turns a numeric value into an enforced quota.
                Neither is required — a feature with both blank is display-only marketing copy.
            </p>

            <div style="display:flex;justify-content:flex-end;margin-top:8px">
                <button type="submit" class="ft-btn ft-btn--primary">
                    <i data-lucide="plus" class="w-4 h-4"></i> Add feature
                </button>
            </div>
        </form>
    </div>

    {{-- ── Catalogue ──────────────────────────────────────────────── --}}
    @if ($features->isNotEmpty())
        <div class="ft-card">
            <div class="ft-card__title"><i data-lucide="list" class="w-4 h-4"></i> Feature catalogue</div>

            <div class="ft-scroll">
                <table class="ft-matrix" style="min-width:640px">
                    <thead>
                        <tr>
                            <th>Feature</th><th>Type</th><th>Module</th><th>Metric</th><th>Shown</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($features as $feature)
                            <tr>
                                <td>
                                    <div class="ft-name">{{ $feature->name }}</div>
                                    <div class="ft-key">{{ $feature->key }}</div>
                                </td>
                                <td>{{ $valueTypes[$feature->value_type] ?? $feature->value_type }}</td>
                                <td>{{ $feature->module_key ?: '—' }}</td>
                                <td>{{ $feature->metric_key ?: '—' }}</td>
                                <td>
                                    {{ $feature->is_visible ? 'table' : '' }}
                                    {{ $feature->is_headline ? ' + card' : '' }}
                                    {{ ! $feature->is_visible && ! $feature->is_headline ? 'hidden' : '' }}
                                </td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('ops.billing.features.destroy', ['id' => $feature->id]) }}"
                                          onsubmit="return confirm('Delete “{{ $feature->name }}” and remove it from every plan?\n\nAny code checking this feature key will start returning “not granted”.');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ft-btn ft-btn--danger">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="ft-help">
                A feature's <code>key</code> can't be renamed — application code and the entitlement cache
                reference it. Change the display name instead.
            </p>
        </div>
    @endif
</div>
@endsection
