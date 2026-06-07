@php
    /** @var \App\Models\User|null $user */
    $user      = auth()->user();
    $clients   = $user ? $user->clients()->orderBy('clients.name')->get() : collect();
    $current   = $user?->activeClient;
@endphp

@if ($clients->count() > 1 && $current)
    <div class="workspace-switcher relative inline-block">
        <button type="button"
                onclick="document.getElementById('workspace-switcher-menu').classList.toggle('hidden')"
                class="inline-flex items-center gap-2 px-3 py-1.5 rounded border border-gray-300
                       bg-white text-sm text-gray-700 hover:bg-gray-50">
            <span class="font-medium">{{ $current->name }}</span>
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                      d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
                      clip-rule="evenodd"/>
            </svg>
        </button>

        <div id="workspace-switcher-menu"
             class="hidden absolute right-0 mt-1 w-56 rounded shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50">
            <div class="py-1">
                @foreach ($clients as $c)
                    <form method="POST" action="{{ route('workspace.select') }}">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $c->id }}">
                        <button type="submit"
                                class="w-full text-left px-4 py-2 text-sm flex items-center justify-between
                                       hover:bg-gray-100 {{ $c->id === $current->id ? 'font-semibold text-gray-900' : 'text-gray-700' }}">
                            <span>{{ $c->name }}</span>
                            @if ($c->id === $current->id)
                                <span class="text-xs text-green-600">●</span>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
@elseif ($current)
    <span class="inline-flex items-center px-3 py-1.5 text-sm text-gray-700">
        <span class="font-medium">{{ $current->name }}</span>
    </span>
@endif
