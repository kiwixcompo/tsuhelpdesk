<?php
session_start();
require_once "config.php";

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
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// Check if notifications.php exists
if (!file_exists("includes/notifications.php")) {
    http_response_code(500);
    echo json_encode(['error' => 'Notifications helper not found']);
    exit;
}

require_once "includes/notifications.php";

// Get last 7 notifications for the user
$sql = "SELECT n.*, c.student_id, c.complaint_text 
        FROM notifications n 
        JOIN complaints c ON n.complaint_id = c.complaint_id 
        WHERE n.user_id = ? 
        ORDER BY n.created_at DESC 
        LIMIT 7";

$notifications = [];
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $notifications[] = $row;
        }
    } else {
        error_log("Error executing notification query: " . mysqli_error($conn));
    }
    mysqli_stmt_close($stmt);
} else {
    error_log("Error preparing notification query: " . mysqli_error($conn));
}

// Get unread count
$unread_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $unread_count = getUnreadNotificationCount($conn, $user_id);
} else {
    // Fallback count
    $count_sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    if ($count_stmt = mysqli_prepare($conn, $count_sql)) {
        mysqli_stmt_bind_param($count_stmt, "i", $user_id);
        if (mysqli_stmt_execute($count_stmt)) {
            $count_result = mysqli_stmt_get_result($count_stmt);
            if ($count_row = mysqli_fetch_assoc($count_result)) {
                $unread_count = $count_row['count'];
            }
        }
        mysqli_stmt_close($count_stmt);
    }
}

// Add error logging for debugging
error_log("Notifications API called for user: " . $user_id . " (student: " . ($is_student ? 'yes' : 'no') . ")");
error_log("Found " . count($notifications) . " notifications");

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'unread_count' => $unread_count,
    'debug' => [
        'user_id' => $_SESSION["user_id"],
        'notification_count' => count($notifications),
        'sql_executed' => true,
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);
?>