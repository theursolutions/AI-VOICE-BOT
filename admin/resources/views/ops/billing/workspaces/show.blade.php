@extends('layouts.ops')

@section('content')
<style>
    .wd-top { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap; margin-bottom:22px; }
    .wd-top__t { font-size:21px; font-weight:700; color:#0f172a; margin:0 0 4px; letter-spacing:-.01em; }
    .wd-top__s { font:12px ui-monospace,Menlo,monospace; color:#94a3b8; }
    .wd-back { font-size:12.5px; color:#0b6e5b; text-decoration:none; font-weight:650; }

    .wd-grid { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:20px; align-items:start; }
    @media (max-width:1080px) { .wd-grid { grid-template-columns:1fr; } }

    .wd-card { background:#fff; border:1px solid #e6ecf1; border-radius:13px; padding:0 0 16px; margin-bottom:18px; }
    .wd-card__h { display:flex; align-items:center; gap:9px; padding:14px 17px; border-bottom:1px solid #f1f5f9; }
    .wd-card__t { font-size:13.5px; font-weight:650; color:#0f172a; }
    .wd-card__b { padding:14px 17px 0; }

    .wd-note { font-size:12px; color:#94a3b8; line-height:1.6; }

    .wd-fld { display:flex; flex-direction:column; gap:5px; margin-bottom:12px; }
    .wd-fld label { font:650 11.5px system-ui,sans-serif; color:#334155; }
    .wd-fld input, .wd-fld select, .wd-fld textarea {
        padding:9px 11px; border:1px solid #e2e8f0; border-radius:8px;
        font:13px system-ui,sans-serif; color:#0f172a; background:#fff; width:100%;
    }
    .wd-fld input:focus, .wd-fld select:focus, .wd-fld textarea:focus {
        outline:none; border-color:#0b6e5b; box-shadow:0 0 0 3px rgba(11,110,91,.12);
    }
    .wd-fld small { font-size:10.5px; color:#94a3b8; line-height:1.5; }

    .wd-btn { background:#0b6e5b; color:#fff; border:none; border-radius:8px; padding:9px 16px; font:650 12.5px system-ui,sans-serif; cursor:pointer; width:100%; }
    .wd-btn--danger { background:#fff; color:#b91c1c; border:1px solid #fecaca; padding:5px 10px; font-size:11.5px; width:auto; }

    .wd-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
    .wd-tbl th { text-align:left; font:600 10px ui-monospace,Menlo,monospace; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; padding:7px 8px; border-bottom:1px solid #eef2f6; }
    .wd-tbl td { padding:8px; border-bottom:1px solid #f6f8fa; color:#334155; }
    .wd-tbl td.num { font-family:ui-monospace,Menlo,monospace; text-align:right; white-space:nowrap; }
    .wd-tbl tr:last-child td { border-bottom:none; }
    .wd-grp { font:600 10px ui-monospace,Menlo,monospace; letter-spacing:.08em; text-transform:uppercase; color:#0b6e5b; background:#f7fbfa; }
    .wd-plus { color:#7e22ce; font-weight:700; }
    .wd-unl  { color:#0369a1; font-weight:650; }

    .wd-alert { padding:11px 14px; border-radius:9px; font-size:12.5px; line-height:1.55; margin-bottom:16px; }
    .wd-alert--ok  { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
    .wd-alert--err { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
    .wd-alert--warn{ background:#fffbeb; border:1px solid #fde68a; color:#92400e; }

    .wd-pill { font:700 9.5px ui-monospace,Menlo,monospace; letter-spacing:.06em; text-transform:uppercase; padding:3px 7px; border-radius:5px; }
    .wd-pill--gift { background:#f3e8ff; color:#7e22ce; }
    .wd-pill--live { background:#dcfce7; color:#15803d; }
    .wd-pill--stale{ background:#eef2f6; color:#64748b; }
</style>

@php
    $assigned = (bool) data_get($subscription?->metadata, 'assigned_by_super_admin');
    $onStripe = (bool) $subscription?->stripe_subscription_ref;
@endphp

<div class="wd-top">
    <div>
        <a href="{{ route('ops.billing.workspaces.index') }}" class="wd-back">&larr; All workspaces</a>
        <h1 class="wd-top__t" style="margin-top:6px">{{ $client->name }}</h1>
        <div class="wd-top__s">{{ $client->slug }} &middot; workspace #{{ $client->id }}</div>
    </div>
    <div style="text-align:right">
        <div style="font-size:12px;color:#94a3b8">Currently on</div>
        <div style="font-size:16px;font-weight:700;color:#0f172a">{{ $plan?->name ?? 'No plan' }}</div>
        @if ($assigned)
            <span class="wd-pill wd-pill--gift" style="margin-top:5px;display:inline-block">Assigned free</span>
        @elseif ($onStripe)
            <span class="wd-pill wd-pill--live" style="margin-top:5px;display:inline-block">Paying via Stripe</span>
        @endif
    </div>
</div>

@if (session('success'))<div class="wd-alert wd-alert--ok">{{ session('success') }}</div>@endif
@if (session('error'))<div class="wd-alert wd-alert--err">{{ session('error') }}</div>@endif

@if ($onStripe && $subscription->grantsAccess())
    <div class="wd-alert wd-alert--warn">
        <strong>This workspace pays through Stripe.</strong>
        Assigning a plan here would leave Stripe billing them for a plan they are no longer on, so it
        is refused. Cancel the Stripe subscription first if you mean to move them onto a free plan.
        Grants below are unaffected — they work alongside a paid plan.
    </div>
@endif

<div class="wd-grid">
    {{-- ── Entitlements ─────────────────────────────────────────────── --}}
    <div>
        <div class="wd-card">
            <div class="wd-card__h">
                <i data-lucide="list-checks" style="width:15px;height:15px;color:#0b6e5b"></i>
                <div class="wd-card__t">What this workspace is allowed</div>
            </div>
            <div class="wd-card__b">
                <p class="wd-note" style="margin:0 0 12px">
                    Effective = what the plan includes, plus grants, plus purchased add-ons. This is
                    the figure the product actually enforces.
                </p>

                <table class="wd-tbl">
                    <thead>
                        <tr>
                            <th>Allowance</th>
                            <th style="text-align:right">Plan</th>
                            <th style="text-align:right">Granted</th>
                            <th style="text-align:right">Effective</th>
                            <th style="text-align:right">Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $group = null; @endphp
                        @foreach ($rows as $row)
                            @if ($row['group'] !== $group)
                                @php $group = $row['group']; @endphp
                                <tr><td colspan="5" class="wd-grp">{{ $group }}</td></tr>
                            @endif
                            <tr>
                                <td>
                                    {{ $row['name'] }}
                                    @if ($row['unit'])<span style="color:#cbd5e1"> · {{ $row['unit'] }}</span>@endif
                                </td>
                                <td class="num">{{ $row['plan'] === null ? '∞' : number_format($row['plan']) }}</td>
                                <td class="num">
                                    @if ($row['granted'] === \App\Models\Billing\PlanGrant::UNLIMITED)
                                        <span class="wd-unl">unlimited</span>
                                    @elseif ($row['granted'] !== 0)
                                        <span class="wd-plus">{{ $row['granted'] > 0 ? '+' : '' }}{{ number_format($row['granted']) }}</span>
                                    @else
                                        <span style="color:#e2e8f0">—</span>
                                    @endif
                                </td>
                                <td class="num"><strong>{{ $row['effective'] === null ? '∞' : number_format($row['effective']) }}</strong></td>
                                <td class="num">
                                    {{ $row['used'] === null ? '' : number_format($row['used']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Grants in force ──────────────────────────────────────── --}}
        @if ($grants->isNotEmpty())
            <div class="wd-card">
                <div class="wd-card__h">
                    <i data-lucide="gift" style="width:15px;height:15px;color:#7e22ce"></i>
                    <div class="wd-card__t">Grants</div>
                </div>
                <div class="wd-card__b">
                    <table class="wd-tbl">
                        <thead>
                            <tr><th>Feature</th><th style="text-align:right">Value</th><th>Expires</th><th>Note</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($grants as $g)
                                <tr>
                                    <td>
                                        {{ $g->feature_key }}
                                        @if ($g->hasExpired())
                                            <span class="wd-pill wd-pill--stale">lapsed</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if ($g->isUnlimited())
                                            <span class="wd-unl">unlimited</span>
                                        @else
                                            <span class="wd-plus">{{ $g->value > 0 ? '+' : '' }}{{ number_format($g->value) }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $g->expires_at?->format('j M Y') ?? 'never' }}</td>
                                    <td style="color:#64748b">{{ $g->note ?: '—' }}</td>
                                    <td style="text-align:right">
                                        <form method="POST" action="{{ route('ops.billing.workspaces.revoke', ['id' => $client->id, 'featureKey' => $g->feature_key]) }}"
                                              onsubmit="return confirm('Remove this grant? Their allowance drops immediately.')">
                                            @csrf @method('DELETE')
                                            <button class="wd-btn wd-btn--danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- ── Actions ──────────────────────────────────────────────────── --}}
    <div>
        <div class="wd-card">
            <div class="wd-card__h">
                <i data-lucide="arrow-left-right" style="width:15px;height:15px;color:#0b6e5b"></i>
                <div class="wd-card__t">Assign a plan</div>
            </div>
            <div class="wd-card__b">
                <p class="wd-note" style="margin:0 0 12px">
                    Moves them onto a plan <strong>at no charge</strong>. Nothing is sent to Stripe and
                    no invoice is raised.
                </p>

                <form method="POST" action="{{ route('ops.billing.workspaces.assign', $client->id) }}">
                    @csrf
                    <div class="wd-fld">
                        <label for="plan_id">Plan</label>
                        <select name="plan_id" id="plan_id" required>
                            @foreach ($plans as $p)
                                <option value="{{ $p->id }}" @selected($plan && $plan->id === $p->id)>
                                    {{ $p->name }}@if ($p->type !== 'standard') ({{ $p->type }})@endif
                                </option>
                            @endforeach
                        </select>
                        @error('plan_id')<small style="color:#b91c1c">{{ $message }}</small>@enderror
                    </div>

                    <div class="wd-fld">
                        <label for="assign_note">Why</label>
                        <textarea name="note" id="assign_note" rows="2" maxlength="500"
                                  placeholder="Pilot until end of quarter, agreed with…"></textarea>
                        <small>Recorded against the workspace. The question later is never what, it is why.</small>
                    </div>

                    <button class="wd-btn" @disabled($onStripe && $subscription->grantsAccess())>
                        Assign free of charge
                    </button>
                </form>
            </div>
        </div>

        <div class="wd-card">
            <div class="wd-card__h">
                <i data-lucide="plus-circle" style="width:15px;height:15px;color:#7e22ce"></i>
                <div class="wd-card__t">Grant an allowance</div>
            </div>
            <div class="wd-card__b">
                <p class="wd-note" style="margin:0 0 12px">
                    Added on top of their plan. Works alongside a paid subscription.
                </p>

                <form method="POST" action="{{ route('ops.billing.workspaces.grant', $client->id) }}">
                    @csrf
                    <div class="wd-fld">
                        <label for="feature_key">Allowance</label>
                        <select name="feature_key" id="feature_key" required>
                            @php $g = null; @endphp
                            @foreach ($grantable as $f)
                                @if ($f->group !== $g)
                                    @php $g = $f->group; @endphp
                                    @if (! $loop->first)</optgroup>@endif
                                    <optgroup label="{{ $g }}">
                                @endif
                                <option value="{{ $f->key }}">{{ $f->name }}</option>
                            @endforeach
                            </optgroup>
                        </select>
                    </div>

                    <div class="wd-fld">
                        <label for="value">Amount to add</label>
                        <input type="number" name="value" id="value" value="1000" min="-1" max="100000000" required>
                        <small>
                            Added to what the plan gives. Use <strong>-1</strong> for unlimited, or
                            <strong>0</strong> to remove an existing grant. For an on/off feature, any
                            value above zero switches it on.
                        </small>
                        @error('value')<small style="color:#b91c1c">{{ $message }}</small>@enderror
                    </div>

                    <div class="wd-fld">
                        <label for="expires_at">Expires</label>
                        <input type="datetime-local" name="expires_at" id="expires_at">
                        <small>Leave blank for a grant that does not lapse — but most should.</small>
                        @error('expires_at')<small style="color:#b91c1c">{{ $message }}</small>@enderror
                    </div>

                    <div class="wd-fld">
                        <label for="grant_note">Why</label>
                        <textarea name="note" id="grant_note" rows="2" maxlength="500"
                                  placeholder="Two seats promised on the renewal call"></textarea>
                    </div>

                    <button class="wd-btn">Grant</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
