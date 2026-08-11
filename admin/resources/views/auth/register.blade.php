@extends('layouts.auth', ['authTitle' => 'Create your account'])

@php $brand = tva_setting('content.brand_name', 'Serve AI'); @endphp

@section('content')
 <div class="container sm:px-10">
    <div class="block xl:grid grid-cols-2 gap-4">
        <!-- BEGIN: Register Info -->
        <div class="hidden xl:flex flex-col min-h-screen">
            <a href="{{ url('/') }}" class="-intro-x flex items-center pt-5">
                <img alt="{{ $brand }}" class="w-8" src="{{ serveai_icon_sized(64) }}" width="32" height="32">
                <span class="text-white text-xl font-semibold ml-3">{{ $brand }}</span>
            </a>
            <div class="my-auto">
                <img alt="" class="-intro-x w-1/2 -mt-16" src="{{url('/assets/dist/images/illustration.svg')}}">
                <div class="-intro-x text-white font-medium text-4xl leading-tight mt-10">
                    Your AI agent,
                    <br>
                    live in minutes.
                </div>
                <div class="-intro-x mt-5 text-lg text-white text-opacity-70 dark:text-slate-400">
                    Answer every call, chat and message — 24/7. Free to start, no card required.
                </div>
            </div>
        </div>
        <!-- END: Register Info -->
        <!-- BEGIN: Register Form -->
        <div class="h-screen xl:h-auto flex py-5 xl:py-0 my-10 xl:my-0">
            <form method="POST" action="{{ route('register') }}">
                @csrf
                <div class="my-auto mx-auto xl:ml-20 bg-white dark:bg-darkmode-600 xl:bg-transparent px-5 sm:px-8 py-8 xl:p-0 rounded-md shadow-md xl:shadow-none w-full sm:w-3/4 lg:w-2/4 xl:w-auto">
                    <h2 class="intro-x font-bold text-2xl xl:text-3xl text-center xl:text-left">
                        Sign Up
                    </h2>
                    <div class="intro-x mt-2 text-slate-400 dark:text-slate-400 xl:hidden text-center">Create your {{ $brand }} workspace. Free to start, no card required.</div>
                    <div class="intro-x mt-8">
                        <input id="name" type="text" class="intro-x login__input form-control py-3 px-4 block @error('name') is-invalid @enderror" placeholder="Enter Name" name="name" value="{{ old('name') }}" required autocomplete="name" autofocus>
                        @error('name')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                        <input id="company_name" type="text" class="intro-x login__input form-control py-3 px-4 block mt-4 @error('company_name') is-invalid @enderror" placeholder="Enter Company Name" name="company_name" value="{{ old('company_name') }}" required>
                        @error('company_name')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                        <input id="email" type="email" class="intro-x login__input form-control py-3 px-4 block mt-4 @error('email') is-invalid @enderror" name="email" placeholder="Enter Email" value="{{ old('email') }}" required autocomplete="email">
                        @error('email')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                        <input id="password" type="password" class="intro-x login__input form-control py-3 px-4 block mt-4 @error('password') is-invalid @enderror" placeholder="Enter Password" name="password" required autocomplete="new-password">
                        @error('password')
                            <span class="invalid-feedback" role="alert">
                                <strong>{{ $message }}</strong>
                            </span>
                        @enderror
                        
                        {{-- Password strength. This used to be four hardcoded bars —
                             three permanently green regardless of what was typed —
                             which told a user that "password" was strong. A meter
                             that lies is worse than no meter. Now driven by the
                             script at the foot of this file. --}}
                        <div class="intro-x w-full grid grid-cols-12 gap-4 h-1 mt-3" id="pwBars" aria-hidden="true">
                            <div class="col-span-3 h-full rounded bg-slate-100 dark:bg-darkmode-800" data-bar></div>
                            <div class="col-span-3 h-full rounded bg-slate-100 dark:bg-darkmode-800" data-bar></div>
                            <div class="col-span-3 h-full rounded bg-slate-100 dark:bg-darkmode-800" data-bar></div>
                            <div class="col-span-3 h-full rounded bg-slate-100 dark:bg-darkmode-800" data-bar></div>
                        </div>
                        <div class="intro-x text-slate-500 block mt-2 text-xs sm:text-sm" id="pwHint" aria-live="polite">
                            At least 8 characters. Longer is stronger than complicated.
                        </div>
                        <input id="password-confirm" type="password" class="intro-x login__input form-control py-3 px-4 block mt-4" placeholder="Confirm Password" name="password_confirmation" required autocomplete="new-password">
                    </div>

                    {{-- Consent. Previously this said "I agree to the Envato Privacy
                         Policy" — the template vendor's name — with an empty href,
                         no `name` attribute and no server-side check. It looked like
                         an agreement and legally was not one: nothing was submitted
                         and nothing was verified. Now it names our own documents,
                         links to both, and is enforced in
                         RegisteredUserController with an `accepted` rule. --}}
                    <div class="intro-x flex items-start text-slate-600 dark:text-slate-500 mt-4 text-xs sm:text-sm">
                        <input id="terms" name="terms" value="1" type="checkbox" required
                               class="form-check-input border mr-2 mt-0.5 flex-shrink-0 @error('terms') border-danger @enderror"
                               @checked(old('terms'))>
                        <label class="cursor-pointer select-none" for="terms">
                            I agree to the {{ $brand }}
                            <a class="text-primary dark:text-slate-200" href="{{ url('/terms') }}" target="_blank" rel="noopener">Terms of Service</a>
                            and
                            <a class="text-primary dark:text-slate-200" href="{{ url('/privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>.
                        </label>
                    </div>
                    @error('terms')
                        <div class="intro-x text-danger mt-1 text-xs sm:text-sm">{{ $message }}</div>
                    @enderror
                    <div class="intro-x mt-5 xl:mt-8 text-center xl:text-left">
@include('partials.turnstile')
                        <button type="submit" class="btn btn-primary py-3 px-4 w-full xl:w-32 xl:mr-3 align-top">Register</button>
                        <a href="{{url('/login')}}" class="btn btn-outline-secondary py-3 px-4 w-full xl:w-32 mt-3 xl:mt-0 align-top">Sign in</a>
                    </div>

                    @include('auth.partials.social-buttons')
                </div>
            </form>
        </div>
        <!-- END: Register Form -->
    </div>
</div>

<script>
/* Password strength — deliberately simple and honest.
   Scores length first (which is what actually resists cracking) and
   character variety second, then colours the bars to match. It never
   claims a short password is strong, which is the bug this replaces. */
(function () {
    var input = document.getElementById('password');
    var bars  = document.querySelectorAll('#pwBars [data-bar]');
    var hint  = document.getElementById('pwHint');
    if (!input || !bars.length) return;

    var EMPTY  = 'bg-slate-100 dark:bg-darkmode-800';
    var LEVELS = [
        { cls: 'bg-danger',  text: 'Too short — use at least 8 characters.' },
        { cls: 'bg-warning', text: 'Weak. Add length, or a number or symbol.' },
        { cls: 'bg-warning', text: 'Fair. A few more characters would help.' },
        { cls: 'bg-success', text: 'Good.' },
        { cls: 'bg-success', text: 'Strong.' },
    ];

    function score(v) {
        if (!v) return -1;
        if (v.length < 8) return 0;

        var s = 1;
        if (v.length >= 12) s++;                       // length beats complexity
        if (v.length >= 16) s++;
        var variety = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/]
            .filter(function (re) { return re.test(v); }).length;
        if (variety >= 3) s++;

        return Math.min(s, 4);
    }

    function render() {
        var s = score(input.value);

        bars.forEach(function (bar, i) {
            bar.className = 'col-span-3 h-full rounded ' +
                (s > 0 && i < s ? LEVELS[s].cls : EMPTY);
        });

        hint.textContent = s < 0
            ? 'At least 8 characters. Longer is stronger than complicated.'
            : LEVELS[s].text;
    }

    input.addEventListener('input', render);
    render();
})();
</script>
@endsection
