<?php

namespace App\Routing;

use App\Support\Hashid;
use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;

/**
 * URL generator that transparently hashes id-like route parameters whenever
 * a named-route URL is built. So `route('data-sources.show', ['id' => 5])`
 * and `route('skills.index', ['project_id' => 3])` emit hashed ids in both
 * the path and the query string — with no change at the call site.
 *
 * Only keys named `id`, `*_id`, or `*Id` carrying a numeric value are
 * encoded; slugs (e.g. `client`), tokens, models, and already-hashed strings
 * are left alone. Decoding happens in the DecodeHashids middleware.
 */
class HashidUrlGenerator extends UrlGenerator
{
    public function toRoute($route, $parameters, $absolute)
    {
        return parent::toRoute($route, $this->encodeIdParameters($route, (array) $parameters), $absolute);
    }

    /**
     * Normalise to name-keyed parameters and hash the id-like ones. Handles
     * both associative (`['id' => 5]`) and positional (`[$id]`) calls.
     */
    private function encodeIdParameters(Route $route, array $parameters): array
    {
        $names  = $route->parameterNames();
        $result = [];

        // Associative entries first.
        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            $result[$key] = $this->maybeEncode($key, $value);
        }

        // Positional entries map onto the next unfilled route segment name.
        $pos = 0;
        foreach ($parameters as $key => $value) {
            if (! is_int($key)) {
                continue;
            }
            while ($pos < count($names) && array_key_exists($names[$pos], $result)) {
                $pos++;
            }
            if ($pos < count($names)) {
                $name = $names[$pos++];
                $result[$name] = $this->maybeEncode($name, $value);
            } else {
                $result[] = $value;   // extra positional → leave untouched
            }
        }

        return $result;
    }

    private function maybeEncode(string $key, $value)
    {
        // Only numeric scalars on id-like keys. Models (route-key objects),
        // slugs, and already-encoded hash strings fall through unchanged.
        if (Hashid::isIdKey($key) && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            return Hashid::encode((int) $value);
        }
        return $value;
    }
}
