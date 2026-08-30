<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Taking you to your payment page…</title>
    <style>
        body {
            margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;
            background:#0f172a; color:#e2e8f0; text-align:center; padding:24px;
        }
        .h-box { max-width:380px; }
        .h-spin {
            width:34px; height:34px; margin:0 auto 20px; border-radius:50%;
            border:3px solid rgba(226,232,240,.25); border-top-color:#6366f1;
            animation:spin .8s linear infinite;
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        h1 { font-size:17px; font-weight:650; margin:0 0 8px; }
        p  { margin:0; font-size:13.5px; color:#94a3b8; }
        button {
            margin-top:18px; background:#6366f1; color:#fff; border:0; border-radius:9px;
            padding:10px 18px; font:600 13.5px system-ui,sans-serif; cursor:pointer;
        }
    </style>
</head>
<body>
    {{--
        A self-submitting form, for a gateway that requires a signed POST rather
        than a redirect. The fields are echoed exactly as the gateway produced
        them: the signature was computed over these values, so re-ordering,
        trimming or re-encoding any one of them breaks it.

        The visible button is the fallback for a browser with JavaScript off.
        Without it the page would sit here forever with no way forward.
    --}}
    <form method="POST" action="{{ $action }}" id="handoff" class="h-box">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <div class="h-spin"></div>
        <h1>Taking you to your payment page</h1>
        <p>Just a moment — please don't close this window.</p>

        <noscript>
            <button type="submit">Continue to payment</button>
        </noscript>
    </form>

    <script>document.getElementById('handoff').submit();</script>
</body>
</html>
