<?php
session_start();

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

// Extend session by regenerating session ID and updating last activity
session_regenerate_id(true);
$_SESSION['last_activity'] = time();

// Return success response
header('Content-Type: application/json');
echo json_encode([
    'success' => true, 
    'message' => 'Session extended successfully',
    'new_expiry' => time() + 1800 // 30 minutes from now
]);
?>