{{--
    What the customer can actually pay with.

    Expects $methods — a list of slugs, in the order to show them.

    REAL MARKS, IN CIRCLES. Each logo is a file under
    `public/assets/dist/images/payments/`; the component looks it up by name and
    uses whatever it finds (.svg, then .png, then .webp). Drop a better file in
    and it appears — no code change.

    When a file is genuinely absent the method still renders, as its name in the
    brand's own colour with a neutral icon. That fallback exists because a
    missing logo must not leave a hole where a payment method should be — not
    because an approximated logo would do. A redrawn mark on a payment page
    reads as a phishing attempt, which is the opposite of the reassurance it was
    put there to give.
--}}
@php
    $catalogue = [
        'card' => [
            'label' => 'Debit & credit card',
            // Two marks, overlapped — the usual acceptance-mark treatment.
            'files' => ['visa', 'mastercard'],
            'icon'  => 'credit-card',
            'ink'   => '#1434cb',
        ],
        'bank' => [
            'label' => 'Bank account',
            'files' => ['bank'],
            'icon'  => 'landmark',
            'ink'   => '#0f766e',
        ],
        'jazzcash' => [
            'label' => 'JazzCash',
            'files' => ['jazzcash'],
            'icon'  => 'smartphone',
            'ink'   => '#ee2327',
        ],
        'easypaisa' => [
            'label' => 'Easypaisa',
            'files' => ['easypaisa'],
            'icon'  => 'smartphone',
            'ink'   => '#00a651',
        ],
        'paypal' => [
            'label' => 'PayPal',
            'files' => ['paypal'],
            'icon'  => 'wallet',
            'ink'   => '#003087',
        ],
        'applepay' => [
            'label' => 'Apple Pay',
            'files' => ['applepay'],
            'icon'  => 'smartphone',
            'ink'   => '#111827',
        ],
        'googlepay' => [
            'label' => 'Google Pay',
            'files' => ['googlepay'],
            'icon'  => 'smartphone',
            'ink'   => '#5f6368',
        ],
    ];

    // Resolved by `file_exists` rather than from a config list, so an operator
    // dropping a logo in has nobody to tell. Cache-busted on mtime: a replaced
    // logo should not sit behind a stale browser cache for a week.
    $logo = function (string $name): ?string {
        foreach (['svg', 'png', 'webp'] as $ext) {
            $relative = "assets/dist/images/payments/{$name}.{$ext}";
            $absolute = public_path($relative);

            if (file_exists($absolute)) {
                return asset($relative) . '?v=' . filemtime($absolute);
            }
        }

        return null;
    };
@endphp

<style>
    .pm-row { display:flex; flex-wrap:wrap; gap:9px; }
    .pm {
        display:flex; align-items:center; gap:9px; border:1px solid #e2e8f0;
        border-radius:999px; padding:6px 14px 6px 7px; background:#fff; min-height:44px;
    }

    /* The circle. White inside whatever the surrounding card is, so a logo
       drawn for a light background is never sitting on a dark one. */
    .pm__circle {
        width:30px; height:30px; border-radius:50%; flex:none; background:#fff;
        display:flex; align-items:center; justify-content:center;
        box-shadow:inset 0 0 0 1px rgba(15,23,42,.10);
    }
    .pm__circle img {
        /* Contained, not covered: a logo cropped by a circle is a ruined logo,
           so it is scaled to fit inside one with a little breathing room. */
        max-width:19px; max-height:19px; width:auto; height:auto;
        object-fit:contain; display:block;
    }

    /* Overlapped pair, for "card". */
    .pm__stack { display:flex; align-items:center; flex:none; }
    .pm__stack .pm__circle + .pm__circle { margin-left:-11px; }

    .pm__label { font-size:12.5px; font-weight:650; color:#334155; white-space:nowrap; }

    html.dark .pm { background:#0f172a; border-color:#334155; }
    html.dark .pm__label { color:#cbd5e1; }
    /* The circles stay light in dark mode, deliberately — see above. */
</style>

<div class="pm-row">
    @foreach ($methods as $slug)
        @php $m = $catalogue[$slug] ?? null; @endphp
        @continue (! $m)

        @php $images = array_values(array_filter(array_map($logo, $m['files']))); @endphp

        <div class="pm">
            @if ($images)
                <span class="pm__stack">
                    @foreach ($images as $src)
                        <span class="pm__circle">
                            <img src="{{ $src }}" alt="" onerror="this.closest('.pm__circle').remove()">
                        </span>
                    @endforeach
                </span>
            @else
                {{-- No file yet. The brand's colour and a neutral icon — never
                     a redrawn logo. --}}
                <span class="pm__circle" style="box-shadow:inset 0 0 0 1px {{ $m['ink'] }}33">
                    <i data-lucide="{{ $m['icon'] }}" class="w-4 h-4" style="color:{{ $m['ink'] }}"></i>
                </span>
            @endif

            <span class="pm__label">{{ $m['label'] }}</span>
        </div>
    @endforeach
</div>
