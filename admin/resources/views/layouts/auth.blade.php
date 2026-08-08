<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <link href="{{serveai_icon()}}" rel="shortcut icon">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Midone admin is super flexible, powerful, clean & modern responsive tailwind admin template with unlimited possibilities.">
    <meta name="keywords" content="admin template, Midone Admin Template, dashboard template, flat admin template, responsive admin template, web app">
    <meta name="author" content="LEFT4CODE">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Same source as layouts/head.blade.php so the tab title is consistent
         everywhere and doesn't depend on APP_NAME being set correctly in .env. --}}
    <title>{{ tva_setting('content.brand_name', 'Serve AI') }}</title>
    <link rel="stylesheet" href="{{url('/assets/dist/css/app.css')}}" />

    {{-- ── Mobile fixes for the Midone auth shell ──────────────────────────
         The vendor theme was built desktop-first and breaks on a phone in
         four specific ways. Kept here rather than in app.css because that
         file is compiled vendor output we don't hand-edit. --}}
    <style>
        /* 1. `body.login` ships with `overflow:hidden`, and the form column is
              `h-screen` (a hard 100vh). Any form taller than the viewport —
              Register, with 5 fields + strength bar + terms + Turnstile — got
              clipped with no way to scroll to it. That is why the Google and
              Facebook buttons were missing on Register but present on Login. */
        body.login {
            overflow-x: hidden;
            overflow-y: auto;
            min-height: 100vh;
            height: auto;
        }
        @media (max-width: 1279px) {
            body.login .h-screen { height: auto; min-height: 100vh; }
        }

        /* 2. iOS Safari zooms the whole page when a focused input's font-size
              is under 16px. Every field here is 14px AND the first one carries
              `autofocus`, so the page opened already zoomed and off-centre.
              16px is the threshold — it is not a design preference. The 1024px
              cap covers iPad portrait, which zooms the same way. */
        @media (max-width: 1024px) {
            body.login input,
            body.login select,
            body.login textarea,
            body.login .form-control,
            body.login .login__input {
                font-size: 16px;
            }
        }

        /* 3. Give the card room. `body.login` carries 32px of side padding from
              the theme, which stacked on the container + card padding left only
              247px of usable width inside a 375px phone. That is what squeezed
              the Turnstile widget (see 4) and cramped the buttons. Reclaiming
              the body padding gets the content box back over 300px. */
        @media (max-width: 767px) {
            body.login { padding-left: 0; padding-right: 0; }
            body.login .container { padding-left: 12px; padding-right: 12px; }
            body.login .px-5 { padding-left: 16px; padding-right: 16px; }
            body.login form > div[class*="w-full"],
            body.login > .container div[class*="sm:w-3/4"] {
                width: 100%; max-width: 100%;
            }
        }

        /* 4. Turnstile renders a fixed-width iframe (~300px minimum). In a
              narrower card it stuck out past the inputs — the mismatch you
              see as the captcha being wider than the fields. Centre it and
              scale it down only when the card really is narrower than 300px. */
        body.login .cf-turnstile {
            display: flex; justify-content: center;
            max-width: 100%; overflow: hidden;
        }
        body.login .cf-turnstile iframe { max-width: 100% !important; }
        @media (max-width: 340px) {
            body.login .cf-turnstile { transform: scale(.92); transform-origin: center top; }
        }

        /* 5. Midone's `.intro-x` entrance animation starts the element 50px to
              the right. With the body no longer clipping horizontally that
              would show as a real sideways scroll mid-animation. */
        body.login { max-width: 100vw; }
    </style>
    @include('partials.sweet-alert')
</head>
<body class="login">
    @yield('content')
    <!-- BEGIN: Dark Mode Switcher-->
    {{-- <div data-url="login-light-login.html" class="dark-mode-switcher cursor-pointer shadow-md fixed bottom-0 right-0 box border rounded-full w-40 h-12 flex items-center justify-center z-50 mb-10 mr-10">
        <div class="mr-4 text-slate-600 dark:text-slate-200">Dark Mode</div>
        <div class="dark-mode-switcher__toggle dark-mode-switcher__toggle--active border"></div>
    </div> --}}
    <!-- END: Dark Mode Switcher-->
    
    <!-- BEGIN: JS Assets-->
    <script src="{{url('/assets/dist/js/app.js')}}"></script>
    <script>
        // The first field carries `autofocus`, which on a phone throws the
        // keyboard up before the visitor has seen the form and scrolls the
        // card half off-screen. Desktop keeps the focus; touch screens don't.
        (function () {
            if (window.matchMedia('(max-width: 767px)').matches) {
                var el = document.querySelector('[autofocus]');
                if (el) { el.removeAttribute('autofocus'); el.blur(); }
            }
        })();
    </script>
    <!-- END: JS Assets-->
</body>
</html>
