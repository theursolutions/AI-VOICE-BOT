{{-- Flash messages for the billing area. Extracted so every billing screen
     reports success and failure identically — a customer shouldn't have to
     learn a second visual language for "that worked" on a different page. --}}
@foreach ([['success','ok','check-circle'], ['error','err','alert-octagon'], ['info','info','info'], ['billing_warning','warn','alert-triangle']] as [$key, $kind, $icon])
    @if (session($key))
        <div class="bl-alert bl-alert--{{ $kind }}">
            <i data-lucide="{{ $icon }}" class="w-5 h-5" style="flex:none"></i>
            <div>{{ session($key) }}</div>
        </div>
    @endif
@endforeach
