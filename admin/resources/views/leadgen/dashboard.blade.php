@extends('layouts.master')
@section('content')


<div class="content">
    @include('leadgen._header', ['subtitle' => 'Find businesses that fit what you sell, qualify them, and reach out — with your approval at every send.'])

    @if (!$project)
        <div class="tva-lg-panel" style="padding:44px 20px;text-align:center">
            <i data-lucide="folder-minus" class="w-8 h-8 mx-auto text-slate-300"></i>
            <div class="mt-3 font-semibold">No project available</div>
            <div class="text-xs text-slate-500 mt-2">You aren't assigned to any project in this workspace yet. Ask the workspace owner to give you access.</div>
        </div>
    @elseif (!$ready)
        {{-- Missing tenant tables is a deployment step; the command goes to the log. --}}
        <div class="tva-lg-panel" style="padding:44px 20px;text-align:center">
            <i data-lucide="clock" class="w-8 h-8 mx-auto text-slate-300"></i>
            <div class="mt-3 font-semibold">Lead Generation is being set up for this project</div>
            <div class="text-xs text-slate-500 mt-2">This usually takes a few minutes. If it doesn't clear up, contact support.</div>
        </div>
    @else
        <div class="tva-lg-stats">
            @foreach ([
                ['Prospects found',      $stats['prospects'], 'building', '#4f46e5'],
                ['Awaiting review',      $stats['new'],       'inbox',      '#d97706'],
                ['Added to CRM',         $stats['in_crm'],    'user-check', '#0d9488'],
                ['Active campaigns',     $stats['campaigns'], 'send',       '#2563eb'],
            ] as [$label, $value, $icon, $colour])
                <div class="tva-lg-stat">
                    <div class="tva-lg-stat__ico" style="background:{{ $colour }}18;color:{{ $colour }}"><i data-lucide="{{ $icon }}"></i></div>
                    <div>
                        <div class="tva-lg-stat__n">{{ number_format($value) }}</div>
                        <div class="tva-lg-stat__l">{{ $label }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        @php $u = auth()->user(); @endphp
        <div class="flex gap-2 mt-5 flex-wrap">
            @if ($u->canAbility($client->id, 'leadgen', 'search'))
                <a href="{{ route('leadgen.searches.create', ['client' => $client->slug, 'project_id' => $project->id]) }}" class="btn btn-primary btn-sm">Find businesses</a>
            @endif
            @if ($u->canAbility($client->id, 'leadgen', 'import'))
                <a href="{{ route('leadgen.imports.create', ['client' => $client->slug, 'project_id' => $project->id]) }}" class="btn btn-outline-secondary btn-sm">Import a list</a>
            @endif
            @if ($stats['running'] > 0)
                <a href="{{ route('leadgen.searches.index', ['client' => $client->slug, 'project_id' => $project->id]) }}" class="btn btn-outline-secondary btn-sm">{{ $stats['running'] }} search{{ $stats['running'] === 1 ? '' : 'es' }} running</a>
            @endif
        </div>

        <div class="tva-lg-grid">
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Where prospects come from</div>
                @foreach ($sources as $s)
                    <div class="tva-lg-row">
                        <div>
                            <div class="tva-lg-row__t">{{ $s['name'] }}</div>
                            <div class="tva-lg-row__d">{{ $s['what'] }}</div>
                        </div>
                        @if ($s['configured'])
                            <span class="tva-lg-pill tva-lg-pill--ok">Available</span>
                        @else
                            <span class="tva-lg-pill tva-lg-pill--off" title="{{ $s['hint'] }}">Not available yet</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="tva-lg-panel">
                <div class="tva-lg-h">How outreach is sent</div>
                @foreach ([
                    ['Email',              $channels['email'] ?? 0,     'Cold outreach goes out from your own connected mailbox, with an unsubscribe link in every message.'],
                    ['WhatsApp',           $channels['whatsapp'] ?? 0,  'Approved templates only, and only to people who opted in to hear from you.'],
                    ['Messenger / Instagram', $channels['messenger'] ?? 0, 'Follow-ups to people who already messaged you. Never used for first contact.'],
                ] as [$name, $n, $what])
                    <div class="tva-lg-row">
                        <div>
                            <div class="tva-lg-row__t">{{ $name }}</div>
                            <div class="tva-lg-row__d">{{ $what }}</div>
                        </div>
                        @if ($n > 0)
                            <span class="tva-lg-pill tva-lg-pill--ok">{{ $n }} connected</span>
                        @else
                            <a href="{{ route('channels.index', ['client' => $client->slug, 'project_id' => hashid($project->id)]) }}"
                               class="tva-lg-pill tva-lg-pill--off">Connect</a>
                        @endif
                    </div>
                @endforeach
                @if ($stats['suppressed'] > 0)
                    <div class="text-xs text-slate-500 mt-3">{{ number_format($stats['suppressed']) }} address{{ $stats['suppressed'] === 1 ? '' : 'es' }} on your do-not-contact list.</div>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
