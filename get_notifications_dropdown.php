<?php
session_start();
require_once "config.php";

header('Content-Type: application/json');

// Determine session type
$is_student = isset($_SESSION["student_loggedin"]) && $_SESSION["student_loggedin"] === true;
$is_staff   = isset($_SESSION["loggedin"])         && $_SESSION["loggedin"]         === true;

if (!$is_student && !$is_staff) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$notifications = [];
$unread_count  = 0;

if ($is_student) {
    // ── Student notifications from student_notifications table ──
    $student_id = (int) $_SESSION["student_id"];

    $sql = "SELECT notification_id, student_id AS user_id, complaint_id,
                   title, message, is_read, created_at,
                   complaint_type
            FROM student_notifications
            WHERE student_id = ?
            ORDER BY created_at DESC
            LIMIT 7";

    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, 'i', $student_id);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) $notifications[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    $cr = mysqli_query($conn,
        "SELECT COUNT(*) c FROM student_notifications
         WHERE student_id=$student_id AND is_read=0");
    if ($cr && $row = mysqli_fetch_assoc($cr)) $unread_count = (int) $row['c'];

} else {
    // ── Staff notifications from notifications table ──
    $user_id = (int) $_SESSION["user_id"];

    // Proactive self-heal: Ensure notifications table has complaint_type column and drops foreign key
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE 'complaint_type'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN complaint_type VARCHAR(20) NOT NULL DEFAULT 'academic' AFTER complaint_id");
        $fk_query = "SELECT CONSTRAINT_NAME 
                     FROM information_schema.KEY_COLUMN_USAGE 
                     WHERE TABLE_SCHEMA = DATABASE() 
                       AND TABLE_NAME = 'notifications' 
                       AND COLUMN_NAME = 'complaint_id' 
                       AND REFERENCED_TABLE_NAME = 'complaints'";
        $fk_res = mysqli_query($conn, $fk_query);
        if ($fk_res) {
            while ($fk_row = mysqli_fetch_assoc($fk_res)) {
                $fk_name = $fk_row['CONSTRAINT_NAME'];
                mysqli_query($conn, "ALTER TABLE notifications DROP FOREIGN KEY `$fk_name`");
            }
        }
    }

    require_once "includes/notifications.php";

    $sql = "SELECT n.notification_id, n.user_id, n.complaint_id,
                   n.title, n.message, n.is_read, n.created_at,
                   n.complaint_type
            FROM notifications n
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT 7";

    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) $notifications[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    $unread_count = getUnreadNotificationCount($conn, $user_id);
}

echo json_encode([
    'success'        => true,
    'notifications'  => $notifications,
    'unread_count'   => $unread_count,
]);
