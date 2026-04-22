<?php
/**
 * Save email notification preferences for the logged-in user.
 * Used by department users to control which emails they receive.
 */
session_start();
require_once "../config.php";

header('Content-Type: application/json');

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Ensure the preferences table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_notification_prefs (
    pref_id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL UNIQUE,
    on_forwarded  TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Email when a complaint is forwarded to this department',
    on_ict_response TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Email when ICT adds a response/feedback to a forwarded complaint',
    on_status_change TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Email when the status of a forwarded complaint changes',
    on_new_student_complaint TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Email when any new student ICT complaint is submitted (admin-level)',
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$on_forwarded             = isset($_POST['on_forwarded'])             ? 1 : 0;
$on_ict_response          = isset($_POST['on_ict_response'])          ? 1 : 0;
$on_status_change         = isset($_POST['on_status_change'])         ? 1 : 0;
$on_new_student_complaint = isset($_POST['on_new_student_complaint']) ? 1 : 0;

$sql = "INSERT INTO user_notification_prefs
            (user_id, on_forwarded, on_ict_response, on_status_change, on_new_student_complaint)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            on_forwarded             = VALUES(on_forwarded),
            on_ict_response          = VALUES(on_ict_response),
            on_status_change         = VALUES(on_status_change),
            on_new_student_complaint = VALUES(on_new_student_complaint)";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'iiiii',
    $user_id,
    $on_forwarded,
    $on_ict_response,
    $on_status_change,
    $on_new_student_complaint
);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => true, 'message' => 'Preferences saved successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save preferences']);
}
