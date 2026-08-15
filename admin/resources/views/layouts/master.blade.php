<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ tva_theme_class('admin') }}">
    <!-- BEGIN: Head -->
    @include('layouts.head')
    @include('partials.theme')
    {{-- Per-project widget theme — applied app-wide so all admin pages
         share the same accent colors as the embeddable widget. --}}
    @php $tw = $tvaWidget ?? \App\Http\Controllers\Admin\WidgetSettingsController::DEFAULTS; @endphp
    <style>
        :root {
            --tva-primary: {{ $tw['primary_color'] }};
            --tva-accent:  {{ $tw['accent_color']  }};
            --tva-gradient: linear-gradient(135deg, {{ $tw['primary_color'] }} 0%, {{ $tw['accent_color'] }} 100%);
        }
    </style>
    <!-- END: Head -->
    <body class="py-5">
        @include('partials.impersonation-banner')
        <!-- BEGIN: Mobile Menu -->
        @include('layouts.mobile-menu')
        <!-- END: Mobile Menu -->
        <div class="flex mt-[4.7rem] md:mt-0">
            <!-- BEGIN: Side Menu -->
            @include('layouts.sidebar')
            <!-- END: Side Menu -->
            <!-- BEGIN: Content -->
            <div class="content">
                <!-- BEGIN: Top Bar -->
                @include('layouts.topbar')
                <!-- END: Top Bar -->
                {{-- Rounds the top of the work area against the navy bar (light mode only; see partials/theme). --}}
                <div class="tva-shoulder" aria-hidden="true"></div>
                <!-- Verify-email nudge (Dashboard + Ask AI, only while unverified) -->
                @include('partials.verify-email-banner')
                <!-- BEGIN Actual page content -->
                @yield('content')
                <!-- END: Actual page content -->
            </div>
            <!-- END: Content -->
        </div>
        @include('layouts.nav-collapse')
        @include('layouts.footer')
    </body>
</html>