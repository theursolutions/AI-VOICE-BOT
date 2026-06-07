<x-guest-layout>
    <div class="space-y-4">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
            Choose a workspace
        </h1>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            You belong to {{ $clients->count() }} workspaces. Pick one to continue —
            you can switch any time from the menu.
        </p>

        @if ($errors->any())
            <div class="p-3 rounded bg-red-50 text-red-700 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <ul class="divide-y divide-gray-200 dark:divide-gray-700 border border-gray-200 dark:border-gray-700 rounded-md">
            @foreach ($clients as $client)
                <li class="p-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-900">
                    <div>
                        <div class="font-medium text-gray-900 dark:text-gray-100">
                            {{ $client->name }}
                        </div>
                        @if ($client->description)
                            <div class="text-xs text-gray-500">{{ $client->description }}</div>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('workspace.select') }}">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <button type="submit"
                                class="inline-flex items-center px-3 py-1.5 rounded-md border border-transparent
                                       bg-gray-800 dark:bg-gray-200 text-white dark:text-gray-800
                                       text-xs uppercase tracking-widest font-semibold
                                       hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2
                                       focus:ring-gray-500 transition">
                            Enter
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center justify-between text-sm">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-gray-500 hover:text-gray-700">Log out</button>
            </form>
        </div>
    </div>
</x-guest-layout>
