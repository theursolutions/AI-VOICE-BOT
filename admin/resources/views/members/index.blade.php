@extends('layouts.master')

@section('content')
@php
    $slug = $client->slug;
    $assignableRoles = $roles->where('is_owner', false);
    $owner  = collect($members)->first(fn ($m) => $m['role'] && $m['role']->is_owner);
    $others = collect($members)->reject(fn ($m) => $m['role'] && $m['role']->is_owner)->values();
    $initials = function ($name) {
        return collect(explode(' ', trim((string) $name)))->filter()->take(2)
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    };
@endphp

{{-- Members page is styled with scoped CSS (mbr-*) rather than ad-hoc
     Tailwind utilities: the admin's app.css is a purged build, so only
     theme component classes (.box/.btn/.form-*) are guaranteed present. --}}
<style>
    .mbr-pagehead { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-top:2rem; }
    .mbr-pagehead__titles { margin-right:auto; }
    .mbr-pagehead h2 { font-size:18px; font-weight:600; line-height:1.2; }
    .mbr-pagehead p { font-size:12px; color:var(--tva-text-2); margin-top:2px; }
    .mbr-btn-ico svg { width:16px; height:16px; margin-right:6px; vertical-align:-3px; }

    /* Owner spotlight */
    .mbr-hero {
        position:relative; overflow:hidden; border-radius:16px; padding:22px 24px; color:#fff;
        background:var(--tva-gradient, linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%));
        box-shadow:0 14px 30px -14px rgba(99,102,241,.55);
    }
    .mbr-hero__bg1, .mbr-hero__bg2 { position:absolute; border-radius:9999px; pointer-events:none; }
    .mbr-hero__bg1 { width:190px; height:190px; right:-44px; top:-54px; background:rgba(255,255,255,.10); }
    .mbr-hero__bg2 { width:150px; height:150px; right:64px; bottom:-72px; background:rgba(255,255,255,.06); }
    .mbr-hero__row { position:relative; display:flex; align-items:center; gap:16px; }
    .mbr-hero__avatar {
        width:62px; height:62px; flex:0 0 auto; border-radius:9999px; font-size:22px; font-weight:600;
        background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.3);
        display:flex; align-items:center; justify-content:center;
    }
    .mbr-hero__min { min-width:0; }
    .mbr-hero__min > div { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .mbr-hero__label { font-size:11px; text-transform:uppercase; letter-spacing:.17em; color:rgba(255,255,255,.7); margin-bottom:3px; }
    .mbr-hero__name { font-size:20px; font-weight:600; line-height:1.15; }
    .mbr-hero__email { font-size:13.5px; color:rgba(255,255,255,.85); }
    .mbr-hero__side { margin-left:auto; text-align:right; }
    .mbr-hero__badge {
        display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600;
        background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.25); padding:6px 13px; border-radius:9999px;
    }
    .mbr-hero__badge svg { width:15px; height:15px; }
    .mbr-hero__sub { font-size:12px; color:rgba(255,255,255,.72); margin-top:7px; }
    @media (max-width:640px) { .mbr-hero__side { display:none; } }

    /* Two-column action row */
    /* Two equal columns (6 / 6) from tablet up; stacks on phones. */
    .mbr-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:stretch; margin-top:20px; }
    @media (max-width:767px) { .mbr-grid { grid-template-columns:1fr; } }

    .mbr-card { display:flex; flex-direction:column; overflow:hidden; padding:0; }
    .mbr-card__head { display:flex; align-items:center; gap:12px; padding:18px 20px; border-bottom:1px solid rgba(148,163,184,.18); }
    .mbr-card__icon {
        width:42px; height:42px; flex:0 0 auto; border-radius:11px; color:#fff;
        display:flex; align-items:center; justify-content:center;
        background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
        box-shadow:0 4px 10px -3px rgba(99,102,241,.6);
    }
    .mbr-card__icon svg { width:21px; height:21px; }
    .mbr-card__title { font-size:15px; font-weight:600; line-height:1.2; }
    .mbr-card__desc { font-size:12px; color:var(--tva-text-2); margin-top:2px; }
    .mbr-card__body { padding:20px; flex:1; display:flex; flex-direction:column; }

    .mbr-row2 { display:grid; grid-template-columns:1fr; gap:12px; }
    @media (min-width:560px) { .mbr-row2 { grid-template-columns:1fr 1fr; } }
    .mbr-label { display:block; font-size:11px; font-weight:600; color:var(--tva-text-2); margin-bottom:5px; }
    .mbr-label span { font-weight:400; color:var(--tva-text-3); }
    .mbr-field { margin-top:14px; }
    .mbr-foot { margin-top:auto; padding-top:16px; }
    .mbr-actions { display:flex; flex-wrap:wrap; align-items:center; gap:10px; }

    /* Scope + project pickers */
    .mbr-scope { display:flex; align-items:center; gap:18px; margin:6px 0 10px; }
    .mbr-scope label { display:flex; align-items:center; gap:7px; font-size:13px; cursor:pointer; }
    .mbr-proj { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:4px; }
    @media (min-width:560px) { .mbr-proj.is-wide { grid-template-columns:1fr 1fr 1fr; } }
    .mbr-proj label {
        display:flex; align-items:center; gap:8px; padding:8px; font-size:13px; cursor:pointer;
        border:1px solid rgba(148,163,184,.22); border-radius:8px;
    }
    .mbr-proj label:hover { background:rgba(148,163,184,.08); }
    .mbr-proj span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

    /* Hint / spec box */
    .mbr-hint { border-radius:10px; background:rgba(148,163,184,.10); padding:13px 14px; font-size:12px; color:var(--tva-text-2); }
    .mbr-hint__title { font-weight:600; color:var(--tva-text); margin-bottom:7px; }
    .mbr-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:9px; }
    .mbr-chips code { background:var(--tva-surface-3); border:1px solid rgba(148,163,184,.28); padding:2px 7px; border-radius:6px; font-size:11.5px; }
    .mbr-hint ul { list-style:disc; padding-left:18px; margin:0; display:flex; flex-direction:column; gap:3px; }

    /* Member list */
    .mbr-list-head { display:flex; align-items:center; margin:32px 0 12px; }
    .mbr-list-head h3 { font-size:15px; font-weight:600; }
    .mbr-list-head .cnt { color:var(--tva-text-2); font-weight:400; font-size:13px; }
    .mbr-member { border:1px solid rgba(148,163,184,.2); border-radius:12px; padding:16px; margin-bottom:14px; }
    .mbr-member:last-child { margin-bottom:0; }
    .mbr-member__top { display:flex; align-items:center; margin-bottom:13px; }
    .mbr-avatar {
        width:38px; height:38px; flex:0 0 auto; margin-right:12px; border-radius:9999px;
        background:rgba(148,163,184,.16); color:var(--tva-text); font-size:13px; font-weight:600;
        display:flex; align-items:center; justify-content:center;
    }
    .mbr-member__id { min-width:0; }
    .mbr-member__name { font-weight:600; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .mbr-member__email { font-size:12px; color:var(--tva-text-2); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .mbr-badge { margin-left:auto; padding-left:12px; }
    .mbr-badge span { font-size:12px; padding:3px 11px; border-radius:9999px; background:rgba(148,163,184,.16); color:var(--tva-text); }
    .mbr-badge span.is-warn { background:rgba(245,158,11,.18); color:#f59e0b; }
    .mbr-empty { text-align:center; color:var(--tva-text-2); font-size:14px; padding:26px 0; }

    /* Import results */
    .mbr-results__wrap { overflow-x:auto; border:1px solid rgba(148,163,184,.2); border-radius:10px; }
    .mbr-results__table { width:100%; border-collapse:collapse; font-size:13px; }
    .mbr-results__table th {
        text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--tva-text-2);
        padding:9px 12px; background:rgba(148,163,184,.09);
    }
    .mbr-results__table td { padding:9px 12px; border-top:1px solid rgba(148,163,184,.15); }
    .mbr-results__table code { background:rgba(148,163,184,.18); padding:2px 7px; border-radius:6px; }
    .mbr-result-warn { font-size:12px; color:#f59e0b; margin-top:8px; }
    .mbr-block { margin-bottom:16px; }
    .mbr-block:last-child { margin-bottom:0; }
    .mbr-block__title { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--tva-text-2); margin-bottom:7px; }
    .mbr-skip { list-style:disc; padding-left:20px; color:#f59e0b; font-size:13px; }
</style>

{{-- ── Page header ─────────────────────────────────────────────── --}}
<div class="intro-y mbr-pagehead">
    <div class="mbr-pagehead__titles">
        <h2>Team Members</h2>
        <p>Add teammates, set their role, and scope which projects they can see.</p>
    </div>
    <a href="{{ route('roles.index', ['client' => $slug]) }}" class="btn btn-outline-secondary mbr-btn-ico">
        <i data-lucide="shield-check"></i> Roles &amp; permissions
    </a>
    <a href="{{ route('invitations.index') }}" class="btn btn-outline-secondary mbr-btn-ico">
        <i data-lucide="mail"></i> Invite by email
    </a>
</div>

@if (session('success'))
    <div class="alert alert-success mt-4">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger mt-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
@endif

{{-- ── Owner spotlight ─────────────────────────────────────────── --}}
@if ($owner)
    <div class="intro-y mbr-hero mt-5">
        <div class="mbr-hero__bg1"></div>
        <div class="mbr-hero__bg2"></div>
        <div class="mbr-hero__row">
            <div class="mbr-hero__avatar">{{ $initials($owner['user']->name) ?: '★' }}</div>
            <div class="mbr-hero__min">
                <div class="mbr-hero__label">Workspace Owner</div>
                <div class="mbr-hero__name">{{ $owner['user']->name }}</div>
                <div class="mbr-hero__email">{{ $owner['user']->email }}</div>
            </div>
            <div class="mbr-hero__side">
                <span class="mbr-hero__badge"><i data-lucide="crown"></i> Full access</span>
                <div class="mbr-hero__sub">Every module &middot; every project</div>
            </div>
        </div>
    </div>
@endif

{{-- ── Import results (after a bulk upload) ─────────────────────── --}}
@if ($result = session('import_result'))
    <div class="intro-y box mbr-card mt-5">
        <div class="mbr-card__head">
            <div class="mbr-card__icon"><i data-lucide="check-circle"></i></div>
            <div>
                <div class="mbr-card__title">Import complete</div>
                <div class="mbr-card__desc">
                    {{ count($result['created']) }} created &middot;
                    {{ count($result['updated']) }} updated &middot;
                    {{ count($result['skipped']) }} skipped
                </div>
            </div>
        </div>
        <div class="mbr-card__body">
            @if (!empty($result['created']))
                <div class="mbr-block">
                    <div class="mbr-block__title">New accounts</div>
                    <div class="mbr-results__wrap">
                        <table class="mbr-results__table">
                            <thead><tr><th>Email</th><th>Role</th><th>Temporary password</th></tr></thead>
                            <tbody>
                            @foreach ($result['created'] as $c)
                                <tr>
                                    <td>{{ $c['email'] }}</td>
                                    <td>{{ $c['role'] }}</td>
                                    <td>
                                        @if (!empty($c['temp_password']))
                                            <code>{{ $c['temp_password'] }}</code>
                                        @else
                                            <span style="color:var(--tva-text-2);">(as supplied)</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mbr-result-warn">⚠ Copy these now — temporary passwords aren’t shown again. Share them securely.</div>
                </div>
            @endif

            @if (!empty($result['updated']))
                <div class="mbr-block">
                    <div class="mbr-block__title">Updated access</div>
                    <div style="font-size:13px;color:var(--tva-text);">{{ collect($result['updated'])->pluck('email')->implode(', ') }}</div>
                </div>
            @endif

            @if (!empty($result['skipped']))
                <div class="mbr-block">
                    <div class="mbr-block__title">Skipped</div>
                    <ul class="mbr-skip">@foreach ($result['skipped'] as $s)<li>{{ $s }}</li>@endforeach</ul>
                </div>
            @endif
        </div>
    </div>
@endif

{{-- ── Two columns: Add member | Bulk import ────────────────────── --}}
<div class="mbr-grid">

    {{-- Add a single member directly (no email invite) --}}
    <div class="intro-y box mbr-card">
        <div class="mbr-card__head">
            <div class="mbr-card__icon"><i data-lucide="user-plus"></i></div>
            <div>
                <div class="mbr-card__title">Add a member</div>
                <div class="mbr-card__desc">Creates the account instantly — no invite email</div>
            </div>
        </div>

        <div class="mbr-card__body">
            @if ($assignableRoles->isEmpty())
                <div style="font-size:13px;color:#f59e0b;">
                    Create a role first on the
                    <a href="{{ route('roles.index', ['client' => $slug]) }}" style="text-decoration:underline;">Roles &amp; permissions</a> page.
                </div>
            @else
                <form method="POST" action="{{ route('members.store', ['client' => $slug]) }}" style="display:flex;flex-direction:column;flex:1;">
                    @csrf

                    <div class="mbr-row2">
                        <div>
                            <label class="mbr-label">Full name</label>
                            <input type="text" name="name" value="{{ old('name') }}" class="form-control" placeholder="Jane Smith" required>
                        </div>
                        <div>
                            <label class="mbr-label">Email</label>
                            <input type="email" name="email" value="{{ old('email') }}" class="form-control" placeholder="jane@company.com" required>
                        </div>
                    </div>

                    <div class="mbr-row2 mbr-field">
                        <div>
                            <label class="mbr-label">Password <span>(optional)</span></label>
                            <input type="text" name="password" class="form-control" placeholder="Auto-generate if blank" autocomplete="new-password">
                        </div>
                        <div>
                            <label class="mbr-label">Role</label>
                            <select name="role_id" class="form-select" required>
                                @foreach ($assignableRoles as $r)
                                    <option value="{{ $r->id }}" @selected((string) old('role_id') === (string) $r->id)>{{ $r->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <label class="mbr-label mbr-field">Project access</label>
                    <div class="mbr-scope">
                        <label>
                            <input type="radio" name="scope" value="all" class="form-check-input" checked
                                   onclick="this.closest('form').querySelector('.proj-list').style.display='none'">
                            <span>All projects</span>
                        </label>
                        <label>
                            <input type="radio" name="scope" value="projects" class="form-check-input"
                                   onclick="this.closest('form').querySelector('.proj-list').style.display='grid'">
                            <span>Specific projects</span>
                        </label>
                    </div>
                    <div class="proj-list mbr-proj" style="display:none;">
                        @foreach ($projects as $p)
                            <label>
                                <input type="checkbox" name="project_ids[]" value="{{ $p->id }}" class="form-check-input">
                                <span>{{ $p->name }}</span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mbr-foot">
                        <button class="btn btn-primary mbr-btn-ico"><i data-lucide="user-plus"></i> Add member</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    {{-- Bulk import from CSV / Excel --}}
    <div class="intro-y box mbr-card">
        <div class="mbr-card__head">
            <div class="mbr-card__icon"><i data-lucide="upload-cloud"></i></div>
            <div>
                <div class="mbr-card__title">Bulk import</div>
                <div class="mbr-card__desc">Create many members at once from a spreadsheet</div>
            </div>
        </div>

        <div class="mbr-card__body">
            @if ($assignableRoles->isEmpty())
                <div style="font-size:13px;color:#f59e0b;">Create a role first before importing.</div>
            @else
                <form method="POST" action="{{ route('members.import', ['client' => $slug]) }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;flex:1;">
                    @csrf

                    <label class="mbr-label">Spreadsheet file (.csv or .xlsx)</label>
                    <input type="file" name="file" accept=".csv,.xlsx,.txt" required style="margin-bottom:14px;">

                    <div class="mbr-hint">
                        <div class="mbr-hint__title">Columns (first row = headers)</div>
                        <div class="mbr-chips">
                            <code>name</code><code>email</code><code>role</code><code>password</code><code>projects</code>
                        </div>
                        <ul>
                            <li>Existing emails are matched and have their role/access updated.</li>
                            <li>Unknown roles fall back to <b>{{ $assignableRoles->first()->name }}</b>.</li>
                            <li>Blank passwords are auto-generated and shown once after upload.</li>
                            <li><code>projects</code>: comma-separated names, or <code>All</code>.</li>
                        </ul>
                    </div>

                    <div class="mbr-foot mbr-actions">
                        <button class="btn btn-primary mbr-btn-ico"><i data-lucide="upload"></i> Upload &amp; create</button>
                        <a href="{{ route('members.template', ['client' => $slug]) }}" class="btn btn-outline-secondary mbr-btn-ico">
                            <i data-lucide="download"></i> Download template
                        </a>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>

{{-- ── All members ─────────────────────────────────────────────── --}}
<div class="intro-y mbr-list-head">
    <h3>All members <span class="cnt">({{ $others->count() }})</span></h3>
</div>

<div class="intro-y box p-5">
    @forelse ($others as $m)
        <div class="mbr-member">
            <div class="mbr-member__top">
                <div class="mbr-avatar">{{ $initials($m['user']->name) ?: '?' }}</div>
                <div class="mbr-member__id">
                    <div class="mbr-member__name">{{ $m['user']->name }}</div>
                    <div class="mbr-member__email">{{ $m['user']->email }}</div>
                </div>
                <div class="mbr-badge">
                    @if ($m['role'])
                        <span>{{ $m['role']->name }}</span>
                    @else
                        <span class="is-warn">No role</span>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('members.update', ['client' => $slug, 'userId' => $m['user']->id]) }}">
                @csrf @method('PATCH')

                <label class="mbr-label">Role</label>
                <select name="role_id" class="form-select" style="max-width:18rem;margin-bottom:12px;" required>
                    @foreach ($roles as $r)
                        @if ($r->is_owner) @continue @endif
                        <option value="{{ $r->id }}" @selected($m['role_id'] === $r->id)>{{ $r->name }}</option>
                    @endforeach
                </select>

                <label class="mbr-label">Project access</label>
                <div class="mbr-scope">
                    <label>
                        <input type="radio" name="scope" value="all" class="form-check-input"
                               @checked($m['all_access']) onclick="this.closest('form').querySelector('.proj-list').style.display='none'">
                        <span>All projects</span>
                    </label>
                    <label>
                        <input type="radio" name="scope" value="projects" class="form-check-input"
                               @checked(!$m['all_access']) onclick="this.closest('form').querySelector('.proj-list').style.display='grid'">
                        <span>Specific projects</span>
                    </label>
                </div>
                <div class="proj-list mbr-proj is-wide" style="display:{{ $m['all_access'] ? 'none' : 'grid' }};margin-bottom:12px;">
                    @foreach ($projects as $p)
                        <label>
                            <input type="checkbox" name="project_ids[]" value="{{ $p->id }}" class="form-check-input"
                                   @checked(in_array($p->id, $m['project_ids'], true))>
                            <span>{{ $p->name }}</span>
                        </label>
                    @endforeach
                </div>

                <button class="btn btn-primary btn-sm">Save access</button>
            </form>
        </div>
    @empty
        <div class="mbr-empty">No other members yet. Add one above, or invite by email.</div>
    @endforelse
</div>
@endsection
