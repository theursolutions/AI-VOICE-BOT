@extends('layouts.auth', ['authTitle' => 'Sign in'])
@section('content')
     <div class="container sm:px-10">
        <div class="block xl:grid grid-cols-2 gap-4">
            <!-- BEGIN: Login Info -->
            <div class="hidden xl:flex flex-col min-h-screen">
                <a href="{{ url('/') }}" class="-intro-x flex items-center pt-5">
                    <img alt="Serve AI" class="w-8" src="{{serveai_icon()}}">
                    <span class="text-white text-xl font-semibold ml-3">Serve AI</span>
                </a>
                <div class="my-auto">
                    <img alt="" class="-intro-x w-1/2 -mt-16" src="{{url('/assets/dist/images/illustration.svg')}}">
                    <div class="-intro-x text-white font-medium text-4xl leading-tight mt-10">
                        Welcome back.
                        <br>
                        Your agent never stopped.
                    </div>
                    <div class="-intro-x mt-5 text-lg text-white text-opacity-70 dark:text-slate-400">Your AI voice &amp; chat platform for every customer conversation.</div>
                </div>
            </div>
            <!-- END: Login Info -->
            <!-- BEGIN: Login Form -->
            <div class="h-screen xl:h-auto flex py-5 xl:py-0 my-10 xl:my-0">
                <div class="my-auto mx-auto xl:ml-20 bg-white dark:bg-darkmode-600 xl:bg-transparent px-5 sm:px-8 py-8 xl:p-0 rounded-md shadow-md xl:shadow-none w-full sm:w-3/4 lg:w-2/4 xl:w-auto">
                    <h2 class="intro-x font-bold text-2xl xl:text-3xl text-center xl:text-left">
                        Sign In
                    </h2>
                    <div class="intro-x mt-2 text-slate-400 xl:hidden text-center">Sign in to your Serve AI workspace.</div>
                    <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div class="intro-x mt-8">
                        <input id="email" type="email" placeholder="Enter Email" class="intro-x login__input form-control py-3 px-4 block @error('email') is-invalid @enderror" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
                        @error('email')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                        <input id="password" type="password" placeholder="Enter Password" class="intro-x login__input form-control py-3 px-4 block mt-4 @error('password') is-invalid @enderror" name="password" required autocomplete="current-password">
                        @error('password')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror    
                    </div>
                    <div class="intro-x flex text-slate-600 dark:text-slate-500 text-xs sm:text-sm mt-4">
                        <div class="flex items-center mr-auto">
                            <input type="checkbox" name="remember" id="remember" class="form-check-input border mr-2">
                            <label class="cursor-pointer select-none" for="remember">{{ __('Remember Me') }}</label>
                        </div>
                        <a href="{{ route('password.request') }}">Forgot Password?</a>
                    </div>

                    @include('partials.turnstile')
                    <div class="intro-x mt-5 xl:mt-8 text-center xl:text-left">
                        <button type="submit" class="btn btn-primary py-3 px-4 w-full xl:w-32 xl:mr-3 align-top">Sign In</button>
                        <a href="{{url('/register')}}" class="btn btn-outline-secondary py-3 px-4 w-full xl:w-32 mt-3 xl:mt-0 align-top">Register</a>
                    </div>
                    </form>

                    @include('auth.partials.social-buttons')
                    <div class="intro-x mt-10 xl:mt-24 text-slate-600 dark:text-slate-500 text-center xl:text-left"> By signing up, you agree to our <a class="text-primary dark:text-slate-200" href="{{ route('terms') }}" target="_blank" rel="noopener">Terms and Conditions</a> &amp; <a class="text-primary dark:text-slate-200" href="{{ route('privacy') }}" target="_blank" rel="noopener">Privacy Policy</a> </div>
                </div>
            </div>
            <!-- END: Login Form -->
        </div>
    </div>
@endsection