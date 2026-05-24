<?php
/**
 * Get Historical Feedback API
 * Retrieves previous treated/resolved complaints of the same category with admin responses.
 * Used by Puter.js AI to learn and suggest auto-responses.
 */
ob_start();
session_start();
require_once "../config.php";

header('Content-Type: application/json');
ob_clean();

// Check if user is logged in (either staff/admin or student)
if (!isset($_SESSION["loggedin"]) && !isset($_SESSION["student_loggedin"])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$category = trim($_GET['category'] ?? '');
$complaint_id = (int) ($_GET['complaint_id'] ?? 0);

if (empty($category)) {
    echo json_encode(['success' => false, 'message' => 'Category is required']);
    exit;
}

// Fetch past resolved complaints from all categories (prioritizing same category first) to allow instant global AI learning
$sql = "SELECT description, admin_response, node_label, category 
        FROM student_ict_complaints 
        WHERE admin_response IS NOT NULL 
          AND admin_response != '' 
          AND complaint_id != ?
        ORDER BY (CASE WHEN category = ? THEN 1 ELSE 2 END) ASC, created_at DESC 
        LIMIT 15";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'is', $complaint_id, $category);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$history = [];
while ($row = mysqli_fetch_assoc($result)) {
    $history[] = [
        'description' => $row['description'],
        'admin_response' => $row['admin_response'],
        'node_label' => $row['node_label']
    ];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'history' => $history
]);
