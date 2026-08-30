@extends('layouts.ops')

@section('content')
<style>
    .ws-head { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:20px; }
    .ws-head__t { font-size:21px; font-weight:700; color:#0f172a; margin:0 0 6px; letter-spacing:-.01em; }
    .ws-head__d { font-size:13px; color:#64748b; line-height:1.6; max-width:72ch; margin:0; }

    .ws-search { display:flex; gap:8px; margin-bottom:18px; }
    .ws-search input {
        flex:1; max-width:340px; padding:9px 12px; border:1px solid #e2e8f0;
        border-radius:9px; font-size:13px; color:#0f172a; background:#fff;
    }
    .ws-search input:focus { outline:none; border-color:#0b6e5b; box-shadow:0 0 0 3px rgba(11,110,91,.12); }
    .ws-btn {
        background:#0b6e5b; color:#fff; border:none; border-radius:9px; padding:9px 16px;
        font:650 12.5px system-ui,sans-serif; cursor:pointer;
    }
    .ws-btn--ghost { background:#fff; color:#334155; border:1px solid #e2e8f0; }

    .ws-tbl { width:100%; background:#fff; border:1px solid #e6ecf1; border-radius:12px; border-collapse:separate; border-spacing:0; overflow:hidden; }
    .ws-tbl th {
        text-align:left; font:600 10.5px ui-monospace,Menlo,monospace; letter-spacing:.09em;
        text-transform:uppercase; color:#94a3b8; padding:11px 14px; background:#fbfcfd;
        border-bottom:1px solid #eef2f6;
    }
    .ws-tbl td { padding:12px 14px; border-bottom:1px solid #f4f7f9; font-size:13px; color:#334155; vertical-align:middle; }
    .ws-tbl tr:last-child td { border-bottom:none; }
    .ws-tbl tr:hover td { background:#fcfdfe; }
    .ws-name { font-weight:650; color:#0f172a; }
    .ws-slug { font:11.5px ui-monospace,Menlo,monospace; color:#94a3b8; }

    .ws-pill { font:700 9.5px ui-monospace,Menlo,monospace; letter-spacing:.06em; text-transform:uppercase; padding:3px 7px; border-radius:5px; white-space:nowrap; }
    .ws-pill--live   { background:#dcfce7; color:#15803d; }
    .ws-pill--free   { background:#e0f2fe; color:#0369a1; }
    .ws-pill--none   { background:#eef2f6; color:#64748b; }
    .ws-pill--warn   { background:#fdf3d7; color:#92400e; }
    .ws-pill--gift   { background:#f3e8ff; color:#7e22ce; }

    .ws-link { color:#0b6e5b; font-weight:650; text-decoration:none; font-size:12.5px; }
    .ws-link:hover { text-decoration:underline; }
    .ws-empty { background:#fff; border:1px dashed #d8e2de; border-radius:12px; padding:34px; text-align:center; color:#64748b; font-size:13px; }
</style>

<div class="content">
<div class="ws-head mt-6">
    <div>
        <h1 class="ws-head__t">Workspace plans</h1>
        <p class="ws-head__d">
            Put any workspace on any plan at no charge, and grant allowances beyond what a plan
            includes — a pilot, a partner, extra seats promised on a call. Everything here is
            recorded with who did it and why.
        </p>
    </div>
</div>

@include('ops.billing._styles')

<form method="GET" class="ws-search">
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search workspaces by name or slug…">
    <button type="submit" class="ws-btn">Search</button>
    @if ($filters['q'])
        <a href="{{ route('ops.billing.workspaces.index') }}" class="ws-btn ws-btn--ghost" style="text-decoration:none;display:inline-flex;align-items:center;">Clear</a>
    @endif
</form>

@if ($clients->isEmpty())
    <div class="ws-empty">No workspaces match that.</div>
@else
    <table class="ws-tbl">
        <thead>
            <tr>
                <th>Workspace</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Grants</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($clients as $c)
                @php
                    $sub  = $c->currentSubscription();
                    $plan = $c->currentPlan();
                    // A free assignment is a local subscription with no Stripe
                    // reference — worth calling out, because it is the one state
                    // that produces no invoice and so appears in no revenue report.
                    $assigned = (bool) data_get($sub?->metadata, 'assigned_by_super_admin');
                    $grants   = (int) ($grantCounts[$c->id] ?? 0);

                    [$pill, $label] = match (true) {
                        $sub === null              => ['none', 'No plan'],
                        $assigned                  => ['gift', 'Assigned free'],
                        $sub->isFree()             => ['free', 'Free window'],
                        ! $sub->grantsAccess()     => ['warn', ucfirst(str_replace('_', ' ', $sub->status))],
                        default                    => ['live', 'Active'],
                    };
                @endphp
                <tr>
                    <td>
                        <div class="ws-name">{{ $c->name }}</div>
                        <div class="ws-slug">{{ $c->slug }}</div>
                    </td>
                    <td>{{ $plan?->name ?? '—' }}</td>
                    <td><span class="ws-pill ws-pill--{{ $pill }}">{{ $label }}</span></td>
                    <td>
                        @if ($grants)
                            <span class="ws-pill ws-pill--gift">{{ $grants }} {{ Str::plural('grant', $grants) }}</span>
                        @else
                            <span style="color:#cbd5e1">—</span>
                        @endif
                    </td>
                    <td style="text-align:right">
                        <a href="{{ route('ops.billing.workspaces.show', $c->id) }}" class="ws-link">Manage &rarr;</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top:16px">{{ $clients->links() }}</div>
@endif
</div>
@endsection
