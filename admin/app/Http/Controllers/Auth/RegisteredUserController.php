<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'email'      => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password'   => ['required', 'confirmed', Rules\Password::defaults()],
            'client_id'  => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $existing = User::where('email', $request->email)->first();

        if ($existing) {
            // Re-using an email = joining another workspace, not collision.
            // Demand the right password to prove identity.
            if (!Hash::check($request->password, $existing->password)) {
                throw ValidationException::withMessages([
                    'email' => __('An account with this email already exists. Please log in to join this workspace.'),
                ]);
            }

            $user = $existing;
        } else {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);

            event(new Registered($user));
        }

        if ($request->filled('client_id') && $request->filled('project_id')) {
            $user->attachMembership(
                (int) $request->client_id,
                (int) $request->project_id,
            );
        }

        Auth::login($user);

        return redirect(RouteServiceProvider::HOME);
    }
}
