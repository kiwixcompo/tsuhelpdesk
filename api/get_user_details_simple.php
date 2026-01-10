<?php
// Prevent any output before JSON
ob_start();
ob_clean();

// Start session
session_start();

// Set JSON headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Disable error display to prevent HTML output
ini_set('display_errors', 0);

// Function to output JSON and exit
function outputJson($data) {
    ob_clean();
    echo json_encode($data);
    exit;
}

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    outputJson([
        'error' => 'Not logged in',
        'debug' => [
            'session_id' => session_id(),
            'logged_in' => isset($_SESSION["loggedin"]) ? $_SESSION["loggedin"] : 'not set'
        ]
    ]);
}

// Check if user is admin
if(!isset($_SESSION["role_id"]) || $_SESSION["role_id"] != 1) {
    outputJson([
        'error' => 'Access denied - Admin role required',
        'debug' => [
            'role_id' => isset($_SESSION["role_id"]) ? $_SESSION["role_id"] : 'not set',
            'required_role' => 1
        ]
    ]);
}

// Get user ID parameter
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if($user_id <= 0) {
    outputJson([
        'error' => 'Invalid user ID',
        'debug' => [
            'provided_id' => $_GET['id'] ?? 'not provided'
        ]
    ]);
}

// Include database config
try {
    require_once "../config.php";
} catch (Exception $e) {
    outputJson([
        'error' => 'Configuration error',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ]);
}

// Check database connection
if (!isset($conn) || !$conn) {
    outputJson([
        'error' => 'Database connection failed',
        'debug' => [
            'mysqli_error' => mysqli_connect_error()
        ]
    ]);
}

// Get user details (removed last_login as it doesn't exist in the table)
$sql = "SELECT u.user_id, u.username, u.full_name, u.email, u.created_at, 
               r.role_name, r.role_id,
               (SELECT COUNT(*) FROM complaints WHERE lodged_by = u.user_id) as complaint_count,
               (SELECT COUNT(*) FROM complaints WHERE handled_by = u.user_id) as handled_count
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        WHERE u.user_id = ?";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    outputJson([
        'error' => 'Database query preparation failed',
        'debug' => [
            'mysqli_error' => mysqli_error($conn)
        ]
    ]);
}

mysqli_stmt_bind_param($stmt, "i", $user_id);

if (!mysqli_stmt_execute($stmt)) {
    outputJson([
        'error' => 'Database query execution failed',
        'debug' => [
            'mysqli_error' => mysqli_error($conn)
        ]
    ]);
}

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    outputJson([
        'error' => 'User not found',
        'debug' => [
            'user_id' => $user_id
        ]
    ]);
}

mysqli_stmt_close($stmt);

// Get basic activity (simplified for now)
$activities = [];
$activity_sql = "SELECT 'complaint' as type, created_at, complaint_id as ref_id, 
                        CONCAT('Lodged complaint #', complaint_id) as action
                 FROM complaints WHERE lodged_by = ? 
                 ORDER BY created_at DESC LIMIT 5";

$activity_stmt = mysqli_prepare($conn, $activity_sql);
if ($activity_stmt) {
    mysqli_stmt_bind_param($activity_stmt, "i", $user_id);
    if (mysqli_stmt_execute($activity_stmt)) {
        $activity_result = mysqli_stmt_get_result($activity_stmt);
        while ($activity = mysqli_fetch_assoc($activity_result)) {
            $activities[] = $activity;
        }
    }
    mysqli_stmt_close($activity_stmt);
}

// Get basic stats
$stats = [
    'messages_sent' => 0,
    'messages_received' => 0,
    'replies_sent' => 0,
    'notifications_received' => 0,
    'unread_notifications' => 0
];

$stats_sql = "SELECT 
                (SELECT COUNT(*) FROM messages WHERE sender_id = ?) as messages_sent,
                (SELECT COUNT(*) FROM messages WHERE recipient_id = ?) as messages_received,
                (SELECT COUNT(*) FROM notifications WHERE user_id = ?) as notifications_received";

$stats_stmt = mysqli_prepare($conn, $stats_sql);
if ($stats_stmt) {
    mysqli_stmt_bind_param($stats_stmt, "iii", $user_id, $user_id, $user_id);
    if (mysqli_stmt_execute($stats_stmt)) {
        $stats_result = mysqli_stmt_get_result($stats_stmt);
        if ($stats_row = mysqli_fetch_assoc($stats_result)) {
            $stats = array_merge($stats, $stats_row);
        }
    }
    mysqli_stmt_close($stats_stmt);
}

// Prepare response
$user['recent_activities'] = $activities;
$user['activity_stats'] = $stats;

// Output successful response
outputJson([
    'success' => true,
    'user' => $user
]);
?>