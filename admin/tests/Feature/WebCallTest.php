<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Role;
use App\Models\SipExtension;
use App\Models\User;
use App\Models\WebCallSession;
use App\Services\Telephony\SipServer;
use App\Services\Telephony\WebCalls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Website "Call us" button and client SIP extensions: who may call, how
 * often, and that every account lands on Asterisk only in a context with
 * no outside line. The voice engine (which talks to Asterisk) is faked.
 */
class WebCallTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.python.base_url' => 'http://engine.test', 'services.python.internal_secret' => 'sekret']);
        Http::fake(['engine.test/internal/sip/*' => Http::response(['ok' => true, 'accounts' => []])]);

        $this->client = Client::create([
            'name' => 'serveAI', 'slug' => 'serveai', 'client_api_key' => bin2hex(random_bytes(8)),
            'is_active' => 'Yes', 'created_at' => time(), 'updated_at' => time(),
        ]);
        $this->project = Project::create([
            'name' => 'serveAI demo', 'client_id' => $this->client->id,
            'project_api_key' => 'demo-key-123', 'project_api_secret' => bin2hex(random_bytes(8)),
        ]);
        app(WebCalls::class)->save($this->project, [
            'enabled' => true, 'allowed_origins' => ['serveai.com.pk'],
            'max_seconds' => 60, 'calls_per_day' => 3, 'gap_hours' => 4,
        ]);
    }

    private function ask(string $origin = 'https://serveai.com.pk', string $ip = '203.0.113.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['Origin' => $origin])
            ->postJson('/api/webcall/session', ['key' => 'demo-key-123', 'page' => 'https://serveai.com.pk/']);
    }

    private function pastCall(string $hoursAgo, string $ip = '203.0.113.7'): void
    {
        WebCallSession::create([
            'project_id' => $this->project->id, 'client_id' => $this->client->id, 'ip' => $ip,
            'sip_username' => 'w' . bin2hex(random_bytes(6)), 'secret' => 'x', 'status' => WebCallSession::ENDED,
            'expires_at' => now()->subHours($hoursAgo), 'started_at' => now()->subHours($hoursAgo),
        ]);
    }

    public function test_a_visitor_gets_a_one_call_account_that_can_only_reach_the_ai(): void
    {
        // Callable from the website's own pages (no cookies, so any origin header works).
        $resp = $this->ask()->assertOk()->assertHeader('Access-Control-Allow-Origin');

        $this->assertStringStartsWith('w', $resp->json('username'));
        $this->assertSame(60, $resp->json('max_seconds'));
        $this->assertNotEmpty($resp->json('ice_servers'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/internal/sip/accounts/' . $resp->json('username'))
            && $r['context'] === 'web-guest' && $r['kind'] === 'guest'
            && $r->header('X-Internal-Secret')[0] === 'sekret');
        $this->assertDatabaseHas('web_call_sessions', ['sip_username' => $resp->json('username'), 'status' => 'issued']);
    }

    public function test_other_websites_cannot_use_the_button(): void
    {
        $this->ask('https://evil.example')->assertStatus(403)->assertJson(['reason' => 'origin']);
        $this->ask('https://www.serveai.com.pk')->assertOk();  // sub-domains are fine
    }

    public function test_three_calls_a_day_four_hours_apart(): void
    {
        // One call an hour ago: the next has to wait ~3 hours.
        $this->pastCall(1);
        $resp = $this->ask()->assertStatus(429)->assertJson(['reason' => 'too_soon']);
        $this->assertEqualsWithDelta(3 * 3600, $resp->json('retry_after'), 120);
        $this->assertStringContainsString('3 hours', $resp->json('message'));

        // Another visitor is not affected.
        $this->ask(ip: '198.51.100.9')->assertOk();
    }

    public function test_the_fourth_call_of_the_day_is_refused(): void
    {
        $this->pastCall(13);
        $this->pastCall(9);
        $this->ask()->assertOk();  // 2 used, last one 9 h ago
        $this->pastCall(5);
        $this->ask()->assertStatus(429)->assertJson(['reason' => 'daily_limit']);
    }

    public function test_a_switched_off_button_issues_nothing(): void
    {
        app(WebCalls::class)->save($this->project, ['enabled' => false]);
        $this->ask()->assertStatus(403)->assertJson(['reason' => 'disabled']);
        Http::assertNothingSent();
    }

    public function test_an_account_starts_one_call_only_and_not_after_it_expires(): void
    {
        $calls = app(WebCalls::class);
        $username = $this->ask()->json('username');

        $this->assertNotNull($calls->start($username));
        $this->assertNull($calls->start($username), 'used twice');

        $late = $this->ask(ip: '198.51.100.10')->json('username');
        WebCallSession::where('sip_username', $late)->update(['expires_at' => now()->subMinute()]);
        $this->assertNull($calls->start($late), 'expired');
        $this->assertSame(1, $calls->prune());
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), $late));
    }

    public function test_browsers_get_turn_when_it_is_configured(): void
    {
        app(SipServer::class)->save(['ws_url' => 'wss://sip.serveai.com.pk/ws', 'host' => 'sip.serveai.com.pk', 'port' => 5060,
            'stun' => ['stun:stun.l.google.com:19302'], 'turn_url' => 'turn:turn.serveai.com.pk:3478',
            'turn_username' => 'web', 'turn_credential' => 'pw']);
        $resp = $this->ask()->assertOk();

        $this->assertSame('wss://sip.serveai.com.pk/ws', $resp->json('ws_url'));
        $this->assertSame('sip.serveai.com.pk', $resp->json('domain'));
        $this->assertSame(['turn:turn.serveai.com.pk:3478'], $resp->json('ice_servers.1.urls'));
    }

    // ── Extensions ──────────────────────────────────────────────────────

    private function owner(): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@serveai.test', 'password' => bcrypt('password')]);
        $role = Role::create(['client_id' => $this->client->id, 'name' => 'Owner', 'modules' => ['*'],
                              'is_owner' => true, 'created_at' => time(), 'updated_at' => time()]);
        $user->attachMembership($this->client->id, null, $user->id, $role->id);
        $user->forceFill(['active_client_id' => $this->client->id, 'email_verified_at' => now()])->save();

        return $user->fresh();
    }

    public function test_the_client_pages_render(): void
    {
        $this->actingAs($this->owner());
        $this->get(route('telephony.extensions', ['client' => $this->client]))->assertOk()->assertSee('Add an extension');
        $this->get(route('telephony.web-call', ['client' => $this->client]))->assertOk()
            ->assertSee('demo-key-123')->assertSee('webcall/call.js');
    }

    public function test_super_admin_sees_how_many_people_tried_the_demo_call(): void
    {
        $this->pastCall(30, '203.0.113.1');
        $this->pastCall(2, '203.0.113.1');
        $this->pastCall(1, '198.51.100.2');
        $admin = User::create(['name' => 'Ops', 'email' => 'ops@serveai.test', 'password' => bcrypt('password')]);
        $admin->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

        $this->actingAs($admin)->get(route('ops.telephony.web-calls'))->assertOk()
            ->assertViewHas('stats', fn ($s) => $s['calls'] === 3 && $s['visitors'] === 2 && $s['week'] === 3);
        $this->get(route('ops.telephony.sip'))->assertOk()->assertSee('SIP &amp; WebRTC server', false);
    }

    public function test_a_client_creates_an_extension_on_asterisk_under_its_own_prefix(): void
    {
        $this->actingAs($this->owner())
            ->post(route('telephony.extensions.store', ['client' => $this->client]), ['extension' => '2001', 'name' => 'Reception', 'kind' => 'app'])
            ->assertRedirect()->assertSessionHas('reveal');

        $ext = SipExtension::firstOrFail();
        $this->assertSame('c' . $this->client->id . '_2001', $ext->sip_username);
        $this->assertNotNull($ext->pushed_at);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/internal/sip/accounts/' . $ext->sip_username)
            && $r['context'] === 'internal-calls' && $r['kind'] === 'app' && strlen($r['password']) === 20);

        // Same number again → refused; 600 is the echo test.
        $this->post(route('telephony.extensions.store', ['client' => $this->client]), ['extension' => '2001', 'name' => 'x', 'kind' => 'app'])
            ->assertSessionHasErrors('extension');
        $this->post(route('telephony.extensions.store', ['client' => $this->client]), ['extension' => '600', 'name' => 'x', 'kind' => 'app'])
            ->assertSessionHasErrors('extension');

        $this->post(route('telephony.extensions.destroy', [$this->client, $ext->id]))->assertRedirect();
        $this->assertDatabaseMissing('sip_extensions', ['id' => $ext->id]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), $ext->sip_username));
    }
}
