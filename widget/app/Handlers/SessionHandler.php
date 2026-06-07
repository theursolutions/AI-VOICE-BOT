<?php
require __DIR__ . '/../config/bootstrap.php';
require __DIR__ . '/../Helpers/cors.php';
require __DIR__ . '/../Controllers/SessionController.php';
use Ursolutions\Tvaibwc\Controllers\SessionController;

$action = $_POST['action'] ?? null;

if (!$action) {
    echo json_encode(["error" => "No action provided"]);
    exit;
}

// Map actions to [Class, Method]
$actions = [
    "getOrCreate" => [SessionController::class, "getOrCreate"],
];

if (isset($actions[$action])) {
    [$class, $method] = $actions[$action];
    $controller = new $class();
    if (method_exists($controller, $method)) {
        $response = $controller->$method($_POST);
        echo json_encode($response);
        exit;
    }
}

echo json_encode(["error" => "Invalid action"]);
