<?php
session_start();
require_once "config.php";
require_once "includes/notifications.php";

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notification_id = isset($input['notification_id']) ? intval($input['notification_id']) : 0;
    
    if ($notification_id > 0) {
        $success = markNotificationAsRead($conn, $notification_id, $_SESSION["user_id"]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => $success]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid notification ID']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>