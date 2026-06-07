@extends('layouts.master')

@section('content')
<div class="content">
    <h2 class="intro-y text-lg font-medium mt-10">
        Workspace Invitations — {{ $client?->name }}
    </h2>

    @if (session('success'))
        <div class="alert alert-success-soft show mt-4" role="alert">
            {{ session('success') }}
        </div>
    @endif

    {{-- Invite form --}}
    <div class="intro-y box p-5 mt-5">
        <h3 class="font-medium text-base mb-3">Invite a colleague</h3>
        <form method="POST" action="{{ route('invitations.store') }}">
            @csrf
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 sm:col-span-5">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" required
                           value="{{ old('email') }}"
                           class="form-control @error('email') border-danger @enderror"
                           placeholder="colleague@example.com">
                    @error('email')
                        <div class="text-danger mt-1 text-xs">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-span-12 sm:col-span-5">
                    <label class="form-label">Name (optional)</label>
                    <input type="text" name="name" value="{{ old('name') }}"
                           class="form-control @error('name') border-danger @enderror"
                           placeholder="Jane Doe">
                    @error('name')
                        <div class="text-danger mt-1 text-xs">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-span-12 sm:col-span-2 flex items-end">
                    <button type="submit" class="btn btn-primary w-full">Send invite</button>
                </div>
            </div>
        </form>
    </div>

    {{-- Invitations list --}}
    <div class="intro-y box mt-5">
        <div class="p-5 border-b border-slate-200/60 dark:border-darkmode-400">
            <h3 class="font-medium text-base">All invitations</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="table table-report">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Invited by</th>
                        <th>Sent</th>
                        <th>Expires</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($invitations as $inv)
                    @php
                        if ($inv->accepted_at) {
                            $status = 'Accepted';
                            $statusClass = 'text-success';
                        } elseif ($inv->revoked_at) {
                            $status = 'Revoked';
                            $statusClass = 'text-slate-500';
                        } elseif ((int) $inv->expires_at <= time()) {
                            $status = 'Expired';
                            $statusClass = 'text-warning';
                        } else {
                            $status = 'Pending';
                            $statusClass = 'text-primary';
                        }
                    @endphp
                    <tr>
                        <td>{{ $inv->email }}</td>
                        <td>{{ $inv->inviter?->name ?? '—' }}</td>
                        <td>{{ $inv->created_at ? \Illuminate\Support\Carbon::createFromTimestamp($inv->created_at)->diffForHumans() : '—' }}</td>
                        <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($inv->expires_at)->toDayDateTimeString() }}</td>
                        <td class="{{ $statusClass }} font-medium">{{ $status }}</td>
                        <td class="text-center">
                            @if ($inv->isPending())
                                <form method="POST" action="{{ route('invitations.destroy', $inv->id) }}"
                                      onsubmit="return confirm('Revoke this invitation?');">
                                    @csrf
                                    <button type="submit" class="text-danger">Revoke</button>
                                </form>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-slate-500 py-6">
                            No invitations yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
