<?php

namespace Tests\Feature;

use App\Services\Crm\ContactResolver;
use Tests\TestCase;

/**
 * Identity resolution and, more importantly, the merge rules.
 *
 * The asymmetry these protect: a MISSED merge is two profiles for one
 * person, fixable by hand in seconds. A WRONG merge shows one customer
 * another customer's entire conversation history — a data breach between
 * two of your own users, and not undoable.
 *
 * So the rules must be provably conservative, not merely careful.
 */
class ContactResolverTest extends TestCase
{
    private function invoke(string $method, array $args)
    {
        $ref = new \ReflectionMethod(ContactResolver::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(new ContactResolver(), $args);
    }

    // ── Phone normalisation ──────────────────────────────────────────

    public function test_the_same_number_written_differently_is_one_number(): void
    {
        $forms = ['+92 300 1234567', '923001234567', '+92-300-1234567', '(92) 300 1234567'];

        $normalised = array_map(fn ($f) => $this->invoke('normalisePhone', [$f]), $forms);

        $this->assertCount(1, array_unique($normalised), 'All of these are the same person.');
        $this->assertSame('923001234567', $normalised[0]);
    }

    /**
     * The dangerous case. A short string is not a phone number, and using
     * one as a merge key would fold every stranger who typed it into one
     * profile.
     */
    public function test_a_too_short_number_is_never_a_merge_key(): void
    {
        foreach (['1234', '00', 'n/a', '', '12'] as $junk) {
            $this->assertNull($this->invoke('normalisePhone', [$junk]), "must reject: {$junk}");
        }
    }

    // ── Email normalisation ──────────────────────────────────────────

    public function test_emails_are_matched_case_insensitively(): void
    {
        $this->assertSame(
            'ayesha@example.com',
            $this->invoke('normaliseEmail', ['  Ayesha@Example.COM  ']),
        );
    }

    public function test_something_that_is_not_an_email_is_rejected(): void
    {
        foreach (['not-an-email', 'a@b', '@example.com', 'hello world', ''] as $junk) {
            $this->assertNull($this->invoke('normaliseEmail', [$junk]), "must reject: {$junk}");
        }
    }

    // ── Channel vocabulary ───────────────────────────────────────────

    /**
     * sessions.channel, the Meta provider name and the webhook event name
     * all differ. One person must not become three contacts because three
     * layers spell Facebook differently.
     */
    public function test_the_channel_spellings_collapse_to_one(): void
    {
        $this->assertSame('facebook', $this->invoke('normaliseChannel', ['messenger']));
        $this->assertSame('facebook', $this->invoke('normaliseChannel', ['facebook_page']));
        $this->assertSame('facebook', $this->invoke('normaliseChannel', ['facebook']));

        $this->assertSame('phone', $this->invoke('normaliseChannel', ['twilio']));
        $this->assertSame('phone', $this->invoke('normaliseChannel', ['plivo']));

        $this->assertSame('whatsapp', $this->invoke('normaliseChannel', ['whatsapp']));
        $this->assertSame('instagram', $this->invoke('normaliseChannel', ['instagram']));
    }

    // ── Availability ─────────────────────────────────────────────────

    /**
     * Tenant migrations lag deploys. An un-migrated project must lose the
     * feature, not its inbox.
     */
    public function test_resolution_degrades_rather_than_throwing(): void
    {
        // No tenant connection is configured in the test environment, so
        // available() must answer false rather than raising.
        $this->assertFalse(ContactResolver::available());

        $this->assertNull(
            (new ContactResolver())->resolve(1, 'whatsapp', '923001234567'),
            'With no contacts table the resolver returns null, and the message still gets a reply.',
        );
    }

    public function test_an_empty_handle_resolves_to_nothing(): void
    {
        $this->assertNull((new ContactResolver())->resolve(1, 'whatsapp', ''));
    }
}
