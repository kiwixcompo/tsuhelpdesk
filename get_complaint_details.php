<?php
session_start();
require_once "config.php";

// Check if user is logged in and has appropriate role
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role_id"], [1, 3, 4])){
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check if complaint ID is provided
if(!isset($_GET['complaint_id']) || !is_numeric($_GET['complaint_id'])){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid complaint ID']);
    exit;
}

$complaint_id = intval($_GET['complaint_id']);

// Fetch complaint details with user information
$sql = "SELECT c.*, 
               u1.full_name AS lodged_by_name,
               u2.full_name AS handler_name,
               u1.email AS lodged_by_email,
               u2.email AS handler_email
        FROM complaints c 
        LEFT JOIN users u1 ON c.lodged_by = u1.user_id
        LEFT JOIN users u2 ON c.handled_by = u2.user_id
        WHERE c.complaint_id = ?";

$complaint = null;
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $complaint_id);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            $complaint = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

if(!$complaint){
    http_response_code(404);
    echo json_encode(['error' => 'Complaint not found']);
    exit;
}

// Helper function to get image URL
function getImageUrl($image) {
    $image = trim($image);
    if (empty($image) || $image === '0') {
        return '';
    }
    $filename = basename($image);
    return 'public_image.php?img=' . urlencode($filename);
}

// Format the complaint data for display
$formatted_complaint = [
    'complaint_id' => $complaint['complaint_id'],
    'student_id' => $complaint['student_id'] ?: 'Not provided',
    'department_name' => $complaint['department_name'] ?: 'Not specified',
    'staff_name' => $complaint['staff_name'] ?: 'Not specified',
    'complaint_text' => $complaint['complaint_text'],
    'status' => $complaint['status'],
    'feedback' => $complaint['feedback'] ?: 'No feedback provided',
    'is_urgent' => $complaint['is_urgent'] ? 'Yes' : 'No',
    'is_payment_related' => $complaint['is_payment_related'] ? 'Yes' : 'No',
    'is_i4cus' => $complaint['is_i4cus'] ? 'Yes' : 'No',
    'is_staff_complaint' => $complaint['is_staff_complaint'] ? 'Yes' : 'No',
    'lodged_by_name' => $complaint['lodged_by_name'] ?: 'Unknown',
    'lodged_by_email' => $complaint['lodged_by_email'] ?: 'Not available',
    'handler_name' => $complaint['handler_name'] ?: 'Not assigned',
    'handler_email' => $complaint['handler_email'] ?: 'Not available',
    'created_at' => date('F j, Y \a\t g:i A', strtotime($complaint['created_at'])),
    'updated_at' => $complaint['updated_at'] ? date('F j, Y \a\t g:i A', strtotime($complaint['updated_at'])) : 'Not updated',
    'images' => []
];

// Process images if any
if(!empty($complaint['image_path'])){
    $image_paths = explode(',', $complaint['image_path']);
    foreach($image_paths as $image_path){
        $image_url = getImageUrl($image_path);
        if($image_url){
            $formatted_complaint['images'][] = $image_url;
        }
    }
}

// Set content type to JSON
header('Content-Type: application/json');
echo json_encode($formatted_complaint);
?> 