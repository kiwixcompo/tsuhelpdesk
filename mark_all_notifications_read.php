<?php
session_start();
require_once "config.php";
require_once "includes/notifications.php";

// Check if user is logged in (staff or student)
$is_student = false;
$user_id = null;

if(isset($_SESSION["student_loggedin"]) && $_SESSION["student_loggedin"] === true){
    $is_student = true;
    $user_id = $_SESSION["student_id"];
} elseif(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    $is_student = false;
    $user_id = $_SESSION["user_id"];
} else {
    http_response_code(401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($is_student) {
        $success = mysqli_query($conn, "UPDATE student_notifications SET is_read = 1 WHERE student_id = $user_id");
    } else {
        $success = markAllNotificationsAsRead($conn, $user_id);
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => (bool)$success]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
