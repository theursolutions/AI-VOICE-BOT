<?php

namespace Tests\Feature\LeadGen;

use App\Models\LeadGen\OutreachMessage;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Suppression;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Lead Generation's second isolation layer: even on one shared tenant
 * database, a model only ever sees the project TenantManager switched to.
 * Runs the real lg_* migration against a SQLite file so no MySQL tenant DB
 * is touched; a file rather than :memory: so switching projects (which
 * purges the connection) keeps the same data.
 */
class TenantScopeTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'lgtenant') . '.sqlite';
        touch($this->file);
        $this->switchTo(1);

        $migration = require database_path('migrations/tenant/2026_10_15_200000_create_leadgen_tables.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        DB::purge('tenant');
        @unlink($this->file);
        parent::tearDown();
    }

    private function switchTo(int $projectId): void
    {
        $project = new Project();
        $project->id = $projectId;
        app(TenantManager::class)->useFor($project);
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('tenant');
    }

    private function prospect(string $recordId, array $extra = []): Prospect
    {
        return Prospect::create($extra + [
            'business_name'    => 'Acme Realty ' . $recordId,
            'source_provider'  => 'overture',
            'source_record_id' => $recordId,
        ]);
    }

    public function test_rows_are_stamped_with_the_current_project_and_unix_timestamps(): void
    {
        $p = $this->prospect('a');

        $this->assertSame(1, (int) $p->project_id);
        $this->assertIsInt($p->fresh()->created_at);
        $this->assertEqualsWithDelta(time(), $p->fresh()->created_at, 5);
    }

    public function test_another_project_cannot_see_or_reach_the_rows(): void
    {
        $p = $this->prospect('a');

        $this->switchTo(2);
        $this->assertSame(0, Prospect::count());
        $this->assertNull(Prospect::find($p->id));

        $this->switchTo(1);
        $this->assertSame(1, Prospect::count());
    }

    public function test_project_id_cannot_be_mass_assigned_or_forced_to_another_project(): void
    {
        $p = Prospect::create(['project_id' => 99, 'business_name' => 'X', 'source_provider' => 'csv', 'source_record_id' => 'r1']);
        $this->assertSame(1, (int) $p->project_id, 'mass-assigned project_id is ignored');

        $this->expectException(LogicException::class);
        $other = new Prospect(['business_name' => 'Y', 'source_provider' => 'csv', 'source_record_id' => 'r2']);
        $other->project_id = 2;
        $other->save();
    }

    public function test_with_no_project_selected_queries_refuse_to_run(): void
    {
        app(TenantManager::class)->reset();

        $this->expectException(LogicException::class);
        Prospect::count();
    }

    public function test_one_prospect_per_source_record(): void
    {
        $this->prospect('dup');

        $this->expectException(QueryException::class);
        $this->prospect('dup');
    }

    public function test_one_message_per_idempotency_key(): void
    {
        $p = $this->prospect('m');
        OutreachMessage::create(['prospect_id' => $p->id, 'channel' => 'email', 'body' => 'Hi', 'idempotency_key' => 'c1:r1:0']);

        $this->expectException(QueryException::class);
        OutreachMessage::create(['prospect_id' => $p->id, 'channel' => 'email', 'body' => 'Hi', 'idempotency_key' => 'c1:r1:0']);
    }

    public function test_one_suppression_per_address(): void
    {
        Suppression::create(['kind' => 'email', 'value_norm' => 'info@acme.ae', 'reason' => 'opt_out']);

        $this->expectException(QueryException::class);
        Suppression::create(['kind' => 'email', 'value_norm' => 'info@acme.ae', 'reason' => 'manual']);
    }
}
