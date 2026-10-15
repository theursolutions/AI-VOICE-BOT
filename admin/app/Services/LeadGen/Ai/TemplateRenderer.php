<?php

namespace App\Services\LeadGen\Ai;

use App\Models\LeadGen\OfferProfile;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Template;

/**
 * Fills {{variables}} in a template. No AI.
 *
 * A variable with no value is left visible as {{name}} and reported, so a
 * message never goes out reading "Hi ," or "Book here: ". Unknown variable
 * names are reported too (usually a typo).
 */
class TemplateRenderer
{
    public static function variables(Prospect $p, ?OfferProfile $profile, ?string $senderName = null): array
    {
        return [
            'business_name'     => $p->business_name,
            'industry'          => $p->category ? str_replace('_', ' ', $p->category) : null,
            'city'              => $p->city,
            'website'           => $p->domain_norm ?: ($p->website ? parse_url($p->website, PHP_URL_HOST) : null),
            'value_proposition' => $profile?->value_prop,
            'sender_name'       => $senderName ?: $profile?->sender_name,
            'booking_link'      => $profile?->booking_link,
        ];
    }

    /** @return array{text: string, missing: string[], unknown: string[]} */
    public static function render(string $text, array $vars): array
    {
        $missing = $unknown = [];
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function ($m) use ($vars, &$missing, &$unknown) {
            $key = strtolower($m[1]);
            if (!in_array($key, Template::VARIABLES, true)) {
                $unknown[] = $key;
                return $m[0];
            }
            $v = trim((string) ($vars[$key] ?? ''));
            if ($v === '') {
                $missing[] = $key;
                return $m[0];
            }
            return $v;
        }, $text);

        return ['text' => $out, 'missing' => array_values(array_unique($missing)), 'unknown' => array_values(array_unique($unknown))];
    }
}
