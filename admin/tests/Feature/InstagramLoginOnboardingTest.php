<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Models\ChannelOnboardingLog;
use Msd\MetaChannels\Models\ChannelOnboardingPayload;
use Msd\MetaChannels\Services\GraphClient;
use Msd\MetaChannels\Services\InstagramLoginService;
use Msd\MetaChannels\Services\OAuthService;
use Msd\MetaChannels\Services\OnboardingService;
use Msd\MetaChannels\Services\TokenService;
use Tests\TestCase;

/**
 * Instagram API with Instagram Login.
 *
 * The failure this path invites is subtle: it produces a ChannelConnection
 * that looks identical to a Facebook-Login one, while needing a different
 * Graph host and carrying a token that expires. Every test here guards one
 * of those differences, because each of them fails silently in production —
 * the connection reports healthy and simply stops working.
 */
class InstagramLoginOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = 7311;
    private const IG_ID      = '17841400000000001';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** The one account Instagram Login authorises. */
    private function discovery(): array
    {
        return [[
            'provider'     => ChannelConnection::PROVIDER_INSTAGRAM,
            'external_id'  => self::IG_ID,
            'name'         => '@serveai',
            'access_token' => 'IG-LONG-LIVED',
            'metadata'     => ['login' => 'instagram', 'username' => 'serveai'],
        ]];
    }

    private function pipeline(?InstagramLoginService $instagram = null): OnboardingService
    {
        $instagram ??= $this->instagramMock();

        // The Facebook services must not be touched on this path. Asserting
        // that is the point: a stray call means the wrong host and a token
        // Meta rejects.
        $oauth = Mockery::mock(OAuthService::class);
        $oauth->shouldReceive('exchangeCode')->never();
        $oauth->shouldReceive('discover')->never();
        $oauth->shouldReceive('subscribeAppToPage')->never();

        $tokens = Mockery::mock(TokenService::class);
        $tokens->shouldReceive('exchangeForLongLived')->never();
        $tokens->shouldReceive('inspect')->never();

        return new OnboardingService($oauth, $tokens, $instagram);
    }

    /**
     * @param array $overrides per-method overrides. Applied INSTEAD of the
     *        default expectation, not after it: Mockery matches the first
     *        expectation it finds, so layering a second `shouldReceive` on the
     *        same method silently does nothing.
     */
    private function instagramMock(array $overrides = []): InstagramLoginService
    {
        $m = Mockery::mock(InstagramLoginService::class);
        $m->shouldReceive('scopes')->andReturn(['instagram_business_basic', 'instagram_business_manage_messages']);
        $m->shouldReceive('exchangeCode')->andReturn($overrides['exchangeCode'] ?? [
            'token'       => 'IG-SHORT',
            'user_id'     => self::IG_ID,
            'permissions' => ['instagram_business_basic', 'instagram_business_manage_messages'],
        ]);
        $m->shouldReceive('longLived')->andReturn($overrides['longLived'] ?? [
            'token'      => 'IG-LONG-LIVED',
            'expires_at' => now()->addDays(60),
        ]);

        if (isset($overrides['discoverThrows'])) {
            $m->shouldReceive('discover')->andThrow($overrides['discoverThrows']);
        } else {
            $m->shouldReceive('discover')->andReturn($this->discovery());
        }

        if (isset($overrides['subscribeThrows'])) {
            $m->shouldReceive('subscribe')->andThrow($overrides['subscribeThrows']);
        } elseif (! array_key_exists('subscribe', $overrides)) {
            $m->shouldReceive('subscribe')->andReturnNull();
        }

        return $m;
    }

    private function freshLog(): ChannelOnboardingLog
    {
        return ChannelOnboardingLog::create([
            'project_id' => self::PROJECT_ID,
            'user_id'    => 1,
            'provider'   => ChannelConnection::PROVIDER_INSTAGRAM,
            'method'     => ChannelOnboardingPayload::METHOD_INSTAGRAM_LOGIN,
            'status'     => ChannelOnboardingLog::STATUS_STARTED,
        ]);
    }

    private function onboard(?OnboardingService $pipeline = null): array
    {
        $pipeline ??= $this->pipeline();
        $log = $this->freshLog();

        $payload = $pipeline->ingestCode(
            self::PROJECT_ID, 1, ChannelConnection::PROVIDER_INSTAGRAM,
            'IG-CODE', 'https://example.test/meta/instagram/callback', $log,
            ChannelOnboardingPayload::METHOD_INSTAGRAM_LOGIN,
        );

        return [$pipeline->process($payload, $log), $payload, $log];
    }

    public function test_a_successful_run_imports_the_account(): void
    {
        [$imported] = $this->onboard();

        $this->assertSame(['@serveai'], $imported);

        $conn = ChannelConnection::where('project_id', self::PROJECT_ID)->firstOrFail();
        $this->assertSame(self::IG_ID, $conn->external_id);
        $this->assertSame('IG-LONG-LIVED', $conn->access_token);
        $this->assertSame(ChannelConnection::PROVIDER_INSTAGRAM, $conn->provider);
    }

    public function test_the_connection_records_that_it_lives_on_the_instagram_host(): void
    {
        $this->onboard();

        $conn = ChannelConnection::where('external_id', self::IG_ID)->firstOrFail();

        // Without this marker every later send goes to graph.facebook.com,
        // which rejects an Instagram-Login token with a generic OAuth error
        // that says nothing about the host being wrong.
        $this->assertSame('instagram', $conn->metadata['login'] ?? null);
        $this->assertSame(
            config('meta.instagram.graph_base'),
            GraphClient::baseFor($conn->metadata),
        );
    }

    public function test_the_token_expiry_is_recorded_so_the_refresh_sweep_finds_it(): void
    {
        $this->onboard();

        $conn = ChannelConnection::where('external_id', self::IG_ID)->firstOrFail();

        // The regression this guards: ensureImport() treats a channel-supplied
        // token as a permanent Page token. Instagram Login's is not permanent,
        // and recording null here would exclude the account from
        // meta:refresh-tokens and break it silently after 60 days.
        $this->assertNotNull($conn->token_expires_at, 'Instagram tokens always expire — recording no expiry hides that.');
        $this->assertTrue($conn->tokenIsValid());
        $this->assertEqualsWithDelta(60, $conn->tokenExpiresInDays(), 1);

        $this->assertTrue(
            ChannelConnection::tokenExpiringWithin(90)->where('external_id', self::IG_ID)->exists(),
            'The refresh sweep must be able to find this connection.',
        );
    }

    public function test_the_account_is_subscribed_to_its_own_webhooks(): void
    {
        // The single most common "connected but nothing arrives" cause: an
        // account that was never subscribed. There is no error to find,
        // because nothing failed — we just never asked.
        $instagram = $this->instagramMock(['subscribe' => null]);
        $instagram->shouldReceive('subscribe')->once()->with(self::IG_ID, 'IG-LONG-LIVED');

        [, , $log] = $this->onboard($this->pipeline($instagram));

        // Mockery verifies the call itself on teardown; this asserts the
        // operator-visible half — that the step is on the log, so "is it
        // subscribed?" is answerable without a Graph call.
        $steps = collect($log->fresh()->steps ?? []);
        $this->assertTrue(
            $steps->contains(fn ($s) => ($s['step'] ?? null) === 'subscribe_instagram' && ($s['ok'] ?? false) === true),
        );
    }

    public function test_a_failed_subscription_does_not_fail_the_onboarding(): void
    {
        $instagram = $this->instagramMock(['subscribeThrows' => new \RuntimeException('rate limited')]);

        [$imported, , $log] = $this->onboard($this->pipeline($instagram));

        // The connection is still usable for sending and repairable with
        // `meta:subscribe --fix`, so throwing away a completed onboarding
        // over this would be worse than recording it.
        $this->assertSame(['@serveai'], $imported);
        $this->assertSame(ChannelOnboardingLog::STATUS_SUCCESS, $log->fresh()->status);

        $steps = collect($log->fresh()->steps ?? []);
        $this->assertTrue(
            $steps->contains(fn ($s) => ($s['step'] ?? null) === 'subscribe_instagram' && ($s['ok'] ?? true) === false),
            'A silent subscription failure must still be recorded on the log.',
        );
    }

    public function test_withheld_permissions_are_named_rather_than_failing_later(): void
    {
        $instagram = $this->instagramMock(['exchangeCode' => [
            'token'       => 'IG-SHORT',
            'user_id'     => self::IG_ID,
            // The user unticked messaging on the consent screen.
            'permissions' => ['instagram_business_basic'],
        ]]);

        try {
            $this->onboard($this->pipeline($instagram));
            $this->fail('Expected onboarding to stop on the missing permission.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('instagram_business_manage_messages', $e->getMessage());
        }
    }

    public function test_a_failure_after_tokenisation_stays_retryable(): void
    {
        $instagram = $this->instagramMock([
            'discoverThrows' => new \RuntimeException('Instagram: temporarily unavailable'),
        ]);

        $log     = $this->freshLog();
        $service = $this->pipeline($instagram);
        $payload = $service->ingestCode(
            self::PROJECT_ID, 1, ChannelConnection::PROVIDER_INSTAGRAM,
            'IG-CODE', 'https://example.test/meta/instagram/callback', $log,
            ChannelOnboardingPayload::METHOD_INSTAGRAM_LOGIN,
        );

        try { $service->process($payload, $log); } catch (\Throwable $e) {}

        $payload->refresh();
        $this->assertSame(ChannelOnboardingPayload::STATUS_FAILED, $payload->status);
        $this->assertSame('IG-LONG-LIVED', $payload->long_lived_token, 'The retry anchor must survive.');
        $this->assertTrue($payload->isRetryable(), 'The customer must not have to revisit Instagram for our outage.');
    }

    public function test_the_deauthorize_endpoint_rejects_an_unsigned_request(): void
    {
        // This endpoint disables channels and is unauthenticated by design.
        // Without signature verification, knowing an IGSID would be enough to
        // switch off a stranger's Instagram.
        ChannelConnection::create([
            'project_id'  => self::PROJECT_ID,
            'provider'    => ChannelConnection::PROVIDER_INSTAGRAM,
            'external_id' => self::IG_ID,
            'name'        => '@serveai',
            'status'      => ChannelConnection::STATUS_ENABLED,
        ]);

        $this->postJson('/meta/instagram/deauthorize', [
            'signed_request' => base64_encode('nonsense') . '.' . base64_encode(json_encode(['user_id' => self::IG_ID])),
        ])->assertStatus(400);

        $this->assertSame(
            ChannelConnection::STATUS_ENABLED,
            ChannelConnection::where('external_id', self::IG_ID)->first()->status,
        );
    }

    public function test_a_signed_deauthorize_disables_the_account(): void
    {
        config(['meta.instagram.app_secret' => 'IG-SECRET']);

        ChannelConnection::create([
            'project_id'   => self::PROJECT_ID,
            'provider'     => ChannelConnection::PROVIDER_INSTAGRAM,
            'external_id'  => self::IG_ID,
            'name'         => '@serveai',
            'access_token' => 'IG-LONG-LIVED',
            'status'       => ChannelConnection::STATUS_ENABLED,
        ]);

        $this->postJson('/meta/instagram/deauthorize', [
            'signed_request' => $this->sign(['user_id' => self::IG_ID], 'IG-SECRET'),
        ])->assertOk();

        $conn = ChannelConnection::where('external_id', self::IG_ID)->first();

        // Disabled, not deleted: the conversation history belongs to the
        // business, and someone who reconnects next week expects it intact.
        $this->assertSame(ChannelConnection::STATUS_DISABLED, $conn->status);
        $this->assertNull($conn->access_token, 'A revoked token must not be kept.');
    }

    /** Build a valid Meta signed_request. */
    private function sign(array $payload, string $secret): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode(
            $payload + ['algorithm' => 'HMAC-SHA256'],
        )), '+/', '-_'), '=');

        $sig = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $encoded, $secret, true),
        ), '+/', '-_'), '=');

        return $sig . '.' . $encoded;
    }
}
