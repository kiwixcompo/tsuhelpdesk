<?php
session_start();

header('Content-Type: application/json');

// Return session information for debugging
echo json_encode([
    'success' => true,
    'session_id' => session_id(),
    'session_data' => [
        'loggedin' => isset($_SESSION["loggedin"]) ? $_SESSION["loggedin"] : null,
        'user_id' => isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : null,
        'role_id' => isset($_SESSION["role_id"]) ? $_SESSION["role_id"] : null,
        'username' => isset($_SESSION["username"]) ? $_SESSION["username"] : null,
        'full_name' => isset($_SESSION["full_name"]) ? $_SESSION["full_name"] : null
    ],
    'timestamp' => date('Y-m-d H:i:s')
]);
?>