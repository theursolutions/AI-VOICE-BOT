<?php

namespace App\Services\LeadGen\Providers;

use App\Services\LeadGen\ProspectNormalizer;

/**
 * Overture Maps places, through the places worker.
 *
 * Open data: CDLA-Permissive-2.0 (Meta, Microsoft, …), Apache-2.0
 * (Foursquare) and CC0 (AllThePlaces) — storage and commercial use are
 * permitted with attribution, which is why it's the first source. Each
 * prospect keeps the licences of the records it came from.
 *
 * Contact fields from Overture are its upstream sources' claims; they are
 * stored as "unverified" until enrichment or a person confirms them.
 */
class OvertureProvider implements LeadSource
{
    public const KEY = 'overture';
    public const ATTRIBUTION = '© Overture Maps Foundation (overturemaps.org). Includes data from Foursquare, licensed Apache-2.0.';

    public function __construct(private PlacesClient $client) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Overture Maps places';
    }

    public function capabilities(): array
    {
        return [
            'filters'     => ['area', 'radius', 'categories', 'keywords', 'require_website', 'require_phone',
                              'require_email', 'require_social', 'min_confidence', 'exclude_closed'],
            'estimate'    => true,
            'storage'     => 'storable',
            'attribution' => self::ATTRIBUTION,
            'fields'      => ['business_name', 'category', 'website', 'phone', 'email', 'socials', 'address', 'location'],
            'unsupported' => ['business size / employee count', 'contact person names', 'revenue'],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->client->configured();
    }

    public function configurationHint(): ?string
    {
        return $this->isConfigured() ? null : 'Set LEADGEN_PLACES_URL to the places worker and PYTHON_INTERNAL_SECRET.';
    }

    public function prepare(SearchCriteria $criteria): bool
    {
        $res = $this->client->area($criteria->bbox);
        return match ($res['status'] ?? null) {
            'ready'   => true,
            'loading' => false,
            'failed'  => throw ProviderException::unavailable('Loading this area failed: ' . ($res['error'] ?? 'unknown'), 600),
            default   => throw ProviderException::failed('Unexpected area status.', $res),
        };
    }

    public function estimate(SearchCriteria $criteria): ?int
    {
        $area = $this->client->area($criteria->bbox);
        if (($area['status'] ?? null) !== 'ready') {
            return null;
        }
        return (int) ($this->client->search($area['area_id'], $this->filters($criteria), 0, 1)['total'] ?? 0);
    }

    public function search(SearchCriteria $criteria, ?array $cursor, int $limit): ProviderPage
    {
        $areaId = $cursor['area_id'] ?? null;
        if (!$areaId) {
            $area = $this->client->area($criteria->bbox);
            if (($area['status'] ?? null) !== 'ready') {
                throw ProviderException::unavailable('The area is still loading.', 15);
            }
            $areaId = (string) $area['area_id'];
        }
        $offset = (int) ($cursor['offset'] ?? 0);

        $res = $this->client->search($areaId, $this->filters($criteria), $offset, $limit);
        $release = explode('_', $areaId)[0];

        $items = array_map(fn (array $row) => $this->normalise($row, $release), (array) ($res['items'] ?? []));
        $next  = isset($res['next_offset']) ? ['area_id' => $areaId, 'offset' => (int) $res['next_offset']] : null;

        return new ProviderPage($items, $next, isset($res['total']) ? (int) $res['total'] : null, ['release' => $release]);
    }

    private function filters(SearchCriteria $c): array
    {
        return [
            'categories'      => $c->categories,
            'keywords'        => $c->keywords,
            'require_website' => $c->requireWebsite,
            'require_phone'   => $c->requirePhone,
            'require_email'   => $c->requireEmail,
            'require_social'  => $c->requireSocial,
            'min_confidence'  => $c->minConfidence,
            'exclude_closed'  => $c->excludeClosed,
            'center'          => $c->center,
            'radius_km'       => $c->radiusKm,
        ];
    }

    /** One worker row → the common prospect shape. */
    public function normalise(array $row, string $release): array
    {
        $address = ((array) ($row['addresses'] ?? []))[0] ?? [];
        $sources = (array) ($row['sources'] ?? []);
        $licences = array_values(array_unique(array_filter(array_column($sources, 'license'))));
        $name = trim((string) ($row['name_en'] ?? '')) ?: trim((string) ($row['name'] ?? ''));

        return ProspectNormalizer::make([
            'business_name'    => $name,
            'category'         => $row['category'] ?? null,
            'website'          => ((array) ($row['websites'] ?? []))[0] ?? null,
            'phone'            => ((array) ($row['phones'] ?? []))[0] ?? null,
            'email'            => ((array) ($row['emails'] ?? []))[0] ?? null,
            'socials'          => (array) ($row['socials'] ?? []),
            'address'          => array_filter([
                'line'     => $address['freeform'] ?? null,
                'locality' => $address['locality'] ?? null,
                'region'   => $address['region'] ?? null,
                'postcode' => $address['postcode'] ?? null,
                'country'  => $address['country'] ?? null,
            ]) ?: null,
            'city'             => $address['locality'] ?? null,
            'country_code'     => $address['country'] ?? null,
            'lat'              => $row['lat'] ?? null,
            'lng'              => $row['lng'] ?? null,
            'source_record_id' => (string) $row['id'],
            'source_url'       => isset($row['lat'], $row['lng'])
                ? sprintf('https://explore.overturemaps.org/#17/%.6f/%.6f', $row['lat'], $row['lng'])
                : null,
            'source_license'   => $licences ? implode(', ', $licences) : null,
            'source_meta'      => array_filter([
                'release'          => $release,
                'local_name'       => ($row['name_en'] ?? null) && ($row['name'] ?? null) !== $row['name_en'] ? $row['name'] : null,
                'confidence'       => isset($row['confidence']) ? round((float) $row['confidence'], 3) : null,
                'basic_category'   => $row['basic_category'] ?? null,
                'hierarchy'        => $row['hierarchy'] ?? null,
                'operating_status' => $row['operating_status'] ?? null,
                'brand'            => $row['brand'] ?? null,
                'datasets'         => array_values(array_unique(array_filter(array_column($sources, 'dataset')))),
                'more_websites'    => array_slice((array) ($row['websites'] ?? []), 1, 3) ?: null,
                'more_phones'      => array_slice((array) ($row['phones'] ?? []), 1, 3) ?: null,
                'more_emails'      => array_slice((array) ($row['emails'] ?? []), 1, 3) ?: null,
            ], fn ($v) => $v !== null && $v !== []),
        ], self::KEY, 'Overture Maps');
    }
}
