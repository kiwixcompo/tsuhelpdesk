<?php
// Prevent any output before JSON
ob_start();

session_start();

// Set proper headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Clear any previous output
ob_clean();

// Error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once "../config.php";
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Configuration error',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ]);
    exit;
}

// Check database connection
if (!$conn) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'debug' => [
            'mysqli_error' => mysqli_connect_error()
        ]
    ]);
    exit;
}

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    echo json_encode([
        'error' => 'Not logged in',
        'debug' => [
            'session_exists' => session_id() ? 'yes' : 'no',
            'logged_in' => isset($_SESSION["loggedin"]) ? $_SESSION["loggedin"] : 'not set',
            'role_id' => isset($_SESSION["role_id"]) ? $_SESSION["role_id"] : 'not set',
            'user_id' => isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : 'not set'
        ]
    ]);
    exit;
}

if($_SESSION["role_id"] != 1){
    http_response_code(403);
    echo json_encode([
        'error' => 'Access denied - Admin role required',
        'debug' => [
            'current_role' => $_SESSION["role_id"],
            'required_role' => 1,
            'user_id' => $_SESSION["user_id"]
        ]
    ]);
    exit;
}

$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($user_id <= 0){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

// Get user details (removed last_login as it doesn't exist in the table)
$sql = "SELECT u.user_id, u.username, u.full_name, u.email, u.created_at, 
               r.role_name, r.role_id,
               (SELECT COUNT(*) FROM complaints WHERE lodged_by = u.user_id) as complaint_count,
               (SELECT COUNT(*) FROM complaints WHERE handled_by = u.user_id) as handled_count
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        WHERE u.user_id = ?";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($user = mysqli_fetch_assoc($result)){
            error_log("User found: " . print_r($user, true));
            // Get comprehensive activity information
            $activity_sql = "
                SELECT 'complaint_lodged' as type, created_at, complaint_id as ref_id, 
                       CONCAT('Lodged complaint #', complaint_id) as action,
                       complaint_text as details
                FROM complaints WHERE lodged_by = ? 
                UNION ALL
                SELECT 'feedback_given' as type, updated_at as created_at, complaint_id as ref_id, 
                       CONCAT('Provided feedback on complaint #', complaint_id) as action,
                       feedback as details
                FROM complaints WHERE handled_by = ? AND feedback IS NOT NULL
                UNION ALL
                SELECT 'reply_sent' as type, created_at, complaint_id as ref_id,
                       CONCAT('Replied to complaint #', complaint_id) as action,
                       reply_text as details
                FROM complaint_replies WHERE sender_id = ?
                UNION ALL
                SELECT 'message_sent' as type, created_at, NULL as ref_id,
                       CONCAT('Sent message: ', subject) as action,
                       message as details
                FROM messages WHERE sender_id = ?
                UNION ALL
                SELECT 'message_received' as type, created_at, NULL as ref_id,
                       CONCAT('Received message: ', subject) as action,
                       message as details
                FROM messages WHERE recipient_id = ?
                ORDER BY created_at DESC LIMIT 15";
            
            $activities = [];
            if($activity_stmt = mysqli_prepare($conn, $activity_sql)){
                mysqli_stmt_bind_param($activity_stmt, "iiiii", $user_id, $user_id, $user_id, $user_id, $user_id);
                if(mysqli_stmt_execute($activity_stmt)){
                    $activity_result = mysqli_stmt_get_result($activity_stmt);
                    while($activity = mysqli_fetch_assoc($activity_result)){
                        // Truncate details for display
                        if($activity['details'] && strlen($activity['details']) > 100){
                            $activity['details'] = substr($activity['details'], 0, 100) . '...';
                        }
                        $activities[] = $activity;
                    }
                }
                mysqli_stmt_close($activity_stmt);
            }
            
            // Get additional statistics
            $stats_sql = "
                SELECT 
                    (SELECT COUNT(*) FROM messages WHERE sender_id = ?) as messages_sent,
                    (SELECT COUNT(*) FROM messages WHERE recipient_id = ?) as messages_received,
                    (SELECT COUNT(*) FROM complaint_replies WHERE sender_id = ?) as replies_sent,
                    (SELECT COUNT(*) FROM notifications WHERE user_id = ?) as notifications_received,
                    (SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0) as unread_notifications
            ";
            
            $stats = [];
            if($stats_stmt = mysqli_prepare($conn, $stats_sql)){
                mysqli_stmt_bind_param($stats_stmt, "iiiii", $user_id, $user_id, $user_id, $user_id, $user_id);
                if(mysqli_stmt_execute($stats_stmt)){
                    $stats_result = mysqli_stmt_get_result($stats_stmt);
                    if($stats_row = mysqli_fetch_assoc($stats_result)){
                        $stats = $stats_row;
                    }
                }
                mysqli_stmt_close($stats_stmt);
            }
            
            $user['recent_activities'] = $activities;
            $user['activity_stats'] = $stats;
            
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            error_log("User not found for ID: " . $user_id);
            http_response_code(404);
            echo json_encode([
                'error' => 'User not found',
                'debug' => [
                    'user_id' => $user_id,
                    'query_executed' => true
                ]
            ]);
        }
    } else {
        error_log("Query execution failed: " . mysqli_error($conn));
        http_response_code(500);
        echo json_encode([
            'error' => 'Database query execution failed',
            'debug' => [
                'mysqli_error' => mysqli_error($conn)
            ]
        ]);
    }
    mysqli_stmt_close($stmt);
} else {
    error_log("Query preparation failed: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode([
        'error' => 'Database query preparation failed',
        'debug' => [
            'mysqli_error' => mysqli_error($conn)
        ]
    ]);
}
?>