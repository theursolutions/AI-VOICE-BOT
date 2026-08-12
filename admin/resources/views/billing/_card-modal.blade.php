{{--
    "Add / change card" — Stripe Elements inside our own modal.

    The card number, expiry and CVC live in Stripe-hosted iframes, so the raw
    details never enter our DOM or reach our server. That is what lets the form
    wear our design without dragging the app into PCI SAQ-A-EP.

    Flow: fetch a SetupIntent client secret → confirmCardSetup() (runs any 3-D
    Secure challenge NOW, while the customer is here) → POST the resulting
    payment_method id to us to attach and make default.

    Requires: $client, $stripeKey.
--}}
@include('skills._modal_css')

<div id="card-modal" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>

    <div class="tva-modal__panel" style="max-width:460px;">
        <div class="tva-modal__head">
            <i data-lucide="credit-card" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
            Add a payment method
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>

        <div class="tva-modal__body">
            <label class="form-label" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700">
                Card details
            </label>

            {{-- Stripe mounts its iframe here. --}}
            <div id="card-modal-element"
                 style="border:1px solid #e2e8f0;border-radius:11px;padding:13px 14px;background:#fff;"></div>

            <div id="card-modal-error"
                 style="display:none;margin-top:10px;font-size:12.5px;color:#b91c1c;background:#fef2f2;
                        border:1px solid #fecaca;border-radius:9px;padding:9px 11px;"></div>

            <p style="font-size:11.5px;color:#94a3b8;margin:12px 0 0;line-height:1.6;display:flex;gap:7px">
                <i data-lucide="lock" class="w-3.5 h-3.5" style="flex:none;margin-top:2px"></i>
                <span>
                    Handled securely by Stripe. Your card details never touch our servers —
                    we only ever see the brand and last four digits.
                </span>
            </p>
        </div>

        <div class="tva-modal__foot" style="display:flex;gap:9px;justify-content:flex-end">
            <button type="button" class="bl-btn bl-btn--ghost" data-tva-modal-close>Cancel</button>
            <button type="button" id="card-modal-save" class="bl-btn bl-btn--primary">
                <span class="js-label">Save card</span>
                <span class="js-spin" hidden><i data-lucide="loader" class="w-4 h-4"></i></span>
            </button>
        </div>
    </div>
</div>

{{-- Form used to POST the tokenised payment method back to us. --}}
<form id="card-modal-form" method="POST" action="{{ route('billing.cards.store', ['client' => $client->slug]) }}" hidden>
    @csrf
    <input type="hidden" name="payment_method" id="card-modal-pm">
</form>

<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
    var openers = document.querySelectorAll('[data-tva-modal-open]');
    var modal   = document.getElementById('card-modal');
    if (!modal) return;

    // ── House modal open/close (matches bot-agents, skills, flows) ──
    openers.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-tva-modal-open'));
            if (target) target.removeAttribute('hidden');
        });
    });
    document.querySelectorAll('[data-tva-modal-close]').forEach(function (el) {
        el.addEventListener('click', function () {
            var m = el.closest('.tva-modal');
            if (m) m.setAttribute('hidden', '');
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.tva-modal:not([hidden])').forEach(function (m) {
                m.setAttribute('hidden', '');
            });
        }
    });

    // ── Stripe Elements ────────────────────────────────────────────
    if (typeof Stripe === 'undefined') return;

    var stripe   = Stripe(@json($stripeKey));
    var elements = stripe.elements();
    var mounted  = false;
    var busy     = false;

    var saveBtn = document.getElementById('card-modal-save');
    var errBox  = document.getElementById('card-modal-error');

    // Styled to match our inputs. Stripe only accepts a restricted subset of
    // CSS here, which is why this is a JS object and not a stylesheet rule.
    var card = elements.create('card', {
        hidePostalCode: false,
        style: {
            base: {
                fontSize: '14px',
                color: '#0f172a',
                fontFamily: 'inherit',
                '::placeholder': { color: '#94a3b8' }
            },
            invalid: { color: '#b91c1c', iconColor: '#b91c1c' }
        }
    });

    function showError(msg) {
        errBox.textContent = msg;
        errBox.style.display = msg ? 'block' : 'none';
    }

    function setBusy(state) {
        busy = state;
        saveBtn.disabled = state;
        saveBtn.querySelector('.js-label').textContent = state ? 'Saving…' : 'Save card';
    }

    // Mount lazily: an iframe mounted into a hidden container measures zero
    // and renders an unusable one-line field when the modal opens.
    document.querySelectorAll('[data-tva-modal-open="card-modal"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!mounted) {
                card.mount('#card-modal-element');
                card.on('change', function (e) { showError(e.error ? e.error.message : ''); });
                mounted = true;
            }
            showError('');
        });
    });

    saveBtn.addEventListener('click', function () {
        if (busy) return;
        setBusy(true);
        showError('');

        // 1. Ask our server for a SetupIntent.
        fetch(@json(route('billing.cards.intent', ['client' => $client->slug])), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (!res.ok) throw new Error(res.body.message || 'Could not start card setup.');

            // 2. Confirm it — this is where 3-D Secure happens, if the bank
            //    asks for it, rather than silently failing at the first invoice.
            return stripe.confirmCardSetup(res.body.client_secret, {
                payment_method: { card: card }
            });
        })
        .then(function (result) {
            if (result.error) throw new Error(result.error.message);

            // 3. Hand the payment method id to our server to attach.
            document.getElementById('card-modal-pm').value = result.setupIntent.payment_method;
            document.getElementById('card-modal-form').submit();
        })
        .catch(function (err) {
            setBusy(false);
            showError(err.message || 'Something went wrong. Please try again.');
        });
    });
})();
</script>
