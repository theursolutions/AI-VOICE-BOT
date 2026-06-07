@extends('layouts.master')

@section('content')
<div class="content">
    <div class="intro-y box p-5 mt-10 max-w-xl mx-auto">
        <h2 class="text-lg font-medium">You've been invited</h2>
        <p class="mt-3">
            You've been invited to join the
            <strong>{{ $client->name }}</strong> workspace.
        </p>
        <p class="mt-2 text-slate-500 text-sm">
            Accepting will add you as a member and switch your active workspace.
        </p>

        <form method="POST" action="{{ route('invitations.accept.confirm', $invitation->token) }}" class="mt-5">
            @csrf
            <button type="submit" class="btn btn-primary">
                Accept invitation to {{ $client->name }}
            </button>
            <a href="{{ url('/dashboard') }}" class="btn btn-outline-secondary ml-2">Not now</a>
        </form>
    </div>
</div>
@endsection
