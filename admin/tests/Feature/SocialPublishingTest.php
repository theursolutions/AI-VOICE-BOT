<?php

namespace Tests\Feature;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\InstagramLoginService;
use Msd\MetaChannels\Services\OAuthService;
use Tests\TestCase;

/**
 * Social Posts: publishing to Facebook Pages and Instagram.
 *
 * Meta is faked at the HTTP layer, so these pin down what we SEND (the
 * endpoints and fields Meta requires) and how each answer moves a target
 * through pending → processing → published / failed.
 */
class SocialPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = 9101;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function connection(string $provider, string $externalId, array $scopes = []): ChannelConnection
    {
        return ChannelConnection::create([
            'project_id'   => self::PROJECT_ID,
            'provider'     => $provider,
            'external_id'  => $externalId,
            'name'         => $provider === 'instagram' ? '@shop' : 'Shop Page',
            'access_token' => 'PAGE-TOKEN',
            'token_scopes' => $scopes,
            'status'       => ChannelConnection::STATUS_ENABLED,
            'metadata'     => [],
        ]);
    }

    private function makePost(array $attrs, array $connections): SocialPost
    {
        $post = SocialPost::create($attrs + [
            'project_id' => self::PROJECT_ID,
            'format'     => SocialPost::FORMAT_POST,
            'caption'    => 'Weekend sale #shoes',
            'media'      => [],
            'status'     => SocialPost::STATUS_PUBLISHING,
        ]);
        foreach ($connections as $c) {
            $post->targets()->create([
                'channel_connection_id' => $c->id,
                'provider'              => $c->provider,
                'account_name'          => $c->name,
                'status'                => SocialPostTarget::STATUS_PENDING,
            ]);
        }

        return $post;
    }

    private function image(): array
    {
        return ['path' => 'social/9101/a.jpg', 'type' => 'image', 'mime' => 'image/jpeg', 'size' => 10];
    }

    private function video(): array
    {
        return ['path' => 'social/9101/v.mp4', 'type' => 'video', 'mime' => 'video/mp4', 'size' => 10];
    }

    public function test_facebook_photo_post_is_published_with_its_permalink(): void
    {
        Http::fake([
            '*/111/photos'    => Http::response(['id' => 'PH1', 'post_id' => '111_222']),
            '*/111_222*'      => Http::response(['permalink_url' => 'https://facebook.com/111/posts/222']),
        ]);

        $fb   = $this->connection('facebook_page', '111');
        $post = $this->makePost(['media' => [$this->image()]], [$fb]);

        app(SocialPublisher::class)->publish($post);

        $t = $post->targets()->first();
        $this->assertSame('published', $t->status);
        $this->assertSame('111_222', $t->remote_id);
        $this->assertSame('https://facebook.com/111/posts/222', $t->permalink);
        $this->assertSame('published', $post->fresh()->status);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/111/photos')
            && $r['caption'] === 'Weekend sale #shoes'
            && str_contains($r['url'], 'social/9101/a.jpg'));
    }

    public function test_facebook_multi_photo_uploads_unpublished_then_attaches_to_one_post(): void
    {
        $n = 0;
        Http::fake([
            '*/111/photos' => function () use (&$n) { return Http::response(['id' => 'PH' . (++$n)]); },
            '*/111/feed'   => Http::response(['id' => '111_999']),
            '*/111_999*'   => Http::response([]),
        ]);

        $fb   = $this->connection('facebook_page', '111');
        $post = $this->makePost(['media' => [$this->image(), $this->image()]], [$fb]);

        app(SocialPublisher::class)->publish($post);

        $this->assertSame('published', $post->targets()->first()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/111/photos') && $r['published'] === false);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/111/feed')
            && $r['attached_media'] === [['media_fbid' => 'PH1'], ['media_fbid' => 'PH2']]);
    }

    public function test_instagram_reel_waits_for_processing_then_publishes(): void
    {
        $status = 'IN_PROGRESS';
        Http::fake([
            '*/1789/media_publish' => Http::response(['id' => 'IGMEDIA']),
            '*/1789/media'         => Http::response(['id' => 'CONTAINER']),
            '*/CONTAINER*'         => function () use (&$status) { return Http::response(['status_code' => $status]); },
            '*/IGMEDIA*'           => Http::response(['permalink' => 'https://instagram.com/reel/x']),
        ]);

        $ig   = $this->connection('instagram', '1789');
        $post = $this->makePost(['format' => SocialPost::FORMAT_REEL, 'media' => [$this->video()]], [$ig]);

        app(SocialPublisher::class)->publish($post);

        $t = $post->targets()->first();
        $this->assertSame('processing', $t->status, 'a video still processing must wait, not fail');
        $this->assertSame(['stage' => 'container', 'container' => 'CONTAINER'], $t->state);
        $this->assertSame('publishing', $post->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1789/media')
            && $r['media_type'] === 'REELS' && str_contains($r['video_url'], 'v.mp4'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));

        // Next sweep: Meta has finished.
        $status = 'FINISHED';
        $this->artisan('social:publish-due')->assertSuccessful();

        $t->refresh();
        $this->assertSame('published', $t->status);
        $this->assertSame('IGMEDIA', $t->remote_id);
        $this->assertSame('https://instagram.com/reel/x', $t->permalink);
        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_instagram_rejection_keeps_metas_reason_and_marks_the_post_partial(): void
    {
        Http::fake([
            '*/111/photos' => Http::response(['id' => 'PH1', 'post_id' => '111_1']),
            '*/111_1*'     => Http::response([]),
            '*/1789/media' => Http::response(['error' => [
                'message'        => 'Invalid parameter',
                'error_user_msg' => 'The aspect ratio is not supported.',
            ]], 400),
        ]);

        $fb   = $this->connection('facebook_page', '111');
        $ig   = $this->connection('instagram', '1789');
        $post = $this->makePost(['media' => [$this->image()]], [$fb, $ig]);

        app(SocialPublisher::class)->publish($post);

        $igTarget = $post->targets()->where('provider', 'instagram')->first();
        $this->assertSame('failed', $igTarget->status);
        $this->assertSame('The aspect ratio is not supported.', $igTarget->error);
        $this->assertSame('published', $post->targets()->where('provider', 'facebook_page')->first()->status);
        $this->assertSame('partial', $post->fresh()->status);
    }

    public function test_instagram_carousel_is_built_only_after_every_item_is_ready(): void
    {
        $n = 0;
        Http::fake([
            '*/1789/media_publish' => Http::response(['id' => 'IGMEDIA']),
            '*/1789/media'         => function (Request $r) use (&$n) {
                return Http::response(['id' => ($r['media_type'] ?? null) === 'CAROUSEL' ? 'PARENT' : 'CHILD' . (++$n)]);
            },
            '*/CHILD*'  => Http::response(['status_code' => 'FINISHED']),
            '*/PARENT*' => Http::response(['status_code' => 'FINISHED']),
            '*/IGMEDIA*'=> Http::response(['permalink' => 'https://instagram.com/p/x']),
        ]);

        $ig   = $this->connection('instagram', '1789');
        $post = $this->makePost(['media' => [$this->image(), $this->image(), $this->image()]], [$ig]);

        app(SocialPublisher::class)->publish($post);

        $this->assertSame('published', $post->targets()->first()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1789/media')
            && ($r['media_type'] ?? null) === 'CAROUSEL' && $r['children'] === 'CHILD1,CHILD2,CHILD3'
            && $r['caption'] === 'Weekend sale #shoes');
        Http::assertSent(fn (Request $r) => ($r['is_carousel_item'] ?? false) === true && ! isset($r['caption']));
    }

    public function test_instagram_without_media_fails_without_calling_meta(): void
    {
        Http::fake();

        $ig   = $this->connection('instagram', '1789');
        $post = $this->makePost(['media' => []], [$ig]);

        app(SocialPublisher::class)->publish($post);

        $this->assertSame('failed', $post->targets()->first()->status);
        $this->assertStringContainsString('photo or video', $post->targets()->first()->error);
        Http::assertNothingSent();
    }

    public function test_a_target_is_never_published_twice(): void
    {
        Http::fake([
            '*/111/feed' => Http::response(['id' => '111_5']),
            '*/111_5*'   => Http::response([]),
        ]);

        $fb   = $this->connection('facebook_page', '111');
        $post = $this->makePost(['media' => []], [$fb]);

        app(SocialPublisher::class)->publish($post);
        app(SocialPublisher::class)->start($post->targets()->first(), $post);

        Http::assertSentCount(2);   // one feed post + one permalink lookup, nothing more
    }

    public function test_due_scheduled_posts_are_queued_and_future_ones_are_not(): void
    {
        Bus::fake();

        $fb  = $this->connection('facebook_page', '111');
        $due = $this->makePost(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->subMinute()], [$fb]);
        $later = $this->makePost(['status' => SocialPost::STATUS_SCHEDULED, 'scheduled_at' => now()->addHour()], [$fb]);

        $this->artisan('social:publish-due')->assertSuccessful();

        Bus::assertDispatched(\App\Jobs\PublishSocialPost::class, fn ($j) => $j->postId === $due->id);
        Bus::assertNotDispatched(\App\Jobs\PublishSocialPost::class, fn ($j) => $j->postId === $later->id);
        $this->assertSame('publishing', $due->fresh()->status);
        $this->assertSame('scheduled', $later->fresh()->status);
    }

    public function test_publish_purpose_adds_posting_scopes_and_plain_connect_does_not(): void
    {
        config([
            'meta.app.id'     => 'APP', 'meta.app.secret' => 'SECRET',
            'meta.app.scopes.facebook_page' => 'pages_show_list,pages_messaging',
            'meta.app.publish_scopes.facebook_page' => 'pages_manage_posts,pages_read_engagement',
            'meta.instagram.scopes' => 'instagram_business_basic',
        ]);

        $plain   = (new OAuthService())->authUrl('facebook_page', 'https://x/cb', 'S');
        $publish = (new OAuthService())->authUrl('facebook_page', 'https://x/cb', 'S', ['pages_manage_posts', 'pages_read_engagement']);

        parse_str(parse_url($plain, PHP_URL_QUERY), $p);
        parse_str(parse_url($publish, PHP_URL_QUERY), $q);
        $this->assertSame('pages_show_list,pages_messaging', $p['scope']);
        $this->assertSame('pages_show_list,pages_messaging,pages_manage_posts,pages_read_engagement', $q['scope']);

        $ig = (new InstagramLoginService())->authUrl('https://x/ig', 'S', ['instagram_business_content_publish']);
        parse_str(parse_url($ig, PHP_URL_QUERY), $i);
        $this->assertSame('instagram_business_basic,instagram_business_content_publish', $i['scope']);
    }

    public function test_connection_reports_missing_publish_scopes(): void
    {
        config(['meta.app.publish_scopes.facebook_page' => 'pages_manage_posts,pages_read_engagement']);

        $messagingOnly = $this->connection('facebook_page', '111', ['pages_messaging', 'pages_read_engagement']);
        $granted       = $this->connection('facebook_page', '112', ['pages_messaging', 'pages_manage_posts', 'pages_read_engagement']);
        $unknown       = $this->connection('facebook_page', '113', []);

        $this->assertSame(['pages_manage_posts'], $messagingOnly->missingPublishScopes());
        $this->assertSame([], $granted->missingPublishScopes());
        $this->assertNull($unknown->missingPublishScopes());
    }

    // ── Plan gating ──────────────────────────────────────────────────

    /** A workspace on $planSlug with one project, returning [client, projectId]. */
    private function workspaceOn(string $planSlug): array
    {
        // A fresh test DB has no catalogue, and the allocation migration ran
        // before there were plans to grant to. Seed, then run it for real.
        if (! Plan::where('slug', 'growth')->exists()) {
            $this->seed(\Database\Seeders\BillingSeeder::class);
            (require database_path('migrations/2026_10_16_100000_add_social_posts_feature.php'))->up();
        }

        $plan = Plan::where('slug', $planSlug)->first();
        if (! $plan) {
            $this->markTestSkipped("Plan [{$planSlug}] not seeded.");
        }

        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Social Co', 'slug' => 'social-' . uniqid(), 'client_api_key' => 'k-' . uniqid(),
            'created_at' => time(), 'updated_at' => time(),
        ]);
        Subscription::create([
            'client_id' => $clientId, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE,
            'interval' => 'monthly', 'currency' => 'usd',
        ]);
        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Social project', 'client_id' => $clientId,
        ]);

        return [Client::find($clientId), $projectId];
    }

    public function test_social_posts_is_growth_and_above_only(): void
    {
        $features = app(\App\Services\Billing\PlanFeatureService::class);
        $features->flush();

        foreach (['free' => false, 'starter' => false, 'growth' => true, 'scale' => true, 'enterprise' => true] as $slug => $allowed) {
            [$client] = $this->workspaceOn($slug);
            $this->assertSame($allowed, $features->clientHasModule($client, 'social'), "plan {$slug}");
        }
    }

    public function test_a_scheduled_post_does_not_publish_after_a_downgrade(): void
    {
        Http::fake();
        [, $projectId] = $this->workspaceOn('starter');

        $fb = ChannelConnection::create([
            'project_id' => $projectId, 'provider' => 'facebook_page', 'external_id' => '555',
            'name' => 'Page', 'access_token' => 'T', 'status' => ChannelConnection::STATUS_ENABLED,
        ]);
        $post = $this->makePost(['project_id' => $projectId], [$fb]);

        (new \App\Jobs\PublishSocialPost($post->id))->handle(app(SocialPublisher::class));

        $t = $post->targets()->first();
        $this->assertSame('failed', $t->status);
        $this->assertStringContainsString('not included in your current plan', $t->error);
        $this->assertSame('failed', $post->fresh()->status);
        Http::assertNothingSent();
    }
}
