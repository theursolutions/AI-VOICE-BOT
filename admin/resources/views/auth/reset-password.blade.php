{{-- Step 2 of the reset flow, reached from the emailed link. Matches
     auth/forgot-password.blade.php (layouts.auth + the Midone theme) rather
     than Breeze's <x-guest-layout>, which is plain Tailwind with different
     branding — landing on it from a Serve AI email looked like a different
     product mid-flow. --}}
@extends('layouts.auth')
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
                    Choose a new
                    <br>
                    password.
                </div>
                <div class="-intro-x mt-5 text-lg text-white text-opacity-70 dark:text-slate-400">
                    Pick something you don't use anywhere else — at least 8 characters.
                </div>
            </div>
        </div>
        <!-- END: Info panel -->

        <!-- BEGIN: New-password form -->
        <div class="h-screen xl:h-auto flex py-5 xl:py-0 my-10 xl:my-0">
            <div class="my-auto mx-auto xl:ml-20 bg-white dark:bg-darkmode-600 xl:bg-transparent px-5 sm:px-8 py-8 xl:p-0 rounded-md shadow-md xl:shadow-none w-full sm:w-3/4 lg:w-2/4 xl:w-auto">
                <h2 class="intro-x font-bold text-2xl xl:text-3xl text-center xl:text-left">
                    Set a New Password
                </h2>
                <div class="intro-x mt-2 text-slate-400 xl:hidden text-center">
                    Pick something you don't use anywhere else.
                </div>

                <form method="POST" action="{{ route('password.store') }}">
                    @csrf

                    {{-- The signed token from the emailed link. --}}
                    <input type="hidden" name="token" value="{{ $request->route('token') }}">

                    <div class="intro-x mt-8">
                        <input id="email" type="email" name="email"
                               value="{{ old('email', $request->email) }}"
                               placeholder="Your account email"
                               class="intro-x login__input form-control py-3 px-4 block @error('email') is-invalid @enderror"
                               required autocomplete="username">
                        @error('email')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror

                        <input id="password" type="password" name="password"
                               placeholder="New password"
                               class="intro-x login__input form-control py-3 px-4 block mt-4 @error('password') is-invalid @enderror"
                               required autocomplete="new-password">
                        @error('password')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror

                        <input id="password_confirmation" type="password" name="password_confirmation"
                               placeholder="Confirm new password"
                               class="intro-x login__input form-control py-3 px-4 block mt-4"
                               required autocomplete="new-password">
                        @error('password_confirmation')
                            <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
                        @enderror
                    </div>

                    <div class="intro-x mt-4 text-slate-500 text-xs sm:text-sm">
                        Use at least 8 characters. You'll be signed in with the new password.
                    </div>

                    <div class="intro-x mt-5 xl:mt-8 text-center xl:text-left">
                        <button type="submit" class="btn btn-primary py-3 px-4 w-full xl:w-auto xl:mr-3 align-top">
                            Reset Password
                        </button>
                        <a href="{{ route('login') }}"
                           class="btn btn-outline-secondary py-3 px-4 w-full xl:w-auto mt-3 xl:mt-0 align-top">
                            Back to Sign In
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <!-- END: New-password form -->
    </div>
</div>
@endsection
