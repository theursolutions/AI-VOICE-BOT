<?php

namespace Tests\Feature;

use App\Mail\OpsAlertMail;
use App\Models\Client;
use App\Models\ContactLead;
use App\Models\OpsNotification;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\GatewayCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Team alerts: new users, clients, projects, website contacts and payments
 * land in the super-admin notification panel (one tab each) and are emailed
 * to the ops inbox. Plus the Website Contacts Excel export.
 */
class OpsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ops_alerts.mail' => true]);   // off for the rest of the suite (phpunit.xml)
    }

    private function superAdmin(): User
    {
        $admin = User::create(['name' => 'Ops', 'email' => 'ops@serveai.test', 'password' => bcrypt('password')]);
        $admin->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

        return $admin;
    }

    private function client(): Client
    {
        return Client::create(['name' => 'Acme Foods', 'slug' => 'acme', 'client_api_key' => bin2hex(random_bytes(8)),
            'is_active' => 'Yes', 'billing_email' => 'pay@acme.test', 'created_at' => time(), 'updated_at' => time()]);
    }

    public function test_new_users_clients_projects_and_contacts_reach_the_panel_and_the_inbox(): void
    {
        Mail::fake();

        User::create(['name' => 'Sara Khan', 'email' => 'sara@acme.test', 'password' => bcrypt('x')]);
        $client = $this->client();
        Project::create(['name' => 'Acme support', 'client_id' => $client->id, 'project_api_key' => 'k1', 'project_api_secret' => 's']);
        $this->postJson('/api/contact', ['name' => 'Bilal', 'phone' => '+92 300 1234567',
            'message' => "Need a voice agent for our 3 branches.\nPlease call after 5pm."])->assertOk();

        $this->assertSame(['client', 'contact', 'project', 'user'],
            OpsNotification::orderBy('kind')->pluck('kind')->all());
        $this->assertSame('New user: Sara Khan', OpsNotification::where('kind', 'user')->value('title'));
        $this->assertSame('Acme Foods', OpsNotification::where('kind', 'project')->first()->detail('Client'));

        Mail::assertQueued(OpsAlertMail::class, 4);
        Mail::assertQueued(OpsAlertMail::class, fn (OpsAlertMail $m) => $m->hasTo('info@serveai.com.pk')
            && $m->title === 'New website contact: Bilal'
            && str_contains($m->details['Message'], 'Please call after 5pm.')
            && $m->details['Phone'] !== '');

        // The email itself renders, message included.
        $mail = new OpsAlertMail('New website contact: Bilal', 'x', ['Message' => 'Please call after 5pm.'], 'https://x.test', 'now');
        $this->assertStringContainsString('Please call after 5pm.', $mail->render());
    }

    public function test_a_paid_charge_is_announced_once_with_the_amount(): void
    {
        Mail::fake();
        $client = $this->client();
        OpsNotification::query()->delete();

        app(GatewayCheckoutService::class)->applyPaidCharge((object) [
            'id' => 1, 'client_id' => $client->id, 'plan_price_id' => null, 'amount_cents' => 1200000,
            'currency' => 'PKR', 'gateway' => 'safepay', 'reference' => 'SP-TEST-1', 'purpose' => 'plan',
            'interval' => 'monthly', 'period_end' => null,
        ]);

        $n = OpsNotification::where('kind', 'payment')->sole();
        $this->assertStringContainsString('Acme Foods', $n->title);
        $this->assertStringContainsString('12,000', $n->title);
        $this->assertSame('Safepay', $n->detail('Gateway'));
        Mail::assertQueued(OpsAlertMail::class, fn (OpsAlertMail $m) => str_starts_with($m->title, 'Payment received'));
    }

    public function test_the_panel_has_a_tab_per_kind_and_remembers_what_each_admin_read(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $this->client();
        ContactLead::create(['phone' => '+923001112222', 'source' => 'reach_out', 'status' => 'new']);

        $this->actingAs($admin);
        $feed = $this->getJson(route('ops.notifications.feed'))->assertOk();
        $this->assertSame(1, $feed->json('counts.client'));
        $this->assertSame(1, $feed->json('counts.contact'));
        $this->assertSame(1, $feed->json('counts.user'));    // the admin's own account
        $this->assertCount(1, $this->getJson(route('ops.notifications.feed', ['kind' => 'contact']))->json('items'));

        $this->postJson(route('ops.notifications.read'), ['kind' => 'contact'])->assertOk()
            ->assertJsonPath('counts.contact', 0)->assertJsonPath('counts.client', 1);

        // Opening one marks it read and goes where it points.
        $client = OpsNotification::where('kind', 'client')->sole();
        $this->get(route('ops.notifications.open', $client->id))->assertRedirect($client->url);
        $this->assertSame(0, OpsNotification::unreadCounts($admin->id)['client']);

        $this->get(route('ops.notifications.index', ['kind' => 'payment']))->assertOk()->assertSee('Nothing here yet');
        $this->get(route('ops.contacts.index'))->assertOk()->assertSee('tva-noti', false);

        // Not for workspace users.
        $user = User::create(['name' => 'U', 'email' => 'u@acme.test', 'password' => bcrypt('x')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->getJson(route('ops.notifications.feed'))->assertNotFound();
    }

    public function test_website_contacts_export_to_excel_with_full_messages(): void
    {
        Mail::fake();
        $long = str_repeat('We run 4 clinics and need bookings by phone. ', 8) . 'Call me = soon & thanks <3';
        ContactLead::create(['name' => 'Ayesha', 'phone' => '+923001234567', 'message' => $long, 'source' => 'contact_form', 'status' => 'new']);
        ContactLead::create(['phone' => '+923009999999', 'source' => 'reach_out', 'status' => 'closed',
            'subject' => 'Reach-out request from the website']);

        $this->actingAs($this->superAdmin());
        $this->get(route('ops.contacts.index'))->assertOk()->assertSee('Export to Excel')->assertSee('Read all')
            ->assertSee('No message: left a phone number');

        $resp = $this->get(route('ops.contacts.export', ['status' => 'new']))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file = $resp->baseResponse->getFile()->getPathname();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file) === true, 'a valid xlsx (zip)');
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertNotFalse(simplexml_load_string($sheet), 'well-formed sheet XML');
        $this->assertStringContainsString('Message', $sheet);
        $this->assertStringContainsString(htmlspecialchars('Call me = soon & thanks <3', ENT_XML1), $sheet);
        $this->assertStringNotContainsString('+923009999999', $sheet, 'status filter applies');
    }
}
