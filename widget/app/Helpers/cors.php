<?php
/**
 * CORS helper for the widget's PHP handlers.
 *
 * Per-project allowlist:
 *   Each project owns a list of allowed origins, configured in the
 *   admin Widget Settings page and stored at
 *   projects.json_data.widget.allowed_origins. We look it up live by
 *   API key on every request (with a short on-disk cache to keep the
 *   overhead under a millisecond after the first call).
 *
 * Resolution flow per incoming request:
 *   1. OPTIONS preflight → respond 204 with permissive headers so the
 *      browser will send the real request (we can't validate origin
 *      yet — preflight has no body, so no API key is available).
 *   2. Real request → read project_api_key from $_POST.
 *      a. No key → fall back to a global allowlist
 *         (TVAIBWC_CORS_ALLOW_ORIGINS env, comma-separated, or "*")
 *      b. Key present → fetch the project's allowed_origins from
 *         Laravel /api/v1/widget/config and check the Origin against it.
 *   3. If origin matches (or list is empty / wildcard), echo it back.
 *      Otherwise emit no CORS headers — the browser will block the
 *      response with a CORS error.
 *
 * Include at the very top of each handler:
 *   require __DIR__ . '/../Helpers/cors.php';
 */

if (!function_exists('tvaibwc_cors_fetch_project_config')) {

    function tvaibwc_cors_fetch_project_config(string $apiKey): ?array
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tvaibwc-cors';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . sha1($apiKey) . '.json';

        // 60 s cache — short enough that admin changes propagate fast,
        // long enough to avoid hitting Laravel on every chat message.
        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 60)) {
            $raw = @file_get_contents($cacheFile);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) return $decoded;
        }

        $base = $_ENV['LARAVEL_BASE_URL']
            ?? getenv('LARAVEL_BASE_URL')
            ?? (defined('LARAVEL_BASE_URL') ? constant('LARAVEL_BASE_URL') : 'http://127.0.0.1:8001');
        $url  = rtrim($base, '/') . '/api/v1/widget/config';

        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPHEADER     => [
                'X-CLIENT-API-KEY: ' . $apiKey,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $body === false) return null;
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['config']) || !is_array($decoded['config'])) {
            return null;
        }

        @file_put_contents($cacheFile, json_encode($decoded['config']));
        return $decoded['config'];
    }
}

if (!function_exists('tvaibwc_apply_cors')) {

    function tvaibwc_apply_cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // ---- preflight: permissive (the actual request will be checked)
        if ($method === 'OPTIONS') {
            if ($origin) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, X-CLIENT-API-KEY, X-Project-Api-Key, Authorization, X-Requested-With');
                header('Access-Control-Max-Age: 86400');
            }
            http_response_code(204);
            exit;
        }

        if (!$origin) return;  // same-origin request, nothing to do

        $allowed = tvaibwc_cors_resolve_allowlist();

        // Empty allowlist means "allow any" (dev mode).
        $allow = (empty($allowed) || in_array('*', $allowed, true) || in_array($origin, $allowed, true))
               ? $origin
               : '';

        if (!$allow) {
            // Origin is not in this project's allowlist. Don't emit
            // CORS headers — the browser will surface a CORS error,
            // which is exactly what we want.
            return;
        }

        header('Access-Control-Allow-Origin: ' . $allow);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CLIENT-API-KEY, X-Project-Api-Key, Authorization, X-Requested-With');
    }
}

if (!function_exists('tvaibwc_cors_resolve_allowlist')) {

    function tvaibwc_cors_resolve_allowlist(): array
    {
        // Per-project allowlist: read API key from request body.
        $apiKey = $_POST['project_api_key']
            ?? $_SERVER['HTTP_X_CLIENT_API_KEY']
            ?? $_SERVER['HTTP_X_PROJECT_API_KEY']
            ?? '';
        $apiKey = trim((string) $apiKey);

        if ($apiKey !== '') {
            $cfg = tvaibwc_cors_fetch_project_config($apiKey);
            if (is_array($cfg) && isset($cfg['allowed_origins']) && is_array($cfg['allowed_origins'])) {
                return $cfg['allowed_origins'];
            }
        }

        // No key OR Laravel unreachable → fall back to the global
        // .env list (legacy single-project deployments).
        $raw = $_ENV['TVAIBWC_CORS_ALLOW_ORIGINS']
            ?? getenv('TVAIBWC_CORS_ALLOW_ORIGINS')
            ?? '';
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }
}

tvaibwc_apply_cors();
