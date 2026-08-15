@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">💬</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">All conversations</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every chat and call across every workspace. Filter by project to drill in, or search by customer detail.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="opsSessTb">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search name, email, phone, CallSID, ID…" autocomplete="off">
            </div>
            <select name="project_id" onchange="this.form.submit()">
                <option value="0">All projects</option>
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int)$projectFilter === (int)$p->id)>
                        {{ $p->name }} · {{ $clients[$p->client_id]->name ?? '—' }}
                    </option>
                @endforeach
            </select>
            <select name="channel" onchange="this.form.submit()">
                <option value="">All channels</option>
                @foreach (['web','voice','phone','sms'] as $ch)
                    <option value="{{ $ch }}" @selected($channel === $ch)>{{ ucfirst($ch) }}</option>
                @endforeach
            </select>
            <select name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (['active','ended','abandoned'] as $st)
                    <option value="{{ $st }}" @selected($status === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; cursor:pointer;">
                <input type="checkbox" name="with_deleted" value="1" @checked($withDeleted) onchange="this.form.submit()">
                Include deleted
            </label>
            <div class="ml-auto" style="color:#64748b; font-size:12px;">
                {{ number_format($paginator->total()) }} session(s)
            </div>
            @include('partials.table-export', ['table' => '#tva-t-ops-sessions', 'filename' => 'ops-sessions', 'paginator' => $paginator ?? null])
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table" id="tva-t-ops-sessions">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Project · Owner</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Last activity</th>
                        <th style="text-align:right;" data-export-skip>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($paginator as $s)
                    @php
                        $name = $s['customer_name'] ?: ($s['customer_phone'] ?: 'Anonymous');
                        $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'A', 0, 2));
                        $deleted = ($s['deleted_at'] ?? null) !== null;
                    @endphp
                    <tr style="{{ $deleted ? 'opacity:.55;' : '' }}">
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $s['id'] }}</td>
                        <td data-label="Customer">
                            <div class="flex items-center gap-3">
                                <div style="width:32px; height:32px; border-radius:50%; background:var(--tva-gradient); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:11px; flex-shrink:0;">{{ $initials }}</div>
                                <div>
                                    <div style="font-weight:600;">{{ $name }}</div>
                                    @if ($s['customer_email'])
                                        <div style="font-size:11px; color:#94a3b8;">{{ $s['customer_email'] }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td data-label="Owner">
                            <div style="font-size:12.5px; font-weight:600;">{{ $s['project_name'] }}</div>
                            <div style="font-size:11px; color:#94a3b8;">{{ $s['client_name'] ?? '—' }}</div>
                        </td>
                        <td data-label="Channel"><span class="tva-channel-chip is-{{ $s['channel'] }}">{{ $s['channel'] }}</span></td>
                        <td data-label="Status"><span class="tva-status is-{{ $s['status'] }}">{{ $s['status'] }}</span></td>
                        <td data-label="Last activity" style="font-size:12px; color:#475569;">
                            {{ $s['last_activity_at'] ? date('M j · H:i', $s['last_activity_at']) : '—' }}
                        </td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            @if ($deleted)
                                <form method="POST" action="{{ route('ops.tenant.restore', ['type' => 'session', 'projectId' => $s['project_id'], 'id' => $s['id']]) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm">Restore</button>
                                </form>
                            @else
                                <a href="{{ route('ops.sessions.show', ['projectId' => $s['project_id'], 'id' => $s['id']]) }}"
                                   class="btn btn-secondary btn-sm" title="View transcript">
                                    <i data-lucide="eye" class="w-3 h-3 inline -mt-0.5"></i>
                                </a>
                                <form method="POST" action="{{ route('ops.tenant.delete', ['type' => 'session', 'projectId' => $s['project_id'], 'id' => $s['id']]) }}" style="display:inline;"
                                      data-confirm="Soft-delete session #{{ $s['id'] }}? Disappears from customer + ops. Recoverable.">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center; padding:60px 20px; color:#94a3b8;">No sessions match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($paginator->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $paginator->firstItem() }}</b>–<b>{{ $paginator->lastItem() }}</b> of <b>{{ number_format($paginator->total()) }}</b>
                </div>
                @include('partials.pagination', ['paginator' => $paginator])
            </div>
        @endif
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    (function () {
        var f = document.getElementById('opsSessTb');
        if (!f) return;
        var input = f.querySelector('input[name="q"]'); var timer = null;
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ f.submit(); }, 350); });
    })();
</script>
@endsection
