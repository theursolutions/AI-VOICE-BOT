@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📦</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Projects</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Disable hides from customer + bot routing. Delete soft-removes (tenant DB preserved). Re-provision is idempotent.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="opsProjTb">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search by name, URL, ID…" autocomplete="off">
            </div>
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; cursor:pointer;">
                <input type="checkbox" name="with_deleted" value="1" @checked($withDeleted) onchange="this.form.submit()">
                Include deleted
            </label>
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($projects->total()) }} project(s)</div>
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Project</th>
                        <th>Owner</th>
                        <th>Tenant DB</th>
                        <th>State</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($projects as $p)
                    @php
                        $c = $clientsById[$p->client_id] ?? null;
                        $deleted = $p->deleted_at !== null;
                        $disabled = $p->is_active !== 'Yes';
                    @endphp
                    <tr style="{{ $deleted ? 'opacity:.55;' : '' }}">
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $p->id }}</td>
                        <td data-label="Project">
                            <div style="font-weight:600;">{{ $p->name }}</div>
                            @if ($p->url)
                                <div style="font-size:11px; color:#94a3b8; font-family: ui-monospace, monospace;">{{ $p->url }}</div>
                            @endif
                        </td>
                        <td data-label="Owner">
                            @if ($c)
                                <a href="{{ route('ops.clients.index', ['q' => $c->slug]) }}" style="color: var(--tva-accent); font-weight:600;">{{ $c->name }}</a>
                                @if ($c->deleted_at !== null)
                                    <span class="tva-status is-suspended" style="margin-left:4px;">owner deleted</span>
                                @endif
                            @else
                                <span style="color:#cbd5e1;">—</span>
                            @endif
                        </td>
                        <td data-label="Tenant DB" style="font-family: ui-monospace, monospace; font-size:11.5px; color:#64748b;">
                            {{ $dbNames[$p->id] }}
                        </td>
                        <td data-label="State">
                            @if ($deleted) <span class="tva-status is-suspended">Deleted</span>
                            @elseif ($disabled) <span class="tva-status is-abandoned">Disabled</span>
                            @else <span class="tva-status is-active">Active</span> @endif
                        </td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            @if ($deleted)
                                <form method="POST" action="{{ route('ops.projects.recover', ['id' => $p->id]) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm">Recover</button>
                                </form>
                            @else
                                @if ($c)
                                    <a href="{{ url('/c/'.$c->slug.'/dashboard') }}" target="_blank" class="btn btn-secondary btn-sm">
                                        <i data-lucide="external-link" class="w-3 h-3 inline -mt-0.5"></i>
                                    </a>
                                @endif
                                <form method="POST" action="{{ route('ops.projects.reprovision', ['id' => $p->id]) }}" style="display:inline;"
                                      onsubmit="return confirm('Re-run migrations for {{ $p->name }}? Idempotent.');">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Re-provision</button>
                                </form>
                                @if ($disabled)
                                    <form method="POST" action="{{ route('ops.projects.enable', ['id' => $p->id]) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm">Enable</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('ops.projects.disable', ['id' => $p->id]) }}" style="display:inline;"
                                          onsubmit="return confirm('Disable {{ $p->name }}? Customer loses access + bot stops responding for this project.');">
                                        @csrf
                                        <button type="submit" class="btn btn-warning btn-sm">Disable</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('ops.projects.delete', ['id' => $p->id]) }}" style="display:inline;"
                                      onsubmit="return confirm('Soft-delete {{ $p->name }}? Disappears everywhere. Tenant DB preserved. Recoverable.');">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:60px 20px; color:#94a3b8;">No projects match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($projects->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $projects->firstItem() }}</b>–<b>{{ $projects->lastItem() }}</b> of <b>{{ number_format($projects->total()) }}</b>
                </div>
                @include('partials.pagination', ['paginator' => $projects])
            </div>
        @endif
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    (function () { var f = document.getElementById('opsProjTb'); if (!f) return;
        var input = f.querySelector('input[name="q"]'); var timer = null;
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ f.submit(); }, 350); });
    })();
</script>
@endsection
