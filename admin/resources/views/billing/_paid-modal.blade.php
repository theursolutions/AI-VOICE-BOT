{{--
    Shown once, after a payment settles.

    Expects $paidCharge — a `gateway_charges` row, already proven to belong to
    this workspace, already proven paid.

    THE DETAIL IS THE POINT. "Thank you, your payment was successful" tells
    somebody nothing they did not already assume; what they actually want is the
    amount, the plan, the dates it bought, and a reference they can quote if
    anything goes wrong. So the modal is a receipt with a tick on it, not a
    congratulation.

    RENDERED FROM THE CHARGE ROW, never from the query string. The reference in
    the URL only selects which row to read; every figure comes from the record
    the webhook wrote. A modal that echoed URL parameters could be made to claim
    any amount at all.
--}}
@php
    $items = json_decode($paidCharge->line_items ?? '', true) ?: [];

    $currency = strtoupper((string) $paidCharge->currency);
    $isAddon  = ($paidCharge->purpose ?? 'plan') === 'addon';

    $tax = (int) ($items['tax'] ?? 0);
@endphp

<style>
    .pd-veil {
        position:fixed; inset:0; z-index:9998; background:rgba(15,23,42,.55);
        backdrop-filter:blur(3px); display:flex; align-items:center; justify-content:center;
        padding:20px; animation:pd-fade .18s ease-out;
    }
    @keyframes pd-fade { from { opacity:0 } to { opacity:1 } }

    .pd {
        background:#fff; border-radius:20px; width:100%; max-width:432px; overflow:hidden;
        box-shadow:0 24px 64px rgba(15,23,42,.28); animation:pd-rise .26s cubic-bezier(.2,.9,.3,1);
    }
    @keyframes pd-rise { from { opacity:0; transform:translateY(14px) scale(.97) } to { opacity:1; transform:none } }

    .pd__top { padding:30px 28px 22px; text-align:center; }

    .pd__tick {
        width:66px; height:66px; border-radius:50%; margin:0 auto 16px;
        background:#dcfce7; display:flex; align-items:center; justify-content:center;
        color:#16a34a; animation:pd-pop .34s cubic-bezier(.2,1.4,.4,1) .06s both;
    }
    @keyframes pd-pop { from { transform:scale(.4); opacity:0 } to { transform:none; opacity:1 } }
    .pd__tick svg { width:32px; height:32px; stroke-width:3; }

    .pd__title { font-size:19px; font-weight:800; color:#0f172a; letter-spacing:-.01em; }
    .pd__sub { font-size:13px; color:#64748b; margin-top:5px; line-height:1.55; }

    .pd__amount { font-size:32px; font-weight:850; color:#0f172a; margin:18px 0 2px; font-variant-numeric:tabular-nums; }
    .pd__amount-note { font-size:11.5px; color:#94a3b8; }

    .pd__rows { padding:4px 28px 20px; }
    .pd__row {
        display:flex; justify-content:space-between; gap:14px; padding:9px 0;
        font-size:13px; border-bottom:1px solid #f1f5f9;
    }
    .pd__row:last-child { border-bottom:0; }
    .pd__row dt { color:#64748b; }
    .pd__row dd { margin:0; color:#0f172a; font-weight:650; text-align:right; }
    .pd__ref { font-family:ui-monospace,monospace; font-size:11.5px; }

    .pd__foot { padding:16px 22px 22px; display:grid; gap:9px; grid-template-columns:1fr 1fr; }
    .pd__btn {
        display:flex; align-items:center; justify-content:center; gap:7px;
        border-radius:11px; padding:11px 14px; font-size:13.5px; font-weight:700;
        text-decoration:none; cursor:pointer; border:1.5px solid transparent;
    }
    .pd__btn--primary { background:#4f46e5; color:#fff; }
    .pd__btn--primary:hover { background:#4338ca; }
    .pd__btn--ghost { background:#fff; color:#334155; border-color:#e2e8f0; }
    .pd__btn--ghost:hover { border-color:#cbd5e1; background:#f8fafc; }

    .pd__close {
        position:absolute; top:14px; right:16px; background:none; border:0; cursor:pointer;
        color:#94a3b8; padding:6px; line-height:0; border-radius:8px;
    }
    .pd__close:hover { background:#f1f5f9; color:#475569; }
    .pd { position:relative; }

    html.dark .pd { background:#1e293b; }
    html.dark .pd__title, html.dark .pd__amount, html.dark .pd__row dd { color:#f1f5f9; }
    html.dark .pd__row { border-bottom-color:#0f172a; }
    html.dark .pd__btn--ghost { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="pd-veil" id="pd-veil">
    <div class="pd" role="dialog" aria-modal="true" aria-labelledby="pd-title">
        <button type="button" class="pd__close" id="pd-close" aria-label="Close">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>

        <div class="pd__top">
            {{-- Inline SVG, not a lucide icon: this is the one mark on the page
                 that must never fail to draw, and a font/bundle problem here
                 would leave a celebration with a blank circle in it. --}}
            <div class="pd__tick">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
            </div>

            <div class="pd__title" id="pd-title">Payment successful</div>
            <div class="pd__sub">
                {{ $isAddon ? 'Your extra capacity is ready to use.' : 'Your plan is active — thank you.' }}
            </div>

            <div class="pd__amount">{{ tva_money((int) $paidCharge->amount_cents, $currency, false) }}</div>
            <div class="pd__amount-note">
                paid {{ \Illuminate\Support\Carbon::parse($paidCharge->paid_at)->format('j M Y, H:i') }}
            </div>
        </div>

        <dl class="pd__rows">
            <div class="pd__row">
                <dt>{{ $isAddon ? 'Added' : 'Plan' }}</dt>
                <dd>
                    @if ($isAddon)
                        {{ (int) ($items['to'] ?? 0) }} × {{ str_replace('addon-', '', (string) ($items['addon_slug'] ?? 'add-on')) }}
                    @else
                        {{ $plan?->name ?? '—' }}{{ $paidCharge->interval ? ' · ' . ucfirst($paidCharge->interval) : '' }}
                    @endif
                </dd>
            </div>

            @if ($tax > 0)
                <div class="pd__row">
                    <dt>{{ ($items['tax_mode'] ?? '') === 'inclusive' ? 'Includes tax' : 'Tax' }}</dt>
                    <dd>{{ tva_money($tax, $currency, false) }}</dd>
                </div>
            @endif

            @if ($paidCharge->period_end)
                <div class="pd__row">
                    <dt>{{ $isAddon ? 'Covered until' : 'Renews' }}</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse($paidCharge->period_end)->format('j M Y') }}</dd>
                </div>
            @endif

            <div class="pd__row">
                <dt>Reference</dt>
                <dd class="pd__ref">{{ $paidCharge->reference }}</dd>
            </div>
        </dl>

        <div class="pd__foot">
            <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="pd__btn pd__btn--ghost">
                <i data-lucide="layout-grid" class="w-4 h-4"></i> Go to plans
            </a>
            <a href="{{ route('billing.receipt', ['client' => $client->slug, 'reference' => $paidCharge->reference]) }}"
               class="pd__btn pd__btn--primary" target="_blank" rel="noopener">
                <i data-lucide="download" class="w-4 h-4"></i> Invoice
            </a>
        </div>
    </div>
</div>

<script>
(function () {
    var veil = document.getElementById('pd-veil');
    if (!veil) return;

    function close() {
        veil.remove();

        // The reference is dropped from the URL so a refresh — or a shared
        // link — does not re-open a celebration for a payment already seen.
        if (window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('paid');
            window.history.replaceState({}, '', url);
        }
    }

    document.getElementById('pd-close').addEventListener('click', close);

    // Clicking the backdrop closes; clicking the card does not.
    veil.addEventListener('click', function (e) { if (e.target === veil) close(); });

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
