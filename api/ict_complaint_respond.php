<?php
/**
 * ICT Complaint Response API
 * Handles response text + image uploads from i4Cus, Payment Admin, and ICT Admin.
 * Stores response in admin_response and image paths in response_images.
 */
ob_start();
session_start();
require_once "../config.php";

header('Content-Type: application/json');
ob_clean();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Allow roles: Admin=1, Director=3, Deputy Director=8, i4Cus=5, Payment Admin=6
$allowed_roles = [1, 3, 5, 6, 8];
if (!in_array($_SESSION['role_id'], $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$complaint_id = (int) ($_POST['complaint_id'] ?? 0);
$response     = trim($_POST['response'] ?? '');
$status       = $_POST['status'] ?? '';

if ($complaint_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid complaint ID']);
    exit;
}

// Ensure response_images column exists
$col = mysqli_query($conn, "SHOW COLUMNS FROM student_ict_complaints LIKE 'response_images'");
if ($col && mysqli_num_rows($col) === 0) {
    mysqli_query($conn, "ALTER TABLE student_ict_complaints ADD COLUMN response_images TEXT NULL DEFAULT NULL AFTER admin_response");
}

// Handle image uploads
$image_paths = [];
if (!empty($_FILES['response_images'])) {
    $upload_dir = '../uploads/responses/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $files = $_FILES['response_images'];

    // Normalize to array format
    if (!is_array($files['name'])) {
        $files = array_map(fn($v) => [$v], $files);
    }

    $count = is_array($files['name']) ? count($files['name']) : 1;
    for ($i = 0; $i < $count; $i++) {
        $name  = is_array($files['name'])  ? $files['name'][$i]  : $files['name'];
        $tmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $size  = is_array($files['size'])  ? $files['size'][$i]  : $files['size'];

        if ($error !== UPLOAD_ERR_OK || $size === 0) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) continue;
        if ($size > 5 * 1024 * 1024) continue; // 5MB max

        $filename = 'resp_' . $complaint_id . '_' . uniqid() . '.' . $ext;
        $dest     = $upload_dir . $filename;
        if (move_uploaded_file($tmp, $dest)) {
            $image_paths[] = 'uploads/responses/' . $filename;
        }
    }
}

// Build update query
$valid_statuses = ['Pending', 'Under Review', 'Resolved', 'Rejected', 'Auto-Resolved'];
if (!in_array($status, $valid_statuses)) $status = '';

$images_json = !empty($image_paths) ? json_encode($image_paths) : null;

// Get existing response_images to append
if ($images_json !== null) {
    $existing = mysqli_query($conn,
        "SELECT response_images FROM student_ict_complaints WHERE complaint_id = $complaint_id");
    if ($existing && $row = mysqli_fetch_assoc($existing)) {
        $existing_imgs = json_decode($row['response_images'] ?? '[]', true) ?: [];
        $all_imgs = array_merge($existing_imgs, $image_paths);
        $images_json = json_encode($all_imgs);
    }
}

// Build SQL
if (!empty($status) && !empty($response) && $images_json !== null) {
    $sql  = "UPDATE student_ict_complaints SET admin_response=?, response_images=?, status=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sssii', $response, $images_json, $status, $_SESSION['user_id'], $complaint_id);
} elseif (!empty($status) && !empty($response)) {
    $sql  = "UPDATE student_ict_complaints SET admin_response=?, status=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ssii', $response, $status, $_SESSION['user_id'], $complaint_id);
} elseif (!empty($response) && $images_json !== null) {
    $sql  = "UPDATE student_ict_complaints SET admin_response=?, response_images=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ssii', $response, $images_json, $_SESSION['user_id'], $complaint_id);
} elseif (!empty($response)) {
    $sql  = "UPDATE student_ict_complaints SET admin_response=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sii', $response, $_SESSION['user_id'], $complaint_id);
} elseif ($images_json !== null) {
    $sql  = "UPDATE student_ict_complaints SET response_images=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sii', $images_json, $_SESSION['user_id'], $complaint_id);
} else {
    echo json_encode(['success' => false, 'message' => 'Nothing to update']);
    exit;
}

if (!$stmt || !mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save response: ' . mysqli_error($conn)]);
    exit;
}
mysqli_stmt_close($stmt);

// Notify student
$get = mysqli_prepare($conn, "SELECT student_id, node_label FROM student_ict_complaints WHERE complaint_id=?");
if ($get) {
    mysqli_stmt_bind_param($get, 'i', $complaint_id);
    mysqli_stmt_execute($get);
    $grow = mysqli_fetch_assoc(mysqli_stmt_get_result($get));
    mysqli_stmt_close($get);

    if ($grow) {
        $sid   = $grow['student_id'];
        $topic = $grow['node_label'];
        $title = "Response on Your ICT Complaint";
        $notif_msg = "A response has been added to your complaint regarding \"$topic\".";

        $ns = mysqli_prepare($conn,
            "INSERT INTO student_notifications (student_id, complaint_id, title, message, created_at)
             VALUES (?,?,?,?,NOW())");
        if ($ns) {
            mysqli_stmt_bind_param($ns, 'iiss', $sid, $complaint_id, $title, $notif_msg);
            mysqli_stmt_execute($ns);
            mysqli_stmt_close($ns);
        }
    }
}

echo json_encode([
    'success'      => true,
    'message'      => 'Response saved successfully.',
    'image_count'  => count($image_paths),
]);
