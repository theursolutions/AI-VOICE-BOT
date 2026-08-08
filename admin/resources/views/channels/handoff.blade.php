{{--
    The page a customer's phone lands on after scanning the QR shown on the
    Channels page.

    Two jobs, both about trust:
      1. Say plainly WHICH workspace is about to gain a channel. The link is
         signed but shareable for 15 minutes, so a forwarded or mis-scanned
         QR has to be obvious before anything is connected — not after.
      2. Get out of the way. One button, then Meta's own screens.

    Deliberately standalone (no app layout): it renders for a signed-out
    visitor on a phone, so it must not depend on session, sidebar or
    workspace context.
--}}
@php
    $brand = tva_setting('content.brand_name', 'Serve AI');
    $labels = [
        'whatsapp'      => ['WhatsApp',      '#25D366'],
        'instagram'     => ['Instagram',     '#E1306C'],
        'facebook_page' => ['Facebook Page', '#1877F2'],
        'messenger'     => ['Messenger',     '#0084FF'],
    ];
    [$providerLabel, $providerColour] = $labels[$provider] ?? [ucfirst(str_replace('_', ' ', $provider)), '#3b82f6'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Connect {{ $providerLabel }} — {{ $brand }}</title>
    <link rel="icon" href="{{ serveai_icon_sized(64) }}">
    <style>
        :root { --bg:#050609; --panel:rgba(15,21,35,.6); --line:rgba(120,180,220,.14);
                --text:#e6edf3; --dim:#8b96a8; --accent:{{ $providerColour }}; }
        * { box-sizing:border-box; }
        body {
            margin:0; min-height:100vh; padding:28px 20px;
            background:radial-gradient(ellipse at 50% -10%, #0d1a2e 0%, #050609 60%, #000 100%);
            color:var(--text); font-family:system-ui,-apple-system,'Segoe UI',sans-serif;
            display:flex; align-items:center; justify-content:center; line-height:1.6;
        }
        .card {
            width:100%; max-width:420px; background:var(--panel); border:1px solid var(--line);
            border-radius:20px; padding:32px 26px; text-align:center; backdrop-filter:blur(10px);
        }
        .mark { width:52px; height:52px; object-fit:contain; margin-bottom:18px; }
        h1 { font-size:22px; font-weight:800; margin:0 0 8px; letter-spacing:-.01em; }
        .lead { color:var(--dim); font-size:14.5px; margin:0 0 22px; }
        /* The workspace being connected — the single most important fact on
           this page, so it gets its own framed block rather than a line of
           body copy someone can skim past. */
        .target {
            border:1px solid var(--line); border-radius:14px; padding:14px 16px;
            background:rgba(0,0,0,.28); margin-bottom:22px; text-align:left;
        }
        .target__k { font-size:10.5px; text-transform:uppercase; letter-spacing:.12em; color:var(--dim); }
        .target__v { font-size:16px; font-weight:700; margin-top:2px; word-break:break-word; }
        .target__sub { font-size:12.5px; color:var(--dim); margin-top:6px; }
        .btn {
            display:flex; align-items:center; justify-content:center; gap:9px;
            width:100%; background:var(--accent); color:#fff; border:none;
            padding:15px 20px; border-radius:13px; font-size:16px; font-weight:700;
            text-decoration:none; cursor:pointer;
        }
        .btn:active { transform:translateY(1px); }
        .note { font-size:12px; color:var(--dim); margin-top:18px; }
        .done { font-size:44px; margin-bottom:10px; }
        .steps { text-align:left; margin:0 0 22px; padding-left:20px; color:var(--dim); font-size:13.5px; }
        .steps li { margin-bottom:6px; }
    </style>
</head>
<body>
<div class="card">
    <img class="mark" src="{{ serveai_icon_sized(64) }}" alt="{{ $brand }}" width="52" height="52">

    @if ($done)
        <div class="done" aria-hidden="true">✅</div>
        <h1>Already connected</h1>
        <p class="lead">This {{ $providerLabel }} connection is finished. You can close this page and go back to your computer.</p>
    @else
        <h1>Connect {{ $providerLabel }}</h1>
        <p class="lead">You scanned this from {{ $brand }} on your computer. Finish here on your phone.</p>

        <div class="target">
            <div class="target__k">Connecting to workspace</div>
            <div class="target__v">{{ $project->name ?? ('Project #' . $project->id) }}</div>
            <div class="target__sub">{{ $client->name ?? $client->slug }}</div>
        </div>

        <ol class="steps">
            <li>Sign in to Facebook (the account that manages your {{ $providerLabel }})</li>
            <li>Choose the account you want {{ $brand }} to answer on</li>
            <li>Leave every permission ticked, or the connection fails</li>
        </ol>

        <a class="btn" href="{{ $goUrl }}">Continue to Facebook →</a>

        <p class="note">
            If this isn't your workspace, close this page — nothing has been connected yet.
            This link expires 15 minutes after the QR was shown.
        </p>
    @endif
</div>
</body>
</html>
