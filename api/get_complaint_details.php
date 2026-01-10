<?php
session_start();
require_once "../config.php";

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$complaint_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($complaint_id <= 0){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid complaint ID']);
    exit;
}

// Get complaint details - only allow users to see their own complaints or admins/staff to see all
$sql = "SELECT c.*, u1.full_name as handler_name, u2.full_name as lodger_name 
        FROM complaints c 
        LEFT JOIN users u1 ON c.handled_by = u1.user_id 
        LEFT JOIN users u2 ON c.lodged_by = u2.user_id 
        WHERE c.complaint_id = ?";

// Add permission check
if($_SESSION["role_id"] != 1 && $_SESSION["role_id"] != 5 && $_SESSION["role_id"] != 6) {
    $sql .= " AND c.lodged_by = ?";
}

if($stmt = mysqli_prepare($conn, $sql)){
    if($_SESSION["role_id"] != 1 && $_SESSION["role_id"] != 5 && $_SESSION["role_id"] != 6) {
        mysqli_stmt_bind_param($stmt, "ii", $complaint_id, $_SESSION["user_id"]);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $complaint_id);
    }
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($complaint = mysqli_fetch_assoc($result)){
            // Process image information
            $images = array();
            if(isset($complaint['image_path']) && !empty($complaint['image_path']) && trim($complaint['image_path']) !== '' && trim($complaint['image_path']) !== '0') {
                $images = array_filter(explode(",", trim($complaint['image_path'])));
                $images = array_map('trim', $images);
                $images = array_filter($images);
            }
            
            $complaint['images'] = $images;
            
            echo json_encode(['success' => true, 'complaint' => $complaint]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Complaint not found or access denied']);
        }
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>