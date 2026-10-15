<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A separate WhatsApp number next to the phone: set in Super Admin, shown as a chat link. */
class ContactWhatsappTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_typed_any_way_become_a_wa_me_link(): void
    {
        $this->assertSame('https://wa.me/923001234567', tva_whatsapp_link('+92 300 1234567'));
        $this->assertSame('https://wa.me/923001234567', tva_whatsapp_link('0300-1234567'));
        $this->assertSame('https://wa.me/923001234567', tva_whatsapp_link('00923001234567'));
        $this->assertSame('https://wa.me/971501234567?text=Hi%21', tva_whatsapp_link('+971 50 123 4567', 'Hi!'));
        $this->assertSame('', tva_whatsapp_link(''));
    }

    public function test_super_admin_sets_a_whatsapp_number_and_the_site_shows_it(): void
    {
        // Hidden until set.
        $this->get('/contact')->assertOk()->assertDontSee('wa.me', false);

        $admin = User::create(['name' => 'Ops', 'email' => 'ops@serveai.test', 'password' => bcrypt('x')]);
        $admin->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();
        $this->actingAs($admin)->get(route('ops.content.index'))->assertOk()->assertSee('contact_whatsapp');
        $fields = $this->get(route('ops.content.index'))->getContent();
        preg_match_all('/name="([a-z0-9_]+)"/', $fields, $m);
        $form = array_fill_keys(array_unique($m[1]), '');
        unset($form['_token']);
        $form = ['contact_phone' => '+92 42 111 222 333', 'contact_whatsapp' => '0349 1494383'] + $form;
        $this->post(route('ops.content.update'), $form)->assertRedirect();

        $page = $this->get('/contact')->assertOk();
        $page->assertSee('https://wa.me/923491494383', false)->assertSee('0349 1494383')->assertSee('+92 42 111 222 333');
        $this->get('/about')->assertOk()->assertSee('https://wa.me/923491494383', false);
    }
}
