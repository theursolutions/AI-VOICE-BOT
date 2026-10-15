@extends('layouts.master')
@section('content')
<style>
    .tva-lg-stats { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:16px; margin-top:20px; }
    @media (max-width: 1100px) { .tva-lg-stats { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    @media (max-width: 600px)  { .tva-lg-stats { grid-template-columns:1fr; gap:12px; } }
    .tva-lg-stat { display:flex; align-items:center; gap:14px;
                   background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; }
    html.dark .tva-lg-stat { background:#1e293b; border-color:#334155; }
    .tva-lg-stat__ico { width:42px; height:42px; border-radius:11px; flex-shrink:0;
                        display:flex; align-items:center; justify-content:center; }
    .tva-lg-stat__ico svg { width:19px !important; height:19px !important; }
    .tva-lg-stat__n { font-size:23px; font-weight:700; line-height:1.15; color:#0f172a; font-variant-numeric:tabular-nums; }
    html.dark .tva-lg-stat__n { color:#f1f5f9; }
    .tva-lg-stat__l { font-size:11.5px; color:#94a3b8; margin-top:3px; }
    .tva-lg-panel { background:#fff; border:1px solid #e2e8f0; border-radius:14px; margin-top:18px; padding:18px 20px; }
    html.dark .tva-lg-panel { background:#1e293b; border-color:#334155; }
    .tva-lg-h { font-size:13px; font-weight:700; color:#0f172a; margin-bottom:12px; }
    html.dark .tva-lg-h { color:#f1f5f9; }
    .tva-lg-row { display:flex; align-items:flex-start; gap:12px; padding:12px 0; border-top:1px solid #e2e8f0; }
    .tva-lg-row:first-of-type { border-top:0; padding-top:0; }
    html.dark .tva-lg-row { border-top-color:#334155; }
    .tva-lg-row__t { font-weight:600; font-size:12.5px; color:#334155; }
    html.dark .tva-lg-row__t { color:#e2e8f0; }
    .tva-lg-row__d { font-size:11.5px; color:#94a3b8; margin-top:2px; line-height:1.55; }
    .tva-lg-pill { font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; margin-left:auto; }
    .tva-lg-pill--ok   { background:#dcfce7; color:#15803d; }
    .tva-lg-pill--off  { background:#f1f5f9; color:#64748b; }
    html.dark .tva-lg-pill--off { background:#334155; color:#cbd5e1; }
    .tva-lg-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width: 900px) { .tva-lg-grid { grid-template-columns:1fr; } }
</style>

<div class="content">
    <div class="flex items-center gap-3 mt-4 flex-wrap">
        <h2 class="text-lg font-semibold">Lead Generation</h2>
        <div class="text-xs text-slate-500">Find businesses that fit what you sell, qualify them, and reach out — with your approval at every send.</div>
        @if ($projects->count() > 1)
            <form method="GET" class="ml-auto">
                <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    @foreach ($projects as $p)
                        <option value="{{ hashid($p->id) }}" @selected($project && (int) $project->id === (int) $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

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
