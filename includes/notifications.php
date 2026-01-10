<?php
// Notification helper functions

function createNotification($conn, $user_id, $complaint_id, $type, $title, $message) {
    $sql = "INSERT INTO notifications (user_id, complaint_id, type, title, message) VALUES (?, ?, ?, ?, ?)";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "iisss", $user_id, $complaint_id, $type, $title, $message);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    }
    return false;
}

function getUnreadNotifications($conn, $user_id) {
    $notifications = [];
    $sql = "SELECT n.*, c.student_id, c.complaint_text 
            FROM notifications n 
            JOIN complaints c ON n.complaint_id = c.complaint_id 
            WHERE n.user_id = ? AND n.is_read = 0 
            ORDER BY n.created_at DESC";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $notifications[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $notifications;
}

function getUnreadNotificationCount($conn, $user_id) {
    $count = 0;
    $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($result)) {
                $count = $row['count'];
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $count;
}

function markNotificationAsRead($conn, $notification_id, $user_id) {
    $sql = "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "ii", $notification_id, $user_id);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    }
    return false;
}

function markAllNotificationsAsRead($conn, $user_id) {
    $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    }
    return false;
}
?>