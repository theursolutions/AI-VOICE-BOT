@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🎯</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">All leads</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every lead extracted across every workspace, with project + owner context.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="opsLeadsTb">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search name, email, phone, notes…" autocomplete="off">
            </div>
            <select name="project_id" onchange="this.form.submit()">
                <option value="0">All projects</option>
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int)$projectFilter === (int)$p->id)>
                        {{ $p->name }} · {{ $clients[$p->client_id]->name ?? '—' }}
                    </option>
                @endforeach
            </select>
            <select name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (['new','contacted','qualified','converted','disqualified'] as $st)
                    <option value="{{ $st }}" @selected($status === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; cursor:pointer;">
                <input type="checkbox" name="with_deleted" value="1" @checked($withDeleted) onchange="this.form.submit()">
                Include deleted
            </label>
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($paginator->total()) }} lead(s)</div>
            @include('partials.table-export', ['table' => '#tva-t-ops-leads', 'filename' => 'ops-leads', 'paginator' => $paginator ?? null])
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table" id="tva-t-ops-leads">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Lead</th>
                        <th>Contact</th>
                        <th>Project · Owner</th>
                        <th>Confidence</th>
                        <th>Status</th>
                        <th style="text-align:right;" data-export-skip>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($paginator as $l)
                    @php
                        $f = $l['fields'] ?? [];
                        $name = $f['name'] ?? 'Anonymous';
                        $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'A', 0, 2));
                        $conf = (int) round(($l['confidence'] ?? 0) * 100);
                        $deleted = ($l['deleted_at'] ?? null) !== null;
                    @endphp
                    <tr style="{{ $deleted ? 'opacity:.55;' : '' }}">
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $l['id'] }}</td>
                        <td data-label="Lead">
                            <div class="flex items-center gap-3">
                                <div style="width:32px; height:32px; border-radius:50%; background:var(--tva-gradient); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:11px; flex-shrink:0;">{{ $initials }}</div>
                                <div style="font-weight:600;">{{ $name }}</div>
                            </div>
                        </td>
                        <td data-label="Contact" style="font-size:12px;">
                            @if (!empty($f['email']))<div>{{ $f['email'] }}</div>@endif
                            @if (!empty($f['phone']))<div>{{ $f['phone'] }}</div>@endif
                            @if (empty($f['email']) && empty($f['phone']))<span style="color:#cbd5e1;">—</span>@endif
                        </td>
                        <td data-label="Owner">
                            <div style="font-size:12.5px; font-weight:600;">{{ $l['project_name'] }}</div>
                            <div style="font-size:11px; color:#94a3b8;">{{ $l['client_name'] ?? '—' }}</div>
                        </td>
                        <td data-label="Confidence">
                            <div style="display:inline-block; width:60px; height:6px; background:#e2e8f0; border-radius:999px; vertical-align:middle; overflow:hidden;">
                                <span style="display:block; height:100%; background: linear-gradient(90deg,var(--tva-primary),var(--tva-accent)); width: {{ $conf }}%;"></span>
                            </div>
                            <span style="font-size:12px; font-weight:600; color:#475569; margin-left:6px;">{{ $conf }}%</span>
                        </td>
                        <td data-label="Status"><span class="tva-status is-{{ $l['status'] }}">{{ $l['status'] }}</span></td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            @if ($deleted)
                                <form method="POST" action="{{ route('ops.tenant.restore', ['type' => 'lead', 'projectId' => $l['project_id'], 'id' => $l['id']]) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm">Restore</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('ops.tenant.delete', ['type' => 'lead', 'projectId' => $l['project_id'], 'id' => $l['id']]) }}" style="display:inline;"
                                      data-confirm="Soft-delete lead #{{ $l['id'] }}? Disappears from customer + ops. Recoverable.">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center; padding:60px 20px; color:#94a3b8;">No leads match.</td></tr>
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
        var f = document.getElementById('opsLeadsTb'); if (!f) return;
        var input = f.querySelector('input[name="q"]'); var timer = null;
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ f.submit(); }, 350); });
    })();
</script>
@endsection
