<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/bootstrap.php';

use Routes\Router;

// load routes
require __DIR__ . '/Routes/web.php';

// get current request info
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// dispatch to controller
Router::dispatch($uri, $method);
