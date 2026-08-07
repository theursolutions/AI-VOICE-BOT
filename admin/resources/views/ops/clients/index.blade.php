@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🏢</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Clients</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Suspend revokes access; delete soft-removes (recoverable). All actions audited.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="opsClientsTb">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search by name, slug, ID…" autocomplete="off">
            </div>
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; cursor:pointer;">
                <input type="checkbox" name="with_deleted" value="1" @checked($withDeleted) onchange="this.form.submit()">
                Include deleted
            </label>
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($clients->total()) }} workspace(s)</div>
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Workspace</th>
                        <th>Projects</th>
                        <th>State</th>
                        <th>Created</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($clients as $c)
                    @php
                        $pc = $projectCounts[$c->id] ?? null;
                        $deleted = $c->deleted_at !== null;
                        $suspended = ($c->is_active ?? 'Yes') === 'No';
                    @endphp
                    <tr style="{{ $deleted ? 'opacity:.55;' : '' }}">
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $c->id }}</td>
                        <td data-label="Workspace">
                            <div style="font-weight:600;">{{ $c->name }}</div>
                            <div style="font-size:11px; color:#94a3b8; font-family: ui-monospace, monospace;">/{{ $c->slug }}</div>
                        </td>
                        <td data-label="Projects" style="font-family: ui-monospace, monospace;">
                            @if ($pc) {{ (int) $pc->active_c }}/{{ (int) $pc->c }} @else 0 @endif
                        </td>
                        <td data-label="State">
                            @if ($deleted) <span class="tva-status is-suspended">Deleted</span>
                            @elseif ($suspended) <span class="tva-status is-abandoned">Suspended</span>
                            @else <span class="tva-status is-active">Active</span> @endif
                        </td>
                        <td data-label="Created" style="font-family: ui-monospace, monospace; color:#64748b; font-size:11.5px;">
                            {{ $c->created_at ? date('M j, Y', is_int($c->created_at) ? $c->created_at : strtotime($c->created_at)) : '—' }}
                        </td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            @if ($deleted)
                                <form method="POST" action="{{ route('ops.clients.recover', ['id' => $c->id]) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm">Recover</button>
                                </form>
                            @else
                                <a href="{{ url('/c/'.$c->slug.'/dashboard') }}" target="_blank" class="btn btn-secondary btn-sm">
                                    <i data-lucide="external-link" class="w-3 h-3 inline -mt-0.5"></i>
                                </a>
                                @if ($suspended)
                                    <form method="POST" action="{{ route('ops.clients.restore', ['id' => $c->id]) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm">Restore</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('ops.clients.suspend', ['id' => $c->id]) }}" style="display:inline;"
                                          data-confirm="Suspend {{ $c->name }}? Users lose access until restored.">
                                        @csrf
                                        <button type="submit" class="btn btn-warning btn-sm">Suspend</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('ops.clients.delete', ['id' => $c->id]) }}" style="display:inline;"
                                      data-confirm="Soft-delete {{ $c->name }}? Workspace + all its projects disappear from every page. Recoverable.">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:60px 20px; color:#94a3b8;">No workspaces match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($clients->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $clients->firstItem() }}</b>–<b>{{ $clients->lastItem() }}</b> of <b>{{ number_format($clients->total()) }}</b>
                </div>
                @include('partials.pagination', ['paginator' => $clients])
            </div>
        @endif
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    (function () { var f = document.getElementById('opsClientsTb'); if (!f) return;
        var input = f.querySelector('input[name="q"]'); var timer = null;
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ f.submit(); }, 350); });
    })();
</script>
@endsection
