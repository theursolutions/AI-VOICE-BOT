<?php

namespace Tests\Feature\LeadGen;

use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Lead;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Search;
use App\Models\LeadGen\SearchResult;
use App\Models\LeadGen\Suppression;
use App\Services\Crm\ContactResolver;
use App\Services\LeadGen\CrmSync;
use App\Services\LeadGen\Enrichment\ContactExtractor;
use App\Services\LeadGen\Enrichment\WebsiteEnricher;
use App\Services\LeadGen\ProspectNormalizer;
use App\Services\LeadGen\ProspectWriter;
use App\Services\LeadGen\SuppressionService;
use App\Support\SafeFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\LeadGen\Concerns\UsesSqliteTenant;
use Tests\TestCase;

/** Cross-source dedup, the do-not-contact list, website enrichment and Add to CRM. */
class EnrichDedupCrmTest extends TestCase
{
    use UsesSqliteTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSqliteTenant();
        $this->useProject(5);
        Cache::flush();
        config(['leadgen.fetch.respect_robots' => false]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSqliteTenant();
        parent::tearDown();
    }

    private function item(string $id, array $in, string $provider = 'overture'): array
    {
        return ProspectNormalizer::make($in + ['source_record_id' => $id, 'country_code' => 'AE'], $provider, $provider === 'import' ? 'Your import' : 'Overture Maps');
    }

    private function write(array $items, array $criteria = []): array
    {
        $s = Search::create(['provider' => 'overture', 'status' => 'running', 'criteria' => $criteria + ['bbox' => [55, 25, 55.5, 25.5]]]);
        return [app(ProspectWriter::class)->write($s, $items), $s];
    }

    // ── dedup ───────────────────────────────────────────────────────────

    public function test_the_same_business_from_another_source_is_folded_in_not_duplicated(): void
    {
        $this->write([$this->item('ov1', ['business_name' => 'Acme Realty', 'website' => 'acme.ae'])]);
        [$counts] = $this->write([$this->item('row:x', ['business_name' => 'ACME Real Estate LLC', 'website' => 'https://www.acme.ae',
            'email' => 'info@acme.ae', 'phone' => '04 555 1234'], 'import')]);

        $this->assertSame(1, Prospect::count());
        $this->assertSame(1, $counts['duplicate']);
        $p = Prospect::first();
        $this->assertSame('Acme Realty', $p->business_name, 'the original name is kept');
        $this->assertSame('info@acme.ae', $p->email, 'an empty field is filled from the other source');
        $this->assertSame('+97145551234', $p->phone_e164);
        $this->assertSame('Your import', $p->field_meta['email']['source'], 'with where it came from');
        $this->assertSame('import', $p->source_meta['also_from'][0]['provider']);
    }

    public function test_folding_in_never_overwrites_an_existing_value(): void
    {
        $this->write([$this->item('ov1', ['business_name' => 'Acme', 'website' => 'acme.ae', 'email' => 'sales@acme.ae'])]);
        $this->write([$this->item('ov2', ['business_name' => 'Acme', 'website' => 'acme.ae', 'email' => 'old@acme.ae'])]);
        $this->assertSame('sales@acme.ae', Prospect::first()->email);
    }

    public function test_a_shared_phone_with_another_name_is_kept_separate_and_flagged(): void
    {
        $this->write([$this->item('ov1', ['business_name' => 'Acme Realty', 'phone' => '+97145551234'])]);
        $this->write([$this->item('ov2', ['business_name' => 'Zenith Holiday Homes', 'phone' => '+97145551234'])]);

        $this->assertSame(2, Prospect::count());
        $zenith = Prospect::where('business_name', 'Zenith Holiday Homes')->first();
        $this->assertSame(Prospect::where('business_name', 'Acme Realty')->value('id'), $zenith->duplicate_of);
    }

    public function test_same_name_different_city_and_site_are_different_businesses(): void
    {
        $this->write([$this->item('a', ['business_name' => 'Al Noor Properties', 'city' => 'Dubai', 'website' => 'alnoor-dxb.ae'])]);
        $this->write([$this->item('b', ['business_name' => 'Al Noor Properties', 'city' => 'Sharjah', 'website' => 'alnoor-shj.ae'])]);
        $this->assertSame(2, Prospect::count());
        $this->assertSame(0, Prospect::whereNotNull('duplicate_of')->count());
    }

    public function test_duplicates_inside_one_page_collapse_to_one(): void
    {
        [$counts, $s] = $this->write([
            $this->item('a', ['business_name' => 'Acme', 'website' => 'acme.ae']),
            $this->item('b', ['business_name' => 'Acme Marina', 'website' => 'www.acme.ae/marina']),
        ]);
        $this->assertSame(1, Prospect::count());
        $this->assertSame(['new' => 1, 'duplicate' => 0], ['new' => $counts['new'], 'duplicate' => $counts['duplicate']],
            'both point at one prospect; this search links it once');
        $this->assertSame(1, SearchResult::where('search_id', $s->id)->count());
    }

    // ── do-not-contact ──────────────────────────────────────────────────

    public function test_deleted_with_never_readd_is_skipped_and_opt_outs_are_kept_but_marked(): void
    {
        $sup = app(SuppressionService::class);
        $sup->add('domain', 'https://gone.ae', 'deleted', 'test');
        $sup->add('email', 'optout@stay.ae', 'opt_out', 'test');

        [$counts] = $this->write([
            $this->item('g', ['business_name' => 'Gone', 'website' => 'gone.ae']),
            $this->item('s', ['business_name' => 'Stay', 'email' => 'optout@stay.ae']),
        ]);

        $this->assertSame(1, $counts['excluded']);
        $this->assertNull(Prospect::where('business_name', 'Gone')->first());
        $this->assertNotNull(Prospect::where('business_name', 'Stay')->value('suppressed_at'));
    }

    public function test_suppressing_marks_existing_prospects_and_rejects_unparseable_values(): void
    {
        $this->write([$this->item('a', ['business_name' => 'Acme', 'phone' => '+971501112222'])]);
        $sup = app(SuppressionService::class);
        $this->assertTrue($sup->add('phone', '+971 50 111 2222', 'manual'));
        $this->assertTrue($sup->add('phone', '+971501112222', 'manual'), 'idempotent');
        $this->assertFalse($sup->add('phone', '050 111 2222', 'manual'), 'no country code: refused, never guessed');
        $this->assertSame(1, Suppression::count());
        $this->assertNotNull(Prospect::first()->suppressed_at);
    }

    // ── enrichment ──────────────────────────────────────────────────────

    private function enricher(array $responses, bool $mx = true): WebsiteEnricher
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $fetcher = new SafeFetcher($client, fn (string $h) => $h === 'internal.acme.ae' ? ['10.0.0.8'] : ['93.184.216.34']);
        return new WebsiteEnricher($fetcher, new ContactExtractor(), fn () => $mx);
    }

    private function html(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/html'], "<html><body>{$body}</body></html>");
    }

    public function test_enrichment_fills_a_generic_inbox_from_their_own_site_and_lists_people_separately(): void
    {
        $this->write([$this->item('a', ['business_name' => 'Acme', 'website' => 'acme.ae'])]);
        $p = Prospect::first();

        $status = $this->enricher([
            $this->html('<a href="/contact">Contact</a> <a href="https://facebook.com/acme">fb</a>'),
            $this->html('<a href="mailto:jane.doe@acme.ae">Jane</a> <a href="mailto:info@acme.ae">Info</a> <a href="tel:045551234">Call</a> gmail: acme.dxb@gmail.com'),
        ])->enrich($p);

        $p->refresh();
        $this->assertSame('done', $status);
        $this->assertSame('info@acme.ae', $p->email, 'a role address on their own domain');
        $this->assertSame('found_on_site', $p->verificationOf('email'));
        $this->assertSame('https://acme.ae/contact', $p->field_meta['email']['url']);
        $this->assertEqualsCanonicalizing(['jane.doe@acme.ae', 'acme.dxb@gmail.com'], $p->field_meta['email']['alternatives']);
        $this->assertSame('+97145551234', $p->phone_e164);
        $this->assertSame('found_on_site', $p->verificationOf('website'));
        $this->assertSame('facebook', $p->socials[0]['platform']);
        $this->assertTrue($p->field_meta['email']['mx']);
    }

    public function test_enrichment_raises_verification_but_never_lowers_it(): void
    {
        $this->write([$this->item('a', ['business_name' => 'Acme', 'website' => 'acme.ae', 'email' => 'sales@acme.ae'])]);
        $p = Prospect::first();
        $p->field_meta = ['email' => ['verification' => 'user_confirmed', 'source' => 'user']] + (array) $p->field_meta;
        $p->save();

        $this->enricher([$this->html('nothing here')], mx: false)->enrich($p);
        $p->refresh();
        $this->assertSame('user_confirmed', $p->verificationOf('email'));
        $this->assertFalse($p->field_meta['email']['mx'], 'the missing mail server is still recorded');
    }

    public function test_an_unverified_listing_email_gets_domain_ok_from_mx_only(): void
    {
        $this->write([$this->item('a', ['business_name' => 'Acme', 'website' => 'acme.ae', 'email' => 'deals@acme.ae'])]);
        $p = Prospect::first();
        $this->enricher([$this->html('no emails')])->enrich($p);
        $this->assertSame('mx_ok', $p->fresh()->verificationOf('email'));
    }

    public function test_unsafe_or_dead_sites_are_recorded_not_fetched(): void
    {
        $this->write([$this->item('a', ['business_name' => 'Internal', 'website' => 'http://internal.acme.ae'])]);
        $p = Prospect::first();
        $this->assertSame('blocked', $this->enricher([])->enrich($p));

        $this->write([$this->item('b', ['business_name' => 'Dead', 'website' => 'dead.ae'])]);
        $dead = Prospect::where('business_name', 'Dead')->first();
        $this->assertSame('failed', $this->enricher([new Response(503)])->enrich($dead));
        $this->assertFalse($dead->fresh()->field_meta['website']['reachable']);
    }

    // ── CRM ─────────────────────────────────────────────────────────────

    public function test_add_to_crm_creates_a_contact_and_a_session_less_lead_once(): void
    {
        $this->createCrmTables();
        $this->write([$this->item('a', ['business_name' => 'Acme Realty', 'website' => 'acme.ae', 'email' => 'info@acme.ae', 'phone' => '04 555 1234'])]);
        $p = Prospect::first();

        $first  = app(CrmSync::class)->add($p, 1);
        $second = app(CrmSync::class)->add($p->fresh(), 1);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created'], 'idempotent');
        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Lead::count());
        $lead = Lead::first();
        $this->assertNull($lead->session_id);
        $this->assertSame('Lead Generation', $lead->fields['source']);
        $this->assertSame('in_crm', $p->fresh()->status);
        $this->assertSame('97145551234', Contact::first()->phone, 'stored as digits, like ContactResolver');

        // A reply from that address resolves to the same contact, not a new one.
        $reply = app(ContactResolver::class)->resolve(5, 'email', 'info@acme.ae', 'inbox@us.ae', ['email' => 'info@acme.ae']);
        $this->assertSame($first['contact']->id, $reply->id);
        $this->assertSame(1, ContactIdentity::where('channel', 'email')->count());
    }

    public function test_add_to_crm_reuses_an_existing_contact_with_the_same_phone(): void
    {
        $this->createCrmTables();
        $existing = app(ContactResolver::class)->resolve(5, 'whatsapp', '97145551234', null, ['name' => 'Ahmed']);
        $this->write([$this->item('a', ['business_name' => 'Acme Realty', 'phone' => '+97145551234'])]);

        $res = app(CrmSync::class)->add(Prospect::first(), 1);

        $this->assertSame($existing->id, $res['contact']->id);
        $this->assertSame('Ahmed', $res['contact']->fresh()->name, 'an existing name is never overwritten');
        $this->assertSame(1, Contact::count());
    }
}
