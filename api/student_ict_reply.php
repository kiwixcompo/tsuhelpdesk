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

if ($ok) {
    // Notify the handler (admin/i4cus staff) that student replied
    if ($row['handled_by']) {
        $notif_ins = mysqli_prepare($conn,
            "INSERT INTO notifications (user_id, complaint_id, type, title, message, created_at)
             VALUES (?, ?, 'feedback_given', 'Student Reply on ICT Complaint', ?, NOW())");
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
