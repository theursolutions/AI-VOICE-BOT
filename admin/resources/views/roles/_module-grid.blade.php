{{--
    Module checkboxes for the create / edit role forms. Modules that declare
    `abilities` (config/modules.php) get a nested list of actions, shown only
    while the module itself is ticked. $role is null on the create form, where
    every ability starts ticked.
--}}
@php
    $roleMods = $role ? $role->moduleKeys() : [];
    $scope    = 'rg' . ($role->id ?? 'new');
@endphp
<div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
    @foreach ($modules as $key => $cfg)
        @if ($key === 'dashboard' || !empty($cfg['abilities'])) @continue @endif
        <label class="flex items-center gap-2 p-2 border rounded-md cursor-pointer hover:bg-slate-50">
            <input type="checkbox" name="modules[]" value="{{ $key }}" class="form-check-input"
                   @checked(in_array($key, $roleMods, true))>
            <span class="text-sm">{{ $cfg['label'] ?? $key }}</span>
        </label>
    @endforeach
</div>

@foreach ($modules as $key => $cfg)
    @if (empty($cfg['abilities'])) @continue @endif
    @php
        $on      = in_array($key, $roleMods, true);
        $granted = $role ? $role->abilitiesFor($key) : array_keys($cfg['abilities']);
        // A role without the module still shows every action ticked, so
        // switching the module on later grants the full set by default.
        if (!$on) { $granted = array_keys($cfg['abilities']); }
        $boxId   = $scope . '-' . $key;
    @endphp
    <div class="border rounded-md p-3 mt-2">
        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="modules[]" value="{{ $key }}" class="form-check-input"
                   data-ability-toggle="{{ $boxId }}" @checked($on)>
            <span class="text-sm font-medium">{{ $cfg['label'] ?? $key }}</span>
            <span class="text-xs text-slate-500">— choose what this role can do</span>
        </label>
        <input type="hidden" name="abilities_present[{{ $key }}]" value="1">
        <div id="{{ $boxId }}" class="grid grid-cols-1 sm:grid-cols-2 gap-1 mt-2 pl-6 {{ $on ? '' : 'hidden' }}">
            @foreach ($cfg['abilities'] as $ability => $label)
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="abilities[{{ $key }}][]" value="{{ $ability }}" class="form-check-input"
                           @checked(in_array($ability, $granted, true))>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </div>
@endforeach

@once
    <script>
        document.addEventListener('change', function (e) {
            var id = e.target.getAttribute && e.target.getAttribute('data-ability-toggle');
            if (!id) return;
            var box = document.getElementById(id);
            if (box) box.classList.toggle('hidden', !e.target.checked);
        });
    </script>
@endonce
