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

    public function test_the_visitors_language_choice_is_kept_for_the_greeting(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeaders(['Origin' => 'https://serveai.com.pk'])
            ->postJson('/api/webcall/session', ['key' => 'demo-key-123', 'lang' => 'ur'])->assertOk();
        $this->assertSame('ur', WebCallSession::latest('id')->value('language'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])->withHeaders(['Origin' => 'https://serveai.com.pk'])
            ->postJson('/api/webcall/session', ['key' => 'demo-key-123', 'lang' => 'fr'])->assertStatus(422);
    }

    public function test_only_a_super_admin_sets_the_main_website_demo_call_length(): void
    {
        config(['services.landing.demo_key' => 'demo-key-123']);
        $form = ['enabled' => 1, 'max_seconds' => 90, 'calls_per_day' => 5, 'gap_hours' => 2];

        $this->actingAs($this->owner())->post(route('ops.telephony.web-calls.demo'), $form)->assertNotFound();

        $admin = User::create(['name' => 'Ops', 'email' => 'ops3@serveai.test', 'password' => bcrypt('password')]);
        $admin->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($admin)->get(route('ops.telephony.web-calls'))->assertOk()->assertSee('Main website demo call');
        $this->post(route('ops.telephony.web-calls.demo'), $form)->assertRedirect()->assertSessionHas('success');

        $s = app(WebCalls::class)->settings($this->project->fresh());
        $this->assertSame([90, 5, true], [$s['max_seconds'], $s['calls_per_day'], $s['enabled']]);
        $this->assertEquals(2, $s['gap_hours']);
        $this->assertSame(['serveai.com.pk'], $s['allowed_origins'], 'other settings untouched');
        $this->assertSame(90, $this->ask()->json('max_seconds'));

        // Too short for the greeting and the goodbye.
        $this->post(route('ops.telephony.web-calls.demo'), ['max_seconds' => 10] + $form)->assertSessionHasErrors('max_seconds');
    }

    // ── Extensions ──────────────────────────────────────────────────────

    private function extension(string $number, string $kind): SipExtension
    {
        return SipExtension::create(['client_id' => $this->client->id, 'extension' => $number, 'name' => "Ext {$number}",
            'kind' => $kind, 'sip_username' => SipExtension::usernameFor($this->client->id, $number), 'secret' => 'pw-' . $number]);
    }

    public function test_the_browser_phone_takes_the_extension_number_people_know(): void
    {
        app(SipServer::class)->save(['ws_url' => 'wss://voice.serveai.test/sip-ws', 'host' => 'voice.serveai.test', 'port' => 5060,
            'stun' => [], 'turn_url' => '', 'turn_username' => '', 'turn_credential' => '']);
        $this->extension('9122', SipExtension::KIND_WEBRTC);
        $this->extension('2001', SipExtension::KIND_APP);
        $this->actingAs($this->owner());

        // "9122" and a guessed server address: saved as the real login on our server.
        $this->postJson(route('softphone.save'), ['extension' => '9122', 'password' => 'pw-9122', 'ws_url' => 'wss://voice.serveai.test:8080/ws'])
            ->assertOk()
            ->assertJson(['extension' => 'c' . $this->client->id . '_9122', 'ws_url' => 'wss://voice.serveai.test/sip-ws']);

        // A phone/app extension can't sign in from the browser: say so.
        $this->postJson(route('softphone.save'), ['extension' => '2001', 'password' => 'pw-2001'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Extension 2001 is set up for a phone or app. Use a "Browser phone" extension here (Telephony → Extensions).']);
    }

    public function test_a_browser_phone_login_saved_wrong_is_corrected_when_it_loads(): void
    {
        app(SipServer::class)->save(['ws_url' => 'wss://voice.serveai.test/sip-ws', 'host' => 'voice.serveai.test', 'port' => 5060,
            'stun' => [], 'turn_url' => '', 'turn_username' => '', 'turn_credential' => '']);
        $this->extension('9122', SipExtension::KIND_WEBRTC);
        $owner = $this->owner();
        \App\Models\UserPhoneAccount::create(['user_id' => $owner->id, 'extension' => '9122', 'sip_password' => 'pw-9122',
            'ws_url' => 'wss://voice.serveai.test:8080/ws']);

        $this->actingAs($owner)->getJson(route('softphone.config'))->assertOk()
            ->assertJson(['extension' => 'c' . $this->client->id . '_9122', 'ws_url' => 'wss://voice.serveai.test/sip-ws',
                          'password' => 'pw-9122', 'server_ws_url' => 'wss://voice.serveai.test/sip-ws']);
        $this->assertDatabaseHas('user_phone_accounts', ['user_id' => $owner->id, 'extension' => 'c' . $this->client->id . '_9122']);
    }

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

    public function test_only_a_super_admin_can_give_one_ip_more_demo_calls(): void
    {
        $this->pastCall(1);                              // blocked: 4 h gap
        $this->pastCall(1, '198.51.100.20');             // another visitor, also blocked
        $this->ask()->assertStatus(429);

        // A client owner can't reset limits (the super-admin area answers 404).
        $this->actingAs($this->owner())
            ->post(route('ops.telephony.web-calls.reset'), ['ip' => '203.0.113.7'])
            ->assertNotFound();
        $this->ask()->assertStatus(429);

        $admin = User::create(['name' => 'Ops', 'email' => 'ops2@serveai.test', 'password' => bcrypt('password')]);
        $admin->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($admin)
            ->post(route('ops.telephony.web-calls.reset'), ['ip' => '203.0.113.7'])
            ->assertRedirect()->assertSessionHas('success');

        $this->ask()->assertOk();                                       // that IP may call again
        $this->ask(ip: '198.51.100.20')->assertStatus(429);             // nobody else
        $this->assertSame(2, WebCallSession::whereNotNull('started_at')->count(), 'calls stay in the stats');
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
