{{-- The phone window (SoftphoneController@window). It holds the SIP sign-in
     and the live call; admin pages mirror it over a BroadcastChannel, so a
     page refresh never drops a call. Deliberately standalone: no admin
     layout, no app bundle. --}}
@php
    $spAsset = fn (string $file) => url('assets/softphone/' . $file) . '?v=' . @filemtime(public_path('assets/softphone/' . $file));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>serveAI Phone</title>
    <link rel="stylesheet" href="{{ $spAsset('softphone.css') }}">
    <style>
        html, body { margin: 0; min-height: 100%; background: #0b2440; }
        body { font-family: Roboto, "Segoe UI", system-ui, sans-serif; }
    </style>
</head>
<body>
    <div id="tva-softphone"
         data-mode="engine"
         data-config-url="{{ route('softphone.config') }}"
         data-save-url="{{ route('softphone.save') }}"
         data-lookup-url="{{ route('softphone.lookup', ['client' => $client->slug]) }}"
         data-csrf="{{ csrf_token() }}"
         data-user="{{ auth()->user()->name }}"></div>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.460.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jssip@3.12.0/dist/jssip.min.js"></script>
    <script src="{{ $spAsset('softphone.js') }}"></script>
</body>
</html>
