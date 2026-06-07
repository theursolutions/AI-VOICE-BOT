<x-guest-layout>
    <div class="mb-4 text-sm">
        <h2 class="text-xl font-semibold">Join {{ $client->name }}</h2>
        <p class="text-gray-600 mt-1">
            You've been invited to collaborate. Set up your account below
            (or if you already have one with this email, just enter your existing password).
        </p>
    </div>

    @if ($errors->any())
        <div class="mb-3 text-sm text-red-600">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('invitations.accept.confirm', $invitation->token) }}">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">Name</label>
            <input id="name" name="name" type="text" required autofocus
                   value="{{ old('name') }}"
                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
        </div>

        <div class="mt-4">
            <label for="email" class="block text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" readonly
                   value="{{ $invitation->email }}"
                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm bg-gray-100">
            <p class="text-xs text-gray-500 mt-1">Locked to the invited address.</p>
        </div>

        <div class="mt-4">
            <label for="password" class="block text-sm font-medium">Password</label>
            <input id="password" name="password" type="password" required
                   autocomplete="new-password"
                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
        </div>

        <div class="mt-4">
            <label for="password_confirmation" class="block text-sm font-medium">Confirm Password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required
                   autocomplete="new-password"
                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
        </div>

        <div class="flex items-center justify-end mt-5">
            <a href="{{ route('login') }}" class="text-sm underline text-gray-600 mr-4">
                Already have an account? Sign in
            </a>
            <button type="submit" class="px-4 py-2 bg-gray-800 text-white rounded-md text-sm">
                Accept &amp; join
            </button>
        </div>
    </form>
</x-guest-layout>
