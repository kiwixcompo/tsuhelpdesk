<?php
session_start();
require_once '../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['student_loggedin']) || $_SESSION['student_loggedin'] !== true) {
    echo json_encode(['success' => false]);
    exit;
}

$ids = $_POST['ids'] ?? [];
if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false]);
    exit;
}

$ids = array_map('intval', $ids);
$placeholders = implode(',', $ids);
$student_id = (int) $_SESSION['student_id'];

$result = mysqli_query($conn,
    "UPDATE student_notifications SET is_read = 1
     WHERE notification_id IN ($placeholders) AND student_id = $student_id"
);

echo json_encode(['success' => (bool)$result]);
