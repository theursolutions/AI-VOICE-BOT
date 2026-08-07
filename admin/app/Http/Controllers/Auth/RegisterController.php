<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    use RegistersUsers;

    protected $redirectTo = '/dashboard';

    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Override the trait so we can handle the "email already exists"
     * case as "user is creating an additional workspace", not a hard
     * collision.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'email'        => ['required', 'string', 'email', 'max:255'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = null;

        DB::beginTransaction();
        try {
            $existing = User::where('email', $data['email'])->first();

            if ($existing) {
                if (!Hash::check($data['password'], $existing->password)) {
                    throw ValidationException::withMessages([
                        'email' => __('An account with this email already exists. Please log in to add a workspace.'),
                    ]);
                }
                $user = $existing;
            } else {
                $user = User::create([
                    'name'     => $data['name'],
                    'email'    => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);
            }

            $client = Client::create([
                'name'           => $data['company_name'],
                'slug'           => $this->uniqueSlug($data['company_name']),
                'client_api_key' => bin2hex(random_bytes(16)),
                'description'    => null,
                'is_active'      => 'Yes',
                'created_at'     => time(),
                'updated_at'     => time(),
            ]);

            // The signup user is the agency Owner: an all-access role they
            // can later use to invite teammates with narrower custom roles.
            $ownerRole = \App\Models\Role::create([
                'client_id'  => $client->id,
                'name'       => 'Owner',
                'modules'    => ['*'],
                'is_owner'   => true,
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            $user->attachMembership($client->id, null, $user->id, $ownerRole->id);

            $user->forceFill([
                'active_client_id' => $client->id,
                'last_picked_at'   => time(),
            ])->save();

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        Auth::login($user);

        // Kick off email verification via the 6-digit OTP (no-op if the
        // account is already verified, e.g. an existing user adding a
        // second workspace). They land on the dashboard and are prompted
        // to verify from there.
        if (!$user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return redirect(route('dashboard', ['client' => $client->slug]));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;
        while (Client::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }
        return $slug;
    }
}
