<?php

namespace Tests\Feature;

use App\Jobs\PurgeMetaUserData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Msd\MetaChannels\Models\DataDeletionRequest;
use Msd\MetaChannels\Support\SignedRequest;
use Tests\TestCase;

/**
 * Meta's data-deletion callback.
 *
 * Two things have to be true at once here, and they pull in opposite
 * directions: the endpoint must accept requests from Meta with no session
 * and no CSRF token, and it must be impossible for anyone else to use. The
 * HMAC on `signed_request` is the entire boundary between those, so most of
 * these tests are about it.
 */
class DataDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-app-secret';
    private const USER   = '17841400000000009';

    protected function setUp(): void
    {
        parent::setUp();
        config(['meta.app.secret' => self::SECRET]);
    }

    /** Build a valid Meta signed_request. */
    private function sign(array $payload, ?string $secret = null): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode(
            $payload + ['algorithm' => 'HMAC-SHA256'],
        )), '+/', '-_'), '=');

        $sig = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $encoded, $secret ?? self::SECRET, true),
        ), '+/', '-_'), '=');

        return $sig . '.' . $encoded;
    }

    // ── The callback ─────────────────────────────────────────────────

    public function test_a_signed_request_opens_a_deletion_and_returns_metas_expected_shape(): void
    {
        Queue::fake();

        $response = $this->postJson('/meta/data-deletion', [
            'signed_request' => $this->sign(['user_id' => self::USER]),
        ])->assertOk();

        // Exactly these two keys. A missing `url` fails App Review.
        $response->assertJsonStructure(['url', 'confirmation_code']);

        $deletion = DataDeletionRequest::firstOrFail();
        $this->assertSame(self::USER, $deletion->external_user_id);
        $this->assertSame(DataDeletionRequest::STATUS_PENDING, $deletion->status);

        // The URL must actually point at the status page for that code —
        // Meta shows this link to the user.
        $response->assertJsonPath('confirmation_code', $deletion->confirmation_code);
        $response->assertJsonPath('url', route('data-deletion.status', ['code' => $deletion->confirmation_code]));

        Queue::assertPushed(PurgeMetaUserData::class);
    }

    public function test_an_unsigned_request_is_refused_and_deletes_nothing(): void
    {
        Queue::fake();

        // The attack this blocks: anyone who learns a PSID could otherwise
        // erase a business's entire conversation history with one curl.
        $this->postJson('/meta/data-deletion', [
            'signed_request' => $this->sign(['user_id' => self::USER], 'wrong-secret'),
        ])->assertStatus(400);

        $this->assertSame(0, DataDeletionRequest::count());
        Queue::assertNothingPushed();
    }

    public function test_a_malformed_request_is_refused(): void
    {
        Queue::fake();

        foreach (['', 'not-a-signed-request', 'a.b', base64_encode('x') . '.' . base64_encode('{}')] as $bad) {
            $this->postJson('/meta/data-deletion', ['signed_request' => $bad])->assertStatus(400);
        }

        $this->assertSame(0, DataDeletionRequest::count());
        Queue::assertNothingPushed();
    }

    public function test_a_request_signed_with_the_instagram_secret_is_accepted(): void
    {
        Queue::fake();

        // An app doing both Facebook Login and Instagram Login has two
        // secrets, and Meta does not say which one signed a given callback.
        config(['meta.app.secret' => 'facebook-secret', 'meta.instagram.app_secret' => 'instagram-secret']);

        $this->postJson('/meta/data-deletion', [
            'signed_request' => $this->sign(['user_id' => self::USER], 'instagram-secret'),
        ])->assertOk();

        $this->assertSame(1, DataDeletionRequest::count());
    }

    public function test_a_repeat_request_reuses_the_pending_one(): void
    {
        Queue::fake();

        $first = $this->postJson('/meta/data-deletion', ['signed_request' => $this->sign(['user_id' => self::USER])])
            ->assertOk()->json('confirmation_code');

        // People click twice, and Meta retries on timeout. Neither should
        // produce a second code for work already queued.
        $second = $this->postJson('/meta/data-deletion', ['signed_request' => $this->sign(['user_id' => self::USER])])
            ->assertOk()->json('confirmation_code');

        $this->assertSame($first, $second);
        $this->assertSame(1, DataDeletionRequest::count());
        Queue::assertPushed(PurgeMetaUserData::class, 1);
    }

    public function test_a_request_without_a_user_id_is_refused(): void
    {
        Queue::fake();

        $this->postJson('/meta/data-deletion', ['signed_request' => $this->sign(['issued_at' => 1])])
            ->assertStatus(400);

        Queue::assertNothingPushed();
    }

    // ── The status page ──────────────────────────────────────────────

    public function test_the_status_page_reports_a_completed_deletion(): void
    {
        $deletion = DataDeletionRequest::open('instagram', self::USER);
        $deletion->markCompleted(sessions: 2, messages: 37);

        $this->get(route('data-deletion.status', ['code' => $deletion->confirmation_code]))
            ->assertOk()
            ->assertSee($deletion->confirmation_code)
            ->assertSee('37')
            // A page keyed to one person's request has no business being
            // indexed, and the code in the URL should not be crawlable.
            ->assertSee('noindex', false);
    }

    public function test_an_unknown_confirmation_code_is_a_404(): void
    {
        $this->get(route('data-deletion.status', ['code' => 'nosuchcodeatall']))->assertNotFound();
    }

    public function test_a_failed_deletion_does_not_leak_the_underlying_error(): void
    {
        $deletion = DataDeletionRequest::open('instagram', self::USER);
        $deletion->markFailed('SQLSTATE[42S02]: Base table or view not found: sessions_v2');

        // The reason is kept for us; the public page must not print it.
        $this->get(route('data-deletion.status', ['code' => $deletion->confirmation_code]))
            ->assertOk()
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('sessions_v2');
    }

    public function test_the_instructions_page_is_public_and_linked_from_the_footer(): void
    {
        // Meta requires the instructions to be reachable from the site, not
        // only from a URL pasted into the app dashboard.
        $this->get('/data-deletion')->assertOk()->assertSee('Data Deletion');
        $this->get('/')->assertOk()->assertSee('/data-deletion', false);
    }

    // ── The parser ───────────────────────────────────────────────────

    public function test_the_parser_rejects_a_payload_claiming_a_different_algorithm(): void
    {
        // `algorithm: none` is the classic downgrade attack on this format.
        $encoded = rtrim(strtr(base64_encode(json_encode([
            'user_id' => self::USER, 'algorithm' => 'none',
        ])), '+/', '-_'), '=');

        $this->assertNull(SignedRequest::parse('sig.' . $encoded, [self::SECRET]));
    }

    public function test_the_parser_accepts_base64url_padding_variants(): void
    {
        // Meta strips `=` padding and uses `-_`; base64_decode alone chokes on
        // both, which would reject every genuine request.
        $signed = $this->sign(['user_id' => self::USER]);

        $this->assertStringNotContainsString('=', $signed);
        $this->assertSame(self::USER, SignedRequest::parse($signed, [self::SECRET])['user_id']);
    }
}
