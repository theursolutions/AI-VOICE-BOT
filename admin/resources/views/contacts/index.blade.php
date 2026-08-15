@extends('layouts.master')

@section('content')
<style>
    .tva-ct-tbl { width:100%; border-collapse:collapse; }
    .tva-ct-tbl th { font-size:9.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
                     color:#94a3b8; text-align:left; padding:0 12px 8px; white-space:nowrap; }
    .tva-ct-tbl td { padding:11px 12px; border-top:1px solid #e2e8f0; font-size:12.5px; color:#334155;
                     vertical-align:middle; }
    html.dark .tva-ct-tbl td { border-top-color:#334155; color:#cbd5e1; }
    .tva-ct-tbl tbody tr { cursor:pointer; transition:.1s; }
    .tva-ct-tbl tbody tr:hover { background:#f8fafc; }
    html.dark .tva-ct-tbl tbody tr:hover { background:#0f172a; }

    .tva-ct-who { display:flex; align-items:center; gap:10px; min-width:0; }
    .tva-ct-av { width:34px; height:34px; border-radius:50%; overflow:hidden; flex-shrink:0;
                 display:flex; align-items:center; justify-content:center;
                 font-size:11px; font-weight:700; }
    .tva-ct-av img { width:100%; height:100%; object-fit:cover; }
    .tva-ct-name { font-weight:600; color:#0f172a; }
    html.dark .tva-ct-name { color:#f1f5f9; }
    .tva-ct-sub { font-size:11px; color:#94a3b8; }

    /* Same circular brand marks as the inbox, so a channel means one thing
       wherever it appears. */
    .tva-ct-ch { display:inline-flex; align-items:center; justify-content:center;
                 width:19px; height:19px; border-radius:50%; color:#fff; margin-right:3px;
                 box-shadow:0 1px 2px rgba(15,23,42,.16); }
    .tva-ct-ch svg { width:11px; height:11px; }
    .tva-ct-ch--whatsapp { background:#25d366; }
    .tva-ct-ch--facebook, .tva-ct-ch--messenger { background:#1877f2; }
    .tva-ct-ch--instagram { background:radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
    .tva-ct-ch--web, .tva-ct-ch--phone { background:#94a3b8; }

    .tva-ct-num { font-variant-numeric:tabular-nums; }
    .tva-ct-empty { text-align:center; padding:52px 20px; color:#94a3b8; font-size:12.5px; line-height:1.7; }

    /* Stat tiles. Own padding rather than the theme's `box p-4`, which left
       the number crammed against its label. */
    .tva-ct-stats { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:16px; margin-top:20px; }
    @media (max-width: 900px) { .tva-ct-stats { grid-template-columns:1fr; gap:12px; } }
    .tva-ct-stat { display:flex; align-items:center; gap:14px;
                   background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; }
    html.dark .tva-ct-stat { background:#1e293b; border-color:#334155; }
    .tva-ct-stat__ico { width:42px; height:42px; border-radius:11px; flex-shrink:0;
                        display:flex; align-items:center; justify-content:center; }
    .tva-ct-stat__ico svg { width:19px !important; height:19px !important; }
    .tva-ct-stat__n { font-size:23px; font-weight:700; line-height:1.15; color:#0f172a;
                      font-variant-numeric:tabular-nums; }
    html.dark .tva-ct-stat__n { color:#f1f5f9; }
    .tva-ct-stat__l { font-size:11.5px; color:#94a3b8; margin-top:3px; }

    /* The table panel. Vertical padding only — horizontal padding on a
       scrolling container clips the last column instead of spacing it. */
    .tva-ct-panel { background:#fff; border:1px solid #e2e8f0; border-radius:14px;
                    margin-top:18px; padding:16px 4px; overflow-x:auto; }
    html.dark .tva-ct-panel { background:#1e293b; border-color:#334155; }
    .tva-ct-tbl th:first-child, .tva-ct-tbl td:first-child { padding-left:20px; }
    .tva-ct-tbl th:last-child,  .tva-ct-tbl td:last-child  { padding-right:20px; }
</style>

<div class="content">
    <div class="flex items-center gap-3 mt-4 flex-wrap">
        <h2 class="text-lg font-semibold">Contacts</h2>
        <div class="text-xs text-slate-500">One row per person — across every channel they use.</div>
        <form method="GET" class="ml-auto flex items-center gap-2">
            <input type="hidden" name="project_id" value="{{ hashid($projectId) }}">
            <input name="q" value="{{ request('q') }}" class="form-control form-control-sm"
                   placeholder="Search name, email or phone…" style="width:230px;">
            <button class="btn btn-sm btn-secondary">Search</button>
        </form>
        <form method="GET">
            <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if (!$available)
        {{-- The operator's instructions used to be printed here. They are a
             deployment step, not something a customer can act on — and
             seeing "php artisan" in your CRM reads as a broken product. The
             command now goes to the log for whoever can actually run it;
             this says only what is true from the customer's side. --}}
        <div class="tva-ct-panel" style="padding:44px 20px;text-align:center">
            <i data-lucide="users" class="w-8 h-8 mx-auto text-slate-300"></i>
            <div class="mt-3 font-semibold">No contacts yet</div>
            <div class="text-xs text-slate-500 mt-2" style="line-height:1.7">
                Contacts are created automatically the first time someone messages you.<br>
                Connect a channel to start collecting them.
            </div>
            <a href="{{ route('channels.index', ['client' => $client->slug, 'project_id' => hashid($projectId)]) }}"
               class="btn btn-sm btn-primary mt-4">Connect a channel</a>
        </div>
    @else
        {{-- Stat tiles. Explicit padding and a fixed icon column rather than
             the theme's `box p-4`, which left the number crammed against the
             label with no breathing room. --}}
        <div class="tva-ct-stats">
            @foreach ([
                ['Total contacts',            $counts['total'],         'users',      '#4f46e5'],
                ['With email or phone',       $counts['with_contact'],  'at-sign',    '#0d9488'],
                ['On more than one channel',  $counts['multi_channel'], 'git-merge',  '#d97706'],
            ] as [$label, $value, $icon, $colour])
                <div class="tva-ct-stat">
                    <div class="tva-ct-stat__ico" style="background:{{ $colour }}18;color:{{ $colour }}">
                        <i data-lucide="{{ $icon }}"></i>
                    </div>
                    <div>
                        <div class="tva-ct-stat__n">{{ number_format($value) }}</div>
                        <div class="tva-ct-stat__l">{{ $label }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="tva-ct-panel">
            <table class="tva-ct-tbl">
                <thead>
                    <tr>
                        <th>Contact</th>
                        <th>Channels</th>
                        <th>Conversations</th>
                        <th>Messages</th>
                        <th>Leads</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($contacts as $c)
                    @php
                        // Deterministic hue from the id, matching the inbox, so
                        // the same person is the same colour in both places.
                        $hue = crc32((string) $c->id) % 360;
                        $parts = preg_split('/\s+/', trim((string) $c->name)) ?: [];
                        $initials = count($parts) > 1
                            ? mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1))
                            : mb_strtoupper(mb_substr($c->name ?: '?', 0, 2));
                    @endphp
                    {{-- Rows open the CONTACT, not the conversation. The
                         detail page is the common destination from here, from
                         a lead, and from the inbox — and the conversation is
                         one click further in. --}}
                    <tr onclick="window.location='{{ route('contacts.show', ['client' => $client->slug, 'id' => $c->id, 'project_id' => hashid($projectId)]) }}'">
                        <td>
                            <div class="tva-ct-who">
                                <div class="tva-ct-av" style="background:hsl({{ $hue }},52%,90%);color:hsl({{ $hue }},45%,32%)">
                                    @if ($c->avatar)
                                        <img src="{{ $c->avatar }}" alt="">
                                    @else
                                        {{ $initials }}
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="tva-ct-name truncate">{{ $c->displayName() }}</div>
                                    <div class="tva-ct-sub truncate">
                                        {{ $c->email ?: ($c->phone ? '+' . $c->phone : 'No contact details') }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;align-items:center">
                            @forelse ($c->channels_list as $ch)
                                @include('contacts._channel', ['channel' => $ch, 'size' => 22])
                            @empty
                                <span class="tva-ct-sub">—</span>
                            @endforelse
                            </div>
                        </td>
                        <td class="tva-ct-num">{{ $c->session_count }}</td>
                        <td class="tva-ct-num">{{ $c->message_count }}</td>
                        <td class="tva-ct-num">{{ $c->lead_count ?: '—' }}</td>
                        <td class="tva-ct-sub">
                            {{ $c->last_seen_at ? \Carbon\Carbon::createFromTimestamp($c->last_seen_at)->diffForHumans() : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <div class="tva-ct-empty">
                            @if (request('q'))
                                Nothing matches “{{ request('q') }}”.
                            @else
                                No contacts yet.<br>
                                They’re created automatically the first time someone messages you.
                            @endif
                        </div>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($contacts instanceof \Illuminate\Contracts\Pagination\Paginator)
            <div class="mt-4">{{ $contacts->links() }}</div>
        @endif
    @endif
</div>
@endsection
