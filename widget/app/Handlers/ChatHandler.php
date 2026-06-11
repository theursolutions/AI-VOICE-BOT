<?php
require __DIR__ . '/../config/bootstrap.php';
require __DIR__ . '/../Helpers/cors.php';
require __DIR__ . '/../Controllers/ChatController.php';
use Ursolutions\Tvaibwc\Controllers\ChatController;

$action = $_POST['action'] ?? null;

if (!$action) {
    echo json_encode(["error" => "No action provided"]);
    exit;
}

$controller = new ChatController();

switch ($action) {
    case 'startSession':
    case 'start-session':
        echo $controller->startSession($_POST);
        exit;

    case 'sendTurn':
    case 'send-turn':
        $sessionId = $_POST['session_id'] ?? null;
        echo $controller->sendTurn($sessionId, $_POST);
        exit;

    case 'flowStep':
    case 'flow-step':
        $sessionId = $_POST['session_id'] ?? null;
        echo $controller->flowStep($sessionId, $_POST);
        exit;

    case 'flowRestart':
    case 'flow-restart':
        $sessionId = $_POST['session_id'] ?? null;
        echo $controller->flowRestart($sessionId, $_POST);
        exit;

    case 'endSession':
    case 'end-session':
        $sessionId = $_POST['session_id'] ?? null;
        echo $controller->endSession($sessionId, $_POST);
        exit;

    // Legacy alias — kept so older builds keep working through the cutover.
    case 'chatResponse':
        echo $controller->chatResponse($_POST);
        exit;

    default:
        echo json_encode(["error" => "Invalid action"]);
        exit;
}
