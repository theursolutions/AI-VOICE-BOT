<?php

namespace App\Services\LeadGen\Providers;

use InvalidArgumentException;

/**
 * What a user is looking for, independent of the source. Stored as JSON on
 * lg_searches.criteria, so a search can be re-run or resumed exactly.
 *
 * The area is always resolved to a bounding box before a search starts —
 * either picked from the place lookup (a city) or drawn as a radius around a
 * point. Sources filter on the box; a radius search also trims to the circle.
 */
final class SearchCriteria
{
    public const MAX_RESULTS_CEILING = 5000;
    public const MAX_RADIUS_KM       = 100;

    public function __construct(
        public readonly array $bbox,               // [xmin, ymin, xmax, ymax] (lng/lat)
        public readonly string $areaLabel,
        public readonly ?array $center = null,     // [lng, lat] for radius searches
        public readonly ?float $radiusKm = null,
        public readonly array $categories = [],
        public readonly string $keywords = '',
        public readonly bool $requireWebsite = false,
        public readonly bool $requirePhone = false,
        public readonly bool $requireEmail = false,
        public readonly bool $requireSocial = false,
        public readonly float $minConfidence = 0.0,
        public readonly bool $excludeClosed = true,
        public readonly bool $excludeExistingCrm = true,
        public readonly int $maxResults = 500,
        public readonly ?string $countryCode = null,
        public readonly string $source = 'overture',
    ) {
        self::assertBbox($bbox);
        if ($maxResults < 1 || $maxResults > self::MAX_RESULTS_CEILING) {
            throw new InvalidArgumentException('maxResults out of range');
        }
    }

    /** Box around a point, wide enough to contain the circle. */
    public static function bboxForRadius(float $lng, float $lat, float $km): array
    {
        $km   = min(max($km, 0.1), self::MAX_RADIUS_KM);
        $dLat = $km / 111.32;
        $dLng = $km / (111.32 * max(cos(deg2rad($lat)), 0.01));
        return [round($lng - $dLng, 6), round($lat - $dLat, 6), round($lng + $dLng, 6), round($lat + $dLat, 6)];
    }

    public static function assertBbox(array $b): void
    {
        if (count($b) !== 4) {
            throw new InvalidArgumentException('bbox must have 4 numbers');
        }
        [$x0, $y0, $x1, $y1] = array_map('floatval', $b);
        if ($x0 < -180 || $x1 > 180 || $y0 < -90 || $y1 > 90 || $x0 >= $x1 || $y0 >= $y1) {
            throw new InvalidArgumentException('bbox out of range');
        }
        // ~2.5° on a side is roughly a large metro region. Anything bigger is
        // a country-wide scan, which this tool deliberately doesn't do.
        if (($x1 - $x0) > 2.5 || ($y1 - $y0) > 2.5) {
            throw new InvalidArgumentException('area too large');
        }
    }

    public static function fromArray(array $a): self
    {
        return new self(
            bbox: array_map('floatval', (array) ($a['bbox'] ?? [])),
            areaLabel: (string) ($a['area_label'] ?? ''),
            center: isset($a['center']) ? array_map('floatval', (array) $a['center']) : null,
            radiusKm: isset($a['radius_km']) ? (float) $a['radius_km'] : null,
            categories: array_values(array_filter(array_map('strval', (array) ($a['categories'] ?? [])))),
            keywords: trim((string) ($a['keywords'] ?? '')),
            requireWebsite: (bool) ($a['require_website'] ?? false),
            requirePhone: (bool) ($a['require_phone'] ?? false),
            requireEmail: (bool) ($a['require_email'] ?? false),
            requireSocial: (bool) ($a['require_social'] ?? false),
            minConfidence: (float) ($a['min_confidence'] ?? 0),
            excludeClosed: (bool) ($a['exclude_closed'] ?? true),
            excludeExistingCrm: (bool) ($a['exclude_existing_crm'] ?? true),
            maxResults: (int) ($a['max_results'] ?? 500),
            countryCode: isset($a['country_code']) ? strtoupper((string) $a['country_code']) : null,
            source: (string) ($a['source'] ?? 'overture'),
        );
    }

    public function toArray(): array
    {
        return [
            'source'               => $this->source,
            'bbox'                 => $this->bbox,
            'area_label'           => $this->areaLabel,
            'center'               => $this->center,
            'radius_km'            => $this->radiusKm,
            'country_code'         => $this->countryCode,
            'categories'           => $this->categories,
            'keywords'             => $this->keywords,
            'require_website'      => $this->requireWebsite,
            'require_phone'        => $this->requirePhone,
            'require_email'        => $this->requireEmail,
            'require_social'       => $this->requireSocial,
            'min_confidence'       => $this->minConfidence,
            'exclude_closed'       => $this->excludeClosed,
            'exclude_existing_crm' => $this->excludeExistingCrm,
            'max_results'          => $this->maxResults,
        ];
    }
}
