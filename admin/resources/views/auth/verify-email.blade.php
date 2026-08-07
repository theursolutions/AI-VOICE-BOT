<x-guest-layout>
    @php
        $askSlug = optional(auth()->user()->activeClient)->slug;
    @endphp

    <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
        {{ __('We emailed a 6-digit verification code to') }}
        <span class="font-medium">{{ auth()->user()->email }}</span>.
        {{ __('Enter it below to unlock your full workspace. The code expires in 10 minutes.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
            {{ __('A new code has been sent to your email address.') }}
        </div>
    @endif

    {{-- Enter the code --}}
    <form method="POST" action="{{ route('verification.otp') }}">
        @csrf
        <div>
            <x-input-label for="code" :value="__('Verification code')" />
            <x-text-input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                          maxlength="6" placeholder="------"
                          class="block mt-1 w-full tracking-[0.5em] text-center text-lg font-semibold"
                          required autofocus />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-primary-button class="w-full justify-center">
                {{ __('Verify email') }}
            </x-primary-button>
        </div>
    </form>

    <div class="mt-6 flex items-center justify-between text-sm">
        {{-- Resend a fresh code --}}
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                {{ __('Resend code') }}
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="underline text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                {{ __('Log out') }}
            </button>
        </form>
    </div>

    @if ($askSlug)
        <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-4 text-center text-sm">
            <a href="{{ route('assistant.index', ['client' => $askSlug]) }}"
               class="text-indigo-600 dark:text-indigo-400 hover:underline">
                {{ __('Skip for now and continue to Ask AI →') }}
            </a>
            <p class="text-xs text-gray-500 mt-1">{{ __('Other sections stay locked until your email is verified.') }}</p>
        </div>
    @endif
</x-guest-layout>
