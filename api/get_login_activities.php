<?php
session_start();
require_once "../config.php";

// Set JSON headers
header('Content-Type: application/json');

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if($user_id <= 0){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user ID']);
    exit;
}

// Check if login_activities table exists
$table_check = "SHOW TABLES LIKE 'login_activities'";
$result = mysqli_query($conn, $table_check);

if(mysqli_num_rows($result) == 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Login activities table does not exist',
        'activities' => [],
        'setup_required' => true
    ]);
    exit;
}

// Get login activities for the user
$sql = "SELECT 
            activity_id,
            login_time,
            logout_time,
            ip_address,
            user_agent,
            login_status,
            failure_reason,
            TIMESTAMPDIFF(MINUTE, login_time, COALESCE(logout_time, NOW())) as session_duration
        FROM login_activities 
        WHERE user_id = ? 
        ORDER BY login_time DESC 
        LIMIT 20";

$activities = [];
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            // Parse user agent for better display
            $browser = 'Unknown';
            $os = 'Unknown';
            
            if($row['user_agent']) {
                $user_agent = $row['user_agent'];
                
                // Simple browser detection
                if(strpos($user_agent, 'Chrome') !== false) $browser = 'Chrome';
                elseif(strpos($user_agent, 'Firefox') !== false) $browser = 'Firefox';
                elseif(strpos($user_agent, 'Safari') !== false) $browser = 'Safari';
                elseif(strpos($user_agent, 'Edge') !== false) $browser = 'Edge';
                
                // Simple OS detection
                if(strpos($user_agent, 'Windows') !== false) $os = 'Windows';
                elseif(strpos($user_agent, 'Mac') !== false) $os = 'macOS';
                elseif(strpos($user_agent, 'Linux') !== false) $os = 'Linux';
                elseif(strpos($user_agent, 'Android') !== false) $os = 'Android';
                elseif(strpos($user_agent, 'iOS') !== false) $os = 'iOS';
            }
            
            $row['browser'] = $browser;
            $row['operating_system'] = $os;
            $activities[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get summary statistics
$stats_sql = "SELECT 
                COUNT(*) as total_logins,
                COUNT(CASE WHEN login_status = 'success' THEN 1 END) as successful_logins,
                COUNT(CASE WHEN login_status = 'failed' THEN 1 END) as failed_logins,
                MAX(login_time) as last_login,
                AVG(TIMESTAMPDIFF(MINUTE, login_time, COALESCE(logout_time, NOW()))) as avg_session_duration
              FROM login_activities 
              WHERE user_id = ?";

$stats = [];
if($stats_stmt = mysqli_prepare($conn, $stats_sql)){
    mysqli_stmt_bind_param($stats_stmt, "i", $user_id);
    
    if(mysqli_stmt_execute($stats_stmt)){
        $stats_result = mysqli_stmt_get_result($stats_stmt);
        if($stats_row = mysqli_fetch_assoc($stats_result)){
            $stats = $stats_row;
        }
    }
    mysqli_stmt_close($stats_stmt);
}

echo json_encode([
    'success' => true,
    'activities' => $activities,
    'statistics' => $stats,
    'setup_required' => false
]);
?>