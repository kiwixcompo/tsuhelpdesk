<?php
session_start();
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['student_loggedin']) || $_SESSION['student_loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$complaint_id = (int) ($_POST['complaint_id'] ?? 0);
$reply        = trim($_POST['reply'] ?? '');
$student_id   = (int) $_SESSION['student_id'];

if ($complaint_id <= 0 || empty($reply)) {
    echo json_encode(['success' => false, 'message' => 'Missing fields']);
    exit;
}

// Verify this complaint belongs to this student and is not resolved
$check = mysqli_prepare($conn,
    "SELECT complaint_id, status, handled_by FROM student_ict_complaints
     WHERE complaint_id = ? AND student_id = ?");
if (!$check) {
    echo json_encode(['success' => false, 'message' => 'DB error']);
    exit;
}
mysqli_stmt_bind_param($check, 'ii', $complaint_id, $student_id);
mysqli_stmt_execute($check);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
mysqli_stmt_close($check);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Complaint not found']);
    exit;
}
if (in_array($row['status'], ['Resolved', 'Auto-Resolved', 'Rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Cannot reply to a resolved complaint']);
    exit;
}

// Ensure student_replies table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_ict_replies (
    reply_id     INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    sender_type  ENUM('student','staff') NOT NULL DEFAULT 'student',
    sender_id    INT NOT NULL,
    reply_text   TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_complaint (complaint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ins = mysqli_prepare($conn,
    "INSERT INTO student_ict_replies (complaint_id, sender_type, sender_id, reply_text, created_at)
     VALUES (?, 'student', ?, ?, NOW())");
if (!$ins) {
    echo json_encode(['success' => false, 'message' => 'DB prepare error']);
    exit;
}
mysqli_stmt_bind_param($ins, 'iis', $complaint_id, $student_id, $reply);
$ok = mysqli_stmt_execute($ins);
mysqli_stmt_close($ins);

// Self-heal: Ensure notifications table does not have a foreign key constraint blocking ICT complaint IDs
// and has the complaint_type column
function selfHealNotificationsTable($conn) {
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if (!$table_check || mysqli_num_rows($table_check) === 0) {
        return;
    }

    // 1. Check and add complaint_type column if missing
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE 'complaint_type'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE notifications ADD COLUMN complaint_type VARCHAR(20) NOT NULL DEFAULT 'academic' AFTER complaint_id");
    }

    // 2. Drop foreign key constraint referencing complaints table
    $fk_query = "SELECT CONSTRAINT_NAME 
                 FROM information_schema.KEY_COLUMN_USAGE 
                 WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 'notifications' 
                   AND COLUMN_NAME = 'complaint_id' 
                   AND REFERENCED_TABLE_NAME = 'complaints'";
    $fk_res = mysqli_query($conn, $fk_query);
    if ($fk_res) {
        while ($row = mysqli_fetch_assoc($fk_res)) {
            $fk_name = $row['CONSTRAINT_NAME'];
            mysqli_query($conn, "ALTER TABLE notifications DROP FOREIGN KEY `$fk_name`");
        }
    }
}

if ($ok) {
    // Notify the handler (admin/i4cus staff) that student replied
    if ($row['handled_by']) {
        selfHealNotificationsTable($conn);
        $notif_ins = mysqli_prepare($conn,
            "INSERT INTO notifications (user_id, complaint_id, type, title, message, complaint_type, created_at)
             VALUES (?, ?, 'feedback_given', 'Student Reply on ICT Complaint', ?, 'ict', NOW())");
        if ($notif_ins) {
            $msg = "A student has replied to ICT complaint #{$complaint_id}.";
            mysqli_stmt_bind_param($notif_ins, 'iis', $row['handled_by'], $complaint_id, $msg);
            mysqli_stmt_execute($notif_ins);
            mysqli_stmt_close($notif_ins);
        }
    }
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save reply']);
}

