@extends('layouts.master')

@section('content')
<div class="content">
    <div class="intro-y flex items-center mt-8">
        <h2 class="text-lg font-medium mr-auto">My Profile</h2>
    </div>

    @if (session('status') === 'profile-updated')
        <div class="alert alert-success-soft show mt-5 flex items-center" role="alert">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> Profile updated.
        </div>
    @elseif (session('status') === 'password-updated')
        <div class="alert alert-success-soft show mt-5 flex items-center" role="alert">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> Password changed.
        </div>
    @endif

    <div class="grid grid-cols-12 gap-6 mt-5">
        {{-- ── Profile information ─────────────────────────────────────── --}}
        <div class="col-span-12 lg:col-span-6">
            <div class="intro-y box">
                <div class="flex items-center p-5 border-b border-slate-200/60 dark:border-darkmode-400">
                    <h2 class="font-medium text-base mr-auto">Profile information</h2>
                </div>
                <div class="p-5">
                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf
                        @method('patch')

                        <div>
                            <label for="name" class="form-label">Name</label>
                            <input id="name" name="name" type="text"
                                   class="form-control @error('name') border-danger @enderror"
                                   value="{{ old('name', $user->name) }}" required autocomplete="name">
                            @error('name') <div class="text-danger mt-1 text-xs">{{ $message }}</div> @enderror
                        </div>

                        <div class="mt-4">
                            <label for="email" class="form-label">Email</label>
                            <input id="email" name="email" type="email"
                                   class="form-control @error('email') border-danger @enderror"
                                   value="{{ old('email', $user->email) }}" required autocomplete="email">
                            @error('email') <div class="text-danger mt-1 text-xs">{{ $message }}</div> @enderror

                            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                                <div class="text-warning mt-2 text-xs">
                                    Your email is not verified.
                                    <a href="{{ route('verification.notice') }}" class="underline">Verify it now</a>.
                                </div>
                            @endif
                        </div>

                        <div class="mt-5 text-right">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="save" class="w-4 h-4 mr-2"></i> Save changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ── Change password ─────────────────────────────────────────── --}}
        <div id="update-password" class="col-span-12 lg:col-span-6">
            <div class="intro-y box">
                <div class="flex items-center p-5 border-b border-slate-200/60 dark:border-darkmode-400">
                    <h2 class="font-medium text-base mr-auto">Change password</h2>
                </div>
                <div class="p-5">
                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        @method('put')

                        <div>
                            <label for="current_password" class="form-label">Current password</label>
                            <input id="current_password" name="current_password" type="password"
                                   class="form-control @error('current_password', 'updatePassword') border-danger @enderror"
                                   autocomplete="current-password">
                            @error('current_password', 'updatePassword') <div class="text-danger mt-1 text-xs">{{ $message }}</div> @enderror
                        </div>

                        <div class="mt-4">
                            <label for="password" class="form-label">New password</label>
                            <input id="password" name="password" type="password"
                                   class="form-control @error('password', 'updatePassword') border-danger @enderror"
                                   autocomplete="new-password">
                            @error('password', 'updatePassword') <div class="text-danger mt-1 text-xs">{{ $message }}</div> @enderror
                        </div>

                        <div class="mt-4">
                            <label for="password_confirmation" class="form-label">Confirm new password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password"
                                   class="form-control" autocomplete="new-password">
                        </div>

                        <div class="mt-5 text-right">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="lock" class="w-4 h-4 mr-2"></i> Update password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- ── Danger zone: delete account ─────────────────────────────── --}}
        <div class="col-span-12">
            <div class="intro-y box border border-danger/40">
                <div class="flex items-center p-5 border-b border-slate-200/60 dark:border-darkmode-400">
                    <h2 class="font-medium text-base mr-auto text-danger">Delete account</h2>
                </div>
                <div class="p-5">
                    <p class="text-slate-500 text-sm mb-4">
                        Once your account is deleted, all of its resources and data are permanently
                        removed. Enter your password to confirm.
                    </p>
                    <form method="POST" action="{{ route('profile.destroy') }}"
                          data-confirm="This permanently deletes your account. Continue?">
                        @csrf
                        @method('delete')
                        <div class="sm:flex sm:items-end gap-3">
                            <div class="flex-1 max-w-xs">
                                <label for="delete_password" class="form-label">Password</label>
                                <input id="delete_password" name="password" type="password"
                                       class="form-control @error('password', 'userDeletion') border-danger @enderror"
                                       placeholder="Your current password" autocomplete="current-password">
                                @error('password', 'userDeletion') <div class="text-danger mt-1 text-xs">{{ $message }}</div> @enderror
                            </div>
                            <button type="submit" class="btn btn-danger mt-3 sm:mt-0">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i> Delete account
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        try { window.lucide.createIcons(); } catch (e) {}
    }
</script>
@endsection
