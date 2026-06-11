<?php
/**
 * API to Toggle Student Result Verification Complaints
 * Allowed Roles: Admin=1, Director=3, Deputy Director=8
 */
session_start();
require_once "../config.php";

header('Content-Type: application/json');

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check allowed roles
if (!in_array($_SESSION["role_id"], [1, 3, 8])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$enabled = isset($_POST['enabled']) ? $_POST['enabled'] : '';
if ($enabled !== '1' && $enabled !== '0') {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Update settings table
$sql = "UPDATE settings SET setting_value = ? WHERE setting_key = 'result_verification_enabled'";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $enabled);
    if (mysqli_stmt_execute($stmt)) {
        // Update/invalidate the settings cache in session
        if (isset($_SESSION['app_settings'])) {
            $_SESSION['app_settings']['result_verification_enabled'] = $enabled;
        }
        echo json_encode(['success' => true, 'message' => 'Setting updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update database setting']);
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

mysqli_close($conn);
?>
