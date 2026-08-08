{{--
    Cloudflare Turnstile widget.

    Renders nothing at all when TURNSTILE_SITE_KEY is unset, so unconfigured
    environments show no broken/empty box. Server-side verification in
    App\Rules\Turnstile is likewise skipped in that case, so the two stay in
    step — you can never end up with a form that demands a token no widget
    produced, or vice versa.

    Include INSIDE the <form>, before the submit button:
        @include('partials.turnstile')

    The script is loaded once per page even if the partial appears twice
    (defer + explicit guard), and `cf-turnstile` auto-renders any matching div.
--}}
@php
    $tvaTurnstileKey = (string) config('services.turnstile.site_key', '');
@endphp

@if ($tvaTurnstileKey !== '')
    <div class="intro-x mt-4">
        <div class="cf-turnstile"
             data-sitekey="{{ $tvaTurnstileKey }}"
             {{-- Follow the admin theme: it's dark (html.dark is hard-coded in
                  layouts/master), but auto also handles the light auth pages. --}}
             data-theme="auto"
             data-size="flexible"
             data-action="turnstile-spin-v2"></div>

        @error('cf-turnstile-response')
            <div class="text-danger mt-2 text-xs">{{ $message }}</div>
        @enderror
    </div>

    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        <script>
            // Turnstile tokens are SINGLE-USE: they are redeemed at siteverify and
            // cannot be replayed. If a submit doesn't navigate away — inline error,
            // client-side validation, an AJAX post — the DOM still holds the spent
            // token, and a retry is rejected as `timeout-or-duplicate`. Reset the
            // widget after every submit so a second attempt gets a fresh token.
            document.addEventListener('submit', function (e) {
                if (e.target && e.target.querySelector && e.target.querySelector('.cf-turnstile')) {
                    setTimeout(function () {
                        if (window.turnstile) { window.turnstile.reset(); }
                    }, 0);
                }
            }, true);
        </script>
    @endonce
@endif
