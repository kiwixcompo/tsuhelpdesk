<?php
ob_start();
session_start();
require_once "../config.php";
header('Content-Type: application/json');

if (!isset($_SESSION["student_loggedin"]) || $_SESSION["student_loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$student_id    = (int) $_SESSION['student_id'];
$node_id       = substr($data['node_id']       ?? '', 0, 100);
$node_label    = substr($data['node_label']    ?? '', 0, 255);
$category      = substr($data['category']      ?? '', 0, 100);
$path_labels   = $data['path_labels']  ?? [];
$action_type   = $data['action_type']  ?? 'escalate';
$auto_response = $data['auto_response'] ?? '';
$escalated     = (int) ($data['escalated'] ?? 0);
$extra_fields  = json_encode($data['extra_fields'] ?? []);
$description   = $data['description']  ?? '';

$path_summary = implode(' → ', array_map('strval', $path_labels));
$full_description = $path_summary;
if ($description) {
    $full_description .= "\n\nAdditional details: " . $description;
}

$status         = ($action_type === 'auto_response' && !$escalated) ? 'Auto-Resolved' : 'Pending';
$admin_response = ($action_type === 'auto_response' && $auto_response) ? $auto_response : null;

$sql = "INSERT INTO student_ict_complaints
        (student_id, node_id, node_label, category, path_summary, description,
         action_type, auto_response, escalated, extra_fields, status, admin_response, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    app_log('error', 'ICT complaint prepare failed', ['error' => mysqli_error($conn)]);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

mysqli_stmt_bind_param($stmt, 'isssssssisss',
    $student_id, $node_id, $node_label, $category, $path_summary,
    $full_description, $action_type, $auto_response, $escalated,
    $extra_fields, $status, $admin_response
);

if (mysqli_stmt_execute($stmt)) {
    $complaint_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Notify student if auto-resolved
    if ($status === 'Auto-Resolved' && $auto_response) {
        $ns = mysqli_prepare($conn,
            "INSERT INTO student_notifications (student_id, complaint_id, title, message, created_at)
             VALUES (?, ?, 'Complaint Auto-Resolved', ?, NOW())");
        if ($ns) {
            $msg = "Your complaint has been automatically resolved. " . substr($auto_response, 0, 120);
            mysqli_stmt_bind_param($ns, 'iis', $student_id, $complaint_id, $msg);
            mysqli_stmt_execute($ns);
            mysqli_stmt_close($ns);
        }
    }

    echo json_encode([
        'success'       => true,
        'complaint_id'  => $complaint_id,
        'status'        => $status,
        'auto_response' => $auto_response,
    ]);
} else {
    app_log('error', 'ICT complaint insert failed', ['error' => mysqli_error($conn)]);
    echo json_encode(['success' => false, 'message' => 'Failed to save complaint: ' . mysqli_error($conn)]);
}
