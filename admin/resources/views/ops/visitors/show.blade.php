@extends('layouts.ops')

@section('content')
<style>
    .vd-wrap { max-width: 1080px; }
    .vd-two { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px; }
    @media (max-width:900px){ .vd-two{ grid-template-columns:1fr; } }

    .vd-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; }
    .vd-card__title { font-size:13px; font-weight:700; color:#0f172a; margin-bottom:14px; display:flex; align-items:center; gap:7px; }

    .vd-row { display:flex; gap:12px; padding:7px 0; border-bottom:1px solid #f1f5f9; font-size:12.5px; }
    .vd-row:last-child { border-bottom:none; }
    .vd-row__k { flex:0 0 40%; color:#64748b; }
    .vd-row__v { flex:1; color:#0f172a; word-break:break-word; }
    .vd-mono { font-family:ui-monospace,monospace; font-size:11.5px; }

    .vd-trail { list-style:none; margin:0; padding:0; }
    .vd-trail li { display:flex; gap:12px; align-items:baseline; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:12.5px; }
    .vd-trail li:last-child { border-bottom:none; }
    .vd-trail time { flex:0 0 130px; color:#94a3b8; font-size:11.5px; font-family:ui-monospace,monospace; }
    .vd-trail .p { color:#0f172a; font-weight:600; word-break:break-all; }

    html.dark .vd-card { background:#1e293b; border-color:#334155; }
    html.dark .vd-card__title, html.dark .vd-row__v, html.dark .vd-trail .p { color:#f1f5f9; }
    html.dark .vd-row, html.dark .vd-trail li { border-bottom-color:#334155; }
</style>

<div class="content vd-wrap">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">{{ $visitor->flag ?: '👤' }}</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">{{ $visitor->ip ?: 'Unknown IP' }}</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                {{ $visitor->location ?: 'Location not resolved' }} ·
                {{ number_format($visitor->page_views) }} page open(s) ·
                last seen {{ $visitor->last_seen_at?->diffForHumans() ?: '—' }}
            </div>
        </div>
        <a href="{{ route('ops.visitors.index') }}" style="background:rgba(255,255,255,.18); padding:10px 16px; border-radius:10px; color:#fff; font-weight:600; font-size:13px; text-decoration:none;">
            ← All visitors
        </a>
    </div>

    @if ($leads->isNotEmpty())
        <div class="alert alert-success-soft show mb-4">
            <strong>This visitor became a lead.</strong>
            @foreach ($leads as $l)
                <div style="font-size:12.5px;margin-top:4px;">
                    {{ $l->created_at?->format('M j, Y H:i') }} —
                    {{ $l->phone ?: '' }}{{ $l->email ? ' · '.$l->email : '' }}{{ $l->name ? ' · '.$l->name : '' }}
                    <span style="color:#64748b;">({{ $l->source }}, {{ $l->status }})</span>
                </div>
            @endforeach
            <div style="font-size:12px;margin-top:6px;">
                <a href="{{ route('ops.contacts.index') }}" style="color:var(--tva-accent);font-weight:600;">Open in Website Contacts →</a>
            </div>
        </div>
    @endif

    <div class="vd-two">
        <div class="vd-card">
            <div class="vd-card__title">🌍 From the IP address</div>
            @php
                $geoRows = [
                    'IP address'      => $visitor->ip,
                    'Country'         => trim(($visitor->flag ? $visitor->flag.' ' : '').($visitor->country ?: '')) ?: null,
                    'Region'          => $visitor->region,
                    'City'            => $visitor->city,
                    'Postal code'     => $visitor->postal,
                    'Continent'       => $visitor->continent,
                    'Time zone'       => $visitor->timezone,
                    'ISP / network'   => $visitor->org,
                    'ASN'             => $visitor->asn,
                    'Connection'      => $visitor->connection_type,
                    'Coordinates'     => $visitor->latitude && $visitor->longitude
                        ? number_format($visitor->latitude, 4).', '.number_format($visitor->longitude, 4) : null,
                    'Lookup status'   => $visitor->geo_status,
                ];
            @endphp
            @foreach ($geoRows as $k => $v)
                <div class="vd-row">
                    <div class="vd-row__k">{{ $k }}</div>
                    <div class="vd-row__v">{{ $v ?: '—' }}</div>
                </div>
            @endforeach
            @if ($visitor->latitude && $visitor->longitude)
                <div style="margin-top:12px;">
                    <a href="https://www.openstreetmap.org/?mlat={{ $visitor->latitude }}&mlon={{ $visitor->longitude }}#map=11/{{ $visitor->latitude }}/{{ $visitor->longitude }}"
                       target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                        <i data-lucide="map" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> View on map
                    </a>
                </div>
            @endif
        </div>

        <div class="vd-card">
            <div class="vd-card__title">💻 From the request headers</div>
            @php
                $uaRows = [
                    'Browser'      => trim(($visitor->browser ?: '').' '.($visitor->browser_version ?: '')) ?: null,
                    'Operating system' => $visitor->os,
                    'Device type'  => $visitor->device_type,
                    'Automated'    => $visitor->is_bot ? 'Yes — crawler or script' : 'No',
                    'Language'     => $visitor->language,
                    'Landed on'    => $visitor->landing_path,
                    'Last page'    => $visitor->last_path,
                    'Referrer'     => $visitor->referrer,
                    'UTM source'   => $visitor->utm_source,
                    'UTM medium'   => $visitor->utm_medium,
                    'UTM campaign' => $visitor->utm_campaign,
                    'First seen'   => $visitor->first_seen_at?->format('M j, Y H:i'),
                    'Last seen'    => $visitor->last_seen_at?->format('M j, Y H:i'),
                ];
            @endphp
            @foreach ($uaRows as $k => $v)
                <div class="vd-row">
                    <div class="vd-row__k">{{ $k }}</div>
                    <div class="vd-row__v">{{ $v ?: '—' }}</div>
                </div>
            @endforeach
            <div class="vd-row">
                <div class="vd-row__k">Raw User-Agent</div>
                <div class="vd-row__v vd-mono">{{ $visitor->user_agent ?: '—' }}</div>
            </div>
        </div>
    </div>

    <div class="vd-card">
        <div class="vd-card__title">🧭 Page trail — every page this visitor opened</div>
        <ul class="vd-trail">
            @forelse ($views as $pv)
                <li>
                    <time>{{ $pv->created_at?->format('M j, H:i:s') }}</time>
                    <span class="p">{{ $pv->path }}</span>
                    @if ($pv->referrer)
                        <span style="color:#94a3b8;font-size:11px;">← {{ parse_url($pv->referrer, PHP_URL_HOST) ?: $pv->referrer }}</span>
                    @endif
                </li>
            @empty
                <li><span style="color:#94a3b8;">No page views recorded.</span></li>
            @endforelse
        </ul>

        @if ($views->hasPages())
            <div style="padding:14px 0 0;">{{ $views->links() }}</div>
        @endif
    </div>
</div>

<script>if (window.lucide?.createIcons) { try { window.lucide.createIcons({ icons: (window.lucide.icons || {}), nameAttr: "data-lucide" }); } catch (_) {} }</script>
@endsection
