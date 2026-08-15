{{--
    Cookie consent.

    Accept and Reject carry EQUAL visual weight. Regulators have been
    explicit that a prominent "Accept all" beside a buried "Reject" is not
    freely given consent, and it is also simply dishonest design — the answer
    the visitor wants should not be the harder one to click.

    Nothing here blocks the page. A modal that traps someone before they have
    read a word is the pattern everyone hates, and for the analytics this site
    actually sets it is not warranted. The bar is dismissible, remembered for
    a year, and re-openable from the footer.

    Analytics only load after an explicit accept — see the `tva:consent`
    event, which GA4 listens for.
--}}
<div id="tvaCookie" class="tva-cookie" hidden role="dialog" aria-live="polite"
     aria-label="Cookie preferences">
    <div class="tva-cookie__body">
        <div class="tva-cookie__text">
            <b>We use cookies</b>
            <span>
                Essential cookies keep you signed in and are always on. Analytics cookies
                help us see which pages are useful — only if you say yes.
                <a href="{{ route('cookies') }}">Cookie Policy</a>
            </span>
        </div>
        <div class="tva-cookie__actions">
            <button type="button" class="tva-cookie__btn" onclick="tvaCookieChoice('reject')">Reject</button>
            <button type="button" class="tva-cookie__btn tva-cookie__btn--yes" onclick="tvaCookieChoice('accept')">Accept</button>
        </div>
    </div>
</div>

<style>
    .tva-cookie {
        position: fixed; left: 16px; right: 16px; bottom: 16px; z-index: 200;
        /* Tokens where they exist (public layout and homepage both define
           them); the fallbacks keep this working on any page that does not. */
        background: var(--panel-2, #fff);
        color: var(--text, #101828);
        border: 1px solid var(--line, rgba(16,32,56,.12));
        border-radius: 14px;
        box-shadow: var(--shadow-lg, 0 20px 44px -16px rgba(16,24,40,.22));
        animation: tvaCookieIn .26s cubic-bezier(.16,1,.3,1);
    }
    @keyframes tvaCookieIn { from { opacity:0; transform: translateY(10px); } to { opacity:1; transform:none; } }
    @media (prefers-reduced-motion: reduce) { .tva-cookie { animation: none; } }

    .tva-cookie__body {
        max-width: 1100px; margin: 0 auto; padding: 15px 18px;
        display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
    }
    .tva-cookie__text { flex: 1 1 380px; min-width: 0; font-size: 13px; line-height: 1.6; }
    .tva-cookie__text b { display: block; font-size: 13.5px; margin-bottom: 2px; }
    .tva-cookie__text span { color: var(--text-dim, #475467); }
    .tva-cookie__text a { color: var(--neon, #1d4ed8); text-decoration: underline; }

    .tva-cookie__actions { display: flex; gap: 9px; margin-left: auto; }
    /* Both buttons are the same size and shape. Only the fill differs, and
       only enough to mark the primary path — not enough to bully. */
    .tva-cookie__btn {
        border: 1px solid var(--line, rgba(16,32,56,.16));
        background: transparent; color: inherit;
        font-size: 13px; font-weight: 600; font-family: inherit;
        padding: 9px 20px; border-radius: 9px; cursor: pointer;
        transition: background .14s, border-color .14s;
    }
    .tva-cookie__btn:hover { background: rgba(16,32,56,.05); }
    .tva-cookie__btn:focus-visible { outline: 2px solid var(--neon, #1d4ed8); outline-offset: 2px; }
    .tva-cookie__btn--yes {
        background: var(--neon-btn, #1d4ed8); border-color: var(--neon-btn, #1d4ed8); color: #fff;
    }
    .tva-cookie__btn--yes:hover { filter: brightness(1.08); }
    html.dark .tva-cookie__btn:hover { background: rgba(255,255,255,.07); }

    @media (max-width: 560px) {
        .tva-cookie__body { padding: 14px; gap: 12px; }
        .tva-cookie__actions { margin-left: 0; width: 100%; }
        .tva-cookie__btn { flex: 1; }
    }
</style>

<script>
(function () {
    var KEY = 'tva_cookie_consent';

    function read() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }

    window.tvaCookieChoice = function (choice) {
        try { localStorage.setItem(KEY, choice); } catch (e) {}
        // Mirrored to a cookie so the server can honour it too — analytics
        // that render server-side must not fire on a reject.
        document.cookie = 'tva_consent=' + choice + ';path=/;max-age=31536000;SameSite=Lax';

        var el = document.getElementById('tvaCookie');
        if (el) el.hidden = true;

        // GA4 and anything else opt-in listens for this rather than loading
        // on page load.
        window.dispatchEvent(new CustomEvent('tva:consent', { detail: { consent: choice } }));
    };

    /** Re-open from the footer, so a decision is never final. */
    window.tvaCookieReopen = function () {
        try { localStorage.removeItem(KEY); } catch (e) {}
        var el = document.getElementById('tvaCookie');
        if (el) el.hidden = false;
    };

    var choice = read();
    if (choice !== 'accept' && choice !== 'reject') {
        var el = document.getElementById('tvaCookie');
        if (el) el.hidden = false;
    } else if (choice === 'accept') {
        // Already agreed on an earlier visit — tell the listeners.
        window.addEventListener('DOMContentLoaded', function () {
            window.dispatchEvent(new CustomEvent('tva:consent', { detail: { consent: 'accept' } }));
        });
    }
})();
</script>
