<?php

namespace App\Services\LeadGen\Providers;

use App\Services\LeadGen\ProspectNormalizer;
use App\Support\SimpleSheetReader;
use Illuminate\Support\Facades\Storage;

/**
 * A user's own CSV / XLSX list, run through the same normaliser and writer as
 * a discovery search so it gets the same progress, pause and cancel.
 *
 * Criteria (stored on lg_searches.criteria for provider "import"):
 *   file           path on the local disk (storage/app/leadgen/imports/…)
 *   original_name  what the user uploaded, for display
 *   mapping        prospect field => column index
 *   country_code   default country for phone numbers without a + prefix
 *
 * Imported records get a content-derived source id, so importing the same
 * row twice — same file again, or an overlapping list — finds the existing
 * prospect instead of creating a second one.
 */
class ImportSource
{
    public const KEY      = 'import';
    public const MAX_ROWS = 10000;

    /** Prospect fields a column can be mapped to. */
    public const FIELDS = [
        'business_name' => 'Business name',
        'website'       => 'Website',
        'email'         => 'Email',
        'phone'         => 'Phone',
        'category'      => 'Category / industry',
        'address'       => 'Address',
        'city'          => 'City',
        'country_code'  => 'Country (2-letter code)',
        'social'        => 'Social profile URL',
    ];

    /** Header row + data rows (capped), for the mapping preview. */
    public static function readSheet(string $relativePath): array
    {
        $path = Storage::disk('local')->path($relativePath);
        $rows = SimpleSheetReader::read($path);
        return [$rows[0] ?? [], array_slice($rows, 1, self::MAX_ROWS)];
    }

    /** Guess a mapping from header names ("Company", "E-mail", "Tel" …). */
    public static function guessMapping(array $header): array
    {
        $patterns = [
            'business_name' => '/^(business|company|organi[sz]ation|name|business name|company name|account)$/i',
            'website'       => '/(web|site|url|domain)/i',
            'email'         => '/e-?mail/i',
            'phone'         => '/(phone|tel|mobile|whatsapp|contact number)/i',
            'category'      => '/(category|industry|sector|type)/i',
            'address'       => '/(address|street)/i',
            'city'          => '/^(city|town|area|locality)$/i',
            'country_code'  => '/^(country|country code)$/i',
            'social'        => '/(facebook|instagram|linkedin|social)/i',
        ];
        $map = [];
        foreach ($header as $i => $label) {
            foreach ($patterns as $field => $re) {
                if (!isset($map[$field]) && preg_match($re, trim((string) $label))) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    public function page(array $criteria, ?array $cursor, int $limit): ProviderPage
    {
        [, $rows] = self::readSheet((string) $criteria['file']);
        $offset = (int) ($cursor['offset'] ?? 0);
        $slice  = array_slice($rows, $offset, $limit);
        $map    = (array) ($criteria['mapping'] ?? []);
        $defaultCountry = $criteria['country_code'] ?? null;

        $items = [];
        foreach ($slice as $row) {
            $get = fn (string $f) => isset($map[$f]) ? trim((string) ($row[(int) $map[$f]] ?? '')) : '';
            $name = $get('business_name');
            if ($name === '') {
                $items[] = ['business_name' => '', 'source_record_id' => ''];   // counted as skipped
                continue;
            }
            $country = strtoupper($get('country_code')) ?: $defaultCountry;
            $country = $country && preg_match('/^[A-Z]{2}$/', $country) ? $country : null;

            $item = ProspectNormalizer::make([
                'business_name' => $name,
                'website'       => $get('website') ?: null,
                'email'         => $get('email') ?: null,
                'phone'         => $get('phone') ?: null,
                'category'      => $get('category') ?: null,
                'address'       => $get('address') ? ['line' => $get('address')] : null,
                'city'          => $get('city') ?: null,
                'country_code'  => $country,
                'socials'       => $get('social') ? [$get('social')] : [],
                'source_record_id' => 'x',   // replaced below, once normalised
                'source_meta'   => ['file' => $criteria['original_name'] ?? null],
            ], self::KEY, 'Your import');

            $item['source_record_id'] = 'row:' . sha1(implode('|', [
                ProspectNormalizer::nameKey($name), $item['domain_norm'], $item['phone_e164'], $item['email'],
                mb_strtolower((string) $item['city']),
            ]));
            $items[] = $item;
        }

        $next = $offset + count($slice);
        return new ProviderPage($items, $next < count($rows) ? ['offset' => $next] : null, count($rows));
    }
}
