<?php

namespace Tests\Feature\LeadGen;

use App\Jobs\LeadGen\QualifyProspects;
use App\Models\Client;
use App\Models\LeadGen\OfferProfile;
use App\Models\LeadGen\OutreachMessage;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Qualification;
use App\Models\LeadGen\Template;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Conversation\BrainResolver;
use App\Services\Conversation\PythonClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\LeadGen\Concerns\UsesSqliteTenant;
use Tests\Feature\LeadGen\Fakes\FakePythonClient;
use Tests\TestCase;

/** Offer profiles, templates, scoring, review and drafts through the real routes. */
class AiHttpTest extends TestCase
{
    use RefreshDatabase, UsesSqliteTenant;

    private Client $client;
    private User $owner;
    private Project $a;
    private FakePythonClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\SiteSetting::flushCache();
        $this->setUpSqliteTenant();
        $this->llm = new FakePythonClient();
        $this->app->instance(PythonClient::class, $this->llm);
        $this->app->instance(BrainResolver::class, new class extends BrainResolver {
            public function optionsFor(?int $projectId, string $callType = self::CALL_REPLY): array { return []; }
        });

        $this->owner  = User::create(['name' => 'Owner', 'email' => 'owner@lga.test', 'password' => bcrypt('x')]);
        $this->client = Client::create(['name' => 'Acme', 'slug' => 'acme-lga', 'client_api_key' => Str::random(16),
            'is_active' => 'Yes', 'created_at' => time(), 'updated_at' => time()]);
        $role = Role::create(['client_id' => $this->client->id, 'name' => 'Owner', 'modules' => ['*'], 'is_owner' => true, 'created_at' => time(), 'updated_at' => time()]);
        $this->owner->attachMembership($this->client->id, null, $this->owner->id, $role->id);
        $this->owner->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();
        $this->a = Project::create(['name' => 'Alpha', 'client_id' => $this->client->id, 'project_api_key' => Str::random(16), 'project_api_secret' => Str::random(16)]);
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

    private function profile(): OfferProfile
    {
        $this->useProject($this->a);
        return OfferProfile::create(['name' => 'AI support', 'product' => 'AI agents', 'icp' => ['Agencies'], 'value_prop' => 'Instant replies.',
            'sender_name' => 'Umer', 'booking_link' => 'https://cal.example/u', 'language' => 'en', 'tone' => 'professional']);
    }

    private function prospect(array $a = []): Prospect
    {
        $this->useProject($this->a);
        return Prospect::create($a + ['business_name' => 'Acme Realty', 'city' => 'Dubai', 'email' => 'info@acme.ae', 'source_provider' => 'overture', 'source_record_id' => Str::random(6)]);
    }

    private function member(array $abilities): User
    {
        $role = Role::create(['client_id' => $this->client->id, 'name' => 'R' . Str::random(4), 'modules' => ['leadgen'],
            'abilities' => ['leadgen' => $abilities], 'created_at' => time(), 'updated_at' => time()]);
        $u = User::create(['name' => 'M', 'email' => Str::random(6) . '@lga.test', 'password' => bcrypt('x')]);
        $u->attachMembership($this->client->id, null, $this->owner->id, $role->id);
        $u->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();
        return $u;
    }

    public function test_offer_profile_create_and_edit(): void
    {
        $this->actingAs($this->owner)->post($this->url('leadgen.profiles.store'), [
            'project_id' => $this->a->id, 'name' => 'AI support for agencies', 'product' => 'AI chat + voice agents',
            'icp' => "Real-estate agencies\nIn Dubai", 'value_prop' => 'Never miss an enquiry.', 'exclusions' => "Government\n",
            'weights' => ['industry_fit' => 50], 'language' => 'ur-Latn', 'tone' => 'friendly', 'booking_link' => 'https://cal.example/x',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->useProject($this->a);
        $p = OfferProfile::firstOrFail();
        $this->assertSame(['Real-estate agencies', 'In Dubai'], $p->icp);
        $this->assertSame(['Government'], $p->exclusions);
        $this->assertSame(50, $p->scoring['industry_fit']['weight']);
        $this->assertSame(20, $p->scoring['location_fit']['weight'], 'unspecified weights keep their defaults');

        $this->actingAs($this->owner)->get($this->url('leadgen.profiles.edit', ['id' => $p->id, 'project_id' => $this->a->id]))->assertOk()->assertSee('AI support for agencies');
        $this->actingAs($this->owner)->post($this->url('leadgen.profiles.store'), ['project_id' => $this->a->id, 'name' => 'x', 'product' => 'x',
            'icp' => 'x', 'value_prop' => 'x', 'language' => 'en', 'tone' => 'friendly', 'booking_link' => 'http://insecure.example'])
            ->assertSessionHasErrors('booking_link');
    }

    public function test_settings_need_the_settings_ability(): void
    {
        $sales = $this->member(['view', 'search', 'edit']);
        $this->actingAs($sales)->get($this->url('leadgen.profiles.index', ['project_id' => $this->a->id]))->assertForbidden();
        $this->actingAs($sales)->get($this->url('leadgen.templates.create', ['project_id' => $this->a->id]))->assertForbidden();
    }

    public function test_templates_reject_unknown_variables_and_require_approved_whatsapp_names(): void
    {
        $base = ['project_id' => $this->a->id, 'name' => 'Intro', 'kind' => 'intro', 'language' => 'en'];
        $this->actingAs($this->owner)->post($this->url('leadgen.templates.store'), $base + ['channel' => 'email', 'subject' => 'Hi', 'body' => 'Hi {{first_name}}'])
            ->assertSessionHasErrors('body');
        $this->actingAs($this->owner)->post($this->url('leadgen.templates.store'), $base + ['channel' => 'whatsapp', 'body' => 'Hi {{business_name}}'])
            ->assertSessionHasErrors('wa_template_name');
        $this->actingAs($this->owner)->post($this->url('leadgen.templates.store'), $base + ['channel' => 'whatsapp', 'body' => 'Hi {{business_name}}', 'wa_template_name' => 'intro_v1', 'subject' => 'dropped'])
            ->assertSessionHasNoErrors();
        $this->useProject($this->a);
        $this->assertNull(Template::firstOrFail()->subject, 'chat templates have no subject');
    }

    public function test_template_preview_fills_sample_values_and_flags_gaps(): void
    {
        $this->profile()->forceFill(['booking_link' => null])->save();
        $this->actingAs($this->owner)->postJson($this->url('leadgen.templates.preview'), ['project_id' => $this->a->id,
            'subject' => 'Idea for {{business_name}}', 'body' => 'Book {{booking_link}} — {{sender_name}}'])
            ->assertOk()->assertJsonPath('subject', 'Idea for Acme Realty')->assertJsonPath('missing', ['booking_link']);
    }

    public function test_drafting_from_a_template_and_with_ai(): void
    {
        $profile = $this->profile();
        $p = $this->prospect();
        $this->useProject($this->a);
        $t = Template::create(['name' => 'Intro', 'channel' => 'email', 'kind' => 'intro', 'language' => 'en', 'subject' => 'For {{business_name}}', 'body' => 'Hi {{business_name}}, {{sender_name}}']);

        $form = ['project_id' => $this->a->id, 'profile_id' => hashid($profile->id), 'template_id' => hashid($t->id), 'channel' => 'email',
                 'language' => 'en', 'tone' => 'professional', 'length' => 'short'];
        $this->actingAs($this->owner)->post($this->url('leadgen.drafts.store', ['id' => $p->id]), $form + ['mode' => 'template'])->assertRedirect();
        $this->useProject($this->a);
        $this->assertSame('Hi Acme Realty, Umer', OutreachMessage::firstOrFail()->body);
        $this->assertCount(0, $this->llm->calls, 'template mode uses no AI');

        $this->llm->answers = [['subject' => 'Quick idea', 'body' => 'Hello {{business_name}} team, a short idea.']];
        $res = $this->actingAs($this->owner)->post($this->url('leadgen.drafts.store', ['id' => $p->id]), $form + ['mode' => 'ai']);
        $this->useProject($this->a);
        $ai = OutreachMessage::where('ai_generated', true)->firstOrFail();
        $res->assertRedirect($this->url('leadgen.drafts.edit', ['id' => $ai->id, 'project_id' => $this->a->id]));
        $this->actingAs($this->owner)->get($this->url('leadgen.drafts.edit', ['id' => $ai->id, 'project_id' => $this->a->id]))
            ->assertOk()->assertSee('Hello Acme Realty team')->assertSee('Written by AI');
    }

    public function test_an_ai_outage_is_a_friendly_error_not_a_500(): void
    {
        $profile = $this->profile();
        $p = $this->prospect();
        $this->llm->fail = true;
        $this->actingAs($this->owner)->post($this->url('leadgen.drafts.store', ['id' => $p->id]), ['project_id' => $this->a->id,
            'profile_id' => hashid($profile->id), 'channel' => 'email', 'language' => 'en', 'tone' => 'professional', 'length' => 'short', 'mode' => 'ai'])
            ->assertSessionHasErrors('draft');
        $this->actingAs($this->owner)->post($this->url('leadgen.qualify.one', ['id' => $p->id]), ['project_id' => $this->a->id, 'profile_id' => hashid($profile->id)])
            ->assertSessionHasErrors('qualify');
    }

    public function test_editing_a_draft_rechecks_it(): void
    {
        $p = $this->prospect();
        $this->useProject($this->a);
        $m = OutreachMessage::create(['prospect_id' => $p->id, 'channel' => 'email', 'subject' => 'Hi', 'body' => 'x', 'status' => 'draft']);
        $this->actingAs($this->owner)->patch($this->url('leadgen.drafts.update', ['id' => $m->id]), ['project_id' => $this->a->id,
            'subject' => 'Hi', 'body' => 'As we discussed, {{booking_link}}'])->assertSessionHas('success');
        $this->useProject($this->a);
        $this->assertStringContainsString('past conversation', $m->fresh()->last_error);
        $this->assertStringContainsString('{{booking_link}}', $m->fresh()->last_error);
    }

    public function test_scoring_one_reviewing_and_overriding(): void
    {
        $profile = $this->profile();
        $p = $this->prospect(['category' => 'real_estate_agent']);
        $this->llm->answers = [['score' => 75, 'band' => 'high', 'explanation' => 'Agency in Dubai.', 'insufficient_evidence' => false,
            'evidence' => [['fact' => 'city', 'value' => 'Dubai']], 'suggested_channel' => 'email']];

        $this->actingAs($this->owner)->post($this->url('leadgen.qualify.one', ['id' => $p->id]), ['project_id' => $this->a->id, 'profile_id' => hashid($profile->id)])
            ->assertSessionHas('success');
        $this->actingAs($this->owner)->get($this->url('leadgen.review.index', ['project_id' => $this->a->id]))
            ->assertOk()->assertSee('Acme Realty')->assertSee('High fit')->assertSee('city: Dubai');

        $this->useProject($this->a);
        $q = Qualification::firstOrFail();
        $this->actingAs($this->owner)->post($this->url('leadgen.review.override', ['id' => $q->id]), ['project_id' => $this->a->id, 'override_band' => 'low'])
            ->assertSessionHasErrors('override_reason');
        $this->actingAs($this->owner)->post($this->url('leadgen.review.override', ['id' => $q->id]), ['project_id' => $this->a->id,
            'override_band' => 'low', 'override_reason' => 'They already use a chatbot'])->assertSessionHasNoErrors();
        $this->useProject($this->a);
        $this->assertSame('low', $q->fresh()->effectiveBand());
    }

    public function test_bulk_scoring_queues_chunks_and_skips_do_not_contact(): void
    {
        Bus::fake([QualifyProspects::class]);
        $profile = $this->profile();
        $ids = collect(range(1, 30))->map(fn ($i) => hashid($this->prospect(['business_name' => "P{$i}"])->id));
        $this->useProject($this->a);
        Prospect::where('business_name', 'P1')->update(['suppressed_at' => time()]);

        $this->actingAs($this->owner)->post($this->url('leadgen.qualify.queue'), ['project_id' => $this->a->id,
            'profile_id' => hashid($profile->id), 'ids' => $ids->all()])->assertRedirect();

        Bus::assertDispatchedTimes(QualifyProspects::class, 2);
        $sent = 0;
        Bus::assertDispatched(QualifyProspects::class, function ($j) use (&$sent) { $sent += count($j->prospectIds); return true; });
        $this->assertSame(29, $sent);
    }
}
