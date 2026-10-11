@extends('layouts.ops')

@section('content')
<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🛰️</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">SIP &amp; WebRTC server</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Where phones and browsers reach Asterisk, used by every client's extensions and website call buttons.
            </div>
        </div>
        <a href="{{ route('ops.telephony.index') }}" class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3);">← All numbers</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <div class="tva-dt-card" style="padding:20px 22px;">
        <div class="flex items-center gap-2 mb-4">
            <span style="width:10px; height:10px; border-radius:50%; background:{{ $connected ? '#10b981' : '#ef4444' }};"></span>
            <b>{{ $connected ? 'Voice engine is connected to Asterisk' : 'Voice engine cannot reach Asterisk' }}</b>
            @unless ($connected)
                <span class="text-slate-500 text-xs">Set ASTERISK_ARI_* in voice-engine/.env and check /metrics shows "asterisk_connected": true.</span>
            @endunless
        </div>

        <form method="POST" action="{{ route('ops.telephony.sip.save') }}" class="grid gap-4" style="max-width:760px;">
            @csrf

            <div>
                <label class="form-label">Browser WebSocket URL</label>
                <input type="text" name="ws_url" class="form-control" required value="{{ old('ws_url', $settings['ws_url']) }}" placeholder="wss://sip.serveai.com.pk/ws">
                <small class="text-slate-500 text-xs">The admin browser phone and website call buttons connect here. Must be <code>wss://</code> for an https website; <code>ws://localhost:8088/ws</code> only for a local lab.</small>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="form-label">SIP server for phones &amp; apps</label>
                    <input type="text" name="host" class="form-control" required value="{{ old('host', $settings['host']) }}" placeholder="sip.serveai.com.pk">
                </div>
                <div>
                    <label class="form-label">Port</label>
                    <input name="port" type="number" class="form-control" required value="{{ old('port', $settings['port']) }}">
                </div>
            </div>
            <small class="text-slate-500 text-xs -mt-2">Shown to clients on their Extensions page as the server to type into MicroSIP, Zoiper or a desk phone.</small>

            <div>
                <label class="form-label">STUN servers <span class="text-slate-400 font-normal">(one per line)</span></label>
                <textarea name="stun" rows="2" class="form-control" placeholder="stun:stun.l.google.com:19302">{{ old('stun', implode("\n", $settings['stun'])) }}</textarea>
            </div>

            <div class="p-3 rounded-lg" style="border:1px solid rgba(100,116,139,.25)">
                <div class="form-label mb-1" style="font-weight:600">TURN server <span class="text-slate-400 font-normal">(recommended for the live site)</span></div>
                <small class="text-slate-500 text-xs block mb-2">Relays audio for visitors behind strict office or mobile-data networks, where STUN alone gives a connected call with no sound.</small>
                <label class="form-label">TURN URL(s)</label>
                <input type="text" name="turn_url" class="form-control" value="{{ old('turn_url', $settings['turn_url']) }}" placeholder="turn:turn.serveai.com.pk:3478?transport=udp, turns:turn.serveai.com.pk:5349">
                <div class="grid grid-cols-2 gap-3 mt-2">
                    <div>
                        <label class="form-label">Username</label>
                        <input type="text" name="turn_username" class="form-control" value="{{ old('turn_username', $settings['turn_username']) }}" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label">Password</label>
                        <input name="turn_credential" type="password" class="form-control" value=""
                               placeholder="{{ $settings['turn_credential'] !== '' ? '•••••••• (saved, leave empty to keep)' : '' }}" autocomplete="new-password">
                    </div>
                </div>
            </div>

            <div><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>
@endsection
