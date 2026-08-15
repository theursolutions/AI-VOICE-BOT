@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">👥</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Users</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Disable to lock out; delete to soft-remove (recoverable). "Sign in as" is audited.
            </div>
        </div>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="opsUsersTb">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search by name, email, ID…" autocomplete="off">
            </div>
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; cursor:pointer;">
                <input type="checkbox" name="with_deleted" value="1" @checked($withDeleted) onchange="this.form.submit()">
                Include deleted
            </label>
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($users->total()) }} user(s)</div>
            @include('partials.table-export', ['table' => '#tva-t-ops-users', 'filename' => 'ops-users', 'paginator' => $users ?? null])
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table" id="tva-t-ops-users">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Workspaces</th>
                        <th>Role</th>
                        <th>State</th>
                        <th style="text-align:right;" data-export-skip>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($users as $u)
                    @php
                        $mc = $memberCounts[$u->id] ?? null;
                        $ac = $u->active_client_id ? ($activeClients[$u->active_client_id] ?? null) : null;
                        $deleted = $u->deleted_at !== null;
                        $disabled = (bool) $u->is_disabled;
                    @endphp
                    <tr style="{{ $deleted ? 'opacity:.55;' : '' }}">
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $u->id }}</td>
                        <td data-label="User">
                            <div style="font-weight:600;">{{ $u->name }}</div>
                            <div style="font-size:11px; color:#94a3b8; font-family: ui-monospace, monospace;">{{ $u->email }}</div>
                            @if ($ac)
                                <div style="font-size:11px; color:#94a3b8; margin-top:2px;">@ {{ $ac->name }}</div>
                            @endif
                        </td>
                        <td data-label="Workspaces" style="font-family: ui-monospace, monospace;">{{ $mc ? (int) $mc->workspace_count : 0 }}</td>
                        <td data-label="Role">
                            @if ($u->is_super_admin)
                                <span class="tva-status is-suspended">Super-admin</span>
                            @elseif ($u->role)
                                <span class="tva-status is-qualified">{{ $u->role }}</span>
                            @else
                                <span class="tva-status is-ended">Customer</span>
                            @endif
                        </td>
                        <td data-label="State">
                            @if ($deleted)
                                <span class="tva-status is-suspended">Deleted</span>
                            @elseif ($disabled)
                                <span class="tva-status is-abandoned">Disabled</span>
                            @else
                                <span class="tva-status is-active">Active</span>
                            @endif
                        </td>
                        <td data-label="Actions" style="text-align:right; white-space:nowrap;">
                            @if ($u->is_super_admin)
                                <span style="color:#94a3b8; font-size:11px;">protected</span>
                            @elseif ($deleted)
                                <form method="POST" action="{{ route('ops.users.recover', ['id' => $u->id]) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm">Recover</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('ops.impersonate.start', ['userId' => $u->id]) }}" style="display:inline;"
                                      data-confirm="Sign in as {{ $u->email }}? Audited.">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Sign in as">
                                        <i data-lucide="log-in" class="w-3 h-3 inline -mt-0.5"></i>
                                    </button>
                                </form>
                                @if ($disabled)
                                    <form method="POST" action="{{ route('ops.users.enable', ['id' => $u->id]) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm">Enable</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('ops.users.disable', ['id' => $u->id]) }}" style="display:inline;"
                                          data-confirm="Disable {{ $u->email }}? They will be logged out + blocked from re-entering until enabled.">
                                        @csrf
                                        <button type="submit" class="btn btn-warning btn-sm">Disable</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('ops.users.delete', ['id' => $u->id]) }}" style="display:inline;"
                                      data-confirm="Soft-delete {{ $u->email }}? They disappear from every page and cannot log in. Recoverable from 'Include deleted'.">
                                    @csrf
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:60px 20px; color:#94a3b8;">No users match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $users->firstItem() }}</b>–<b>{{ $users->lastItem() }}</b> of <b>{{ number_format($users->total()) }}</b>
                </div>
                @include('partials.pagination', ['paginator' => $users])
            </div>
        @endif
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    (function () { var f = document.getElementById('opsUsersTb'); if (!f) return;
        var input = f.querySelector('input[name="q"]'); var timer = null;
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function(){ f.submit(); }, 350); });
    })();
</script>
@endsection
