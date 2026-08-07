<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Something went wrong') · Serve AI</title>
    <link rel="shortcut icon" href="{{ serveai_icon() }}">
    {{-- Self-contained: every style is inline so the page renders even if the
         asset pipeline, database, or app config is unavailable (500s). --}}
    <style>
        *{box-sizing:border-box;margin:0;padding:0;}
        html,body{height:100%;}
        body{
            font-family:'Inter',ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
            background:#0b1120; color:#e2e8f0;
            background-image:
                radial-gradient(60rem 60rem at 85% -10%, rgba(99,102,241,.18), transparent 60%),
                radial-gradient(50rem 50rem at -10% 110%, rgba(16,185,129,.14), transparent 60%);
            display:flex; align-items:center; justify-content:center;
            min-height:100%; padding:24px;
        }
        .err{
            max-width:560px; width:100%; text-align:center;
            background:rgba(255,255,255,.03);
            border:1px solid rgba(255,255,255,.08);
            border-radius:22px; padding:48px 40px;
            box-shadow:0 30px 80px -30px rgba(0,0,0,.6);
            backdrop-filter:blur(6px);
        }
        .err__brand{
            display:inline-flex; align-items:center; gap:9px; margin-bottom:30px;
            font-weight:700; font-size:15px; letter-spacing:.02em; color:#f8fafc;
        }
        .err__brand img{width:26px;height:26px;}
        .err__code{
            font-size:84px; line-height:1; font-weight:900; letter-spacing:-.03em;
            background:linear-gradient(135deg,#818cf8 0%,#c084fc 50%,#34d399 100%);
            -webkit-background-clip:text; background-clip:text; color:transparent;
            margin-bottom:6px;
        }
        .err__heading{font-size:23px;font-weight:800;color:#f8fafc;margin-bottom:12px;}
        .err__text{font-size:15px;line-height:1.65;color:#94a3b8;margin-bottom:32px;}
        .err__actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
        .err__btn{
            display:inline-flex;align-items:center;gap:8px;
            font-weight:600;font-size:14px;text-decoration:none;
            padding:12px 24px;border-radius:12px;transition:filter .15s,transform .05s,background .15s;
        }
        .err__btn--primary{
            background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%); color:#fff;
            box-shadow:0 10px 30px -10px rgba(99,102,241,.6);
        }
        .err__btn--primary:hover{filter:brightness(1.08);}
        .err__btn--primary:active{transform:translateY(1px);}
        .err__btn--ghost{
            background:rgba(255,255,255,.05); color:#cbd5e1; border:1px solid rgba(255,255,255,.1);
        }
        .err__btn--ghost:hover{background:rgba(255,255,255,.09);}
        .err__ref{margin-top:26px;font-size:12px;color:#475569;font-family:ui-monospace,monospace;}
        @media (max-width:480px){
            .err{padding:34px 24px;}
            .err__code{font-size:64px;}
            .err__heading{font-size:19px;}
        }
    </style>
</head>
<body>
    <div class="err">
        <div class="err__brand">
            <img src="{{ serveai_icon() }}" alt="">
            <span>Serve AI</span>
        </div>

        <div class="err__code">@yield('code', 'Oops')</div>
        <h1 class="err__heading">@yield('heading', 'Something went wrong')</h1>
        <p class="err__text">@yield('message', 'An unexpected error occurred. Please try again.')</p>

        <div class="err__actions">
            <a href="{{ url('/dashboard') }}" class="err__btn err__btn--primary">← Back to dashboard</a>
            <a href="{{ url('/') }}" class="err__btn err__btn--ghost">Go home</a>
        </div>

        @hasSection('reference')
            <div class="err__ref">@yield('reference')</div>
        @endif
    </div>
</body>
</html>
