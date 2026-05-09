<?php
/**
 * Save email notification preferences for the logged-in user.
 */
session_start();
require_once "../config.php";
require_once "../includes/notification_prefs.php";

header('Content-Type: application/json');

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
ensureNotifPrefsTable($conn);

$on_forwarded             = !empty($_POST['on_forwarded'])             ? 1 : 0;
$on_ict_response          = !empty($_POST['on_ict_response'])          ? 1 : 0;
$on_status_change         = !empty($_POST['on_status_change'])         ? 1 : 0;
$on_new_student_complaint = !empty($_POST['on_new_student_complaint']) ? 1 : 0;
$on_new_complaint         = !empty($_POST['on_new_complaint'])         ? 1 : 0;
$on_feedback_received     = !empty($_POST['on_feedback_received'])     ? 1 : 0;

$sql = "INSERT INTO user_notification_prefs
            (user_id, on_forwarded, on_ict_response, on_status_change,
             on_new_student_complaint, on_new_complaint, on_feedback_received)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            on_forwarded             = VALUES(on_forwarded),
            on_ict_response          = VALUES(on_ict_response),
            on_status_change         = VALUES(on_status_change),
            on_new_student_complaint = VALUES(on_new_student_complaint),
            on_new_complaint         = VALUES(on_new_complaint),
            on_feedback_received     = VALUES(on_feedback_received)";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'iiiiiii',
    $user_id,
    $on_forwarded,
    $on_ict_response,
    $on_status_change,
    $on_new_student_complaint,
    $on_new_complaint,
    $on_feedback_received
);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => true, 'message' => 'Preferences saved']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save preferences']);
}
