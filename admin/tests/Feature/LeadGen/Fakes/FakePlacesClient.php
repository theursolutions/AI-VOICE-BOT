<?php

namespace Tests\Feature\LeadGen\Fakes;

use App\Services\LeadGen\Providers\PlacesClient;
use App\Services\LeadGen\Providers\ProviderException;

/**
 * Stands in for the places worker. Test-only — never registered in the app.
 * `$rows` are worker-shaped place rows; `$areaStatus` lets a test hold the
 * area in "loading" or make it fail.
 */
class FakePlacesClient extends PlacesClient
{
    public string $areaStatus = 'ready';
    public ?ProviderException $throwOnSearch = null;
    public int $searchCalls = 0;

    public function __construct(public array $rows = []) {}

    public static function rows(int $n, string $prefix = 'p'): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'id' => "{$prefix}{$i}", 'name' => "Realty {$prefix}{$i}", 'name_en' => null,
                'category' => 'real_estate_agent', 'basic_category' => 'real_estate_service', 'hierarchy' => ['real_estate_agent'],
                'websites' => ["https://realty{$prefix}{$i}.ae"], 'phones' => ['+97150' . str_pad((string) $i, 7, '0', STR_PAD_LEFT)],
                'emails' => [], 'socials' => [], 'addresses' => [['locality' => 'Dubai', 'country' => 'AE']],
                'confidence' => 0.9, 'operating_status' => 'open', 'lat' => 25.1, 'lng' => 55.2,
                'sources' => [['dataset' => 'meta', 'license' => 'CDLA-Permissive-2.0']],
            ];
        }
        return $out;
    }

    public function configured(): bool
    {
        return true;
    }

    public function area(array $bbox): array
    {
        return match ($this->areaStatus) {
            'ready'  => ['status' => 'ready', 'area_id' => '2026-09-23.1_0123456789abcdef', 'release' => '2026-09-23.1', 'total' => count($this->rows)],
            'failed' => ['status' => 'failed', 'error' => 's3 down'],
            default  => ['status' => 'loading'],
        };
    }

    public function categories(string $areaId, int $limit = 300): array
    {
        return ['status' => 'ready', 'categories' => [['key' => 'real_estate_agent', 'group' => 'real_estate_service', 'count' => count($this->rows)]]];
    }

    public function geocode(string $country, string $q, int $limit = 10): array
    {
        return ['status' => 'ready', 'matches' => [['name' => 'Dubai Emirate', 'subtype' => 'region', 'bbox' => [54.65, 24.62, 56.2, 25.37], 'too_large' => false]]];
    }

    public function search(string $areaId, array $filters, int $offset, int $limit): array
    {
        $this->searchCalls++;
        if ($this->throwOnSearch) {
            throw $this->throwOnSearch;
        }
        $items = array_slice($this->rows, $offset, $limit);
        $next  = $offset + count($items);
        return ['status' => 'ready', 'total' => count($this->rows), 'items' => $items,
                'next_offset' => $next < count($this->rows) ? $next : null];
    }
}
