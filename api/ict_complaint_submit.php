<?php
ob_start();
session_start();
require_once "../config.php";

// Must set header before any output
header('Content-Type: application/json');

// Catch any PHP output that would break JSON
ob_clean();

if (!isset($_SESSION["student_loggedin"]) || $_SESSION["student_loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$data = [];
if (isset($_POST['payload'])) {
    $data = json_decode($_POST['payload'], true);
} else {
    $data = json_decode(file_get_contents('php://input'), true);
}

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid request body']);
    exit;
}

$attachment_path = null;
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = '../uploads/complaints/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    
    $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','pdf','doc','docx'];
    if (in_array($ext, $allowed)) {
        $filename = uniqid('comp_') . '_' . time() . '.' . $ext;
        $dest = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
            $attachment_path = 'uploads/complaints/' . $filename;
        }
    }
}

$student_id    = (int) $_SESSION['student_id'];
$node_id       = substr($data['node_id']        ?? '', 0, 100);
$node_label    = substr($data['node_label']     ?? '', 0, 255);
$category      = substr($data['category']       ?? '', 0, 100);
$path_labels   = $data['path_labels']   ?? [];
$action_type   = $data['action_type']   ?? 'escalate';
$auto_response = $data['auto_response'] ?? '';
$escalated     = (int) ($data['escalated'] ?? 0);
$extra_fields  = json_encode($data['extra_fields'] ?? []);
$description   = $data['description']   ?? '';

$path_summary     = implode(' → ', array_map('strval', $path_labels));
$full_description = $path_summary;
if ($description) {
    $full_description .= "\n\nAdditional details: " . $description;
}

$status         = ($action_type === 'auto_response' && !$escalated) ? 'Auto-Resolved' : 'Pending';
$admin_response = ($action_type === 'auto_response' && $auto_response) ? $auto_response : null;

// Auto-create table if it doesn't exist
$create_sql = "CREATE TABLE IF NOT EXISTS student_ict_complaints (
    complaint_id  INT AUTO_INCREMENT PRIMARY KEY,
    student_id    INT NOT NULL,
    node_id       VARCHAR(100) NOT NULL,
    node_label    VARCHAR(255) NOT NULL,
    category      VARCHAR(100) NOT NULL DEFAULT '',
    path_summary  TEXT NOT NULL,
    description   TEXT,
    action_type   VARCHAR(20) NOT NULL DEFAULT 'escalate',
    auto_response TEXT,
    escalated     TINYINT(1) NOT NULL DEFAULT 0,
    extra_fields  TEXT,
    attachment_path VARCHAR(255) NULL,
    status        VARCHAR(30) NOT NULL DEFAULT 'Pending',
    admin_response TEXT,
    handled_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student  (student_id),
    INDEX idx_status   (status),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $create_sql); // silently create if missing

// Add attachment_path column if it doesn't already exist — suppress duplicate column error
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM student_ict_complaints LIKE 'attachment_path'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE student_ict_complaints ADD COLUMN attachment_path VARCHAR(255) NULL AFTER extra_fields");
}
// Also add forwarded_to if missing
$fw_check = mysqli_query($conn, "SHOW COLUMNS FROM student_ict_complaints LIKE 'forwarded_to'");
if ($fw_check && mysqli_num_rows($fw_check) === 0) {
    mysqli_query($conn, "ALTER TABLE student_ict_complaints ADD COLUMN forwarded_to VARCHAR(255) NULL DEFAULT NULL");
}

$sql = "INSERT INTO student_ict_complaints
        (student_id, node_id, node_label, category, path_summary, description,
         action_type, auto_response, escalated, extra_fields, attachment_path, status, admin_response, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB prepare error: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'isssssssissss',
    $student_id, $node_id, $node_label, $category, $path_summary,
    $full_description, $action_type, $auto_response, $escalated,
    $extra_fields, $attachment_path, $status, $admin_response
);

if (mysqli_stmt_execute($stmt)) {
    $complaint_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    // Notify student if auto-resolved
    if ($status === 'Auto-Resolved' && $auto_response) {
        // Auto-create notifications table if missing
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_notifications (
            notification_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            complaint_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
    $err = mysqli_error($conn);
    if (function_exists('app_log')) app_log('error', 'ICT complaint insert failed', ['error' => $err]);
    echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $err]);
}
