<?php

namespace App\Services\CrmProviders;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Encrypt/decrypt OAuth tokens inside a DataSource `config` array.
 *
 * We never want plaintext `access_token` / `refresh_token` sitting in the
 * `data_sources.config` JSON column — Laravel's app-key-backed AES wraps
 * them so the DB dump alone can't replay against HubSpot.
 *
 * Non-token keys (provider, expires_at, scopes, hub_id, ...) pass through
 * untouched. Decryption is best-effort: if a value isn't a valid Laravel
 * payload (e.g. legacy plaintext, mid-migration row) we return it as-is.
 */
class TokenVault
{
    private const SECRET_KEYS = ['access_token', 'refresh_token'];

    public function encryptConfig(array $config): array
    {
        foreach (self::SECRET_KEYS as $key) {
            if (!empty($config[$key]) && is_string($config[$key])) {
                $config[$key] = Crypt::encryptString($config[$key]);
            }
        }
        return $config;
    }

    public function decryptConfig(array $config): array
    {
        foreach (self::SECRET_KEYS as $key) {
            if (!empty($config[$key]) && is_string($config[$key])) {
                try {
                    $config[$key] = Crypt::decryptString($config[$key]);
                } catch (Throwable $e) {
                    // Leave as-is; resolver will treat as invalid and trigger refresh/error.
                }
            }
        }
        return $config;
    }
}
