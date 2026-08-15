@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🎤</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">All voices</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every speaker reference uploaded across every workspace — cloned voices, default voices, providers.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="opsVoicesTb">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search by name, language, provider…" autocomplete="off">
            </div>
            <select name="project_id" onchange="this.form.submit()">
                <option value="0">All projects</option>
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int)$projectFilter === (int)$p->id)>
                        {{ $p->name }} · {{ $clients[$p->client_id]->name ?? '—' }}
                    </option>
                @endforeach
            </select>
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; cursor:pointer;">
                <input type="checkbox" name="with_deleted" value="1" @checked($withDeleted) onchange="this.form.submit()">
                Include deleted
            </label>
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($paginator->total()) }} voice(s)</div>
            @include('partials.table-export', ['table' => '#tva-t-ops-voices', 'filename' => 'ops-voices', 'paginator' => $paginator ?? null])
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table" id="tva-t-ops-voices">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Voice</th>
                        <th>Provider</th>
                        <th>Language</th>
                        <th>Project · Owner</th>
                        <th>Status</th>
                        <th style="text-align:right;" data-export-skip>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($paginator as $v)
                    @php $deleted = ($v['deleted_at'] ?? null) !== null; @endphp
                    <tr style="{{ $deleted ? 'opacity:.55;' : '' }}">
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $v['id'] }}</td>
                        <td data-label="Voice">
                            <div style="font-weight:600;">{{ $v['name'] }}</div>
                            @if ($v['external_id'])
                                <div style="font-size:11px; color:#94a3b8; font-family: ui-monospace, monospace;">{{ $v['external_id'] }}</div>
                            @endif
                        </td>
                        <td data-label="Provider">
                            <span class="tva-status is-ended">{{ $v['provider'] ?: 'unknown' }}</span>
                        </td>
                        <td data-label="Language" style="font-family: ui-monospace, monospace; font-size:12px;">{{ $v['language'] ?: '—' }}</td>
                        <td data-label="Owner">
                            <div style="font-size:12.5px; font-weight:600;">{{ $v['project_name'] }}</div>
                            <div style="font-size:11px; color:#94a3b8;">{{ $v['client_name'] ?? '—' }}</div>
                        </td>
                        <td data-label="Status">
                            @if ($deleted)
                                <span class="tva-status is-suspended">Deleted</span>
                            @elseif ($v['status'] === 'ready')
                                <span class="tva-status is-active">Ready</span>
                            @elseif ($v['status'] === 'processing')
                                <span class="tva-status is-contacted">Processing</span>
                            @else
                                <span class="tva-status is-ended">{{ $v['status'] ?: '—' }}</span>
                            @endif
                        </td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            @if ($deleted)
                                <form method="POST" action="{{ route('ops.tenant.restore', ['type' => 'voice', 'projectId' => $v['project_id'], 'id' => $v['id']]) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm">Restore</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('ops.tenant.delete', ['type' => 'voice', 'projectId' => $v['project_id'], 'id' => $v['id']]) }}" style="display:inline;"
                                      data-confirm="Soft-delete voice #{{ $v['id'] }} ({{ $v['name'] }})? Disappears from customer + ops. Recoverable.">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center; padding:60px 20px; color:#94a3b8;">No voices match.</td></tr>
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
    (function () { var f = document.getElementById('opsVoicesTb'); if (!f) return;
        var input = f.querySelector('input[name="q"]'); var timer = null;
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ f.submit(); }, 350); });
    })();
</script>
@endsection
