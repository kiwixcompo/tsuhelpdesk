<?php
/**
 * Get Historical Department Feedback API
 * Retrieves previous treated/resolved department complaints with admin feedback/responses.
 * Used to suggest past responses for department complaints.
 */
ob_start();
session_start();
require_once "../config.php";

header('Content-Type: application/json');
ob_clean();

// Check if user is logged in
if (!isset($_SESSION["loggedin"])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$complaint_id = (int) ($_GET['complaint_id'] ?? 0);

// Fetch past resolved department complaints with feedback (role 7 users are departments)
$sql = "SELECT c.complaint_text, c.feedback 
        FROM complaints c 
        JOIN users u ON c.lodged_by = u.user_id
        WHERE u.role_id = 7 
          AND c.feedback IS NOT NULL 
          AND c.feedback != '' 
          AND c.complaint_id != ?
        ORDER BY c.created_at DESC 
        LIMIT 15";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 'i', $complaint_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$history = [];
while ($row = mysqli_fetch_assoc($result)) {
    $history[] = [
        'complaint_text' => $row['complaint_text'],
        'feedback' => $row['feedback']
    ];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'history' => $history
]);
