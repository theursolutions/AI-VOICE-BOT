{{--
    "Where are you buying from" — the control that decides currency, gateway
    and price.

    Expects:
      $countryAction   route to POST to (workspace or public)
      $countryCurrent  the ISO-2 code currently in force
      $countryDetected the code the IP suggested, or null
      $countryCurrency the currency being charged

    A REAL FORM UNDERNEATH. The visible control is a button and a panel, but
    what submits is a plain <select> that works with JavaScript off — every
    price, symbol and provider name on the page is rendered server-side, so a
    round trip is not a compromise, it is the only way the whole page is
    certain to agree with itself.

    FLAGS ARE IMAGES, NOT EMOJI. Regional-indicator emoji render as two bare
    letters on Windows, which is most of this product's customers — so a flag
    that only works on a Mac is not a flag. Images come from flagcdn, and any
    that fails to load falls back to the country code in the same circle, so a
    blocked CDN or an offline machine degrades to something still legible
    rather than to a row of broken-image icons.
--}}
@php
    $countries = \App\Support\Payments::sellableCountries();
    $current   = $countries[$countryCurrent] ?? null;
    $guessed   = $countryDetected && $countryDetected === $countryCurrent;

    // WHICH PROCESSOR handles the money is our business, not the customer's.
    // Naming it here would put a company they have never heard of between them
    // and the buy button, and it changes — the copy would then be wrong the day
    // the routing does.
    $gateway = app(\App\Services\Billing\Gateways\GatewayRegistry::class)
                ->forCountry($countryCurrent);
@endphp

<style>
    .cp-card {
        border:1px solid #e2e8f0; border-radius:16px; background:#fff; padding:16px 18px;
        display:flex; align-items:center; gap:16px; flex-wrap:wrap;
        box-shadow:0 1px 2px rgba(16,24,40,.04);
    }
    .cp-card__lead { display:flex; align-items:center; gap:11px; margin-right:auto; }
    .cp-card__icon {
        width:38px; height:38px; border-radius:11px; flex:none; display:flex;
        align-items:center; justify-content:center; background:#eef2ff; color:#4f46e5;
    }
    .cp-card__title { font-size:14px; font-weight:750; color:#0f172a; line-height:1.3; }
    .cp-card__sub { font-size:12px; color:#64748b; margin-top:2px; line-height:1.45; }
    .cp-card__sub strong { color:#334155; font-weight:700; }

    /* ── The flag circle, shared by the button and every row ───────── */
    /*
       The code sits UNDERNEATH the image, always rendered. When the image
       loads it covers it; when it fails `onerror` removes the image and the
       code is simply revealed. No JavaScript decides anything, so a blocked
       CDN, an offline laptop or a slow network degrades to something legible
       instead of an empty circle.
    */
    .cp-flag {
        width:26px; height:26px; border-radius:50%; flex:none; position:relative;
        overflow:hidden; background:#e8edf3; display:flex; align-items:center;
        justify-content:center; box-shadow:inset 0 0 0 1px rgba(15,23,42,.12);
    }
    .cp-flag img {
        position:absolute; inset:0; width:100%; height:100%;
        /* Flags are 4:3 and the circle is 1:1, so a contained image would sit
           in a letterboxed band. Cover fills it; the slight crop off the left
           and right edges is what every flag picker does. */
        object-fit:cover; display:block;
    }
    .cp-flag__code {
        font-size:9.5px; font-weight:800; color:#64748b; letter-spacing:.02em;
    }
    .cp-flag--lg { width:34px; height:34px; }
    .cp-flag--lg .cp-flag__code { font-size:11.5px; }

    /* ── The button ────────────────────────────────────────────────── */
    .cp-btn {
        display:flex; align-items:center; gap:11px; min-width:268px;
        border:1.5px solid #e2e8f0; border-radius:12px; background:#fff;
        padding:9px 13px; cursor:pointer; text-align:left; transition:border-color .12s, box-shadow .12s;
    }
    .cp-btn:hover { border-color:#c7d2fe; }
    .cp-btn[aria-expanded="true"] { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.13); }
    .cp-btn__name { font-size:14px; font-weight:700; color:#0f172a; line-height:1.25; }
    .cp-btn__meta { font-size:11.5px; color:#94a3b8; margin-top:1px; }
    .cp-btn__body { min-width:0; margin-right:auto; }
    .cp-btn__chev { color:#94a3b8; flex:none; transition:transform .15s; }
    .cp-btn[aria-expanded="true"] .cp-btn__chev { transform:rotate(180deg); }

    .cp-cur {
        font-size:10.5px; font-weight:800; letter-spacing:.04em; color:#4f46e5;
        background:#eef2ff; border-radius:999px; padding:3px 8px; flex:none;
    }

    /* ── The panel ─────────────────────────────────────────────────── */
    /*
       POSITIONED FIXED AND MOVED TO <body> WHEN OPEN, which looks like
       overkill and is not. The admin template gives every `.intro-y` sibling
       BOTH a transform and `z-index: calc(50 - n)`, so each one is its own
       stacking context — and a transformed ancestor also captures
       `position: fixed`. No z-index this panel could carry escapes that from
       the inside; it would keep appearing behind the cards below it. Lifting
       it out of the tree is the only fix that actually works, and it also
       escapes any ancestor with `overflow: hidden`.
    */
    .cp-wrap { position:relative; }
    .cp-panel {
        position:fixed; z-index:99999; width:340px; max-width:92vw;
        background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden;
        box-shadow:0 18px 48px rgba(16,24,40,.18), 0 2px 6px rgba(16,24,40,.08);
    }
    .cp-panel[hidden] { display:none; }
    .cp-search { padding:11px 12px; border-bottom:1px solid #f1f5f9; position:relative; }
    .cp-search input {
        width:100%; border:1px solid #e2e8f0; border-radius:9px; padding:8px 11px 8px 34px;
        font-size:13.5px; color:#0f172a; background:#f8fafc;
    }
    .cp-search input:focus { outline:2px solid #6366f1; outline-offset:-1px; background:#fff; }

    /*
       Targeted BY CLASS, not by element. Lucide replaces each `<i data-lucide>`
       with an `<svg>` and carries the class across — so a selector written as
       `.cp-search i` matches until the icons initialise and then silently stops,
       dropping the absolute positioning and letting the icon fall inline
       outside the field. Every icon rule here is class-based for that reason.
    */
    .cp-search__icon {
        position:absolute; left:23px; top:50%; margin-top:-8px;
        width:16px; height:16px; color:#94a3b8; pointer-events:none;
    }

    .cp-list { max-height:310px; overflow-y:auto; padding:6px; }
    .cp-item {
        display:flex; align-items:center; gap:11px; width:100%; border:0; background:none;
        padding:8px 10px; border-radius:9px; cursor:pointer; text-align:left;
    }

    /*
       WITHOUT THIS THE SEARCH DOES NOTHING VISIBLE. `hidden` is only
       `display:none` from the user-agent stylesheet, and any author `display`
       rule beats it — so `.cp-item { display:flex }` above kept every filtered
       row on screen while the script believed it had hidden them. Every element
       this component hides needs the same explicit rule.
    */
    .cp-item[hidden] { display:none !important; }
    .cp-item:hover, .cp-item.is-active { background:#f1f5f9; }
    .cp-item.is-on { background:#eef2ff; }
    .cp-item__name { font-size:13.5px; font-weight:600; color:#0f172a; margin-right:auto;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .cp-item.is-on .cp-item__name { color:#4338ca; font-weight:750; }
    .cp-item__cur { font-size:11px; font-weight:700; color:#94a3b8; flex:none; }
    .cp-item__tick { color:#4f46e5; flex:none; }

    .cp-empty { display:block; padding:22px 14px; text-align:center; font-size:12.5px; color:#94a3b8; }
    .cp-empty[hidden] { display:none !important; }

    .cp-native { display:none; }

    html.dark .cp-card { background:#1e293b; border-color:#334155; }
    html.dark .cp-card__title { color:#f1f5f9; }
    html.dark .cp-btn, html.dark .cp-panel { background:#0f172a; border-color:#334155; }
    html.dark .cp-btn__name, html.dark .cp-item__name { color:#e2e8f0; }
    html.dark .cp-item:hover, html.dark .cp-item.is-active { background:#1e293b; }
    html.dark .cp-item.is-on { background:#312e81; }
    html.dark .cp-search input { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    html.dark .cp-search { border-bottom-color:#1e293b; }
</style>

<form method="POST" action="{{ $countryAction }}" class="cp-card js-cp">
    @csrf

    <div class="cp-card__lead">
        <div class="cp-card__icon"><i data-lucide="globe" class="w-5 h-5"></i></div>
        <div>
            <div class="cp-card__title">Where are you buying from?</div>
            <div class="cp-card__sub">
                Prices shown in <strong>{{ $countryCurrency }}</strong>.
                @if ($guessed)
                    We guessed this from your connection — change it if it's wrong.
                @endif
            </div>
        </div>
    </div>

    <div class="cp-wrap">
        {{-- The control people see. Replaced by the native select when there
             is no JavaScript, so the choice is never unreachable. --}}
        <button type="button" class="cp-btn js-cp-btn" aria-haspopup="listbox" aria-expanded="false">
            <span class="cp-flag cp-flag--lg js-cp-flag">
                <span class="cp-flag__code">{{ $countryCurrent ?: '??' }}</span>
                @if ($countryCurrent)
                    <img src="https://flagcdn.com/w80/{{ strtolower($countryCurrent) }}.png"
                         srcset="https://flagcdn.com/w160/{{ strtolower($countryCurrent) }}.png 2x"
                         alt="" onerror="this.remove()">
                @endif
            </span>
            <span class="cp-btn__body">
                <span class="cp-btn__name js-cp-name">{{ $current['name'] ?? 'Choose your country' }}</span>
                <span class="cp-btn__meta">Changes your currency and payment options</span>
            </span>
            <span class="cp-cur js-cp-cur">{{ $countryCurrency }}</span>
            <i data-lucide="chevron-down" class="w-4 h-4 cp-btn__chev"></i>
        </button>

        <div class="cp-panel js-cp-panel" role="listbox" hidden>
            <div class="cp-search">
                <i data-lucide="search" class="cp-search__icon"></i>
                <input type="text" class="js-cp-search" placeholder="Search {{ count($countries) }} countries…"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="cp-list js-cp-list">
                @foreach ($countries as $code => $country)
                    <button type="button" role="option"
                            class="cp-item js-cp-item {{ $code === $countryCurrent ? 'is-on' : '' }}"
                            data-code="{{ $code }}"
                            data-name="{{ $country['name'] }}"
                            data-currency="{{ $country['currency'] }}"
                            aria-selected="{{ $code === $countryCurrent ? 'true' : 'false' }}">
                        <span class="cp-flag">
                            <span class="cp-flag__code">{{ $code }}</span>
                            <img src="https://flagcdn.com/w80/{{ strtolower($code) }}.png"
                                 srcset="https://flagcdn.com/w160/{{ strtolower($code) }}.png 2x"
                                 alt="" loading="lazy" onerror="this.remove()">
                        </span>
                        <span class="cp-item__name">{{ $country['name'] }}</span>
                        <span class="cp-item__cur">{{ $country['currency'] }}</span>
                        @if ($code === $countryCurrent)
                            <i data-lucide="check" class="w-4 h-4 cp-item__tick"></i>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="cp-empty js-cp-empty" hidden>
                No country matches <strong class="js-cp-term"></strong>.
            </div>
        </div>
    </div>

    {{-- What actually submits. Visible only without JavaScript. --}}
    <select name="country" class="cp-native js-cp-native">
        @foreach ($countries as $code => $country)
            <option value="{{ $code }}" @selected($code === $countryCurrent)>
                {{ $country['name'] }} ({{ $country['currency'] }})
            </option>
        @endforeach
    </select>

    <noscript>
        <style>.cp-wrap { display:none } .cp-native { display:inline-block; padding:8px 11px; border-radius:9px; border:1px solid #e2e8f0 }</style>
        <button type="submit" class="bl-btn bl-btn--primary bl-btn--sm">Update</button>
    </noscript>
</form>

<script>
(function () {
    document.querySelectorAll('.js-cp').forEach(function (form) {
        var btn    = form.querySelector('.js-cp-btn');
        var panel  = form.querySelector('.js-cp-panel');
        var search = form.querySelector('.js-cp-search');
        var native = form.querySelector('.js-cp-native');
        var empty  = form.querySelector('.js-cp-empty');
        var items  = Array.prototype.slice.call(form.querySelectorAll('.js-cp-item'));

        if (!btn || !panel || !native) return;

        // Moved to <body> once, at load. See the CSS note: from inside a
        // transformed ancestor no z-index can lift it above its siblings, so
        // the element has to leave that subtree entirely. It carries only
        // type="button" controls, so nothing about the form breaks by it
        // living elsewhere.
        document.body.appendChild(panel);

        function place() {
            var r = btn.getBoundingClientRect();
            var w = Math.max(r.width, 300);

            panel.style.width = w + 'px';

            // Flipped above the button when there is no room below — a panel
            // that opens off the bottom of the viewport is a panel nobody can
            // reach the end of.
            var below = window.innerHeight - r.bottom;
            var need  = Math.min(panel.offsetHeight || 360, 380);

            if (below < need && r.top > below) {
                panel.style.top = Math.max(8, r.top - need - 7) + 'px';
            } else {
                panel.style.top = (r.bottom + 7) + 'px';
            }

            // Nudged back inside if the button sits near the right edge.
            var left = Math.min(r.left, window.innerWidth - w - 10);
            panel.style.left = Math.max(8, left) + 'px';
        }

        function open() {
            panel.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            place();
            if (search) { search.value = ''; filter(''); search.focus(); }
            place();   // again once the list is at full height
        }

        function close() {
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        }

        btn.addEventListener('click', function () {
            panel.hidden ? open() : close();
        });

        // The panel is no longer inside the form, so "did the click land
        // outside" has to consider both.
        document.addEventListener('click', function (e) {
            if (!form.contains(e.target) && !panel.contains(e.target)) close();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) { close(); btn.focus(); }
        });

        // Fixed positioning does not follow the page, so it is re-anchored
        // rather than left floating over the wrong part of the screen.
        window.addEventListener('scroll', function () { if (!panel.hidden) place(); }, true);
        window.addEventListener('resize', function () { if (!panel.hidden) place(); });

        var termEl = panel.querySelector('.js-cp-term');

        function filter(term) {
            term = term.toLowerCase().trim();
            var shown = 0;

            items.forEach(function (item) {
                // Matched on name AND code, so "PK" and "Pak" both find
                // Pakistan — people type whichever they think of first.
                var hay = (item.getAttribute('data-name') + ' ' + item.getAttribute('data-code')).toLowerCase();
                var hit = term === '' || hay.indexOf(term) !== -1;

                item.hidden = !hit;
                item.classList.remove('is-active');
                if (hit) shown++;
            });

            if (empty) {
                empty.hidden = shown > 0;
                if (termEl) termEl.textContent = '“' + term + '”';
            }

            // The list shrinks as it filters, so the panel has to be re-anchored
            // or it hangs in space below a much shorter box.
            place();
        }

        if (search) {
            search.addEventListener('input', function () { filter(search.value); });

            // Enter picks the first match, so a country can be chosen without
            // ever leaving the keyboard.
            search.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();

                var first = items.filter(function (i) { return !i.hidden; })[0];
                if (first) first.click();
            });
        }

        items.forEach(function (item) {
            item.addEventListener('click', function () {
                var code = item.getAttribute('data-code');

                if (native.value === code) { close(); return; }

                native.value = code;
                btn.disabled = true;
                form.querySelector('.js-cp-name').textContent = item.getAttribute('data-name');
                form.querySelector('.js-cp-cur').textContent  = item.getAttribute('data-currency');

                // Swap the button's flag immediately, so the control shows the
                // new country while the page is still reloading.
                var slot = form.querySelector('.js-cp-flag');
                var pick = item.querySelector('img');

                if (slot && pick) {
                    var shown = slot.querySelector('img');
                    if (shown) shown.remove();
                    slot.appendChild(pick.cloneNode(true));
                }

                form.submit();
            });
        });
    });
})();
</script>
