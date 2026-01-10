<?php
// Login Activity Tracker
// Include this file in your login/logout processes

function trackLoginActivity($conn, $user_id, $status = 'success', $failure_reason = null) {
    // Check if login_activities table exists
    $table_check = "SHOW TABLES LIKE 'login_activities'";
    $result = mysqli_query($conn, $table_check);
    
    if(mysqli_num_rows($result) == 0) {
        // Table doesn't exist, skip tracking
        return false;
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $session_id = session_id();
    
    // Handle proxy/load balancer IPs
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
        $ip_address = $_SERVER['HTTP_X_REAL_IP'];
    }
    
    $sql = "INSERT INTO login_activities (user_id, ip_address, user_agent, session_id, login_status, failure_reason) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "isssss", $user_id, $ip_address, $user_agent, $session_id, $status, $failure_reason);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    }
    
    return false;
}

function trackLogoutActivity($conn, $user_id) {
    // Check if login_activities table exists
    $table_check = "SHOW TABLES LIKE 'login_activities'";
    $result = mysqli_query($conn, $table_check);
    
    if(mysqli_num_rows($result) == 0) {
        return false;
    }
    
    $session_id = session_id();
    
    // Update the most recent login activity for this user/session
    $sql = "UPDATE login_activities 
            SET logout_time = NOW(), login_status = 'logout' 
            WHERE user_id = ? AND session_id = ? AND logout_time IS NULL 
            ORDER BY login_time DESC 
            LIMIT 1";
    
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "is", $user_id, $session_id);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    }
    
    return false;
}

function trackFailedLogin($conn, $username, $failure_reason = 'Invalid credentials') {
    // Check if login_activities table exists
    $table_check = "SHOW TABLES LIKE 'login_activities'";
    $result = mysqli_query($conn, $table_check);
    
    if(mysqli_num_rows($result) == 0) {
        return false;
    }
    
    // Try to get user ID from username
    $user_id = null;
    $user_sql = "SELECT user_id FROM users WHERE username = ?";
    if($user_stmt = mysqli_prepare($conn, $user_sql)) {
        mysqli_stmt_bind_param($user_stmt, "s", $username);
        if(mysqli_stmt_execute($user_stmt)) {
            $user_result = mysqli_stmt_get_result($user_stmt);
            if($user_row = mysqli_fetch_assoc($user_result)) {
                $user_id = $user_row['user_id'];
            }
        }
        mysqli_stmt_close($user_stmt);
    }
    
    if($user_id) {
        return trackLoginActivity($conn, $user_id, 'failed', $failure_reason);
    }
    
    return false;
}

// Clean up old login activities (optional - call periodically)
function cleanupOldLoginActivities($conn, $days_to_keep = 90) {
    $sql = "DELETE FROM login_activities WHERE login_time < DATE_SUB(NOW(), INTERVAL ? DAY)";
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $days_to_keep);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    }
    return false;
}
?>