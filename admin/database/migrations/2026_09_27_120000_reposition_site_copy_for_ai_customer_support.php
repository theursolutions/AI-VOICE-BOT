<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the September 2026 copy changes into environments where the SEO
 * console or content editor has been saved.
 *
 * Two changes ship in config/site.php:
 *
 *   • the homepage moves from "AI receptionist" to "AI customer support /
 *     AI agents" — title, description, H1, share card — and three channel
 *     claims the product does not support are corrected (outbound calls,
 *     Instagram story replies, SMS; email is what actually exists);
 *   • the brand is written "serveAI" everywhere, not "Serve AI".
 *
 * Saving /admin/seo writes EVERY seo.* field to site_settings, and a stored
 * row always beats the config default — so on production the new defaults
 * would never be seen. This rewrites a row only where it still holds the
 * exact old default. Anything an operator has written themselves is left
 * exactly as it is.
 *
 * The brand pass then replaces "Serve AI" inside any remaining seo.* or
 * content.* value, and on blog posts signed by the company.
 */
return new class extends Migration {
    /** key => [old default, new default] */
    private const MAP = [
        'seo.meta_title' => [
            'Serve AI — your AI receptionist that never sleeps',
            'AI Customer Support & AI Agents for Business | serveAI',
        ],
        'seo.meta_description' => [
            'Serve AI — AI receptionist + CRM that never sleeps. Voice calls, web chat, lead capture. Drop your data, watch it work.',
            'serveAI is AI customer support software: AI agents answer calls, web chat, WhatsApp, Instagram and Facebook 24/7 from your own data. Start free, no card.',
        ],
        'seo.meta_keywords' => [
            'AI receptionist, AI voice agent, CRM, lead capture, voice bot, chatbot, call automation',
            'AI customer support, AI customer service, AI support agent, AI chatbot for business, AI voice agent, WhatsApp AI chatbot, omnichannel customer support',
        ],
        'seo.og_title' => [
            'Serve AI — your AI receptionist that never sleeps',
            'serveAI — AI customer support agents for every channel',
        ],
        'seo.og_description' => [
            'Answers calls & chats 24/7 in your own cloned voice, qualifies leads, drops them in your CRM.',
            'AI agents that answer calls, web chat, WhatsApp, Instagram and Facebook from your own data, capture leads, and hand off to your team when it matters.',
        ],
        'content.blog_tagline' => [
            'Practical writing on AI receptionists, WhatsApp automation and turning conversations into customers.',
            'Practical writing on AI customer support, AI agents, voice and WhatsApp automation — and turning conversations into customers.',
        ],
        'content.hero_title' => [
            'Your AI receptionist that',
            'AI customer support agents that',
        ],
        'content.hero_title_accent' => [
            'never sleeps.',
            'never sleep.',
        ],
        'content.hero_subtitle' => [
            'Serve AI answers your calls and chats 24/7 in your own cloned voice, qualifies leads on the spot, and drops them straight into your CRM. Drop your data — watch it work.',
            'serveAI answers your customers on phone calls, web chat, WhatsApp, Instagram and Facebook 24/7 — from your own data, in their language — then captures the lead and hands anything tricky to your team.',
        ],
        'content.channel1_body' => [
            'Inbound & outbound phone, answered in a human voice.',
            'Inbound calls answered in a natural, human-sounding voice.',
        ],
        'content.channel4_body' => [
            'DMs and story replies handled automatically.',
            'Instagram DMs answered automatically, with your team a click away.',
        ],
        'content.channel6_icon' => ['sms', 'email'],
        'content.channel6_title' => ['SMS & more', 'Email'],
        'content.channel6_body' => [
            'Text fallback and new channels added over time.',
            'Customer emails land in the same shared inbox as every other conversation.',
        ],
        'content.footer_tagline' => [
            'The AI receptionist and CRM that answers every call, chat and message — 24/7, in your own voice.',
            'AI customer support and CRM in one: AI agents that answer every call, chat and message — 24/7, from your own data.',
        ],
    ];

    private const OLD_BRAND = 'Serve AI';
    private const NEW_BRAND = 'serveAI';

    public function up(): void
    {
        if (Schema::hasTable('site_settings')) {
            foreach (self::MAP as $key => [$old, $new]) {
                $this->swap($key, $old, $new);
            }
            $this->replaceBrand(self::OLD_BRAND, self::NEW_BRAND);
        }

        if (Schema::hasTable('blog_posts')) {
            DB::table('blog_posts')->where('author_name', self::OLD_BRAND)->update(['author_name' => self::NEW_BRAND]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('site_settings')) {
            $this->replaceBrand(self::NEW_BRAND, self::OLD_BRAND);
            foreach (self::MAP as $key => [$old, $new]) {
                $this->swap($key, str_replace(self::NEW_BRAND, self::OLD_BRAND, $new), $old);
            }
        }

        if (Schema::hasTable('blog_posts')) {
            DB::table('blog_posts')->where('author_name', self::NEW_BRAND)->update(['author_name' => self::OLD_BRAND]);
        }
    }

    private function swap(string $key, string $from, string $to): void
    {
        DB::table('site_settings')
            ->where('key', $key)
            ->where('value', $this->encode($from))
            ->update(['value' => $this->encode($to), 'updated_at' => now()]);
    }

    /** Rewrite the brand inside stored seo.* / content.* values. */
    private function replaceBrand(string $from, string $to): void
    {
        $rows = DB::table('site_settings')
            ->where(fn ($q) => $q->where('key', 'like', 'seo.%')->orWhere('key', 'like', 'content.%'))
            ->where('value', 'like', '%' . $from . '%')
            ->get(['id', 'value']);

        foreach ($rows as $row) {
            DB::table('site_settings')->where('id', $row->id)->update([
                'value'      => str_replace($from, $to, (string) $row->value),
                'updated_at' => now(),
            ]);
        }
    }

    private function encode(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};
