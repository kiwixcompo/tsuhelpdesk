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
    reply_images TEXT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_complaint (complaint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Process reply image uploads
$reply_image_paths = array();
if(isset($_FILES["reply_images"]) && !empty($_FILES["reply_images"]["name"][0])){
    $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
    $target_dir = "../uploads/";
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    $files = $_FILES['reply_images'];
    // Normalize to array format if it's not already
    if (!is_array($files['name'])) {
        $files = array_map(fn($v) => [$v], $files);
    }
    
    $count = is_array($files['name']) ? count($files['name']) : 1;
    for ($i = 0; $i < $count; $i++) {
        $name  = is_array($files['name'])  ? $files['name'][$i]  : $files['name'];
        $tmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $size  = is_array($files['size'])  ? $files['size'][$i]  : $files['size'];
        $type  = is_array($files['type'])  ? $files['type'][$i]  : $files['type'];

        if ($error !== UPLOAD_ERR_OK || $size === 0) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!array_key_exists($ext, $allowed)) continue;
        if ($size > 5 * 1024 * 1024) continue; // 5MB max

        if (in_array($type, $allowed)) {
            $new_filename = "reply_" . uniqid() . "." . $ext;
            $target_file = $target_dir . $new_filename;
            
            if (move_uploaded_file($tmp, $target_file)) {
                chmod($target_file, 0644);
                $reply_image_paths[] = $new_filename;
            }
        }
    }
}

$reply_images_str = !empty($reply_image_paths) ? implode(",", $reply_image_paths) : null;

$ins = mysqli_prepare($conn,
    "INSERT INTO student_ict_replies (complaint_id, sender_type, sender_id, reply_text, reply_images, created_at)
     VALUES (?, 'student', ?, ?, ?, NOW())");
if (!$ins) {
    echo json_encode(['success' => false, 'message' => 'DB prepare error']);
    exit;
}
mysqli_stmt_bind_param($ins, 'iiss', $complaint_id, $student_id, $reply, $reply_images_str);
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

