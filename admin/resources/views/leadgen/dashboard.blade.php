@extends('layouts.master')
@section('content')
@php
    $u    = auth()->user();
    $can  = fn (string $a) => $u->canAbility($client->id, 'leadgen', $a);
    $base = $project ? ['client' => $client->slug, 'project_id' => $project->id] : ['client' => $client->slug];
@endphp
<div class="content">
    @include('leadgen._header', ['hideTitle' => true])

    @if (!$project)
        <div class="tva-lg-panel tva-lg-empty">
            <span class="tva-tone tva-tone--slate mx-auto" style="width:46px;height:46px"><i data-lucide="folder-minus"></i></span>
            <div class="mt-3 font-semibold text-slate-600">No project available</div>
            <div class="mt-1">You aren’t assigned to any project in this workspace yet. Ask the workspace owner to give you access.</div>
        </div>
    @elseif (!$ready)
        <div class="tva-lg-panel tva-lg-empty">
            <span class="tva-tone tva-tone--amber mx-auto" style="width:46px;height:46px"><i data-lucide="clock"></i></span>
            <div class="mt-3 font-semibold text-slate-600">Lead Generation is being set up for this project</div>
            <div class="mt-1">This usually takes a few minutes. If it doesn’t clear up, contact support.</div>
        </div>
    @else
        @php
            $steps = [
                ['Describe your offer', 'target', 'indigo', $stats['profiles'] > 0,
                 'Say what you sell and who your ideal customer is. Scores and AI drafts use this.',
                 $can('settings') ? route('leadgen.profiles.create', $base) : null, 'Create offer profile'],
                ['Find businesses', 'search', 'blue', $stats['prospects'] > 0,
                 'Search an area for business types you want, or import your own CSV / Excel list.',
                 $can('search') ? route('leadgen.searches.create', $base) : ($can('import') ? route('leadgen.imports.create', $base) : null), 'Start a search'],
                ['Check & score', 'clipboard-check', 'emerald', $stats['scored'] > 0,
                 'Check their websites for contact details, then let AI score each one against your offer.',
                 route('leadgen.prospects.index', $base), 'Open prospects'],
                ['Organise', 'list', 'amber', $stats['lists'] > 0 || $stats['in_crm'] > 0,
                 'Approve the good fits, group them into lists, and add the best to your CRM.',
                 route('leadgen.review.index', $base), 'Review scores'],
                ['Write outreach', 'pen-tool', 'rose', $stats['drafts'] > 0,
                 'Draft a personal first message with AI or a template — then edit it. Nothing sends without approval.',
                 route('leadgen.prospects.index', $base + ['status' => 'approved']), 'Pick a prospect'],
            ];
            $done = collect($steps)->filter(fn ($s) => $s[3])->count();
        @endphp

        {{-- Banner --}}
        <section class="tva-lg-hero">
            <div class="tva-lg-hero__row">
                <div style="flex:1; min-width:260px">
                    <h1>Lead Generation</h1>
                    <p>Find businesses that fit what you sell, check their public details, score them with AI and prepare personal outreach — all in one place, with your approval at every step.</p>
                    <div class="tva-lg-hero__btns">
                        @if ($can('search'))
                            <a class="tva-lg-btn-white" href="{{ route('leadgen.searches.create', $base) }}"><i data-lucide="search"></i>Find businesses</a>
                        @endif
                        @if ($can('import'))
                            <a class="tva-lg-btn-ghost" href="{{ route('leadgen.imports.create', $base) }}"><i data-lucide="upload"></i>Import a list</a>
                        @endif
                        @if ($stats['running'] > 0)
                            <a class="tva-lg-btn-ghost" href="{{ route('leadgen.searches.index', $base) }}"><i data-lucide="activity"></i>{{ $stats['running'] }} running</a>
                        @endif
                    </div>
                </div>
                <div class="tva-lg-ring">
                    <svg width="56" height="56" viewBox="0 0 36 36" aria-hidden="true">
                        <circle cx="18" cy="18" r="15.5" fill="none" stroke="rgba(255,255,255,.25)" stroke-width="3.5"/>
                        <circle cx="18" cy="18" r="15.5" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round"
                                stroke-dasharray="{{ round($done / 5 * 97.4, 1) }} 97.4" transform="rotate(-90 18 18)"/>
                    </svg>
                    <div>
                        <div class="tva-lg-ring__n">{{ $done }}/5</div>
                        <div class="tva-lg-ring__l">{{ $done === 5 ? 'All set up' : 'Getting started' }}</div>
                    </div>
                </div>
            </div>
        </section>

        {{-- How it works --}}
        <div class="tva-lg-card-h mt-6 flex-wrap"><b>How it works</b><span class="tva-lg-sub">Five steps from “who should we contact?” to a ready-to-send message.</span></div>
        <div class="tva-lg-steps" style="margin-top:0">
            @foreach ($steps as $i => [$t, $icon, $tone, $isDone, $desc, $href, $cta])
                <div class="tva-lg-step {{ $isDone ? 'tva-lg-step--done' : '' }}">
                    <span class="tva-lg-step__num">{{ $i + 1 }}</span>
                    <span class="tva-tone tva-tone--{{ $tone }}" style="width:40px;height:40px"><i data-lucide="{{ $icon }}"></i></span>
                    <div class="tva-lg-step__t">{{ $t }}</div>
                    <div class="tva-lg-step__d">{{ $desc }}</div>
                    @if ($isDone)
                        <span class="tva-lg-step__ok"><i data-lucide="check-circle"></i>Done</span>
                    @elseif ($href)
                        <a class="tva-lg-step__cta" href="{{ $href }}">{{ $cta }}<i data-lucide="arrow-right"></i></a>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Numbers --}}
        <div class="tva-lg-kpis">
            @foreach ([
                ['Prospects',          $stats['prospects'],  'building',   'indigo',  route('leadgen.prospects.index', $base)],
                ['Waiting for review', $stats['review'],     'inbox',      'amber',   route('leadgen.prospects.index', $base + ['status' => 'reviewing'])],
                ['High fit',           $stats['high_fit'],   'award',      'emerald', route('leadgen.review.index', $base + ['band' => 'high'])],
                ['Added to CRM',       $stats['in_crm'],     'user-check', 'teal',    route('leadgen.prospects.index', $base + ['status' => 'in_crm'])],
                ['Drafts ready',       $stats['drafts'],     'mail',       'rose',    null],
                ['Do not contact',     $stats['suppressed'], 'slash',      'slate',   route('leadgen.prospects.index', $base + ['show' => 'suppressed'])],
            ] as [$label, $n, $icon, $tone, $href])
                @if ($href)<a class="tva-lg-kpi" href="{{ $href }}">@else<div class="tva-lg-kpi">@endif
                    <span class="tva-tone tva-tone--{{ $tone }}" style="width:36px;height:36px"><i data-lucide="{{ $icon }}"></i></span>
                    <div>
                        <div class="tva-lg-kpi__n">{{ number_format($n) }}</div>
                        <div class="tva-lg-kpi__l">{{ $label }}</div>
                    </div>
                @if ($href)</a>@else</div>@endif
            @endforeach
        </div>

        <div class="tva-lg-grid" style="align-items:start">
            {{-- Recent searches --}}
            <div class="tva-lg-panel">
                <div class="tva-lg-card-h">
                    <span class="tva-tone tva-tone--blue" style="width:32px;height:32px"><i data-lucide="clock"></i></span>
                    <b>Recent searches</b>
                    <a href="{{ route('leadgen.searches.index', $base) }}">See all</a>
                </div>
                @forelse ($recent as $s)
                    @php $sp = (array) $s->progress; @endphp
                    <a class="tva-lg-item" href="{{ route('leadgen.searches.show', $base + ['id' => $s->id]) }}">
                        <span class="tva-tone {{ $s->provider === 'import' ? 'tva-tone--violet' : 'tva-tone--blue' }}" style="width:32px;height:32px">
                            <i data-lucide="{{ $s->provider === 'import' ? 'upload' : 'map-pin' }}"></i>
                        </span>
                        <div style="min-width:0;flex:1">
                            <div class="tva-lg-item__t truncate">{{ $s->name }}</div>
                            <div class="tva-lg-item__d">{{ number_format((int) ($sp['new'] ?? 0)) }} new · {{ $s->created_at ? \Carbon\Carbon::createFromTimestamp($s->created_at)->diffForHumans() : '' }}</div>
                        </div>
                        @include('leadgen.searches._status', ['status' => $s->status])
                    </a>
                @empty
                    <div class="tva-lg-item">
                        <div class="tva-lg-item__d">No searches yet. Start with “Find businesses” — for example real-estate agencies in Dubai.</div>
                    </div>
                @endforelse
            </div>

            <div>
                {{-- Sources --}}
                <div class="tva-lg-panel" style="margin-top:18px">
                    <div class="tva-lg-card-h">
                        <span class="tva-tone tva-tone--indigo" style="width:32px;height:32px"><i data-lucide="database"></i></span>
                        <b>Where prospects come from</b>
                    </div>
                    @foreach ([
                        ['Business listings', 'map', 'blue', $sources[0]['configured'], 'Open data from Overture Maps — names, categories, websites, phones and addresses, free to store and use.'],
                        ['Your own lists', 'upload', 'violet', true, 'Import CSV or Excel files you already have. Duplicates are matched automatically.'],
                        ['Their own websites', 'globe', 'teal', true, 'We read a business’s contact page for its public email and phone, and mark what we confirmed.'],
                    ] as [$name, $icon, $tone, $on, $what])
                        <div class="tva-lg-item">
                            <span class="tva-tone tva-tone--{{ $tone }}" style="width:32px;height:32px"><i data-lucide="{{ $icon }}"></i></span>
                            <div style="flex:1">
                                <div class="tva-lg-item__t">{{ $name }}</div>
                                <div class="tva-lg-item__d">{{ $what }}</div>
                            </div>
                            <span class="tva-lg-pill {{ $on ? 'tva-lg-pill--ok' : 'tva-lg-pill--off' }}">{{ $on ? 'Ready' : 'Not available yet' }}</span>
                        </div>
                    @endforeach
                </div>

                {{-- Channels --}}
                <div class="tva-lg-panel">
                    <div class="tva-lg-card-h">
                        <span class="tva-tone tva-tone--rose" style="width:32px;height:32px"><i data-lucide="send"></i></span>
                        <b>How outreach is sent</b>
                        @if ($u->canModule($client->id, 'channels'))
                            <a href="{{ route('channels.index', ['client' => $client->slug, 'project_id' => hashid($project->id)]) }}">Manage channels</a>
                        @endif
                    </div>
                    @foreach ([
                        ['Email', 'mail', 'blue', $channels['email'] ?? 0, 'From your own connected mailbox, with an unsubscribe link in every message.'],
                        ['WhatsApp', 'message-circle', 'green', $channels['whatsapp'] ?? 0, 'Approved templates only, to people who opted in to hear from you.'],
                        ['Messenger & Instagram', 'message-square', 'violet', $channels['messenger'] ?? 0, 'Follow-ups to people who messaged you first — never cold.'],
                    ] as [$name, $icon, $tone, $n, $what])
                        <div class="tva-lg-item">
                            <span class="tva-tone tva-tone--{{ $tone }}" style="width:32px;height:32px"><i data-lucide="{{ $icon }}"></i></span>
                            <div style="flex:1">
                                <div class="tva-lg-item__t">{{ $name }}</div>
                                <div class="tva-lg-item__d">{{ $what }}</div>
                            </div>
                            <span class="tva-lg-pill {{ $n > 0 ? 'tva-lg-pill--ok' : 'tva-lg-pill--off' }}">{{ $n > 0 ? $n . ' connected' : 'Not connected' }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Trust --}}
        <div class="tva-lg-panel">
            <div class="tva-lg-card-h">
                <span class="tva-tone tva-tone--emerald" style="width:32px;height:32px"><i data-lucide="shield-check"></i></span>
                <b>Kept honest and compliant</b>
            </div>
            <div class="tva-lg-trust">
                <div><i data-lucide="eye" class="w-4 h-4 text-slate-400 flex-shrink-0"></i><span>Every detail shows where it came from and whether it was confirmed — nothing unverified is presented as fact.</span></div>
                <div><i data-lucide="award" class="w-4 h-4 text-slate-400 flex-shrink-0"></i><span>AI scores cite the facts they used; guesses about a business’s needs are labelled as guesses.</span></div>
                <div><i data-lucide="lock" class="w-4 h-4 text-slate-400 flex-shrink-0"></i><span>Nothing is sent without approval, and only through channels the recipient allows.</span></div>
                <div><i data-lucide="slash" class="w-4 h-4 text-slate-400 flex-shrink-0"></i><span>Opt-outs go on your do-not-contact list and are respected everywhere.</span></div>
            </div>
        </div>
    @endif
</div>
@endsection
