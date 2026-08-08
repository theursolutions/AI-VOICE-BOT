{{-- Verification screen, inside the ADMIN shell (layouts.master) rather than
     Breeze's bare <x-guest-layout>. Two reasons:
       • The user is authenticated — dropping them onto a styleless standalone
         page reads like the app broke.
       • The sidebar already shows the blurred "verify to unlock" veil, so
         keeping the shell makes the connection obvious: here is what's locked,
         here is how to unlock it.
     All the master-layout view-composer variables ($tvaProfile, $tvaProject,
     $tvaModules, $tvaWidget) are null-safe, so this works on a non-client route. --}}
@extends('layouts.master')

@section('content')
@php
    $askSlug = optional(auth()->user()->activeClient)->slug;
    $email   = auth()->user()->email;
@endphp

<div class="intro-y flex items-center mt-8">
    <h2 class="text-lg font-medium mr-auto">Verify your email</h2>
</div>

<div class="grid grid-cols-12 gap-6 mt-5">
    <div class="col-span-12 lg:col-span-7 xl:col-span-6">
        <div class="intro-y box">

            {{-- Header band, matching the email template's theme --}}
            <div class="px-5 py-6 border-b border-slate-200/60 dark:border-darkmode-400 flex items-center">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 text-white"
                     style="background-image: var(--tva-gradient, linear-gradient(135deg,#3b82f6,#2563eb));">
                    <i data-lucide="mail-check" class="w-6 h-6"></i>
                </div>
                <div class="ml-4">
                    <div class="font-medium text-base">Check your inbox</div>
                    <div class="text-slate-500 text-xs mt-0.5">
                        We sent a 6-digit code to <span class="font-medium text-slate-600 dark:text-slate-300">{{ $email }}</span>
                    </div>
                </div>
            </div>

            <div class="p-5">
                @if (session('status') == 'verification-link-sent' || session('status'))
                    <div class="alert alert-success-soft show flex items-center mb-5" role="alert">
                        <i data-lucide="check-circle" class="w-5 h-5 mr-2 flex-shrink-0"></i>
                        <span>A fresh verification code is on its way to your inbox.</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('verification.otp') }}">
                    @csrf
                    <label for="code" class="form-label font-medium">Verification code</label>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="6" pattern="[0-9]{6}" required autofocus
                           placeholder="------"
                           class="form-control text-center @error('code') border-danger @enderror"
                           style="font-size:30px; font-weight:700; letter-spacing:14px; padding:14px 0; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">
                    @error('code')
                        <div class="text-danger mt-2 text-xs">{{ $message }}</div>
                    @enderror

                    <div class="text-slate-500 text-xs mt-3">
                        The code expires 10 minutes after it was sent.
                    </div>

                    <button type="submit" class="btn btn-primary w-full mt-5 py-3">
                        Verify &amp; unlock my workspace
                    </button>
                </form>

                <div class="flex flex-col sm:flex-row items-center gap-3 mt-5 pt-5 border-t border-slate-200/60 dark:border-darkmode-400">
                    <form method="POST" action="{{ route('verification.send') }}" class="w-full sm:w-auto">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary w-full sm:w-auto">
                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i> Resend code
                        </button>
                    </form>
                    <form method="POST" action="{{ route('logout') }}" class="w-full sm:w-auto sm:ml-auto">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger w-full sm:w-auto">
                            <i data-lucide="log-out" class="w-4 h-4 mr-2"></i> Sign out
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- What verifying unlocks — mirrors the sidebar's locked veil. --}}
    <div class="col-span-12 lg:col-span-5 xl:col-span-6">
        <div class="intro-y box p-5">
            <div class="font-medium text-base flex items-center">
                <i data-lucide="lock" class="w-4 h-4 mr-2 text-danger"></i>
                Locked until you verify
            </div>
            <div class="text-slate-500 text-xs mt-2">
                Everything greyed out in the sidebar becomes available the moment your email is confirmed.
            </div>
            <div class="mt-4 space-y-3">
                @foreach ([
                    ['message-square', 'Messages &amp; Channels', 'WhatsApp, Instagram and Facebook in one inbox'],
                    ['database',       'Data Sources',            'Upload files or connect your database'],
                    ['audio-lines',    'Voices &amp; Telephony',  'Pick a voice and connect a phone number'],
                    ['users',          'Leads &amp; Conversations','Every enquiry your agent captures'],
                ] as [$icon, $title, $body])
                    <div class="flex items-start">
                        <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-darkmode-400 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="{{ $icon }}" class="w-4 h-4 text-slate-500"></i>
                        </div>
                        <div class="ml-3">
                            <div class="text-sm font-medium">{!! $title !!}</div>
                            <div class="text-slate-500 text-xs">{{ $body }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($askSlug)
                <div class="mt-5 pt-5 border-t border-slate-200/60 dark:border-darkmode-400">
                    <div class="text-slate-500 text-xs mb-3">
                        In the meantime, Ask AI is already available.
                    </div>
                    <a href="{{ route('assistant.index', ['client' => $askSlug]) }}" class="btn btn-outline-primary w-full">
                        <i data-lucide="bot" class="w-4 h-4 mr-2"></i> Open Ask AI
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
