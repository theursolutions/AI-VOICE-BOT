<?php

namespace Tests\Feature\LeadGen;

use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenant\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who reaches Lead Generation: module gate, ability gate, project scope.
 *
 * The tenant DB for these projects doesn't exist, so the dashboard renders its
 * "being set up" state — enough to prove the request got through every gate.
 */
class LeadGenAccessTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $owner;
    private Project $projectA;
    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\SiteSetting::flushCache();

        $this->owner  = User::create(['name' => 'Owner', 'email' => 'owner@lg.test', 'password' => bcrypt('x')]);
        $this->client = Client::create([
            'name' => 'Acme', 'slug' => 'acme-lg', 'client_api_key' => Str::random(16),
            'is_active' => 'Yes', 'created_at' => time(), 'updated_at' => time(),
        ]);
        $ownerRole = $this->role('Owner', ['*'], null, true);
        $this->owner->attachMembership($this->client->id, null, $this->owner->id, $ownerRole->id);
        $this->verify($this->owner);

        $this->projectA = $this->project('Alpha');
        $this->projectB = $this->project('Beta');
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    private function role(string $name, array $modules, ?array $abilities = null, bool $owner = false): Role
    {
        return Role::create([
            'client_id' => $this->client->id, 'name' => $name, 'modules' => $modules,
            'abilities' => $abilities, 'is_owner' => $owner, 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function project(string $name): Project
    {
        return Project::create([
            'name' => $name, 'client_id' => $this->client->id,
            'project_api_key' => Str::random(16), 'project_api_secret' => Str::random(16),
        ]);
    }

    private function verify(User $u): void
    {
        $u->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();
    }

    private function member(Role $role, ?int $projectId = null): User
    {
        $u = User::create(['name' => 'M', 'email' => Str::random(6) . '@lg.test', 'password' => bcrypt('x')]);
        $u->attachMembership($this->client->id, $projectId, $this->owner->id, $role->id);
        $this->verify($u);
        return $u;
    }

    private function dashboard(User $u, ?Project $p = null)
    {
        $query = $p ? ['project_id' => $p->id] : [];
        return $this->actingAs($u)->get(route('leadgen.index', ['client' => $this->client->slug] + $query));
    }

    public function test_owner_reaches_the_dashboard(): void
    {
        $this->dashboard($this->owner)->assertOk()->assertSee('Lead Generation');
    }

    public function test_inner_pages_send_you_to_the_overview_while_the_project_is_not_set_up(): void
    {
        // The test projects have no tenant DB — exactly a project nobody has
        // run `tenant:migrate` for. That used to be a bare 404.
        foreach (['leadgen.profiles.index', 'leadgen.prospects.index', 'leadgen.searches.create', 'leadgen.templates.index'] as $name) {
            $this->actingAs($this->owner)
                ->get(route($name, ['client' => $this->client->slug, 'project_id' => $this->projectA->id]))
                ->assertRedirect(route('leadgen.index', ['client' => $this->client->slug, 'project_id' => $this->projectA->id]));
        }
        $this->dashboard($this->owner, $this->projectA)->assertOk()->assertSee('being set up');
    }

    public function test_a_role_without_the_module_is_refused(): void
    {
        $this->dashboard($this->member($this->role('Support', ['contacts'])))->assertForbidden();
    }

    public function test_the_module_without_the_view_ability_is_refused(): void
    {
        $role = $this->role('Sender', ['leadgen'], ['leadgen' => ['send']]);
        $this->dashboard($this->member($role))->assertForbidden();
    }

    public function test_a_role_with_the_module_and_no_ability_list_gets_in(): void
    {
        $this->dashboard($this->member($this->role('Sales', ['leadgen'])))->assertOk();
    }

    public function test_a_member_scoped_to_one_project_cannot_open_another(): void
    {
        $member = $this->member($this->role('Sales', ['leadgen']), $this->projectA->id);

        $this->dashboard($member, $this->projectA)->assertOk();
        $this->dashboard($member, $this->projectB)->assertNotFound();
    }

    public function test_a_disabled_module_is_unavailable_even_to_the_owner(): void
    {
        \App\Models\SiteSetting::set(\App\Support\Modules::SETTING_KEY, ['leadgen']);
        \App\Models\SiteSetting::flushCache();

        $this->assertNotSame(200, $this->dashboard($this->owner)->status());
    }

    public function test_a_set_up_project_shows_its_own_numbers_and_channel_status(): void
    {
        config(['leadgen.places.url' => null]);   // independent of the developer's .env
        $file = tempnam(sys_get_temp_dir(), 'lgdash') . '.sqlite';
        touch($file);
        $toSqlite = function () use ($file) {
            config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
            \Illuminate\Support\Facades\DB::purge('tenant');
        };
        // Same resolution rules, but the tenant connection lands on SQLite.
        $this->app->instance(\App\Services\LeadGen\LeadGenContext::class, new class(app(TenantManager::class), $toSqlite) extends \App\Services\LeadGen\LeadGenContext {
            public function __construct(TenantManager $t, private \Closure $after) { parent::__construct($t); }
            public function resolve(\Illuminate\Http\Request $r, Client $c): ?Project { $p = parent::resolve($r, $c); ($this->after)(); return $p; }
        });

        app(TenantManager::class)->useFor($this->projectA);
        $toSqlite();
        (require database_path('migrations/tenant/2026_10_15_200000_create_leadgen_tables.php'))->up();
        \App\Models\LeadGen\Prospect::create(['business_name' => 'Acme Realty', 'source_provider' => 'overture', 'source_record_id' => 'x1']);
        \App\Models\LeadGen\Suppression::create(['kind' => 'email', 'value_norm' => 'a@b.ae', 'reason' => 'opt_out']);
        app(TenantManager::class)->reset();

        $this->dashboard($this->owner, $this->projectA)
            ->assertOk()
            ->assertSee('How it works')
            ->assertSee('Business listings')
            ->assertSee('Not available yet')     // no places worker configured in tests
            ->assertSeeInOrder(['tva-lg-kpi__n">1<', 'Do not contact'], false)   // the one suppression
            ->assertSee('1/5', false);           // getting started: prospects found

        @unlink($file);
    }

    public function test_owner_can_narrow_a_roles_abilities_and_all_ticked_means_unrestricted(): void
    {
        $role = $this->role('Sales', ['leadgen']);
        $url  = route('roles.update', ['client' => $this->client->slug, 'id' => $role->id]);

        $this->actingAs($this->owner)->patch($url, [
            'name' => 'Sales', 'modules' => ['leadgen'],
            'abilities_present' => ['leadgen' => 1],
            'abilities' => ['leadgen' => ['view', 'search', 'bogus']],
        ])->assertRedirect();
        $this->assertSame(['leadgen' => ['view', 'search']], $role->fresh()->abilities);

        $this->actingAs($this->owner)->patch($url, [
            'name' => 'Sales', 'modules' => ['leadgen'],
            'abilities_present' => ['leadgen' => 1],
            'abilities' => ['leadgen' => Role::abilityKeys('leadgen')],
        ])->assertRedirect();
        $this->assertNull($role->fresh()->abilities);
    }
}
