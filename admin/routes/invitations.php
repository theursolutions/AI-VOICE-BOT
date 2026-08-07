<?php

use App\Http\Controllers\InvitationAcceptController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

/*
| Workspace-scoped admin routes — managing invitations for the
| currently-active workspace. Live under /c/{client:slug}/invitations
| so the URL is bookmarkable per workspace.
*/
Route::middleware(['auth', 'active.client'])
    ->prefix('c/{client:slug}/invitations')
    ->scopeBindings()
    ->group(function () {
        Route::get('/', [InvitationController::class, 'index'])->name('invitations.index');
        Route::post('/', [InvitationController::class, 'store'])->name('invitations.store');
        Route::post('/{id}/revoke', [InvitationController::class, 'destroy'])
            ->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('invitations.destroy');
    });

/*
| Public accept routes — the invitee may not have an account yet, and the
| URL must be reachable without a workspace context.
*/
Route::get ('/invitations/accept/{token}', [InvitationAcceptController::class, 'show'])
    ->name('invitations.accept.show');
Route::post('/invitations/accept/{token}', [InvitationAcceptController::class, 'confirm'])
    ->name('invitations.accept.confirm');
