<?php
require __DIR__ . '/../vendor/autoload.php';
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;

// Load .env from project root.
//
// safeLoad(), not load(): the file is gitignored — correctly, it holds DB
// credentials and API keys — so it does not exist in a container image. load()
// THROWS on a missing file, which took the whole widget down with an uncaught
// InvalidPathException on every request in production while working fine on a
// developer machine, where the file is always present.
//
// safeLoad() simply does nothing when there is no file, leaving the real
// process environment in place. That is what a container supplies anyway
// (compose passes the stack's .env into the container), so both worlds work:
// a file in development, injected variables in production.
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

define('BASE_URL', getBaseUrl());
define('BASE_PATH', realpath(__DIR__ . '/..'));
define('BASE_CONTROLLERS_URL', BASE_URL."/app/Controllers");
define('BASE_HANDLERS_URL', BASE_URL."/app/Handlers");

// Where the Laravel admin lives — used to fetch per-project widget
// branding (/api/v1/widget/config) when the widget is opened.
if (!defined('LARAVEL_BASE_URL')) {
    define('LARAVEL_BASE_URL',
        $_ENV['LARAVEL_BASE_URL']
        ?? getenv('LARAVEL_BASE_URL')
        ?? 'http://localhost/AI-CRM-AGENT/admin/public');
}