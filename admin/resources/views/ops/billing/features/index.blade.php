@extends('layouts.ops')

@section('content')
@include('ops.billing._styles')

<style>
    /* Matrix: the feature column is sticky so plan columns stay identifiable
       while scrolling sideways, and every value cell is the same fixed width
       so the inputs form clean vertical tracks instead of jittering with
       whatever each cell happens to contain. */
    .fm-wrap { overflow-x:auto; }
    .fm { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; min-width:720px; }
    .fm th, .fm td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .fm thead th {
        position:sticky; top:0; z-index:2; background:#fff;
        font-size:10.5px; text-transform:uppercase; letter-spacing:.06em;
        color:#94a3b8; font-weight:800; text-align:center; border-bottom:2px solid #e2e8f0;
    }
    .fm thead th:first-child, .fm tbody th { text-align:left; }
    .fm .fm-col { width:132px; }
    .fm tbody th {
        position:sticky; left:0; z-index:1; background:#fff;
        min-width:250px; font-weight:500; padding-left:0;
    }
    .fm tbody tr:hover th, .fm tbody tr:hover td { background:#fcfcfd; }
    .fm td { text-align:center; }

    .fm-grp td {
        background:#fffbeb; border-bottom:1px solid #fde68a; padding:7px 12px;
        font-size:10.5px; font-weight:800; letter-spacing:.09em; text-transform:uppercase; color:#b45309;
    }
    .fm-grp td:first-child { padding-left:0; }

    .fm-name { font-weight:600; color:#0f172a; font-size:13px; }
    .fm-key { font-family:ui-monospace,monospace; font-size:10.5px; color:#94a3b8; margin-top:2px; }
    .fm-tags { display:flex; gap:5px; flex-wrap:wrap; margin-top:5px; }
    .fm-tag {
        font-size:9.5px; font-weight:700; padding:2px 6px; border-radius:5px;
        background:#f1f5f9; color:#475569; display:inline-flex; align-items:center; gap:3px;
    }
    .fm-tag--mod { background:#eef2ff; color:#4338ca; }
    .fm-tag--met { background:#ecfdf3; color:#067647; }

    /* One consistent control per cell — the earlier version mixed raw
       checkboxes with differently-sized text inputs, so no two columns lined up. */
    .fm input[type=text] { width:100%; max-width:108px; margin:0 auto; text-align:center; }
    .fm input[type=checkbox] { width:17px; height:17px; accent-color:#c97a00; cursor:pointer; }

    .fm-legend { display:flex; gap:18px; flex-wrap:wrap; font-size:11.5px; color:#94a3b8; margin-top:14px; }
    .fm-legend b { color:#475569; font-weight:700; }

    .fm-savebar {
        display:flex; align-items:center; gap:12px; justify-content:flex-end;
        margin-top:18px; padding-top:16px; border-top:1px solid #e2e8f0;
    }
    .fm-savebar p { margin:0; margin-right:auto; }

    html.dark .fm thead th, html.dark .fm tbody th { background:#1e293b; }
    html.dark .fm th, html.dark .fm td { border-color:#334155; }
    html.dark .fm tbody tr:hover th, html.dark .fm tbody tr:hover td { background:#0f172a; }
    html.dark .fm-name { color:#f1f5f9; }
    html.dark .fm-grp td { background:rgba(180,83,9,.15); border-color:rgba(253,230,138,.3); color:#fbbf24; }
    html.dark .fm-savebar { border-color:#334155; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🎚️</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Features &amp; limits</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                One grid for every plan’s entitlements and quotas. A blank cell means the plan does
                <strong>not</strong> include that feature.
            </div>
        </div>
        <div class="ob-card__actions">
            <a href="{{ route('ops.billing.plans.index') }}" class="ob-btn">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Plans
            </a>
        </div>
    </div>

    <div class="mt-5">
        {{-- ── The matrix ────────────────────────────────────────────── --}}
        <form method="POST" action="{{ route('ops.billing.features.matrix') }}">
            @csrf
            <div class="ob-card">
                <div class="ob-card__head">
                    <i data-lucide="grid" class="w-4 h-4" style="color:#c97a00"></i>
                    <div>
                        <div class="ob-card__title">Plan × feature matrix</div>
                        <div class="ob-card__sub">Changes take effect immediately — the entitlement cache is cleared on save.</div>
                    </div>
                </div>

                <div class="fm-wrap">
                    <div class="tva-export-bar">@include('partials.table-export', ['table' => '#tva-t-ops-billing-features', 'filename' => 'ops-billing-features', 'paginator' => null])</div>
                    <table class="fm" id="tva-t-ops-billing-features">
                        <thead>
                            <tr>
                                <th>Feature</th>
                                @foreach ($plans as $plan)
                                    <th class="fm-col">
                                        {{ $plan->name }}
                                        @unless ($plan->is_active)
                                            <div style="font-weight:700;color:#b42318;text-transform:none;letter-spacing:0;margin-top:2px">hidden</div>
                                        @endunless
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @php $group = null; @endphp
                            @forelse ($features as $feature)
                                @if ($feature->group !== $group)
                                    @php $group = $feature->group; @endphp
                                    <tr class="fm-grp"><td colspan="{{ 1 + $plans->count() }}">{{ $group ?: 'General' }}</td></tr>
                                @endif

                                <tr>
                                    <th scope="row">
                                        <div class="fm-name">{{ $feature->name }}</div>
                                        <div class="fm-key">{{ $feature->key }}</div>
                                        <div class="fm-tags">
                                            <span class="fm-tag">{{ $valueTypes[$feature->value_type] ?? $feature->value_type }}</span>
                                            @if ($feature->module_key)
                                                <span class="fm-tag fm-tag--mod" title="Gates this admin module">
                                                    <i data-lucide="lock" style="width:9px;height:9px"></i>{{ $feature->module_key }}
                                                </span>
                                            @endif
                                            @if ($feature->metric_key)
                                                <span class="fm-tag fm-tag--met" title="Enforced as a usage quota">
                                                    <i data-lucide="gauge" style="width:9px;height:9px"></i>{{ $feature->metric_key }}
                                                </span>
                                            @endif
                                        </div>
                                    </th>

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
                                                <input type="text" class="ob-input ob-input--sm"
                                                       name="values[{{ $plan->id }}][{{ $feature->id }}]"
                                                       value="{{ $current }}"
                                                       placeholder="{{ $feature->value_type === 'numeric' ? '—' : 'text' }}">
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ 1 + $plans->count() }}">
                                        <div class="ob-empty">
                                            <i data-lucide="sliders" class="w-8 h-8"></i>
                                            No features defined yet. Add one below, or run
                                            <code>php artisan db:seed --class=BillingSeeder</code>.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="fm-legend">
                    <span><b>number</b> = the ceiling</span>
                    <span><b>-1</b> = unlimited</span>
                    <span><b>blank</b> = not included at all</span>
                    <span><b>0</b> = included, zero allowance</span>
                </div>

                <div class="fm-savebar">
                    <p class="ob-help" style="margin:0">
                        Blank and 0 are different: blank withholds the feature, 0 grants it with nothing in it
                        (which is how “has telephony, but no minutes” is expressed).
                    </p>
                    <button type="submit" class="ob-btn ob-btn--primary">
                        <i data-lucide="save" class="w-4 h-4"></i> Save all limits
                    </button>
                </div>
            </div>
        </form>

        {{-- ── Add a feature ─────────────────────────────────────────── --}}
        <div class="ob-card">
            <div class="ob-card__head">
                <i data-lucide="plus-circle" class="w-4 h-4" style="color:#c97a00"></i>
                <div>
                    <div class="ob-card__title">Add a feature</div>
                    <div class="ob-card__sub">Defines what a plan <em>can</em> grant. Set the per-plan value in the matrix above.</div>
                </div>
            </div>

            <form method="POST" action="{{ route('ops.billing.features.store') }}">
                @csrf

                <div class="ob-grid3">
                    <div class="ob-field">
                        <label for="f-name">Display name</label>
                        <input class="ob-input" id="f-name" name="name" required maxlength="150" placeholder="AI conversations">
                    </div>
                    <div class="ob-field">
                        <label for="f-type">Value type</label>
                        <select class="ob-select" id="f-type" name="value_type">
                            @foreach ($valueTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="ob-field">
                        <label for="f-unit">Unit <span class="hint">optional</span></label>
                        <input class="ob-input" id="f-unit" name="unit" maxlength="40" placeholder="per month">
                    </div>
                </div>

                <div class="ob-grid3">
                    <div class="ob-field">
                        <label for="f-group">Group heading</label>
                        <input class="ob-input" id="f-group" name="group" maxlength="80" placeholder="Volume">
                    </div>
                    <div class="ob-field">
                        <label for="f-module">Gate a module <span class="hint">optional</span></label>
                        <select class="ob-select" id="f-module" name="module_key">
                            <option value="">— none —</option>
                            @foreach ($moduleKeys as $key => $cfg)
                                <option value="{{ $key }}">{{ $cfg['label'] ?? $key }} ({{ $key }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="ob-field">
                        <label for="f-metric">Cap a usage meter <span class="hint">optional</span></label>
                        <select class="ob-select" id="f-metric" name="metric_key">
                            <option value="">— none —</option>
                            @foreach ($metricKeys as $key => $meta)
                                <option value="{{ $key }}">{{ $meta['label'] ?? $key }} ({{ $key }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="ob-grid3">
                    <div class="ob-field" style="grid-column:span 2">
                        <label for="f-desc">Description</label>
                        <input class="ob-input" id="f-desc" name="description" maxlength="500"
                               placeholder="Shown under the feature name in this matrix.">
                    </div>
                    <div class="ob-field">
                        <label for="f-sort">Sort order</label>
                        <input class="ob-input ob-input--num" id="f-sort" name="sort_order" type="number" min="0" max="9999" value="0">
                    </div>
                </div>

                <div class="ob-grid2" style="align-items:end">
                    <div class="ob-field ob-field--checks" style="margin:0">
                        <label class="ob-check">
                            <input type="hidden" name="is_visible" value="0">
                            <input type="checkbox" name="is_visible" value="1" checked>
                            <span><b>Show in the comparison table</b>
                                <span class="sub">The full grid on the pricing page.</span></span>
                        </label>
                        <label class="ob-check">
                            <input type="hidden" name="is_headline" value="0">
                            <input type="checkbox" name="is_headline" value="1">
                            <span><b>Bullet on the plan card</b>
                                <span class="sub">The short list customers read first.</span></span>
                        </label>
                    </div>
                    <div style="display:flex;justify-content:flex-end">
                        <button type="submit" class="ob-btn ob-btn--primary">
                            <i data-lucide="plus" class="w-4 h-4"></i> Add feature
                        </button>
                    </div>
                </div>

                <p class="ob-help" style="margin-top:14px">
                    <strong>Gate a module</strong> makes this feature control access to that admin section —
                    the same keys the Roles matrix uses, so entitlements and permissions can’t drift.
                    <strong>Cap a usage meter</strong> turns a numeric value into an enforced quota.
                    A feature with neither is display-only marketing copy.
                </p>
            </form>
        </div>

        {{-- ── Catalogue ─────────────────────────────────────────────── --}}
        @if ($features->isNotEmpty())
            <div class="ob-card">
                <div class="ob-card__head">
                    <i data-lucide="list" class="w-4 h-4" style="color:#c97a00"></i>
                    <div class="ob-card__title">Feature catalogue</div>
                    <div class="ob-card__actions" style="font-size:12px;color:#94a3b8">
                        {{ $features->count() }} features
                    </div>
                </div>

                <div class="ob-tablewrap">
                    <div class="tva-export-bar">@include('partials.table-export', ['table' => '#tva-t-ops-billing-features-2', 'filename' => 'ops-billing-features-2', 'paginator' => null])</div>
                    <table class="ob-table" id="tva-t-ops-billing-features-2">
                        <thead>
                            <tr>
                                <th>Feature</th><th style="width:130px">Type</th>
                                <th style="width:120px">Module</th><th style="width:130px">Metric</th>
                                <th style="width:110px">Shown</th><th style="width:60px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($features as $feature)
                                <tr>
                                    <td>
                                        <div class="fm-name">{{ $feature->name }}</div>
                                        <div class="fm-key">{{ $feature->key }}</div>
                                    </td>
                                    <td>{{ $valueTypes[$feature->value_type] ?? $feature->value_type }}</td>
                                    <td>{{ $feature->module_key ?: '—' }}</td>
                                    <td>{{ $feature->metric_key ?: '—' }}</td>
                                    <td>
                                        @if ($feature->is_visible)<span class="ob-pill ob-pill--muted">table</span>@endif
                                        @if ($feature->is_headline)<span class="ob-pill ob-pill--accent">card</span>@endif
                                        @if (! $feature->is_visible && ! $feature->is_headline)
                                            <span class="ob-pill ob-pill--off">hidden</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="ob-rowactions">
                                            <form method="POST" action="{{ route('ops.billing.features.destroy', ['id' => $feature->id]) }}" class="ob-inline"
                                                  onsubmit="return confirm('Delete “{{ $feature->name }}” and remove it from every plan?\n\nAny code checking this feature key will start returning “not granted”.');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="ob-btn ob-btn--danger ob-btn--icon" title="Delete">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="ob-help">
                    A feature’s <code>key</code> can’t be renamed — application code and the entitlement cache
                    reference it. Change the display name instead.
                </p>
            </div>
        @endif
    </div>
</div>
@endsection
