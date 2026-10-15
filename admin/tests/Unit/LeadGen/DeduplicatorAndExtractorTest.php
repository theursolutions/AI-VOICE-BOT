<?php

namespace Tests\Unit\LeadGen;

use App\Services\LeadGen\Deduplicator as D;
use App\Services\LeadGen\Enrichment\ContactExtractor;
use Tests\TestCase;

class DeduplicatorAndExtractorTest extends TestCase
{
    private function p(array $a): array
    {
        return $a + ['business_name' => 'X', 'domain_norm' => null, 'email' => null, 'phone_e164' => null];
    }

    public function test_strong_matches(): void
    {
        $this->assertSame(D::STRONG, D::compare($this->p(['domain_norm' => 'acme.ae']), $this->p(['domain_norm' => 'acme.ae', 'business_name' => 'Totally Different'])));
        $this->assertSame(D::STRONG, D::compare($this->p(['email' => 'info@acme.ae']), $this->p(['email' => 'info@acme.ae'])));
        $this->assertSame(D::STRONG, D::compare(
            $this->p(['phone_e164' => '+97145551234', 'business_name' => 'Acme Real Estate Brokers LLC']),
            $this->p(['phone_e164' => '+97145551234', 'business_name' => 'ACME Real Estate'])));
    }

    public function test_a_shared_phone_with_a_different_name_is_only_possible(): void
    {
        $this->assertSame(D::POSSIBLE, D::compare(
            $this->p(['phone_e164' => '+97145551234', 'business_name' => 'Acme Realty']),
            $this->p(['phone_e164' => '+97145551234', 'business_name' => 'Zenith Holiday Homes'])));
    }

    public function test_a_similar_name_alone_never_matches(): void
    {
        $this->assertNull(D::compare($this->p(['business_name' => 'Al Noor Properties']), $this->p(['business_name' => 'Al Noor Properties'])));
        $this->assertNull(D::compare($this->p(['business_name' => 'Acme', 'domain_norm' => 'a.ae']), $this->p(['business_name' => 'Acme', 'domain_norm' => 'b.ae'])));
    }

    public function test_best_match_prefers_strong_then_oldest(): void
    {
        $item = $this->p(['domain_norm' => 'acme.ae', 'phone_e164' => '+971500000001', 'business_name' => 'Acme']);
        [$kind, $m] = D::bestMatch($item, [
            ['id' => 9, 'business_name' => 'Other', 'phone_e164' => '+971500000001', 'domain_norm' => null, 'email' => null],
            ['id' => 7, 'business_name' => 'Acme', 'domain_norm' => 'acme.ae', 'email' => null, 'phone_e164' => null],
            ['id' => 5, 'business_name' => 'Acme old', 'domain_norm' => 'acme.ae', 'email' => null, 'phone_e164' => null],
        ]);
        $this->assertSame([D::STRONG, 5], [$kind, $m['id']]);
    }

    public function test_extractor_finds_business_contacts_and_skips_junk(): void
    {
        $html = <<<HTML
<html><body>
  <a href="mailto:Info@Acme.ae?subject=Hi">Email us</a>
  <p>Sales: sales@acme.ae · jane.doe@acme.ae · logo@2x.png · noreply@acme.ae · you@example.com</p>
  <a href="tel:+971 4 555 1234">Call</a>
  <a href="https://www.instagram.com/acme/">IG</a>
  <a href="/contact-us">Contact</a> <a href="https://acme.ae/about">About us</a>
  <a href="https://other.com/contact">Partner</a> <a href="/pricing">Pricing</a>
  <script>var x = "hidden@acme.ae";</script>
</body></html>
HTML;
        $out = (new ContactExtractor())->extract($html, 'https://www.acme.ae/');

        $this->assertEqualsCanonicalizing(['info@acme.ae', 'sales@acme.ae', 'jane.doe@acme.ae'], $out['emails']);
        $this->assertSame(['+971 4 555 1234'], $out['phones']);
        $this->assertSame([['platform' => 'instagram', 'url' => 'https://www.instagram.com/acme']], $out['socials']);
        $this->assertEqualsCanonicalizing(['https://www.acme.ae/contact-us', 'https://acme.ae/about'], $out['pages']);
    }

    public function test_adjacent_elements_do_not_glue_into_a_fake_address(): void
    {
        // Real site, 2026-10-16: a phone span right before an email span.
        $html = '<html><body><div><span>+966920022442</span><span>info@madaproperties.com</span></div></body></html>';
        $out = (new ContactExtractor())->extract($html, 'https://madaproperties.sa/');
        $this->assertSame(['info@madaproperties.com'], $out['emails']);
    }

    public function test_role_addresses_versus_people(): void
    {
        foreach (['info@a.ae', 'sales@a.ae', 'Enquiries@a.ae', 'contact-us@a.ae', 'customercare@a.ae'] as $e) {
            $this->assertTrue(ContactExtractor::isRoleAddress($e), $e);
        }
        foreach (['jane.doe@a.ae', 'ahmed@a.ae', 'j.smith@a.ae'] as $e) {
            $this->assertFalse(ContactExtractor::isRoleAddress($e), $e);
        }
    }
}
