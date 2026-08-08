<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    /**
     * Signup is not stock Breeze: it also provisions the user's first
     * workspace. This asserts that whole contract, because the workspace half
     * used to live in the laravel/ui RegisterController that shadowed this
     * route — deleting that scaffolding without porting it would have left
     * every new account with no Client, no Owner role, and nowhere to land.
     */
    public function test_new_users_can_register_and_get_a_workspace(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Test User',
            'company_name'          => 'Acme Widgets',
            'email'                 => 'test@example.com',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $client = Client::where('name', 'Acme Widgets')->firstOrFail();
        $this->assertSame('acme-widgets', $client->slug);
        $this->assertNotEmpty($client->client_api_key);

        // Owner role, all modules, attached to the signup user.
        $role = Role::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('Owner', $role->name);
        $this->assertTrue((bool) $role->is_owner);
        $this->assertSame(['*'], $role->modules);
        $this->assertTrue($user->hasMembership($client->id));

        // And they land inside that workspace, not on a generic dashboard.
        $this->assertSame($client->id, $user->fresh()->active_client_id);
        $response->assertRedirect(route('dashboard', ['client' => $client->slug]));
    }

    public function test_registration_requires_a_company_name(): void
    {
        $response = $this->post('/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@example.com',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('company_name');
        $this->assertGuest();
    }
}
