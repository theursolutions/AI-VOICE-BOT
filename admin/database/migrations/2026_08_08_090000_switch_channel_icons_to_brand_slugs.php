<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The homepage channel strip now renders real brand logos (App\Support\BrandIcons)
 * instead of emoji, keyed by slug. config/site.php ships the new slugs as
 * defaults, but any environment where a super-admin has opened the content
 * editor already has `content.channelN_icon` rows in site_settings, and a
 * stored row always wins over the config default — so those sites would keep
 * showing 📞 / 🟢 / 📸 forever.
 *
 * This rewrites those rows, but ONLY where the stored value is still one of
 * the original emoji. A row an operator has deliberately changed to something
 * else is left exactly as it is.
 */
return new class extends Migration {
    /** Original config/site.php emoji => new BrandIcons slug. */
    private const MAP = [
        'content.channel1_icon' => ['📞', 'voice'],
        'content.channel2_icon' => ['💬', 'webchat'],
        'content.channel3_icon' => ['🟢', 'whatsapp'],
        'content.channel4_icon' => ['📸', 'instagram'],
        'content.channel5_icon' => ['👍', 'facebook'],
        'content.channel6_icon' => ['✉️', 'sms'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        foreach (self::MAP as $key => [$oldEmoji, $slug]) {
            DB::table('site_settings')
                ->where('key', $key)
                ->where('value', json_encode($oldEmoji, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->update(['value' => json_encode($slug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        foreach (self::MAP as $key => [$oldEmoji, $slug]) {
            DB::table('site_settings')
                ->where('key', $key)
                ->where('value', json_encode($slug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->update(['value' => json_encode($oldEmoji, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }
};
