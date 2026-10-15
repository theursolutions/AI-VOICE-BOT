<?php

namespace Tests\Feature\LeadGen;

use App\Jobs\LeadGen\RunSearch;
use App\Models\LeadGen\Event;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Search;
use App\Models\LeadGen\SearchResult;
use App\Models\Project;
use App\Services\LeadGen\Providers\PlacesClient;
use App\Services\LeadGen\Providers\ProviderException;
use App\Services\LeadGen\Providers\ProviderRegistry;
use App\Services\LeadGen\ProspectWriter;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\LeadGen\Concerns\UsesSqliteTenant;
use Tests\Feature\LeadGen\Fakes\FakePlacesClient;
use Tests\TestCase;

/**
 * The search job's lifecycle: paging, resumability, idempotency, pause /
 * cancel, provider failures. Project 7's Project row is faked — the job only
 * needs TenantManager to resolve it.
 */
class RunSearchTest extends TestCase
{
    use UsesSqliteTenant;

    private FakePlacesClient $places;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSqliteTenant();
        $this->places = new FakePlacesClient(FakePlacesClient::rows(12));
        $this->app->instance(PlacesClient::class, $this->places);
        Bus::fake([RunSearch::class]);   // capture the hand-over to the next run

        // useForProjectId() looks the project up; give it one without touching MySQL.
        $tenants = app(TenantManager::class);
        $this->app->singleton(TenantManager::class, fn () => new class($tenants) extends TenantManager {
            public function __construct(private TenantManager $inner) {}
            public function useFor(Project $p): void { $this->inner->useFor($p); }
            public function useForProjectId(int $id): Project { $p = new Project(); $p->id = $id; $this->inner->useFor($p); return $p; }
        });
        $this->useProject(7);
    }

    protected function tearDown(): void
    {
        $this->tearDownSqliteTenant();
        parent::tearDown();
    }

    private function search(array $criteria = [], string $status = Search::QUEUED): Search
    {
        return Search::create([
            'provider' => 'overture', 'status' => $status, 'name' => 'Dubai realty',
            'criteria' => $criteria + ['source' => 'overture', 'bbox' => [55.0, 25.0, 55.5, 25.4], 'area_label' => 'Dubai', 'max_results' => 500],
        ]);
    }

    private function runJob(Search $s): void
    {
        (new RunSearch(7, $s->id))->handle(app(TenantManager::class), app(ProviderRegistry::class), app(ProspectWriter::class));
        $this->useProject(7);
    }

    public function test_a_search_pages_through_all_results_and_completes(): void
    {
        $s = $this->search();
        $this->runJob($s);

        $s->refresh();
        $this->assertSame(Search::COMPLETED, $s->status);
        $this->assertSame(12, $s->progress['new']);
        $this->assertSame(12, Prospect::count());
        $this->assertSame(12, SearchResult::where('search_id', $s->id)->count());
        $this->assertTrue(Event::where('event', 'search.completed')->exists());
    }

    public function test_a_long_search_hands_over_to_a_fresh_run_and_resumes_from_its_cursor(): void
    {
        $this->places->rows = FakePlacesClient::rows(RunSearch::PAGES_PER_RUN * RunSearch::PAGE_SIZE + 30);
        $s = $this->search(['max_results' => 5000]);

        $this->runJob($s);
        $this->assertSame(Search::RUNNING, $s->fresh()->status);
        Bus::assertDispatched(RunSearch::class, fn ($j) => $j->searchId === $s->id);
        $this->assertSame(RunSearch::PAGES_PER_RUN * RunSearch::PAGE_SIZE, $s->fresh()->progress['new']);

        $this->runJob($s);
        $this->assertSame(Search::COMPLETED, $s->fresh()->status);
        $this->assertSame(RunSearch::PAGES_PER_RUN * RunSearch::PAGE_SIZE + 30, Prospect::count());
    }

    public function test_max_results_is_respected(): void
    {
        $s = $this->search(['max_results' => 5]);
        $this->runJob($s);

        $this->assertSame(Search::COMPLETED, $s->fresh()->status);
        $this->assertSame(5, Prospect::count());
    }

    public function test_replaying_a_page_creates_and_counts_nothing_twice(): void
    {
        $s = $this->search();
        $writer = app(ProspectWriter::class);
        $items = app(ProviderRegistry::class)->get('overture')
            ->search(\App\Services\LeadGen\Providers\SearchCriteria::fromArray($s->criteria), null, 5)->items;

        $this->assertSame(['new' => 5, 'duplicate' => 0, 'existing_crm' => 0, 'skipped' => 0], $writer->write($s, $items));
        $this->assertSame(['new' => 0, 'duplicate' => 0, 'existing_crm' => 0, 'skipped' => 0], $writer->write($s, $items));
        $this->assertSame(5, Prospect::count());
    }

    public function test_a_second_search_reuses_prospects_it_already_found(): void
    {
        $this->runJob($first = $this->search());
        $this->runJob($second = $this->search());

        $this->assertSame(12, Prospect::count());
        $this->assertSame(0, $second->fresh()->progress['new']);
        $this->assertSame(12, $second->fresh()->progress['duplicates']);
    }

    public function test_an_area_still_loading_releases_without_failing(): void
    {
        $this->places->areaStatus = 'loading';
        $s = $this->search();
        $this->runJob($s);

        $this->assertSame(Search::RUNNING, $s->fresh()->status);
        $this->assertSame('loading_area', $s->fresh()->progress['phase']);
        $this->assertSame(0, $this->places->searchCalls);
    }

    public function test_paused_and_cancelling_searches(): void
    {
        $paused = $this->search([], Search::PAUSED);
        $this->runJob($paused);
        $this->assertSame(Search::PAUSED, $paused->fresh()->status);
        $this->assertSame(0, $this->places->searchCalls);

        $cancelling = $this->search([], Search::CANCELLING);
        $this->runJob($cancelling);
        $this->assertSame(Search::CANCELLED, $cancelling->fresh()->status);
        $this->assertNotNull($cancelling->fresh()->finished_at);
    }

    public function test_a_temporary_provider_error_retries_and_a_permanent_one_fails(): void
    {
        $this->places->throwOnSearch = ProviderException::unavailable('worker restarting');
        $s = $this->search();
        $this->runJob($s);
        $this->assertSame(Search::RUNNING, $s->fresh()->status);
        $this->assertTrue(Event::where('event', 'search.retry')->exists());

        $this->places->throwOnSearch = ProviderException::failed('credentials rejected');
        $this->runJob($s);
        $this->assertSame(Search::FAILED, $s->fresh()->status);
        $this->assertSame('credentials rejected', $s->fresh()->error);
    }

    public function test_businesses_already_in_the_crm_are_marked_by_email_or_phone_only(): void
    {
        // The CRM tables ContactResolver::available() looks for.
        $schema = \Illuminate\Support\Facades\Schema::connection('tenant');
        $schema->create('contacts', function ($t) {
            $t->id(); $t->integer('project_id'); $t->string('name')->nullable(); $t->string('email')->nullable();
            $t->string('phone')->nullable(); $t->integer('deleted_at')->nullable();
        });
        $schema->create('contact_identities', fn ($t) => $t->id());
        $schema->create('sessions', function ($t) { $t->id(); $t->unsignedBigInteger('contact_id')->nullable(); });

        $db = \Illuminate\Support\Facades\DB::connection('tenant');
        $byPhone = $db->table('contacts')->insertGetId(['project_id' => 7, 'name' => 'Someone', 'phone' => '971500000002']);
        $db->table('contacts')->insert(['project_id' => 7, 'name' => 'Realty p3', 'email' => 'nobody@else.ae']);   // same name: not a match
        $db->table('contacts')->insert(['project_id' => 8, 'phone' => '971500000004']);                           // other project: not a match

        $s = $this->search(['exclude_existing_crm' => true]);
        $this->runJob($s);

        $p = $s->fresh()->progress;
        $this->assertSame(1, $p['in_crm']);
        $this->assertSame(11, $p['new']);
        $hit = SearchResult::where('outcome', SearchResult::EXISTING_CRM)->firstOrFail();
        $this->assertSame($byPhone, (int) Prospect::find($hit->prospect_id)->contact_id);

        // Not asked to exclude: the same record counts as found, and isn't flagged.
        $again = $this->search(['exclude_existing_crm' => false]);
        $this->runJob($again);
        $this->assertSame(0, (int) ($again->fresh()->progress['in_crm'] ?? 0));
    }

    public function test_another_projects_job_never_sees_this_projects_search(): void
    {
        $s = $this->search();
        (new RunSearch(8, $s->id))->handle(app(TenantManager::class), app(ProviderRegistry::class), app(ProspectWriter::class));

        $this->useProject(7);
        $this->assertSame(Search::QUEUED, $s->fresh()->status, 'project 8 cannot load project 7\'s search');
        $this->assertSame(0, Prospect::count());
    }
}
