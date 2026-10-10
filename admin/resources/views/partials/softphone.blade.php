{{-- serveAI softphone — the in-browser phone on every admin page.
     Logic: public/assets/softphone/softphone.js · backend: SoftphoneController. --}}
@auth
    @php
        $spClient = request()->attributes->get('client') ?? auth()->user()->activeClient;
        $spAsset  = fn (string $file) => url('assets/softphone/' . $file) . '?v=' . @filemtime(public_path('assets/softphone/' . $file));
    @endphp
    @if ($spClient)
        {{-- A remote for the phone window (/c/{client}/phone), which holds the
             call — so refreshing or navigating this page never drops it. --}}
        <link rel="stylesheet" href="{{ $spAsset('softphone.css') }}">
        <div id="tva-softphone"
             data-mode="remote"
             data-window-url="{{ route('softphone.window', ['client' => $spClient->slug]) }}"
             data-user="{{ auth()->user()->name }}"></div>
        <script src="{{ $spAsset('softphone.js') }}"></script>
    @endif
@endauth
