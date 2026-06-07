@extends('layouts.ops')

@section('content')
<style>
    .msg-row { display:flex; gap:10px; margin-bottom:14px; }
    .msg-row.is-bot { flex-direction: row-reverse; }
    .msg-bubble { max-width:70%; padding:10px 14px; border-radius:14px; font-size:13.5px; line-height:1.5; }
    .msg-row.is-user .msg-bubble { background:#f1f5f9; color:#0f172a; border-bottom-left-radius:4px; }
    .msg-row.is-bot  .msg-bubble { background: var(--tva-gradient); color:#fff; border-bottom-right-radius:4px; }
    .msg-who { font-size:10px; color:#94a3b8; text-transform:uppercase; letter-spacing:.08em; margin-bottom:3px; }
    html.dark .msg-row.is-user .msg-bubble { background:#334155; color:#f1f5f9; }
</style>

<div class="content">
    <div class="intro-y flex items-center mt-6 mb-4">
        <a href="{{ route('ops.sessions.index') }}" class="btn btn-secondary mr-3"><i data-lucide="arrow-left" class="w-4 h-4 mr-1 inline"></i> Back</a>
        <h2 class="text-lg font-medium">Session #{{ $session->id }}</h2>
    </div>

    <div class="grid grid-cols-12 gap-5">
        <div class="intro-y box p-5 col-span-12 lg:col-span-4">
            <h3 class="font-medium mb-3"><i data-lucide="info" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Details</h3>
            <div style="font-size:13px; line-height:1.8;">
                <div><b>Project:</b> {{ $project->name }}</div>
                <div><b>Owner:</b> {{ $client?->name ?? '—' }}</div>
                <div><b>Channel:</b> <span class="tva-channel-chip is-{{ $session->channel }}">{{ $session->channel }}</span></div>
                <div><b>Status:</b> <span class="tva-status is-{{ $session->status }}">{{ $session->status }}</span></div>
                <div><b>Customer:</b> {{ $session->customer_name ?: '—' }}</div>
                <div><b>Email:</b> {{ $session->customer_email ?: '—' }}</div>
                <div><b>Phone:</b> {{ $session->customer_phone ?: '—' }}</div>
                <div><b>Started:</b> {{ $session->started_at ? date('M j, Y · H:i', $session->started_at) : '—' }}</div>
                <div><b>Last activity:</b> {{ $session->last_activity_at ? date('M j, Y · H:i', $session->last_activity_at) : '—' }}</div>
                @if ($session->external_id)
                    <div style="margin-top:6px;"><b>External ID:</b><br><span style="font-family: ui-monospace, monospace; font-size:11px; color:#94a3b8;">{{ $session->external_id }}</span></div>
                @endif
            </div>
        </div>

        <div class="intro-y box p-5 col-span-12 lg:col-span-8">
            <h3 class="font-medium mb-4"><i data-lucide="message-square" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Transcript · {{ count($messages) }} messages</h3>
            @forelse ($messages as $m)
                @php $isBot = ($m->role ?? '') === 'assistant' || ($m->sender ?? '') === 'bot'; @endphp
                <div class="msg-row {{ $isBot ? 'is-bot' : 'is-user' }}">
                    <div>
                        <div class="msg-who">{{ $isBot ? 'AI' : 'User' }} · {{ $m->created_at ? date('H:i:s', is_int($m->created_at) ? $m->created_at : strtotime($m->created_at)) : '' }}</div>
                        <div class="msg-bubble">{{ $m->content ?? $m->text ?? '—' }}</div>
                    </div>
                </div>
            @empty
                <div style="text-align:center; padding:30px; color:#94a3b8;">No messages logged on this session.</div>
            @endforelse
        </div>
    </div>
</div>

<script>if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();</script>
@endsection
