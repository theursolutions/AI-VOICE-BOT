@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📜</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Audit log</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Append-only history of every sensitive super-admin action. Rows are never updated or deleted.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar">
            <select name="action" onchange="this.form.submit()">
                <option value="">All actions</option>
                @foreach ($actions as $a)
                    <option value="{{ $a }}" @selected($action === $a)>{{ $a }}</option>
                @endforeach
            </select>
            @if ($action)
                <a href="{{ route('ops.audit.index') }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($entries->total()) }} event(s)</div>
            @include('partials.table-export', ['table' => '#tva-t-ops-audit', 'filename' => 'ops-audit', 'paginator' => $entries ?? null])
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table" id="tva-t-ops-audit">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>IP</th>
                        <th>Payload</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($entries as $e)
                    @php $actor = $e->actor_id ? ($actors[$e->actor_id] ?? null) : null; @endphp
                    <tr>
                        <td data-label="When" style="font-family: ui-monospace, monospace; color:#475569; white-space:nowrap;">{{ date('M j H:i:s', $e->created_at) }}</td>
                        <td data-label="Actor">
                            @if ($actor)
                                <div style="font-weight:600;">{{ $actor->name }}</div>
                                <div style="font-size:11px; color:#94a3b8; font-family: ui-monospace, monospace;">{{ $actor->email }}</div>
                            @else
                                <span style="color:#94a3b8;">system</span>
                            @endif
                        </td>
                        <td data-label="Action" style="font-family: ui-monospace, monospace; color: var(--tva-accent); font-weight:700;">{{ $e->action }}</td>
                        <td data-label="Target" style="font-family: ui-monospace, monospace; color:#64748b;">
                            {{ $e->target_type ? $e->target_type.'#'.$e->target_id : '—' }}
                        </td>
                        <td data-label="IP" style="font-family: ui-monospace, monospace; color:#94a3b8; font-size:11px;">{{ $e->ip ?? '—' }}</td>
                        <td data-label="Payload" style="font-family: ui-monospace, monospace; color:#64748b; font-size:11px; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                            title="{{ json_encode($e->payload) }}">
                            {{ $e->payload ? json_encode($e->payload) : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:60px 20px; color:#94a3b8;">No audit events yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $entries->firstItem() }}</b>–<b>{{ $entries->lastItem() }}</b> of <b>{{ number_format($entries->total()) }}</b>
                </div>
                @include('partials.pagination', ['paginator' => $entries])
            </div>
        @endif
    </div>
</div>

<script>if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();</script>
@endsection
