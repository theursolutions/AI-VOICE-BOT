<?php

namespace Tests\Feature\LeadGen;

use App\Models\Client;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Search;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGen\Providers\PlacesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\LeadGen\Concerns\UsesSqliteTenant;
use Tests\Feature\LeadGen\Fakes\FakePlacesClient;
use Tests\TestCase;

/**
 * Searches and imports through the real routes and middleware. The queue is
 * `sync` in tests, so a started search runs to completion inside the request
 * against the fake places worker.
 */
class SearchHttpTest extends TestCase
{
    use RefreshDatabase, UsesSqliteTenant;

    private Client $client;
    private User $owner;
    private Project $projectA;
    private Project $projectB;
    private FakePlacesClient $places;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\SiteSetting::flushCache();
        $this->setUpSqliteTenant();
        Storage::fake('local');

        $this->places = new FakePlacesClient(FakePlacesClient::rows(7));
        $this->app->instance(PlacesClient::class, $this->places);

        $this->owner  = User::create(['name' => 'Owner', 'email' => 'owner@lgs.test', 'password' => bcrypt('x')]);
        $this->client = Client::create(['name' => 'Acme', 'slug' => 'acme-lgs', 'client_api_key' => Str::random(16),
            'is_active' => 'Yes', 'created_at' => time(), 'updated_at' => time()]);
        $role = Role::create(['client_id' => $this->client->id, 'name' => 'Owner', 'modules' => ['*'], 'is_owner' => true,
            'created_at' => time(), 'updated_at' => time()]);
        $this->owner->attachMembership($this->client->id, null, $this->owner->id, $role->id);
        $this->owner->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();

        $this->projectA = Project::create(['name' => 'Alpha', 'client_id' => $this->client->id,
            'project_api_key' => Str::random(16), 'project_api_secret' => Str::random(16)]);
        $this->projectB = Project::create(['name' => 'Beta', 'client_id' => $this->client->id,
            'project_api_key' => Str::random(16), 'project_api_secret' => Str::random(16)]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSqliteTenant();
        parent::tearDown();
    }

    private function url(string $name, array $params = []): string
    {
        return route($name, ['client' => $this->client->slug] + $params);
    }

    private function searchPayload(array $over = []): array
    {
        return $over + [
            'project_id' => $this->projectA->id, 'source' => 'overture', 'area_mode' => 'place',
            'bbox' => [55.0, 25.0, 55.5, 25.4], 'area_label' => 'Dubai Marina', 'country_code' => 'AE',
            'categories' => ['real_estate_agent'], 'require_website' => 1, 'max_results' => 500,
        ];
    }

    private function member(array $abilities): User
    {
        $role = Role::create(['client_id' => $this->client->id, 'name' => 'R' . Str::random(4), 'modules' => ['leadgen'],
            'abilities' => ['leadgen' => $abilities], 'created_at' => time(), 'updated_at' => time()]);
        $u = User::create(['name' => 'M', 'email' => Str::random(6) . '@lgs.test', 'password' => bcrypt('x')]);
        $u->attachMembership($this->client->id, null, $this->owner->id, $role->id);
        $u->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();
        return $u;
    }

    public function test_starting_a_search_runs_it_and_shows_the_results(): void
    {
        $this->actingAs($this->owner)->get($this->url('leadgen.searches.create', ['project_id' => $this->projectA->id]))
            ->assertOk()->assertSee('Find businesses');

        $res = $this->actingAs($this->owner)->post($this->url('leadgen.searches.store'), $this->searchPayload());

        $this->useProject($this->projectA);
        $search = Search::firstOrFail();
        $res->assertRedirect($this->url('leadgen.searches.show', ['id' => $search->id, 'project_id' => $this->projectA->id]));
        $this->assertSame(Search::COMPLETED, $search->status);
        $this->assertSame(7, Prospect::count());
        $this->assertSame('Real estate agent in Dubai Marina', $search->name);

        $this->actingAs($this->owner)->get($this->url('leadgen.searches.show', ['id' => $search->id, 'project_id' => $this->projectA->id]))
            ->assertOk()->assertSee('Realty p1')->assertSee('Overture Maps Foundation')->assertSee('unverified');
        $this->assertDatabaseHas('audit_log', ['action' => 'leadgen.search.started', 'target_id' => $search->id]);
    }

    public function test_an_oversized_area_is_refused(): void
    {
        $this->actingAs($this->owner)->post($this->url('leadgen.searches.store'), $this->searchPayload(['bbox' => [50, 20, 56, 26]]))
            ->assertSessionHasErrors('bbox');
    }

    public function test_a_radius_search_builds_its_own_box(): void
    {
        $this->actingAs($this->owner)->post($this->url('leadgen.searches.store'), $this->searchPayload([
            'area_mode' => 'radius', 'bbox' => null, 'lat' => 25.08, 'lng' => 55.14, 'radius_km' => 5,
        ]))->assertRedirect();

        $this->useProject($this->projectA);
        $c = Search::firstOrFail()->criteria;
        $this->assertSame([55.14, 25.08], $c['center']);
        $this->assertEqualsWithDelta(0.045, $c['bbox'][3] - 25.08, 0.001);
    }

    public function test_an_unconfigured_source_offers_import_instead(): void
    {
        $this->app->instance(PlacesClient::class, new PlacesClient());
        config(['leadgen.places.url' => null]);

        $this->actingAs($this->owner)->get($this->url('leadgen.searches.create', ['project_id' => $this->projectA->id]))
            ->assertOk()->assertSee("isn't available yet", false);
        $this->actingAs($this->owner)->post($this->url('leadgen.searches.store'), $this->searchPayload())
            ->assertSessionHasErrors('source');
    }

    public function test_view_only_members_can_look_but_not_search(): void
    {
        $viewer = $this->member(['view']);
        $this->actingAs($viewer)->get($this->url('leadgen.searches.index', ['project_id' => $this->projectA->id]))->assertOk();
        $this->actingAs($viewer)->get($this->url('leadgen.searches.create'))->assertForbidden();
        $this->actingAs($viewer)->post($this->url('leadgen.searches.store'), $this->searchPayload())->assertForbidden();
        $this->actingAs($viewer)->get($this->url('leadgen.imports.create'))->assertForbidden();
    }

    public function test_a_search_cannot_be_opened_or_controlled_through_another_project(): void
    {
        $this->actingAs($this->owner)->post($this->url('leadgen.searches.store'), $this->searchPayload());
        $this->useProject($this->projectA);
        $id = Search::firstOrFail()->id;

        $this->actingAs($this->owner)->get($this->url('leadgen.searches.show', ['id' => $id, 'project_id' => $this->projectB->id]))->assertNotFound();
        $this->actingAs($this->owner)->post($this->url('leadgen.searches.cancel', ['id' => $id]), ['project_id' => $this->projectB->id])->assertNotFound();
    }

    public function test_pause_resume_and_cancel(): void
    {
        $this->places->areaStatus = 'loading';    // keeps the search from finishing inside the request
        $this->actingAs($this->owner)->post($this->url('leadgen.searches.store'), $this->searchPayload());
        $this->useProject($this->projectA);
        $s = Search::firstOrFail();
        $p = ['id' => $s->id];

        $this->actingAs($this->owner)->post($this->url('leadgen.searches.pause', $p), ['project_id' => $this->projectA->id]);
        $this->useProject($this->projectA);
        $this->assertSame(Search::PAUSED, $s->fresh()->status);

        $this->places->areaStatus = 'ready';
        $this->actingAs($this->owner)->post($this->url('leadgen.searches.resume', $p), ['project_id' => $this->projectA->id]);
        $this->useProject($this->projectA);
        $this->assertSame(Search::COMPLETED, $s->fresh()->status, 'resume re-queues and the run finishes');

        $this->actingAs($this->owner)->post($this->url('leadgen.searches.cancel', $p), ['project_id' => $this->projectA->id])
            ->assertSessionHasErrors('search');   // already finished: nothing to cancel
    }

    public function test_csv_import_maps_columns_and_normalises_rows(): void
    {
        $csv = "Company,Web,Tel,E-mail,City\nAcme Realty,acme.ae,050 123 4567,info@acme.ae,Dubai\n,nobody.ae,,,\nApex Homes,https://apex.ae,04 555 1234,,Dubai\n";
        $file = UploadedFile::fake()->createWithContent('prospects.csv', $csv);

        $up = $this->actingAs($this->owner)->post($this->url('leadgen.imports.upload'), [
            'project_id' => $this->projectA->id, 'file' => $file, 'attested' => 1,
        ]);
        $up->assertRedirect();
        $mapUrl = $up->headers->get('Location');
        $token = Str::before(Str::afterLast(parse_url($mapUrl, PHP_URL_PATH), '/'), '?');

        $this->actingAs($this->owner)->get($mapUrl)->assertOk()->assertSee('Company')->assertSee('Acme Realty');

        $this->actingAs($this->owner)->post($this->url('leadgen.imports.start', ['token' => $token]), [
            'project_id' => $this->projectA->id, 'country_code' => 'AE',
            'mapping' => ['business_name' => 0, 'website' => 1, 'phone' => 2, 'email' => 3, 'city' => 4],
        ])->assertRedirect();

        $this->useProject($this->projectA);
        $import = Search::where('provider', 'import')->firstOrFail();
        $this->assertSame(Search::COMPLETED, $import->status);
        $this->assertSame(2, $import->progress['new']);
        $this->assertSame(1, $import->progress['skipped'], 'the row with no business name');
        $acme = Prospect::where('business_name', 'Acme Realty')->firstOrFail();
        $this->assertSame('+971501234567', $acme->phone_e164);
        $this->assertSame('acme.ae', $acme->domain_norm);
        $this->assertSame('import', $acme->source_provider);

        // The token was single-use.
        $this->actingAs($this->owner)->get($mapUrl)->assertNotFound();
    }

    public function test_import_requires_the_rights_confirmation_and_a_readable_file(): void
    {
        $this->actingAs($this->owner)->post($this->url('leadgen.imports.upload'), [
            'project_id' => $this->projectA->id, 'file' => UploadedFile::fake()->createWithContent('a.csv', "Name\nX\n"),
        ])->assertSessionHasErrors('attested');

        $this->actingAs($this->owner)->post($this->url('leadgen.imports.upload'), [
            'project_id' => $this->projectA->id, 'file' => UploadedFile::fake()->createWithContent('a.pdf', '%PDF'), 'attested' => 1,
        ])->assertSessionHasErrors('file');
    }

    public function test_an_import_token_is_bound_to_its_project(): void
    {
        $up = $this->actingAs($this->owner)->post($this->url('leadgen.imports.upload'), [
            'project_id' => $this->projectA->id, 'file' => UploadedFile::fake()->createWithContent('p.csv', "Name\nAcme\n"), 'attested' => 1,
        ]);
        $token = Str::before(Str::afterLast(parse_url($up->headers->get('Location'), PHP_URL_PATH), '/'), '?');

        $this->actingAs($this->owner)->get($this->url('leadgen.imports.map', ['token' => $token, 'project_id' => $this->projectB->id]))->assertNotFound();
        $this->actingAs($this->owner)->get($this->url('leadgen.imports.map', ['token' => Str::random(32), 'project_id' => $this->projectA->id]))->assertNotFound();
    }

    public function test_place_lookup_and_categories_proxy_the_worker(): void
    {
        $this->actingAs($this->owner)->getJson($this->url('leadgen.places.geocode', ['country' => 'AE', 'q' => 'Dubai', 'project_id' => $this->projectA->id]))
            ->assertOk()->assertJsonPath('matches.0.name', 'Dubai Emirate');
        $this->actingAs($this->owner)->postJson($this->url('leadgen.places.categories'), ['bbox' => [55.0, 25.0, 55.5, 25.4], 'project_id' => $this->projectA->id])
            ->assertOk()->assertJsonPath('categories.0.key', 'real_estate_agent');
        $this->actingAs($this->owner)->postJson($this->url('leadgen.places.categories'), ['bbox' => [40, 20, 56, 30], 'project_id' => $this->projectA->id])
            ->assertStatus(422);
    }
}
