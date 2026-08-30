<!DOCTYPE html>
<html lang="en" class="{{ tva_theme_class() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $brandName }} — Payment</title>
    <style>
        body {
            margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            padding:24px; background:#f1f5f9; color:#0f172a;
            font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;
        }
        .pay { max-width:420px; width:100%; background:#fff; border-radius:18px; padding:36px 32px;
               text-align:center; box-shadow:0 12px 40px rgba(15,23,42,.08); }
        .pay__mark {
            width:56px; height:56px; border-radius:16px; margin:0 auto 18px;
            background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
            color:#fff; display:flex; align-items:center; justify-content:center;
            font-size:22px; font-weight:800;
        }
        h1 { font-size:19px; font-weight:800; margin:0 0 8px; }
        p  { font-size:13.5px; color:#64748b; margin:0; }
        .pay__spin {
            width:30px; height:30px; margin:20px auto 0; border-radius:50%;
            border:3px solid #e2e8f0; border-top-color:#6366f1; animation:spin .8s linear infinite;
        }
        @keyframes spin { to { transform:rotate(360deg) } }
        .pay__btn {
            display:inline-flex; align-items:center; gap:8px; margin-top:20px;
            background:#4f46e5; color:#fff; border:0; border-radius:11px;
            padding:11px 20px; font:700 13.5px system-ui,sans-serif; cursor:pointer;
            text-decoration:none;
        }
        .pay__btn--ghost { background:#fff; color:#334155; border:1.5px solid #e2e8f0; }
        .pay__err { display:none; margin-top:16px; font-size:13px; color:#b91c1c;
                    background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:11px 13px; }
    </style>
</head>
<body>
    {{--
        THE DEFAULT PAYMENT LINK.

        Paddle sends customers here with `?_ptxn=txn_…` appended, and it is not
        only a fallback: it is where a customer lands when Paddle asks them to
        update a failing card, and where a transaction's own `checkout.url`
        points. A page that ignores `_ptxn` turns all of that into a dead end.

        DELIBERATELY PUBLIC AND DELIBERATELY EMPTY OF OUR DATA. Whoever arrives
        may have no session — they followed a link from a Paddle email — so
        requiring a login would strand exactly the customer who is trying to
        give us money. Nothing about the transaction is rendered here; the id in
        the URL is Paddle's, and Paddle's own overlay decides what to show for
        it. The only thing this page contributes is the PUBLIC client token.
    --}}
    <div class="pay">
        <div class="pay__mark">{{ mb_substr($brandName, 0, 1) }}</div>

        <h1 id="pay-title">Opening your payment…</h1>
        <p id="pay-note">This only takes a moment.</p>

        <div class="pay__spin" id="pay-spin"></div>
        <div class="pay__err" id="pay-err"></div>

        <div id="pay-actions" style="display:none">
            <a href="{{ url('/') }}" class="pay__btn pay__btn--ghost">Go to {{ $brandName }}</a>
        </div>
    </div>

    @if ($clientToken)
        <script src="{{ $jsUrl }}"></script>
        <script>
        (function () {
            var title   = document.getElementById('pay-title');
            var note    = document.getElementById('pay-note');
            var spin    = document.getElementById('pay-spin');
            var err     = document.getElementById('pay-err');
            var actions = document.getElementById('pay-actions');

            function stop(heading, message, isError) {
                spin.style.display = 'none';
                title.textContent  = heading;
                note.textContent   = message || '';
                actions.style.display = 'block';

                if (isError) {
                    err.textContent   = message || '';
                    err.style.display = 'block';
                    note.textContent  = '';
                }
            }

            // Paddle appends the transaction as `_ptxn`. Read rather than
            // assumed: somebody may reach this page with no transaction at all.
            var txn = new URLSearchParams(window.location.search).get('_ptxn');

            if (!txn) {
                stop('Nothing to pay', 'This link does not point at a payment.', false);
                return;
            }

            try {
                Paddle.Environment.set(@json($environment));
                Paddle.Initialize({
                    token: @json($clientToken),
                    eventCallback: function (event) {
                        if (event.name === 'checkout.completed') {
                            stop('Payment complete', 'Thank you — you can close this page.', false);
                        }
                    }
                });

                Paddle.Checkout.open({ transactionId: txn });

                // The overlay is open on top of this page; the page behind it
                // should say so rather than spin forever.
                stop('Complete your payment', 'The secure payment window is open above.', false);
            } catch (e) {
                stop(
                    'We could not open the payment window',
                    'Please disable any ad blocker for this page and refresh, or contact us for help.',
                    true
                );
            }
        })();
        </script>
    @else
        <script>
            document.getElementById('pay-spin').style.display = 'none';
            document.getElementById('pay-title').textContent  = 'Payments are not set up';
            document.getElementById('pay-note').textContent   = 'Please contact us and we will help you complete this.';
            document.getElementById('pay-actions').style.display = 'block';
        </script>
    @endif
</body>
</html>
