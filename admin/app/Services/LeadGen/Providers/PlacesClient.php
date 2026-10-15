<?php

namespace App\Services\LeadGen\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * HTTP client for the places worker (voice-engine image, `app.places.app`).
 * Translates transport and HTTP failures into ProviderException kinds so the
 * search job can decide between retrying and failing.
 */
class PlacesClient
{
    private ?Client $http = null;

    // No constructor parameter on purpose: the container autowires a typed
    // ?Client with a bare Guzzle client (no base_uri), which sent every
    // request to "http://places/…". Tests that need a custom handler use
    // withHttp().
    public function withHttp(Client $http): static
    {
        $this->http = $http;
        return $this;
    }

    public function configured(): bool
    {
        return (bool) config('leadgen.places.url') && (bool) config('services.python.internal_secret');
    }

    public function geocode(string $country, string $q, int $limit = 10): array
    {
        return $this->call('POST', 'places/geocode', ['country' => $country, 'q' => $q, 'limit' => $limit]);
    }

    public function area(array $bbox): array
    {
        return $this->call('POST', 'places/area', ['bbox' => array_values($bbox)]);
    }

    public function categories(string $areaId, int $limit = 300): array
    {
        return $this->call('GET', 'places/area/' . rawurlencode($areaId) . '/categories', null, ['limit' => $limit]);
    }

    public function search(string $areaId, array $filters, int $offset, int $limit): array
    {
        return $this->call('POST', 'places/search', [
            'area_id' => $areaId, 'filters' => $filters, 'offset' => $offset, 'limit' => $limit,
        ]);
    }

    private function client(): Client
    {
        if (!$this->configured()) {
            throw ProviderException::notConfigured('The places worker is not configured (LEADGEN_PLACES_URL).');
        }
        return $this->http ??= new Client([
            'base_uri' => rtrim((string) config('leadgen.places.url'), '/') . '/',
            'timeout'  => (int) config('leadgen.places.timeout', 240),
            'connect_timeout' => 5,
            'headers'  => [
                'X-Internal-Secret' => (string) config('services.python.internal_secret'),
                'Accept'            => 'application/json',
            ],
        ]);
    }

    private function call(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $options = array_filter(['json' => $json, 'query' => $query ?: null], fn ($v) => $v !== null);
        try {
            $res = $this->client()->request($method, $path, $options);
        } catch (ConnectException $e) {
            throw ProviderException::unavailable('The places worker is not reachable.', 30, ['error' => $e->getMessage()]);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;
            $detail = (string) (json_decode((string) $e->getResponse()?->getBody(), true)['detail'] ?? $e->getMessage());
            throw match (true) {
                $status === 429                   => ProviderException::rateLimited(60, ['status' => $status]),
                $status >= 500 || $status === 0   => ProviderException::unavailable('The places worker had an error.', 60, ['status' => $status, 'detail' => $detail]),
                $status === 401                   => ProviderException::failed('The places worker rejected our credentials.', ['status' => $status]),
                $status === 422                   => ProviderException::unsupported($detail),
                default                           => ProviderException::failed($detail, ['status' => $status]),
            };
        }

        $body = json_decode((string) $res->getBody(), true);
        if (!is_array($body)) {
            throw ProviderException::unavailable('The places worker returned an unreadable response.');
        }
        return $body;
    }
}
