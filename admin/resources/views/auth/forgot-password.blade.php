{{-- Matches auth/login.blade.php: layouts.auth + the Midone theme, rather than
     Breeze's <x-guest-layout> (plain Tailwind, wrong branding, and the layout
     that was 500ing on a bad @vite entry). Same two-column split, same input
     styling, so the reset flow doesn't look like a different product. --}}
@extends('layouts.auth', ['authTitle' => 'Reset your password'])
@section('content')
<div class="container sm:px-10">
    <div class="block xl:grid grid-cols-2 gap-4">
        <!-- BEGIN: Info panel -->
        <div class="hidden xl:flex flex-col min-h-screen">
            <a href="{{ url('/') }}" class="-intro-x flex items-center pt-5">
                <img alt="Serve AI" class="w-8" src="{{ serveai_icon() }}">
                <span class="text-white text-xl font-semibold ml-3">Serve AI</span>
            </a>
            <div class="my-auto">
                <img alt="Serve AI" class="-intro-x w-1/2 -mt-16" src="{{ url('/assets/dist/images/illustration.svg') }}">
                <div class="-intro-x text-white font-medium text-4xl leading-tight mt-10">
                    Forgot your
                    <br>
                    password?
                </div>
                <div class="-intro-x mt-5 text-lg text-white text-opacity-70 dark:text-slate-400">
                    Tell us your email and we'll send you a secure link to set a new one.
                </div>
            </div>
        </div>
        <!-- END: Info panel -->

        <!-- BEGIN: Reset form -->
        <div class="h-screen xl:h-auto flex py-5 xl:py-0 my-10 xl:my-0">
            <div class="my-auto mx-auto xl:ml-20 bg-white dark:bg-darkmode-600 xl:bg-transparent px-5 sm:px-8 py-8 xl:p-0 rounded-md shadow-md xl:shadow-none w-full sm:w-3/4 lg:w-2/4 xl:w-auto">
                <h2 class="intro-x font-bold text-2xl xl:text-3xl text-center xl:text-left">
                    Reset Password
                </h2>
                <div class="intro-x mt-2 text-slate-400 xl:hidden text-center">
                    We'll email you a link to choose a new password.
                </div>

                {{-- Success notice after the link is sent. Laravel puts the
                     localised message in the session, not in $errors. --}}
                @if (session('status'))
                    <div class="intro-x mt-6 alert alert-success-soft show flex items-center" role="alert">
                        <i data-lucide="check-circle" class="w-5 h-5 mr-2 flex-shrink-0"></i>
                        <span>{{ session('status') }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}">
                    @csrf

                    <div class="intro-x mt-8">
                        <input id="email" type="email" name="email" value="{{ old('email') }}"
                               placeholder="Enter your account email"
                               class="intro-x login__input form-control py-3 px-4 block @error('email') is-invalid @enderror"
                               required autocomplete="email" autofocus>
                        @error('email')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="intro-x mt-4 text-slate-500 text-xs sm:text-sm">
                        The link expires shortly for security. Check your spam folder if it doesn't arrive.
                    </div>

                    @include('partials.turnstile')

                    <div class="intro-x mt-5 xl:mt-8 text-center xl:text-left">
                        <button type="submit" class="btn btn-primary py-3 px-4 w-full xl:w-auto xl:mr-3 align-top">
                            Send Reset Link
                        </button>
                        <a href="{{ route('login') }}"
                           class="btn btn-outline-secondary py-3 px-4 w-full xl:w-auto mt-3 xl:mt-0 align-top">
                            Back to Sign In
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <!-- END: Reset form -->
    </div>
</div>
@endsection
