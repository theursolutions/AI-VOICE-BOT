@extends('layouts.ops')

@section('content')
<style>
    .tva-nf-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
    .tva-nf-tab { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:10px; text-decoration:none; font-size:13px; font-weight:600;
        border:1px solid var(--tva-border, #e2e8f0); background:var(--tva-surface, #fff); color:var(--tva-text-2, #475569); }
    .tva-nf-tab.is-on { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
    .tva-nf-tab b { font-size:11px; background:#ef4444; color:#fff; border-radius:999px; padding:1px 8px; }
    .tva-nf-row { display:flex; gap:14px; padding:14px 18px; border-bottom:1px solid var(--tva-border, #eef0f3); text-decoration:none; color:inherit; }
    .tva-nf-row:hover { background:var(--tva-hover, #f8fafc); }
    .tva-nf-row.is-unread { background:rgba(37,99,235,.06); }
    .tva-nf-kind { flex:0 0 120px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#64748b; padding-top:2px; }
    .tva-nf-title { font-size:14px; font-weight:600; color:var(--tva-text, #0f172a); }
    .tva-nf-row.is-unread .tva-nf-title::after { content:""; display:inline-block; width:7px; height:7px; border-radius:50%; background:#2563eb; margin-left:8px; vertical-align:middle; }
    .tva-nf-body { font-size:13px; color:var(--tva-text-2, #475569); margin-top:3px; }
    .tva-nf-details { display:grid; grid-template-columns:max-content 1fr; gap:3px 14px; font-size:12.5px; margin-top:8px; color:var(--tva-text-2, #475569); }
    .tva-nf-details dt { color:#94a3b8; }
    .tva-nf-details dd { margin:0; white-space:pre-wrap; word-break:break-word; }
    .tva-nf-time { flex:0 0 auto; font-size:12px; color:#94a3b8; white-space:nowrap; }
    @media (max-width: 700px) { .tva-nf-row { flex-wrap:wrap; } .tva-nf-kind { flex-basis:100%; } }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🔔</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Notifications</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                New users, clients, projects, website contacts and payments. Each one is also emailed to {{ config('services.ops_alerts.email') }}.
            </div>
        </div>
        <form method="POST" action="{{ route('ops.notifications.read') }}">
            @csrf
            @if ($kind)<input type="hidden" name="kind" value="{{ $kind }}">@endif
            <button class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3);">Mark {{ $kind ? 'these' : 'all' }} as read</button>
        </form>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4">{{ session('success') }}</div>
    @endif

    <div class="tva-nf-tabs">
        @foreach (['' => 'All'] + \App\Models\OpsNotification::KINDS as $key => $label)
            @php $n = $counts[$key === '' ? 'all' : $key]; @endphp
            <a href="{{ route('ops.notifications.index', array_filter(['kind' => $key])) }}" class="tva-nf-tab {{ (string) $kind === $key ? 'is-on' : '' }}">
                {{ $label }} @if ($n)<b>{{ $n }}</b>@endif
            </a>
        @endforeach
    </div>

    <div class="tva-dt-card">
        @forelse ($items as $item)
            <a href="{{ route('ops.notifications.open', $item->id) }}" class="tva-nf-row {{ isset($unread[$item->id]) ? 'is-unread' : '' }}">
                <div class="tva-nf-kind">{{ \App\Models\OpsNotification::KINDS[$item->kind] ?? $item->kind }}</div>
                <div style="flex:1; min-width:0;">
                    <div class="tva-nf-title">{{ $item->title }}</div>
                    @if ($item->body)<div class="tva-nf-body">{{ $item->body }}</div>@endif
                    @if ($item->detailPairs())
                        <dl class="tva-nf-details">
                            @foreach ($item->detailPairs() as [$label, $value])
                                <dt>{{ $label }}</dt><dd>{{ \Illuminate\Support\Str::limit((string) $value, 300) }}</dd>
                            @endforeach
                        </dl>
                    @endif
                </div>
                <div class="tva-nf-time" title="{{ $item->created_at }}">{{ $item->created_at?->diffForHumans() }}</div>
            </a>
        @empty
            <div style="padding:40px 0; text-align:center; color:#94a3b8;">Nothing here yet.</div>
        @endforelse

        @if ($items->hasPages())
            <div style="padding:14px 16px;">{{ $items->links() }}</div>
        @endif
    </div>
</div>
@endsection
