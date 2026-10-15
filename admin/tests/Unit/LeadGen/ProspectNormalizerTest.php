<?php

namespace Tests\Unit\LeadGen;

use App\Services\LeadGen\Providers\OvertureProvider;
use App\Services\LeadGen\Providers\PlacesClient;
use App\Services\LeadGen\ProspectNormalizer as N;
use Tests\TestCase;

/** The fields deduplication keys on, and how a source record becomes a prospect. */
class ProspectNormalizerTest extends TestCase
{
    public function test_phone_numbers_become_e164_only_when_the_country_is_certain(): void
    {
        $this->assertSame('+971501234567', N::phone('050 123 4567', 'AE'));
        $this->assertSame('+97145551234', N::phone('04 555 1234', 'AE'));
        $this->assertSame('+971501234567', N::phone('+971 50 123 4567', null));
        $this->assertSame('+971501234567', N::phone('00971501234567', null));
        $this->assertSame('+971501234567', N::phone('971501234567', 'AE'));
        $this->assertSame('+923001234567', N::phone('0300-1234567', 'PK'));
        $this->assertNull(N::phone('0300-1234567', null), 'no country, no + — never guessed');
        $this->assertNull(N::phone('12', 'AE'));
        $this->assertNull(N::phone('', 'AE'));
    }

    public function test_domains_keep_subdomains_and_drop_shared_hosts(): void
    {
        $this->assertSame('acme.ae', N::domain('https://www.acme.ae/contact'));
        $this->assertSame('acme.ae', N::domain('acme.ae'));
        $this->assertSame('agent1.platform.ae', N::domain('http://agent1.platform.ae'), 'different subdomains are different businesses');
        foreach (['https://facebook.com/acme', 'https://linktr.ee/acme', 'https://acme.business.site', 'https://www.instagram.com/acme', 'https://acme.wixsite.com/home'] as $shared) {
            $this->assertNull(N::domain($shared), $shared);
        }
        $this->assertNull(N::domain('not a url'));
    }

    public function test_email_and_social_parsing(): void
    {
        $this->assertSame('info@acme.ae', N::email('mailto:Info@Acme.AE'));
        $this->assertNull(N::email('info at acme'));
        $this->assertSame('instagram', N::socialPlatform('https://www.instagram.com/acme'));
        $this->assertSame('facebook', N::socialPlatform('m.facebook.com/acme'));
        $this->assertNull(N::socialPlatform('https://acme.ae'));
    }

    public function test_name_key_ignores_case_punctuation_and_legal_suffixes(): void
    {
        $this->assertSame(N::nameKey('ACME Real Estate Brokers L.L.C'), N::nameKey('Acme'));
        $this->assertNotSame(N::nameKey('Acme Realty'), N::nameKey('Apex Realty'));
    }

    public function test_contact_fields_are_recorded_as_unverified_claims(): void
    {
        $p = N::make([
            'business_name' => 'Acme', 'website' => 'acme.ae', 'phone' => '050 123 4567',
            'email' => 'info@acme.ae', 'country_code' => 'ae', 'source_record_id' => 'r1',
            'socials' => ['https://instagram.com/acme', 'https://acme.ae'],
        ], 'overture', 'Overture Maps');

        $this->assertSame('https://acme.ae', $p['website']);
        $this->assertSame('AE', $p['country_code']);
        $this->assertSame('unverified', $p['field_meta']['phone']['verification']);
        $this->assertSame('unverified', $p['field_meta']['website']['verification']);
        $this->assertSame('syntax_ok', $p['field_meta']['email']['verification'], 'a valid format is the most we know');
        $this->assertSame([['platform' => 'instagram', 'url' => 'https://instagram.com/acme']], $p['socials']);
    }

    public function test_an_unnormalisable_phone_is_kept_for_people_but_not_for_matching(): void
    {
        $p = N::make(['business_name' => 'X', 'phone' => '0300 1234567', 'source_record_id' => 'r'], 'import', 'Your import');
        $this->assertNull($p['phone_e164']);
        $this->assertSame('0300 1234567', $p['field_meta']['phone']['raw']);
    }

    public function test_overture_rows_keep_attribution_and_prefer_the_english_name(): void
    {
        $provider = new OvertureProvider(new PlacesClient());
        $p = $provider->normalise([
            'id' => 'ov-1', 'name' => 'دار العقارات', 'name_en' => 'Dar Properties', 'category' => 'real_estate_agent',
            'basic_category' => 'real_estate_service', 'hierarchy' => ['services', 'real_estate_agent'],
            'websites' => ['https://dar.ae', 'https://dar.com'], 'phones' => ['+971 4 555 1234'], 'emails' => [],
            'socials' => [], 'addresses' => [['freeform' => 'Marina', 'locality' => 'Dubai', 'country' => 'AE']],
            'confidence' => 0.91, 'operating_status' => 'open', 'lat' => 25.08, 'lng' => 55.14,
            'sources' => [['dataset' => 'meta', 'license' => 'CDLA-Permissive-2.0'], ['dataset' => 'Foursquare', 'license' => 'Apache-2.0']],
        ], '2026-09-23.1');

        $this->assertSame('Dar Properties', $p['business_name']);
        $this->assertSame('دار العقارات', $p['source_meta']['local_name']);
        $this->assertSame('CDLA-Permissive-2.0, Apache-2.0', $p['source_license']);
        $this->assertSame('2026-09-23.1', $p['source_meta']['release']);
        $this->assertSame(['https://dar.com'], $p['source_meta']['more_websites']);
        $this->assertSame('+97145551234', $p['phone_e164']);
        $this->assertSame('Dubai', $p['city']);
        $this->assertStringContainsString('explore.overturemaps.org', $p['source_url']);
    }
}
