@extends('layouts.master')

@section('content')
@php $slug = $client->slug; @endphp

<div class="intro-y flex items-center mt-8">
    <h2 class="text-lg font-medium mr-auto">Roles &amp; Permissions</h2>
    <a href="{{ route('members.index', ['client' => $slug]) }}" class="btn btn-outline-secondary">Manage members →</a>
</div>

@if (session('success'))
    <div class="alert alert-success mt-4">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger mt-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
@endif

<p class="text-slate-500 text-sm mt-2">
    A role is a named set of modules a teammate can access. <b>Dashboard</b> is always available.
    Assign roles to people on the <a class="text-primary" href="{{ route('members.index', ['client' => $slug]) }}">Members</a> page.
</p>

{{-- Create a new role --}}
<div class="intro-y box p-5 mt-5">
    <div class="font-medium text-base mb-3">Create a role</div>
    <form method="POST" action="{{ route('roles.store', ['client' => $slug]) }}">
        @csrf
        <input type="text" name="name" required maxlength="80" placeholder="Role name (e.g. Support Agent)"
               class="form-control w-full sm:w-80 mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            @foreach ($modules as $key => $cfg)
                @if ($key === 'dashboard') @continue @endif
                <label class="flex items-center gap-2 p-2 border rounded-md cursor-pointer hover:bg-slate-50">
                    <input type="checkbox" name="modules[]" value="{{ $key }}" class="form-check-input">
                    <span class="text-sm">{{ $cfg['label'] ?? $key }}</span>
                </label>
            @endforeach
        </div>
        <button class="btn btn-primary mt-4">Create role</button>
    </form>
</div>

{{-- Existing roles --}}
<div class="intro-y box p-5 mt-5">
    <div class="font-medium text-base mb-3">Existing roles</div>

    @foreach ($roles as $role)
        @php $roleMods = $role->moduleKeys(); $n = (int) ($counts[$role->id] ?? 0); @endphp
        <div class="border rounded-lg p-4 mb-4">
            <div class="flex items-center mb-3">
                <div class="font-medium">
                    {{ $role->name }}
                    @if ($role->is_owner)<span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full ml-1">all access</span>@endif
                </div>
                <div class="ml-auto text-xs text-slate-500">{{ $n }} member{{ $n === 1 ? '' : 's' }}</div>
            </div>

            @if ($role->is_owner)
                <div class="text-sm text-slate-500">The Owner role can access every module and project. It can't be edited or deleted.</div>
            @else
                <form method="POST" action="{{ route('roles.update', ['client' => $slug, 'id' => $role->id]) }}">
                    @csrf @method('PATCH')
                    <input type="text" name="name" required maxlength="80" value="{{ $role->name }}"
                           class="form-control w-full sm:w-80 mb-3">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @foreach ($modules as $key => $cfg)
                            @if ($key === 'dashboard') @continue @endif
                            <label class="flex items-center gap-2 p-2 border rounded-md cursor-pointer hover:bg-slate-50">
                                <input type="checkbox" name="modules[]" value="{{ $key }}" class="form-check-input"
                                       @checked(in_array($key, $roleMods, true))>
                                <span class="text-sm">{{ $cfg['label'] ?? $key }}</span>
                            </label>
                        @endforeach
                    </div>
                    <button class="btn btn-primary btn-sm mt-3">Save changes</button>
                </form>
                <form method="POST" action="{{ route('roles.destroy', ['client' => $slug, 'id' => $role->id]) }}"
                      data-confirm="Delete this role?" class="mt-2">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline-danger btn-sm">Delete role</button>
                </form>
            @endif
        </div>
    @endforeach
</div>
@endsection
