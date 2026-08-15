@extends('layouts.master')

@section('content')
@include('contacts._icons')
<style>
    /* 4 / 8 split. The left column is reference material you scan once; the
       right is the history you actually read, so it gets twice the width. */
    .tva-cd { display:grid; grid-template-columns:repeat(12, minmax(0,1fr)); gap:18px; margin-top:18px; }
    .tva-cd__left  { grid-column:span 4 / span 4; min-width:0; }
    .tva-cd__right { grid-column:span 8 / span 8; min-width:0; }
    @media (max-width: 1100px) {
        .tva-cd__left, .tva-cd__right { grid-column:span 12 / span 12; }
    }

    .tva-cd__card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-bottom:18px; }
    html.dark .tva-cd__card { background:#1e293b; border-color:#334155; }
    .tva-cd__h { font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
                 color:#94a3b8; margin-bottom:12px; }

    /* Identity */
    .tva-cd__id { text-align:center; padding-bottom:4px; }
    .tva-cd__av { width:82px; height:82px; border-radius:50%; margin:0 auto 12px; overflow:hidden;
                  display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700; }
    .tva-cd__av img { width:100%; height:100%; object-fit:cover; }
    .tva-cd__name { font-size:19px; font-weight:700; color:#0f172a; line-height:1.3; }
    html.dark .tva-cd__name { color:#f1f5f9; }

    .tva-cd__field { display:flex; align-items:center; gap:10px; padding:9px 0;
                     border-bottom:1px solid #f1f5f9; font-size:13px; color:#334155; min-width:0; }
    .tva-cd__field:last-child { border-bottom:none; }
    html.dark .tva-cd__field { color:#cbd5e1; border-bottom-color:#334155; }
    .tva-cd__field svg { width:15px !important; height:15px !important; opacity:.55; flex-shrink:0; }
    .tva-cd__field span { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .tva-cd__field em { margin-left:auto; font-style:normal; color:#94a3b8; font-size:11px; flex-shrink:0; }

    /* Linked accounts. The primary is called out because "which of these is
       the real one?" is the first question a merged profile raises. */
    .tva-cd__link { display:flex; align-items:center; gap:11px; padding:10px 0;
                    border-bottom:1px solid #f1f5f9; }
    .tva-cd__link:last-child { border-bottom:none; }
    html.dark .tva-cd__link { border-bottom-color:#334155; }
    .tva-cd__link-t { min-width:0; flex:1; }
    .tva-cd__link-t b { display:block; font-size:12.5px; font-weight:600; color:#334155;
                        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    html.dark .tva-cd__link-t b { color:#e2e8f0; }
    .tva-cd__link-t span { font-size:11px; color:#94a3b8; }
    .tva-cd__tag { font-size:9px; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
                   padding:2px 7px; border-radius:999px; background:#eef2ff; color:#4338ca; flex-shrink:0; }

    /* Engagement */
    .tva-cd__score { display:flex; align-items:baseline; gap:9px; margin-bottom:9px; }
    .tva-cd__score b { font-size:30px; font-weight:800; line-height:1; color:#0f172a; }
    html.dark .tva-cd__score b { color:#f1f5f9; }
    .tva-cd__badge { font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
                     padding:3px 9px; border-radius:999px; }
    .tva-cd__badge--hot { background:#fef2f2; color:#b91c1c; }
    .tva-cd__badge--warm { background:#fffbeb; color:#b45309; }
    .tva-cd__badge--cold { background:#eff6ff; color:#1d4ed8; }
    .tva-cd__badge--unqualified { background:#f1f5f9; color:#64748b; }
    .tva-cd__bar { height:6px; border-radius:99px; background:#e2e8f0; overflow:hidden; margin-bottom:12px; }
    .tva-cd__bar i { display:block; height:100%; border-radius:99px; background:#4f46e5; }
    .tva-cd__why { display:flex; gap:9px; font-size:11.5px; color:#64748b; padding:3px 0; }
    .tva-cd__why b { min-width:30px; color:#334155; }
    html.dark .tva-cd__why b { color:#cbd5e1; }

    /* Tabs */
    .tva-cd__tabs { display:flex; gap:4px; border-bottom:1px solid #e2e8f0; margin-bottom:18px; padding:0 2px; }
    html.dark .tva-cd__tabs { border-bottom-color:#334155; }
    .tva-cd__tab { border:none; background:transparent; font-size:13px; font-weight:600; color:#64748b;
                   padding:10px 14px; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; }
    .tva-cd__tab:hover { color:#4f46e5; }
    .tva-cd__tab.is-on { color:#4f46e5; border-bottom-color:#4f46e5; }
    .tva-cd__tab span { font-size:10.5px; font-weight:700; opacity:.7; margin-left:4px;
                        font-variant-numeric:tabular-nums; }
    .tva-cd__pane { display:none; }
    .tva-cd__pane.is-on { display:block; }

    /* Timeline. A rail with dots reads as chronology without needing dates
       on every row. */
    .tva-cd__tl { position:relative; padding-left:26px; }
    .tva-cd__tl::before { content:''; position:absolute; left:8px; top:6px; bottom:6px; width:2px; background:#e2e8f0; }
    html.dark .tva-cd__tl::before { background:#334155; }
    .tva-cd__ev { position:relative; padding:0 0 20px; }
    .tva-cd__ev::before { content:''; position:absolute; left:-22px; top:5px; width:10px; height:10px;
                          border-radius:50%; background:#fff; border:2px solid #cbd5e1; }
    html.dark .tva-cd__ev::before { background:#1e293b; border-color:#475569; }
    .tva-cd__ev-head { display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
    .tva-cd__ev-head a { font-size:13px; font-weight:600; color:#0f172a; text-decoration:none; }
    .tva-cd__ev-head a:hover { color:#4f46e5; }
    html.dark .tva-cd__ev-head a { color:#f1f5f9; }
    .tva-cd__ev-when { font-size:11px; color:#94a3b8; margin-left:auto; }
    .tva-cd__ev-body { font-size:12.5px; color:#64748b; margin-top:5px; line-height:1.6;
                       display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

    .tva-cd__lead { display:flex; align-items:center; gap:12px; padding:14px 0;
                    border-bottom:1px solid #f1f5f9; }
    .tva-cd__lead:last-child { border-bottom:none; }
    html.dark .tva-cd__lead { border-bottom-color:#334155; }
    .tva-cd__pill { font-size:10px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
                    padding:3px 9px; border-radius:999px; background:#f1f5f9; color:#475569; }
    .tva-cd__pill--converted { background:#dcfce7; color:#15803d; }
    .tva-cd__pill--qualified { background:#e0f2fe; color:#0369a1; }
    .tva-cd__pill--disqualified { background:#fef2f2; color:#b91c1c; }

    .tva-cd__empty { text-align:center; padding:44px 20px; color:#94a3b8; font-size:12.5px; line-height:1.7; }
    .tva-cd__soon { display:inline-block; font-size:9.5px; font-weight:700; letter-spacing:.05em;
                    text-transform:uppercase; background:#f1f5f9; color:#94a3b8;
                    padding:2px 7px; border-radius:999px; margin-left:5px; }
</style>

<div class="content">
    <div class="flex items-center gap-3 mt-4 flex-wrap">
        <a href="{{ route('contacts.index', ['client' => $client->slug, 'project_id' => hashid($projectId)]) }}"
           class="btn btn-sm btn-secondary">
            <i data-lucide="arrow-left" class="w-4 h-4 mr-1"></i> Contacts
        </a>
        <h2 class="text-lg font-semibold">{{ $profile['name'] }}</h2>
    </div>

    @php
        $hue = crc32((string) $contact->id) % 360;
        $parts = preg_split('/\s+/', trim((string) $contact->name)) ?: [];
        $initials = count(array_filter($parts)) > 1
            ? mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1))
            : mb_strtoupper(mb_substr($contact->name ?: '?', 0, 2));
        $e = $profile['engagement'];
    @endphp

    <div class="tva-cd">
        {{-- ── Left: who they are ─────────────────────────────────── --}}
        <div class="tva-cd__left">
            <div class="tva-cd__card">
                <div class="tva-cd__id">
                    <div class="tva-cd__av" style="background:hsl({{ $hue }},52%,90%);color:hsl({{ $hue }},45%,32%)">
                        @if ($contact->avatar)<img src="{{ $contact->avatar }}" alt="">@else{{ $initials }}@endif
                    </div>
                    <div class="tva-cd__name">{{ $profile['name'] }}</div>
                </div>

                <div style="margin-top:16px">
                    <div class="tva-cd__field">
                        <i data-lucide="mail"></i>
                        <span>{{ $contact->email ?: 'No email captured' }}</span>
                    </div>
                    <div class="tva-cd__field">
                        <i data-lucide="phone"></i>
                        <span>{{ $contact->phone ? '+' . $contact->phone : 'No phone captured' }}</span>
                    </div>
                    <div class="tva-cd__field">
                        <i data-lucide="calendar"></i>
                        <span>First seen</span>
                        <em>{{ $contact->first_seen_at ? \Carbon\Carbon::createFromTimestamp($contact->first_seen_at)->diffForHumans() : '—' }}</em>
                    </div>
                    <div class="tva-cd__field">
                        <i data-lucide="clock"></i>
                        <span>Last seen</span>
                        <em>{{ $contact->last_seen_at ? \Carbon\Carbon::createFromTimestamp($contact->last_seen_at)->diffForHumans() : '—' }}</em>
                    </div>
                    <div class="tva-cd__field">
                        <i data-lucide="messages-square"></i>
                        <span>Messages</span>
                        <em>{{ number_format($profile['messages']['total']) }}</em>
                    </div>
                </div>

                @if ($contact->notes)
                    <div style="margin-top:14px">
                        <div class="tva-cd__h">Notes</div>
                        <div style="font-size:12.5px;color:#64748b;line-height:1.7">{{ $contact->notes }}</div>
                    </div>
                @endif
            </div>

            <div class="tva-cd__card">
                <div class="tva-cd__h">Linked accounts</div>
                @forelse ($profile['identities'] as $i => $identity)
                    <div class="tva-cd__link">
                        @include('contacts._channel', ['channel' => $identity['channel'], 'size' => 26])
                        <div class="tva-cd__link-t">
                            <b>{{ $identity['label'] }}</b>
                            <span>{{ ucfirst($identity['channel']) }}</span>
                        </div>
                        {{-- The oldest identity is the one this profile grew
                             from — worth naming, because a merged contact
                             immediately raises "which of these is the real
                             one?". --}}
                        @if ($i === 0)<span class="tva-cd__tag">Primary</span>@endif
                    </div>
                @empty
                    <div style="font-size:12px;color:#94a3b8">No linked accounts yet.</div>
                @endforelse

                @if (!empty($profile['merged']))
                    <div class="tva-cd__h" style="margin-top:16px">Merged profiles</div>
                    @foreach ($profile['merged'] as $m)
                        <div class="tva-cd__link">
                            <i data-lucide="git-merge" style="width:16px;height:16px;color:#94a3b8"></i>
                            <div class="tva-cd__link-t">
                                <b>{{ $m['name'] ?? ('Contact #' . ($m['id'] ?? '?')) }}</b>
                                <span>Folded in{{ !empty($m['at']) ? ' ' . \Carbon\Carbon::createFromTimestamp($m['at'])->diffForHumans() : '' }}</span>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="tva-cd__card">
                <div class="tva-cd__h">Engagement</div>
                <div class="tva-cd__score">
                    <b>{{ $e['score'] }}</b>
                    <span class="tva-cd__badge tva-cd__badge--{{ strtolower($e['label']) }}">{{ $e['label'] }}</span>
                </div>
                <div class="tva-cd__bar"><i style="width:{{ $e['score'] }}%"></i></div>
                @foreach ($e['reasons'] as $r)
                    <div class="tva-cd__why"><b>{{ $r[0] }}</b><span>{{ $r[1] }}</span></div>
                @endforeach
            </div>
        </div>

        {{-- ── Right: what has happened ───────────────────────────── --}}
        <div class="tva-cd__right">
            <div class="tva-cd__card" style="padding:20px 22px">
                <div class="tva-cd__tabs">
                    <button class="tva-cd__tab is-on" data-tab="activity">
                        Activity <span>{{ $sessions->count() }}</span>
                    </button>
                    <button class="tva-cd__tab" data-tab="leads">
                        Leads <span>{{ $leads->count() }}</span>
                    </button>
                    {{-- More activity types land here as they are built —
                         orders, calls, notes. The tab bar exists now so the
                         page does not have to be restructured later. --}}
                    <button class="tva-cd__tab" data-tab="soon" disabled style="opacity:.5;cursor:default">
                        Orders <span class="tva-cd__soon">soon</span>
                    </button>
                </div>

                <div class="tva-cd__pane is-on" data-pane="activity">
                    @if ($sessions->isEmpty())
                        <div class="tva-cd__empty">No conversations yet.</div>
                    @else
                        <div class="tva-cd__tl">
                            @foreach ($sessions as $s)
                                <div class="tva-cd__ev">
                                    <div class="tva-cd__ev-head">
                                        @include('contacts._channel', ['channel' => $s->channel, 'size' => 22])
                                        <a href="{{ route('chat.index', ['client' => $client->slug, 'project_id' => hashid($projectId)]) }}#s{{ $s->id }}">
                                            {{ ucfirst($s->channel === 'messenger' ? 'facebook' : $s->channel) }} conversation
                                        </a>
                                        <span class="tva-cd__pill">{{ $previews[$s->id]['count'] }} messages</span>
                                        @if ($s->handoff_status === 'resolved')
                                            <span class="tva-cd__pill tva-cd__pill--converted">Resolved</span>
                                        @endif
                                        <span class="tva-cd__ev-when">
                                            {{ $s->last_activity_at ? \Carbon\Carbon::createFromTimestamp($s->last_activity_at)->diffForHumans() : '' }}
                                        </span>
                                    </div>
                                    @if ($previews[$s->id]['last'])
                                        <div class="tva-cd__ev-body">{{ $previews[$s->id]['last'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="tva-cd__pane" data-pane="leads">
                    @if ($leads->isEmpty())
                        <div class="tva-cd__empty">
                            No leads captured from this contact yet.<br>
                            Leads are extracted automatically once they share what they are after.
                        </div>
                    @else
                        @foreach ($leads as $l)
                            <div class="tva-cd__lead">
                                <i data-lucide="user-check" style="width:17px;height:17px;color:#94a3b8"></i>
                                <div class="min-w-0" style="flex:1">
                                    <a href="{{ route('leads.show', ['client' => $client->slug, 'id' => hashid($l->id)]) }}"
                                       style="font-size:13px;font-weight:600;color:inherit;text-decoration:none">
                                        Lead #{{ $l->id }}
                                    </a>
                                    <div style="font-size:11.5px;color:#94a3b8;margin-top:2px">
                                        @php
                                            $summary = collect((array) $l->fields)
                                                ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')
                                                ->take(3)
                                                ->map(fn ($v, $k) => str_replace('_', ' ', $k) . ': ' . $v)
                                                ->implode(' · ');
                                        @endphp
                                        {{ $summary ?: 'No details captured' }}
                                    </div>
                                </div>
                                @if ($l->confidence !== null)
                                    <span style="font-size:11px;color:#94a3b8">{{ (int) round($l->confidence * 100) }}%</span>
                                @endif
                                <span class="tva-cd__pill tva-cd__pill--{{ $l->status }}">{{ $l->status }}</span>
                                <span style="font-size:11px;color:#94a3b8">
                                    {{ $l->created_at ? \Carbon\Carbon::createFromTimestamp($l->created_at)->diffForHumans() : '' }}
                                </span>
                            </div>
                        @endforeach
                    @endif
                </div>

                <div class="tva-cd__pane" data-pane="soon"></div>
            </div>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.tva-cd__tab:not([disabled])').forEach(function (tab) {
        tab.onclick = function () {
            document.querySelectorAll('.tva-cd__tab').forEach(t => t.classList.toggle('is-on', t === tab));
            document.querySelectorAll('.tva-cd__pane').forEach(function (p) {
                p.classList.toggle('is-on', p.dataset.pane === tab.dataset.tab);
            });
        };
    });
</script>
@endsection
