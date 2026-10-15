<?php

namespace Tests\Feature\LeadGen;

use App\Jobs\LeadGen\EnrichProspect;
use App\Models\Client;
use App\Models\LeadGen\LeadList;
use App\Models\LeadGen\LeadListItem;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Search;
use App\Models\LeadGen\Suppression;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadGen\ProspectNormalizer;
use App\Services\LeadGen\ProspectWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\LeadGen\Concerns\UsesSqliteTenant;
use Tests\TestCase;

/** Prospect list, detail, edits, bulk actions and export through the real routes. */
class ProspectHttpTest extends TestCase
{
    use RefreshDatabase, UsesSqliteTenant;

    private Client $client;
    private User $owner;
    private Project $a;
    private Project $b;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\SiteSetting::flushCache();
        $this->setUpSqliteTenant();

        $this->owner  = User::create(['name' => 'Owner', 'email' => 'owner@lgp.test', 'password' => bcrypt('x')]);
        $this->client = Client::create(['name' => 'Acme', 'slug' => 'acme-lgp', 'client_api_key' => Str::random(16),
            'is_active' => 'Yes', 'created_at' => time(), 'updated_at' => time()]);
        $role = Role::create(['client_id' => $this->client->id, 'name' => 'Owner', 'modules' => ['*'], 'is_owner' => true,
            'created_at' => time(), 'updated_at' => time()]);
        $this->owner->attachMembership($this->client->id, null, $this->owner->id, $role->id);
        $this->owner->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();
        $this->a = Project::create(['name' => 'Alpha', 'client_id' => $this->client->id, 'project_api_key' => Str::random(16), 'project_api_secret' => Str::random(16)]);
        $this->b = Project::create(['name' => 'Beta', 'client_id' => $this->client->id, 'project_api_key' => Str::random(16), 'project_api_secret' => Str::random(16)]);

        $this->useProject($this->a);
        $s = Search::create(['provider' => 'overture', 'status' => 'completed', 'criteria' => ['bbox' => [55, 25, 55.5, 25.5]]]);
        $items = [];
        foreach ([['Acme Realty', 'acme.ae', 'info@acme.ae'], ['Apex Homes', 'apex.ae', null], ['Zenith', null, null]] as $i => [$n, $w, $e]) {
            $items[] = ProspectNormalizer::make(['business_name' => $n, 'website' => $w, 'email' => $e, 'city' => 'Dubai',
                'country_code' => 'AE', 'source_record_id' => "r{$i}"], 'overture', 'Overture Maps');
        }
        app(ProspectWriter::class)->write($s, $items);
    }

    protected function tearDown(): void
    {
        $this->tearDownSqliteTenant();
        parent::tearDown();
    }

    private function url(string $name, array $p = []): string
    {
        return route($name, ['client' => $this->client->slug] + $p);
    }

    private function ids(string ...$names): array
    {
        $this->useProject($this->a);
        return Prospect::whereIn('business_name', $names)->pluck('id')->map(fn ($id) => hashid($id))->all();
    }

    private function bulk(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post($this->url('leadgen.prospects.bulk'), $data + ['project_id' => $this->a->id]);
    }

    public function test_list_filters_and_detail(): void
    {
        $this->actingAs($this->owner)->get($this->url('leadgen.prospects.index', ['project_id' => $this->a->id, 'has' => 'email']))
            ->assertOk()->assertSee('Acme Realty')->assertDontSee('Apex Homes');

        $id = Prospect::where('business_name', 'Acme Realty')->value('id');
        $this->actingAs($this->owner)->get($this->url('leadgen.prospects.show', ['id' => $id, 'project_id' => $this->a->id]))
            ->assertOk()->assertSee('info@acme.ae')->assertSee('format ok')->assertSee('Overture Maps Foundation');
    }

    public function test_another_project_cannot_open_or_bulk_edit_these_prospects(): void
    {
        $id = Prospect::where('business_name', 'Acme Realty')->value('id');
        $this->actingAs($this->owner)->get($this->url('leadgen.prospects.show', ['id' => $id, 'project_id' => $this->b->id]))->assertNotFound();

        $this->actingAs($this->owner)->post($this->url('leadgen.prospects.bulk'), [
            'project_id' => $this->b->id, 'action' => 'reject', 'ids' => $this->ids('Acme Realty'),
        ]);
        $this->useProject($this->a);
        $this->assertSame('new', Prospect::where('business_name', 'Acme Realty')->value('status'), 'ids from another project match nothing');
    }

    public function test_bulk_add_to_a_new_list_then_remove(): void
    {
        $this->bulk(['action' => 'add_to_list', 'new_list' => 'Dubai agencies', 'ids' => $this->ids('Acme Realty', 'Apex Homes')])->assertSessionHas('success');
        $this->useProject($this->a);
        $list = LeadList::where('name', 'Dubai agencies')->firstOrFail();
        $this->assertSame(2, LeadListItem::where('list_id', $list->id)->count());

        $this->bulk(['action' => 'add_to_list', 'list_id' => hashid($list->id), 'ids' => $this->ids('Acme Realty')]);
        $this->useProject($this->a);
        $this->assertSame(2, LeadListItem::where('list_id', $list->id)->count(), 'adding again is a no-op');

        $this->bulk(['action' => 'remove_from_list', 'list_id' => hashid($list->id), 'ids' => $this->ids('Apex Homes')]);
        $this->useProject($this->a);
        $this->assertSame(1, LeadListItem::where('list_id', $list->id)->count());

        $this->actingAs($this->owner)->get($this->url('leadgen.prospects.index', ['project_id' => $this->a->id, 'list_id' => $list->id]))
            ->assertOk()->assertSee('List: Dubai agencies')->assertSee('Acme Realty')->assertDontSee('Apex Homes');
    }

    public function test_bulk_tag_reject_and_enrich(): void
    {
        Bus::fake([EnrichProspect::class]);
        $this->bulk(['action' => 'tag', 'tag' => 'hot', 'ids' => $this->ids('Acme Realty', 'Zenith')]);
        $this->bulk(['action' => 'reject', 'ids' => $this->ids('Zenith')]);
        $this->bulk(['action' => 'enrich', 'ids' => $this->ids('Acme Realty', 'Apex Homes')]);

        $this->useProject($this->a);
        $this->assertSame(['hot'], Prospect::where('business_name', 'Acme Realty')->first()->tags);
        $this->assertSame('rejected', Prospect::where('business_name', 'Zenith')->value('status'));
        Bus::assertDispatchedTimes(EnrichProspect::class, 2);
    }

    public function test_delete_with_never_readd_suppresses_and_hides(): void
    {
        $this->bulk(['action' => 'delete', 'never_readd' => 1, 'ids' => $this->ids('Acme Realty')]);
        $this->useProject($this->a);
        $this->assertNull(Prospect::where('business_name', 'Acme Realty')->first());
        $this->assertTrue(Suppression::where('kind', 'domain')->where('value_norm', 'acme.ae')->where('reason', 'deleted')->exists());
        $this->assertTrue(Suppression::where('kind', 'email')->where('value_norm', 'info@acme.ae')->exists());
        $this->assertDatabaseHas('audit_log', ['action' => 'leadgen.prospects.deleted']);
    }

    public function test_edits_mark_contact_details_as_confirmed(): void
    {
        $id = Prospect::where('business_name', 'Apex Homes')->value('id');
        $this->actingAs($this->owner)->patch($this->url('leadgen.prospects.update', ['id' => $id]), [
            'project_id' => $this->a->id, 'business_name' => 'Apex Homes', 'website' => 'apex.ae', 'email' => 'sales@apex.ae',
            'phone' => '04 444 0000', 'country_code' => 'AE', 'status' => 'approved', 'tags' => 'vip, dubai', 'owner_id' => hashid($this->owner->id),
        ])->assertSessionHasNoErrors();

        $this->useProject($this->a);
        $p = Prospect::find($id);
        $this->assertSame('user_confirmed', $p->verificationOf('email'));
        $this->assertSame('+97144440000', $p->phone_e164);
        $this->assertSame('unverified', $p->verificationOf('website'), 'an unchanged field keeps its level');
        $this->assertSame(['vip', 'dubai'], $p->tags);
        $this->assertSame($this->owner->id, (int) $p->owner_id);
    }

    public function test_view_only_and_no_export_roles(): void
    {
        $role = Role::create(['client_id' => $this->client->id, 'name' => 'Viewer', 'modules' => ['leadgen'],
            'abilities' => ['leadgen' => ['view']], 'created_at' => time(), 'updated_at' => time()]);
        $viewer = User::create(['name' => 'V', 'email' => 'v@lgp.test', 'password' => bcrypt('x')]);
        $viewer->attachMembership($this->client->id, null, $this->owner->id, $role->id);
        $viewer->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();

        $this->actingAs($viewer)->get($this->url('leadgen.prospects.index', ['project_id' => $this->a->id]))->assertOk()->assertDontSee('Choose an action');
        $this->bulk(['action' => 'reject', 'ids' => $this->ids('Zenith')], $viewer)->assertForbidden();
        $this->bulk(['action' => 'enrich', 'ids' => $this->ids('Zenith')], $viewer)->assertForbidden();
        $this->actingAs($viewer)->get($this->url('leadgen.prospects.export', ['project_id' => $this->a->id]))->assertForbidden();
    }

    public function test_export_is_an_xlsx_without_do_not_contact_rows(): void
    {
        $this->bulk(['action' => 'suppress', 'ids' => $this->ids('Acme Realty')]);
        $res = $this->actingAs($this->owner)->get($this->url('leadgen.prospects.export', ['project_id' => $this->a->id]));
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));

        $zip = new \ZipArchive();
        $zip->open($res->getFile()->getPathname());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml') . $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        $this->assertStringContainsString('Apex Homes', $sheet);
        $this->assertStringNotContainsString('Acme Realty', $sheet);
        $this->assertDatabaseHas('audit_log', ['action' => 'leadgen.prospects.exported']);
    }
}
