<?php
require __DIR__ . '/../vendor/autoload.php';
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;

// Load .env from project root
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

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