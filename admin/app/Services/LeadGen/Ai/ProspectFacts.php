<?php

namespace App\Services\LeadGen\Ai;

use App\Models\LeadGen\Prospect;

/**
 * The only things the AI is told about a prospect: a flat list of named
 * facts we actually hold, each with its verification. Scores and drafts must
 * cite these keys; anything the model claims beyond them is a hypothesis at
 * best and is checked for.
 *
 * Deliberately absent: notes (may hold private remarks), owner, internal ids,
 * raw source payloads, anything about a person rather than the business.
 */
class ProspectFacts
{
    /** @return array<string, string> fact key => value */
    public static function for(Prospect $p): array
    {
        $meta = (array) $p->source_meta;
        $facts = [
            'business_name'    => $p->business_name,
            'category'         => $p->category ? str_replace('_', ' ', $p->category) : null,
            'category_path'    => !empty($meta['hierarchy']) ? str_replace('_', ' ', implode(' > ', (array) $meta['hierarchy'])) : null,
            'brand'            => $meta['brand'] ?? null,
            'city'             => $p->city,
            'country'          => $p->country_code,
            'website'          => $p->website ? ($p->domain_norm ?: parse_url($p->website, PHP_URL_HOST)) . ' (' . self::level($p, 'website') . ')' : 'none known',
            'email'            => $p->email ? 'yes (' . self::level($p, 'email') . ')' : 'none known',
            'phone'            => $p->phone_e164 ? 'yes (' . self::level($p, 'phone') . ')' : 'none known',
            'social_profiles'  => $p->socials ? implode(', ', array_unique(array_column((array) $p->socials, 'platform'))) : 'none known',
            'listing_status'   => $meta['operating_status'] ?? null,
            'listing_confidence' => isset($meta['confidence']) ? (string) $meta['confidence'] : null,
            'tags'             => $p->tags ? implode(', ', (array) $p->tags) : null,
        ];
        return array_filter($facts, fn ($v) => $v !== null && $v !== '');
    }

    private static function level(Prospect $p, string $field): string
    {
        return str_replace('_', ' ', $p->verificationOf($field));
    }

    public static function render(array $facts): string
    {
        $lines = [];
        foreach ($facts as $k => $v) {
            $lines[] = "- {$k}: " . self::clean((string) $v);
        }
        return implode("\n", $lines);
    }

    /** Untrusted text into a prompt: one line, bounded, no prompt-like markup. */
    public static function clean(string $s, int $max = 300): string
    {
        $s = preg_replace('/[\r\n\t]+/', ' ', $s);
        $s = str_replace(['```', '<<', '>>'], '', $s);
        return mb_substr(trim($s), 0, $max);
    }
}
