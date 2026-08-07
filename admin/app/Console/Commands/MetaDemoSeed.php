<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Console\Command;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Seed demo Meta conversations + messages (all types) so the Messages
 * console can be previewed without live Meta traffic. Idempotent: clears
 * prior demo data (metadata.demo=true) and re-creates it.
 *
 *   php artisan meta:demo-seed {project?}     (defaults to first active project)
 *   php artisan meta:demo-seed --clear        (remove demo data only)
 */
class MetaDemoSeed extends Command
{
    protected $signature = 'meta:demo-seed {project? : Project ID} {--clear : Remove demo data and exit}';
    protected $description = 'Seed demo WhatsApp/IG/FB conversations for the Messages console preview.';

    private int $t;   // running timestamp

    public function handle(TenantManager $tenants): int
    {
        $project = $this->argument('project')
            ? Project::find((int) $this->argument('project'))
            : Project::where('is_active', 'Yes')->orderBy('id')->first();

        if (!$project) {
            $this->error('No project found.');
            return self::FAILURE;
        }
        $tenants->useFor($project);
        $pid = $project->id;
        $this->info("Project #{$pid} ({$project->name})");

        // ── clear prior demo data ──
        $old = Session::where('project_id', $pid)->where('metadata->demo', true)->pluck('id');
        if ($old->isNotEmpty()) {
            Message::whereIn('session_id', $old)->delete();
            Session::whereIn('id', $old)->forceDelete();
            $this->line("  cleared {$old->count()} demo conversation(s)");
        }
        if ($this->option('clear')) {
            $this->info('Done (cleared).');
            return self::SUCCESS;
        }

        // ── demo channel connections (so the Channels page shows them) ──
        $accounts = ['whatsapp' => '15550001111', 'instagram' => '17841400000000000', 'facebook' => '1098765432100000'];
        $this->ensureConnection($pid, ChannelConnection::PROVIDER_WHATSAPP, $accounts['whatsapp'], 'Demo WhatsApp +1 555 000 1111');
        $this->ensureConnection($pid, ChannelConnection::PROVIDER_INSTAGRAM, $accounts['instagram'], 'Demo Instagram @ourstore');
        $this->ensureConnection($pid, ChannelConnection::PROVIDER_FACEBOOK_PAGE, $accounts['facebook'], 'Demo Facebook Page');

        $now = time();

        // ── Conversation 1: WhatsApp, OPEN, unread, bot ON, rich media ──
        $this->t = $now - 5400;
        $s = $this->session($pid, 'whatsapp', $accounts['whatsapp'], '923001234567', 'Ayesha Khan', [
            'last_inbound_at' => $now - 1800, 'profile_pic' => 'https://i.pravatar.cc/100?img=47',
        ]);
        $this->in($s, 'Hi! Do you deliver to Lahore?');
        $this->bot($s, 'Hello Ayesha! 👋 Yes, we deliver across Lahore within 2–3 days.');
        $this->in($s, 'Great. Here is the product I want 👇');
        $this->in($s, '', [['type' => 'image', 'url' => 'https://picsum.photos/seed/bag/400/300', 'mime' => 'image/jpeg']]);
        $this->in($s, '', [['type' => 'audio', 'url' => 'https://www.w3schools.com/html/horse.ogg', 'mime' => 'audio/ogg']], 'Voice note: "Can I get this in black, large size?"');
        $this->agent($s, 'Yes, black in large is in stock. Want me to reserve it?');
        $this->in($s, '', [['type' => 'document', 'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf', 'mime' => 'application/pdf', 'filename' => 'my-address.pdf']]);
        $this->agentInteractive($s, 'Shall I place the order?', ['Place order', 'Change size', 'Cancel']);
        $this->in($s, 'Place order');   // interactive button reply

        // ── Conversation 2: Instagram, OPEN, bot PAUSED (human) ──
        $this->t = $now - 3600;
        $s = $this->session($pid, 'instagram', $accounts['instagram'], 'ig_8830024', 'fashionista_22', [
            'last_inbound_at' => $now - 600, 'bot_paused' => true, 'profile_pic' => 'https://i.pravatar.cc/100?img=32',
        ]);
        $this->in($s, 'Loved your latest reel! Is the dress available?');
        $this->agent($s, 'Thank you so much! 😍 Yes it is — sending you a photo.');
        $this->agent($s, '', [['type' => 'image', 'url' => 'https://picsum.photos/seed/dress/400/400', 'mime' => 'image/jpeg', 'outbound' => true]]);

        // ── Conversation 3: Facebook, EXPIRED window ──
        $this->t = $now - 120000;
        $s = $this->session($pid, 'facebook', $accounts['facebook'], 'fb_553201', 'John Doe', [
            'last_inbound_at' => $now - 108000, // 30h ago → window closed
        ]);
        $this->in($s, 'Is my warranty claim approved?');
        $this->bot($s, 'Let me check that for you, John. One moment…');

        // ── Conversation 4: WhatsApp, OPEN — Flow + catalog order journey ──
        $this->t = $now - 2400;
        $s = $this->session($pid, 'whatsapp', $accounts['whatsapp'], '923009998877', 'Bilal Traders', [
            'last_inbound_at' => $now - 300,
        ]);
        $this->in($s, 'I want to order 3 items for my shop.');
        $this->agent($s, 'Sure! Please fill this quick order form 👇' . "\n[form: Start order]");
        $this->in($s, 'Form submitted — product: Cotton Shirt, quantity: 3, address: Hall Road Lahore, payment: COD');
        $this->agent($s, '🛒 Here are the items you selected (3 items)');
        $this->bot($s, 'Your order #SO-10432 is confirmed. Total Rs. 4,500, COD. Thank you! 🎉');

        $this->info('Seeded 4 demo conversations. Open: Messages → project "' . $project->name . '".');
        return self::SUCCESS;
    }

    private function ensureConnection(int $pid, string $provider, string $externalId, string $name): void
    {
        ChannelConnection::firstOrCreate(
            ['project_id' => $pid, 'provider' => $provider, 'external_id' => $externalId],
            ['name' => $name, 'status' => ChannelConnection::STATUS_ENABLED, 'metadata' => ['demo' => true]],
        );
    }

    private function session(int $pid, string $channel, string $account, string $extId, string $name, array $opts = []): Session
    {
        $now = time();
        $meta = ['demo' => true, 'meta' => array_filter([
            'provider'    => $channel === 'instagram' ? 'instagram' : ($channel === 'facebook' ? 'facebook_page' : 'whatsapp'),
            'channel_id'  => $account,
            'profile_pic' => $opts['profile_pic'] ?? null,
            'bot_paused'  => $opts['bot_paused'] ?? null,
        ])];
        return Session::create([
            'project_id'       => $pid,
            'channel'          => $channel,
            'channel_account'  => $account,
            'external_id'      => $extId,
            'customer_name'    => $name,
            'customer_phone'   => $channel === 'whatsapp' ? $extId : null,
            'status'           => 'active',
            'started_at'       => $this->t,
            'last_activity_at' => $opts['last_inbound_at'] ?? $now,
            'last_inbound_at'  => $opts['last_inbound_at'] ?? $now,
            'metadata'         => $meta,
            'created_at'       => $this->t,
            'update_at'        => $now,
        ]);
    }

    private function in(Session $s, string $text, array $att = [], string $extraText = ''): void
    {
        $this->msg($s, 'user', $text !== '' ? $text : $extraText, null, $att);
    }
    private function bot(Session $s, string $text, array $att = []): void
    {
        $this->msg($s, 'assistant', $text, null, $att);
    }
    private function agent(Session $s, string $text, array $att = []): void
    {
        $this->msg($s, 'assistant', $text, 'agent', $att);
    }
    private function agentInteractive(Session $s, string $body, array $buttons): void
    {
        $this->msg($s, 'assistant', $body . "\n[" . implode(' · ', $buttons) . ']', 'agent');
    }

    private function msg(Session $s, string $role, string $content, ?string $author, array $att = []): void
    {
        $this->t += rand(40, 180);
        Message::create([
            'session_id' => $s->id,
            'project_id' => $s->project_id,
            'role'       => $role,
            'content'    => $content !== '' ? $content : null,
            'metadata'   => array_filter(['author' => $author, 'attachments' => $att ?: null, 'demo' => true]),
            'created_at' => $this->t,
        ]);
    }
}
