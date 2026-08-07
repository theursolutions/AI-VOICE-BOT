<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super-admin editor for the public marketing site's copy. Every textual
 * block on welcome.blade.php is exposed here; edits write `content.*` rows
 * to site_settings and take effect on the live homepage immediately
 * (the blade reads each block through tva_setting() with the config/site.php
 * value as fallback).
 */
class SiteContentController extends Controller
{
    /**
     * Grouped field definitions that drive the editor UI. Keys match
     * config/site.php → content.* (which supplies the defaults).
     *
     * @return array<string,array{icon:string,fields:array<int,array{key:string,label:string,type:string}>}>
     */
    public static function sections(): array
    {
        $t = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'text'];
        $a = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'textarea'];

        return [
            'Brand' => ['icon' => '🏷️', 'fields' => [
                $t('brand_name', 'Brand name (nav + footer)'),
            ]],
            'Hero' => ['icon' => '🚀', 'fields' => [
                $t('hero_eyebrow', 'Eyebrow badge'),
                $t('hero_title', 'Headline (plain part)'),
                $t('hero_title_accent', 'Headline (highlighted part)'),
                $a('hero_subtitle', 'Sub-headline'),
                $t('hero_cta_label', 'Call-bar button label'),
                $t('hero_callbar_msg', 'Call-bar helper text'),
                $t('hero_meta1', 'Trust point #1'),
                $t('hero_meta2', 'Trust point #2'),
                $t('hero_meta3', 'Trust point #3'),
            ]],
            'Mission Console' => ['icon' => '🛰️', 'fields' => [
                $t('how_eyebrow', 'Eyebrow'),
                $t('how_title', 'Heading'),
                $a('how_lead', 'Lead paragraph'),
            ]],
            'Launch Steps' => ['icon' => '🪜', 'fields' => [
                $t('steps_eyebrow', 'Eyebrow'),
                $t('steps_title', 'Heading'),
                $a('steps_lead', 'Lead paragraph'),
                $t('step1_title', 'Step 1 — title'), $a('step1_body', 'Step 1 — body'),
                $t('step2_title', 'Step 2 — title'), $a('step2_body', 'Step 2 — body'),
                $t('step3_title', 'Step 3 — title'), $a('step3_body', 'Step 3 — body'),
                $t('step4_title', 'Step 4 — title'), $a('step4_body', 'Step 4 — body'),
            ]],
            'Channels' => ['icon' => '📡', 'fields' => [
                $t('channels_eyebrow', 'Eyebrow'),
                $t('channels_title', 'Heading'),
                $a('channels_lead', 'Lead paragraph'),
                $t('channel1_icon', 'Channel 1 — icon'), $t('channel1_title', 'Channel 1 — title'), $a('channel1_body', 'Channel 1 — body'),
                $t('channel2_icon', 'Channel 2 — icon'), $t('channel2_title', 'Channel 2 — title'), $a('channel2_body', 'Channel 2 — body'),
                $t('channel3_icon', 'Channel 3 — icon'), $t('channel3_title', 'Channel 3 — title'), $a('channel3_body', 'Channel 3 — body'),
                $t('channel4_icon', 'Channel 4 — icon'), $t('channel4_title', 'Channel 4 — title'), $a('channel4_body', 'Channel 4 — body'),
                $t('channel5_icon', 'Channel 5 — icon'), $t('channel5_title', 'Channel 5 — title'), $a('channel5_body', 'Channel 5 — body'),
                $t('channel6_icon', 'Channel 6 — icon'), $t('channel6_title', 'Channel 6 — title'), $a('channel6_body', 'Channel 6 — body'),
            ]],
            'Platform Features' => ['icon' => '🧩', 'fields' => [
                $t('platform_eyebrow', 'Eyebrow'),
                $t('platform_title', 'Heading'),
                $a('platform_lead', 'Lead paragraph'),
                $t('feat1_icon', 'Card 1 — icon'),  $t('feat1_title', 'Card 1 — title'),  $a('feat1_body', 'Card 1 — body'),
                $t('feat2_icon', 'Card 2 — icon'),  $t('feat2_title', 'Card 2 — title'),  $a('feat2_body', 'Card 2 — body'),
                $t('feat3_icon', 'Card 3 — icon'),  $t('feat3_title', 'Card 3 — title'),  $a('feat3_body', 'Card 3 — body'),
                $t('feat4_icon', 'Card 4 — icon'),  $t('feat4_title', 'Card 4 — title'),  $a('feat4_body', 'Card 4 — body'),
                $t('feat5_icon', 'Card 5 — icon'),  $t('feat5_title', 'Card 5 — title'),  $a('feat5_body', 'Card 5 — body'),
                $t('feat6_icon', 'Card 6 — icon'),  $t('feat6_title', 'Card 6 — title'),  $a('feat6_body', 'Card 6 — body'),
                $t('feat7_icon', 'Card 7 — icon'),  $t('feat7_title', 'Card 7 — title'),  $a('feat7_body', 'Card 7 — body'),
                $t('feat8_icon', 'Card 8 — icon'),  $t('feat8_title', 'Card 8 — title'),  $a('feat8_body', 'Card 8 — body'),
                $t('feat9_icon', 'Card 9 — icon'),  $t('feat9_title', 'Card 9 — title'),  $a('feat9_body', 'Card 9 — body'),
                $t('feat10_icon', 'Card 10 — icon'), $t('feat10_title', 'Card 10 — title'), $a('feat10_body', 'Card 10 — body'),
                $t('feat11_icon', 'Card 11 — icon'), $t('feat11_title', 'Card 11 — title'), $a('feat11_body', 'Card 11 — body'),
                $t('feat12_icon', 'Card 12 — icon'), $t('feat12_title', 'Card 12 — title'), $a('feat12_body', 'Card 12 — body'),
            ]],
            'Use Cases' => ['icon' => '🏪', 'fields' => [
                $t('cases_eyebrow', 'Eyebrow'),
                $t('cases_title', 'Heading'),
                $a('cases_lead', 'Lead paragraph'),
                $t('case1_icon', 'Case 1 — icon'), $t('case1_title', 'Case 1 — title'), $a('case1_body', 'Case 1 — body'),
                $t('case2_icon', 'Case 2 — icon'), $t('case2_title', 'Case 2 — title'), $a('case2_body', 'Case 2 — body'),
                $t('case3_icon', 'Case 3 — icon'), $t('case3_title', 'Case 3 — title'), $a('case3_body', 'Case 3 — body'),
                $t('case4_icon', 'Case 4 — icon'), $t('case4_title', 'Case 4 — title'), $a('case4_body', 'Case 4 — body'),
                $t('case5_icon', 'Case 5 — icon'), $t('case5_title', 'Case 5 — title'), $a('case5_body', 'Case 5 — body'),
                $t('case6_icon', 'Case 6 — icon'), $t('case6_title', 'Case 6 — title'), $a('case6_body', 'Case 6 — body'),
            ]],
            'Security & Control' => ['icon' => '🔒', 'fields' => [
                $t('security_eyebrow', 'Eyebrow'),
                $t('security_title', 'Heading'),
                $a('security_lead', 'Lead paragraph'),
                $t('security1_title', 'Point 1 — title'), $a('security1_body', 'Point 1 — body'),
                $t('security2_title', 'Point 2 — title'), $a('security2_body', 'Point 2 — body'),
                $t('security3_title', 'Point 3 — title'), $a('security3_body', 'Point 3 — body'),
                $t('security4_title', 'Point 4 — title'), $a('security4_body', 'Point 4 — body'),
                $t('security5_title', 'Point 5 — title'), $a('security5_body', 'Point 5 — body'),
                $t('security6_title', 'Point 6 — title'), $a('security6_body', 'Point 6 — body'),
            ]],
            'FAQ' => ['icon' => '❓', 'fields' => [
                $t('faq_eyebrow', 'Eyebrow'),
                $t('faq_title', 'Heading'),
                $t('faq1_q', 'Q1 — question'), $a('faq1_a', 'Q1 — answer'),
                $t('faq2_q', 'Q2 — question'), $a('faq2_a', 'Q2 — answer'),
                $t('faq3_q', 'Q3 — question'), $a('faq3_a', 'Q3 — answer'),
                $t('faq4_q', 'Q4 — question'), $a('faq4_a', 'Q4 — answer'),
                $t('faq5_q', 'Q5 — question'), $a('faq5_a', 'Q5 — answer'),
                $t('faq6_q', 'Q6 — question'), $a('faq6_a', 'Q6 — answer'),
            ]],
            'Trust Strip' => ['icon' => '📊', 'fields' => [
                $t('trust1_num', 'Stat 1 — number'), $t('trust1_label', 'Stat 1 — label'),
                $t('trust2_num', 'Stat 2 — number'), $t('trust2_label', 'Stat 2 — label'),
                $t('trust3_num', 'Stat 3 — number'), $t('trust3_label', 'Stat 3 — label'),
                $t('trust4_num', 'Stat 4 — number'), $t('trust4_label', 'Stat 4 — label'),
            ]],
            'Final CTA' => ['icon' => '🎯', 'fields' => [
                $t('cta_title', 'Heading'),
                $a('cta_subtitle', 'Sub-text'),
                $t('cta_button', 'Button label'),
            ]],
            'Footer & Contact' => ['icon' => '🦶', 'fields' => [
                $a('footer_tagline', 'Footer tagline (under brand)'),
                $t('contact_phone', 'Contact phone (shown in footer + Contact page)'),
                $t('contact_email', 'Contact email'),
                $t('contact_address', 'Contact address / location'),
                $t('social_twitter', 'Social — X/Twitter URL'),
                $t('social_linkedin', 'Social — LinkedIn URL'),
                $t('social_facebook', 'Social — Facebook URL'),
                $t('social_instagram', 'Social — Instagram URL'),
                $a('footer_text', 'Bottom copyright line (blank = "© year Brand")'),
            ]],
        ];
    }

    public function index(Request $request): View
    {
        $title    = 'Page Content';
        $sections = self::sections();

        // Current value (stored override or config default) for each field.
        $values = [];
        foreach ($sections as $sec) {
            foreach ($sec['fields'] as $f) {
                $values[$f['key']] = tva_setting('content.' . $f['key'], '');
            }
        }

        return view('ops.content.index', compact('title', 'sections', 'values'));
    }

    public function update(Request $request): RedirectResponse
    {
        // Save only keys we know about (config/site.php → content.*).
        $known = array_keys((array) config('site.content', []));

        $saved = 0;
        foreach ($known as $key) {
            if ($request->has($key)) {
                SiteSetting::set('content.' . $key, (string) $request->input($key, ''));
                $saved++;
            }
        }

        AuditLog::record('seo.content_update', ['payload' => ['fields' => $saved]]);

        return back()->with('success', "Homepage content saved — {$saved} field(s) updated and live.");
    }

    /** Restore every content block to its config/site.php default. */
    public function reset(Request $request): RedirectResponse
    {
        $known = array_keys((array) config('site.content', []));
        SiteSetting::query()
            ->whereIn('key', array_map(fn ($k) => 'content.' . $k, $known))
            ->delete();
        SiteSetting::flushCache();

        AuditLog::record('seo.content_reset');

        return back()->with('success', 'Homepage content reset to defaults.');
    }
}
