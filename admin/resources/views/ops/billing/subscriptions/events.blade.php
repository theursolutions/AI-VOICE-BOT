@extends('layouts.ops')

@section('content')
@include('ops.billing._styles')

<style>
    .ev-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; }
    .ev-table { width:100%; border-collapse:collapse; font-size:13px; }
    .ev-table th, .ev-table td { text-align:left; padding:10px; border-bottom:1px solid #f1f5f9; color:#475569; vertical-align:top; }
    .ev-table th { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
    .ev-ref { font-family:ui-monospace,monospace; font-size:10.5px; color:#94a3b8; }
    .ev-type { font-weight:600; color:#0f172a; font-family:ui-monospace,monospace; font-size:11.5px; }
    .ev-err { font-size:11.5px; color:#b91c1c; max-width:420px; word-break:break-word; }

    .ev-pill { font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; padding:3px 8px; border-radius:999px; }
    .ev-pill--processed { background:#dcfce7; color:#15803d; }
    .ev-pill--skipped   { background:#f1f5f9; color:#475569; }
    .ev-pill--failed    { background:#fee2e2; color:#b91c1c; }
    .ev-pill--pending   { background:#fef3c7; color:#b45309; }
    .ev-pill--test      { background:#ede9fe; color:#6d28d9; }

    .ev-btn {
        display:inline-flex; align-items:center; gap:6px; text-decoration:none;
        font-size:12px; font-weight:600; padding:6px 11px; border-radius:8px;
        border:1px solid #e2e8f0; background:#fff; color:#334155;
    }
    .ev-btn.is-on { background:var(--tva-gradient, linear-gradient(135deg,#c97a00,#8b5cf6)); color:#fff; border-color:transparent; }
    .ev-note { font-size:11.5px; color:#94a3b8; margin-top:14px; line-height:1.6; }

    html.dark .ev-card { background:#1e293b; border-color:#334155; }
    html.dark .ev-type { color:#f1f5f9; }
    html.dark .ev-table th, html.dark .ev-table td { border-color:#334155; }
    html.dark .ev-btn { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🪝</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Stripe events</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every webhook Stripe has sent us, and what we did with it. The first place to look when a
                subscription's state doesn't match the Stripe dashboard.
            </div>
        </div>
        <a href="{{ route('ops.billing.subscriptions.index') }}" class="ob-btn ob-btn--sm">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Subscriptions
        </a>
    </div>

    <div class="ev-card mt-5">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
            @foreach (['' => 'All', 'processed' => 'Processed', 'skipped' => 'Skipped', 'failed' => 'Failed', 'pending' => 'Pending'] as $key => $label)
                <a href="{{ request()->fullUrlWithQuery(['status' => $key ?: null]) }}"
                   class="ob-btn ob-btn--sm {{ $filter === $key ? 'ob-btn--primary' : '' }}">{{ $label }}</a>
            @endforeach
        </div>

        <div style="overflow-x:auto">
            <div class="tva-export-bar">@include('partials.table-export', ['table' => '#tva-t-ops-billing-subscriptions-events', 'filename' => 'ops-billing-subscriptions-events', 'paginator' => $events ?? null])</div>
            <table class="ev-table" id="tva-t-ops-billing-subscriptions-events">
                <thead>
                    <tr>
                        <th>Received</th><th>Type</th><th>Event id</th>
                        <th>Result</th><th>Mode</th><th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td>
                                {{ $event->created_at?->format('j M Y H:i') }}
                                @if ($event->processed_at && $event->created_at)
                                    <div class="ev-ref">{{ $event->created_at->diffInMilliseconds($event->processed_at) }}ms</div>
                                @endif
                            </td>
                            <td><span class="ev-type">{{ $event->type }}</span></td>
                            <td><span class="ev-ref">{{ $event->stripe_event_id }}</span></td>
                            <td>
                                <span class="ob-pill ob-pill--{{ $event->status }}">{{ $event->status }}</span>
                                @if ($event->attempts > 1)
                                    <div class="ev-ref">{{ $event->attempts }} attempts</div>
                                @endif
                            </td>
                            <td>
                                @if ($event->livemode === false)
                                    <span class="ob-pill ob-pill--test">test</span>
                                @elseif ($event->livemode)
                                    <span class="ev-ref">live</span>
                                @else
                                    <span class="ev-ref">—</span>
                                @endif
                            </td>
                            <td class="ev-err">{{ $event->error }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="color:#94a3b8;padding:22px">
                                No events recorded yet. If you've completed a checkout and this is empty, the
                                webhook endpoint isn't reaching us — check the URL and signing secret in the
                                Stripe dashboard.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px">{{ $events->links() }}</div>

        <p class="ev-note">
            <strong>Every event is recorded exactly once.</strong> The unique index on the Stripe event id is
            what makes that true — Stripe delivers at least once and retries any non-2xx, so duplicates are
            normal traffic, not a fault. A duplicate is ACKed and ignored, never re-applied.
            <br>
            <strong>skipped</strong> means an event type we don't act on. <strong>failed</strong> means we
            tried and threw; Stripe will keep retrying with backoff, and the payload is stored here so it can
            be replayed. <strong>Nothing arriving at all</strong> points at the endpoint URL
            (<code>{{ url('/stripe/webhook') }}</code>) or a mismatched <code>STRIPE_WEBHOOK_SECRET</code> —
            an unverifiable request is rejected with 400 before it's ever recorded.
        </p>
    </div>
</div>
@endsection
