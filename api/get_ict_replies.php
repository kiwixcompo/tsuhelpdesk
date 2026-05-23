<?php
session_start();
require_once "../config.php";

header('Content-Type: application/json');

// Check if user is logged in (staff only)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$complaint_id = isset($_GET['complaint_id']) ? intval($_GET['complaint_id']) : 0;

if($complaint_id <= 0){
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid complaint ID']);
    exit;
}

$sql = "SELECT r.*, CONCAT(s.first_name, ' ', s.last_name) AS student_name 
        FROM student_ict_replies r
        LEFT JOIN students s ON r.sender_id = s.student_id AND r.sender_type = 'student'
        WHERE r.complaint_id = ?
        ORDER BY r.created_at ASC";

$replies = [];
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $complaint_id);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $replies[] = [
                'reply_id' => $row['reply_id'],
                'sender_type' => $row['sender_type'],
                'sender_name' => $row['sender_type'] === 'student' ? ($row['student_name'] ?: 'Student') : 'Staff',
                'reply_text' => $row['reply_text'],
                'created_at' => date('M d, Y h:i A', strtotime($row['created_at']))
            ];
        }
        echo json_encode(['success' => true, 'replies' => $replies]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database execution error']);
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database prepare error']);
}
?>
