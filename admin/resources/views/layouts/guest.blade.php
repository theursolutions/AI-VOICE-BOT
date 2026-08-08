<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="icon" href="{{ serveai_icon() }}">
        <link rel="shortcut icon" href="{{ serveai_icon() }}">

        <title>{{ config('app.name', 'Serve AI') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        {{-- Was: @vite(['resources/css/app.css', 'resources/js/app.js'])
             `resources/css/app.css` is NOT a Vite input (see vite.config.js —
             the stylesheet entry is resources/sass/app.scss), so it never lands
             in public/build/manifest.json and @vite threw "Unable to locate file
             in Vite manifest" while rendering this component.

             That was the 500 on /verify-email: the controller and the OTP
             creation both succeeded, then the response blew up mid-render — so
             the failure looked like a mail/OTP bug when it was purely an asset
             reference. The remaining views avoid @vite for the same reason
             (layouts/app.blade.php has it commented out); the Midone theme
             ships prebuilt CSS/JS under public/assets/dist instead. --}}
        @vite(['resources/sass/app.scss', 'resources/js/app.js'])
        {{-- Same iOS rule as layouts/auth.blade.php: Safari zooms the page when
             a focused input is under 16px, which reads to the user as the page
             "opening zoomed". Applies to reset-password / verify-email, the two
             screens that render through this layout. --}}
        <style>
            @media (max-width: 1024px) {
                input, select, textarea { font-size: 16px; }
            }
        </style>
        @include('partials.sweet-alert')
</head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
            <div>
                <a href="/">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-gray-800 shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
