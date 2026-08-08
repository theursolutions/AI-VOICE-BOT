<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Models\ChannelOnboardingLog;
use Msd\MetaChannels\Models\ChannelOnboardingPayload;
use Msd\MetaChannels\Services\OAuthService;
use Msd\MetaChannels\Services\OnboardingService;
use Msd\MetaChannels\Services\TokenService;
use Tests\TestCase;

/**
 * The promise this feature makes is narrow and testable: once Meta has said
 * yes, a customer never has to go back to Meta because OUR side broke.
 *
 * These tests hold that promise honest without touching the Graph API.
 */
class ChannelOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = 4242;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** A service wired to fakes, so nothing reaches Facebook. */
    private function pipeline(array $discovery = null, bool $tokenExchangeFails = false): OnboardingService
    {
        $discovery ??= [[
            'provider'     => ChannelConnection::PROVIDER_WHATSAPP,
            'external_id'  => '109876543210',
            'name'         => 'Serve AI Sales',
            'access_token' => null,
            'metadata'     => ['waba_id' => '555000111'],
        ]];

        $oauth = Mockery::mock(OAuthService::class);
        $oauth->shouldReceive('exchangeCode')->andReturn('SHORT-LIVED-TOKEN');
        $oauth->shouldReceive('discover')->andReturn($discovery);
        $oauth->shouldReceive('discoverWhatsAppByIds')->andReturn($discovery);

        $tokens = Mockery::mock(TokenService::class);
        if ($tokenExchangeFails) {
            $tokens->shouldReceive('exchangeForLongLived')->andThrow(new \RuntimeException('Meta is down'));
        } else {
            $tokens->shouldReceive('exchangeForLongLived')->andReturn([
                'token'      => 'LONG-LIVED-TOKEN',
                'expires_in' => 60 * 60 * 24 * 60,
                'expires_at' => now()->addDays(60),
            ]);
        }
        // Grant exactly the scopes configured, so the scope check passes.
        $tokens->shouldReceive('inspect')->andReturn([
            'valid'      => true,
            'expires_at' => now()->addDays(60),
            'scopes'     => array_filter(explode(',', (string) config('meta.app.scopes.whatsapp'))),
            'type'       => 'USER',
            'error'      => null,
        ]);

        return new OnboardingService($oauth, $tokens);
    }

    private function freshLog(): ChannelOnboardingLog
    {
        return ChannelOnboardingLog::create([
            'project_id' => self::PROJECT_ID,
            'user_id'    => 1,
            'provider'   => ChannelConnection::PROVIDER_WHATSAPP,
            'method'     => ChannelOnboardingPayload::METHOD_REDIRECT,
            'status'     => ChannelOnboardingLog::STATUS_STARTED,
        ]);
    }

    public function test_the_meta_response_is_persisted_before_anything_is_attempted(): void
    {
        $log = $this->freshLog();

        $payload = $this->pipeline()->ingestCode(
            self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP,
            'AUTH-CODE', 'https://example.test/callback', $log,
        );

        // Stored, and stored encrypted — a token in plaintext in the DB is
        // the failure mode this whole table has to avoid.
        $this->assertDatabaseHas('channel_onboarding_payloads', [
            'id'         => $payload->id,
            'project_id' => self::PROJECT_ID,
            'status'     => ChannelOnboardingPayload::STATUS_RECEIVED,
        ]);
        $this->assertSame('AUTH-CODE', $payload->fresh()->auth_code);
        $this->assertNotSame('AUTH-CODE', \DB::table('channel_onboarding_payloads')->find($payload->id)->auth_code);

        $this->assertSame($payload->id, $log->fresh()->payload_id);
    }

    public function test_a_successful_run_imports_the_channel_and_stores_both_tokens(): void
    {
        $log      = $this->freshLog();
        $pipeline = $this->pipeline();
        $payload  = $pipeline->ingestCode(self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP, 'AUTH-CODE', 'https://example.test/callback', $log);

        $imported = $pipeline->process($payload, $log);

        $this->assertSame(['Serve AI Sales'], $imported);

        $conn = ChannelConnection::where('project_id', self::PROJECT_ID)->firstOrFail();
        $this->assertSame('LONG-LIVED-TOKEN', $conn->access_token, 'The working token must be the long-lived one.');
        $this->assertSame('SHORT-LIVED-TOKEN', $conn->short_lived_token, 'The short-lived token is kept for diagnosis.');
        $this->assertNotNull($conn->token_expires_at);
        $this->assertTrue($conn->tokenIsValid());

        $this->assertSame(ChannelOnboardingLog::STATUS_SUCCESS, $log->fresh()->status);
    }

    public function test_credentials_are_purged_once_the_import_succeeds(): void
    {
        $log      = $this->freshLog();
        $pipeline = $this->pipeline();
        $payload  = $pipeline->ingestCode(self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP, 'AUTH-CODE', 'https://example.test/callback', $log);

        $pipeline->process($payload, $log);

        $payload->refresh();
        $this->assertSame(ChannelOnboardingPayload::STATUS_IMPORTED, $payload->status);
        $this->assertNull($payload->long_lived_token, 'Secrets must not outlive their purpose.');
        $this->assertNull($payload->auth_code);
        $this->assertNull($payload->discovery);
        $this->assertFalse($payload->isRetryable(), 'An imported payload has nothing left to replay.');
    }

    public function test_a_failure_after_tokenisation_keeps_the_payload_retryable(): void
    {
        $log      = $this->freshLog();
        // Discovery returns a row missing `provider`, which blows up the
        // import — i.e. a failure on OUR side, after Meta already said yes.
        $pipeline = $this->pipeline(discovery: [['external_id' => 'x', 'name' => 'Broken']]);
        $payload  = $pipeline->ingestCode(self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP, 'AUTH-CODE', 'https://example.test/callback', $log);

        try {
            $pipeline->process($payload, $log);
            $this->fail('Expected the import to fail.');
        } catch (\Throwable $e) {
            // expected
        }

        $payload->refresh();
        $this->assertSame(ChannelOnboardingPayload::STATUS_FAILED, $payload->status);
        $this->assertSame(OnboardingService::ERR_IMPORT, $payload->error_code);
        $this->assertSame('LONG-LIVED-TOKEN', $payload->long_lived_token, 'The retry anchor must survive the failure.');
        $this->assertTrue($payload->isRetryable(), 'This is exactly the case the retry button exists for.');
        $this->assertNotNull($log->fresh()->guidance(), 'A failure must carry an actionable message.');
    }

    public function test_retry_replays_our_side_without_reusing_the_authorization_code(): void
    {
        $log      = $this->freshLog();
        $pipeline = $this->pipeline(discovery: [['external_id' => 'x', 'name' => 'Broken']]);
        $payload  = $pipeline->ingestCode(self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP, 'AUTH-CODE', 'https://example.test/callback', $log);

        try { $pipeline->process($payload, $log); } catch (\Throwable $e) {}

        // Second run: discovery now returns something valid. A fresh service
        // whose exchangeCode() would explode if called — proving the retry
        // never goes back to Meta for a new code.
        $oauth = Mockery::mock(OAuthService::class);
        $oauth->shouldReceive('exchangeCode')->never();
        $oauth->shouldReceive('discover')->andReturn([[
            'provider' => ChannelConnection::PROVIDER_WHATSAPP, 'external_id' => '109876543210',
            'name' => 'Serve AI Sales', 'access_token' => null, 'metadata' => [],
        ]]);
        $oauth->shouldReceive('discoverWhatsAppByIds')->andReturn([[
            'provider' => ChannelConnection::PROVIDER_WHATSAPP, 'external_id' => '109876543210',
            'name' => 'Serve AI Sales', 'access_token' => null, 'metadata' => [],
        ]]);
        $tokens = Mockery::mock(TokenService::class);
        $tokens->shouldReceive('exchangeForLongLived')->never();   // already held
        $tokens->shouldReceive('inspect')->andReturn(['valid' => true, 'expires_at' => null, 'scopes' => [], 'type' => 'USER', 'error' => null]);

        // Discovery was already stored, so clear it to force a re-fetch —
        // mirroring an operator retrying after fixing a Graph-side problem.
        $payload->discovery = null;
        $payload->save();

        $result = (new OnboardingService($oauth, $tokens))->retry($payload->fresh(), 1);

        $this->assertSame(['Serve AI Sales'], $result['imported']);
        $this->assertSame(2, $result['log']->attempt);
        $this->assertSame($log->id, $result['log']->retry_of_id, 'Retries must chain to the original attempt.');
    }

    public function test_retrying_twice_does_not_duplicate_the_connection(): void
    {
        $log      = $this->freshLog();
        $pipeline = $this->pipeline();
        $payload  = $pipeline->ingestCode(self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP, 'AUTH-CODE', 'https://example.test/callback', $log);
        $pipeline->process($payload, $log);

        // Simulate a second import over the same discovery.
        $again      = $this->freshLog();
        $pipeline2  = $this->pipeline();
        $payload2   = $pipeline2->ingestCode(self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP, 'AUTH-CODE-2', 'https://example.test/callback', $again);
        $pipeline2->process($payload2, $again);

        $this->assertSame(
            1,
            ChannelConnection::where('project_id', self::PROJECT_ID)->where('external_id', '109876543210')->count(),
            'updateOrCreate on (project, provider, external_id) must keep this idempotent.',
        );
    }

    public function test_a_payload_with_no_long_lived_token_is_not_offered_as_retryable(): void
    {
        $log      = $this->freshLog();
        $pipeline = $this->pipeline(tokenExchangeFails: true);
        $payload  = $pipeline->ingestCode(self::PROJECT_ID, 1, ChannelConnection::PROVIDER_WHATSAPP, 'AUTH-CODE', 'https://example.test/callback', $log);

        try { $pipeline->process($payload, $log); } catch (\Throwable $e) {}

        $payload->refresh();
        $this->assertFalse($payload->isRetryable(), 'Without an anchor there is genuinely nothing to replay.');
        $this->assertNotNull($payload->retryBlockedReason());
        $this->assertSame(OnboardingService::ERR_TOKEN_EXCHANGE, $payload->error_code);
    }

    public function test_expired_stored_credentials_block_a_retry(): void
    {
        $payload = ChannelOnboardingPayload::create([
            'project_id'       => self::PROJECT_ID,
            'provider'         => ChannelConnection::PROVIDER_WHATSAPP,
            'method'           => ChannelOnboardingPayload::METHOD_REDIRECT,
            'long_lived_token' => 'STALE',
            'expires_at'       => now()->subDay(),
            'status'           => ChannelOnboardingPayload::STATUS_FAILED,
        ]);

        $this->assertFalse($payload->isRetryable());
        $this->assertStringContainsString('expired', strtolower((string) $payload->retryBlockedReason()));
    }

    public function test_every_failure_class_has_operator_guidance(): void
    {
        foreach ([
            OnboardingService::ERR_NOT_CONFIGURED, OnboardingService::ERR_CONSENT_DENIED,
            OnboardingService::ERR_CODE_EXCHANGE, OnboardingService::ERR_TOKEN_EXCHANGE,
            OnboardingService::ERR_MISSING_SCOPES, OnboardingService::ERR_NO_CHANNELS,
            OnboardingService::ERR_GRAPH, OnboardingService::ERR_IMPORT, OnboardingService::ERR_EXPIRED,
        ] as $code) {
            $log = new ChannelOnboardingLog(['error_code' => $code]);
            $this->assertNotNull($log->guidance(), "No guidance text for failure class '{$code}'.");
        }
    }

    public function test_the_qr_handoff_page_rejects_an_unsigned_url(): void
    {
        // Without a valid signature this is a public URL that could attach a
        // channel to someone else's workspace.
        $this->get('/connect/1')->assertStatus(403);
        $this->get('/connect/1/go')->assertStatus(403);
    }
}
