<?php
session_start();
require_once "../config.php";

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get search query
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if(empty($query) || strlen($query) < 2){
    echo json_encode(['users' => []]);
    exit;
}

// Search users by full name or username
$sql = "SELECT u.user_id, u.username, u.full_name, u.email, u.created_at, r.role_name, r.role_id
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        WHERE (u.full_name LIKE ? OR u.username LIKE ?) 
        AND u.user_id != ?
        ORDER BY u.full_name ASC 
        LIMIT 10";

$users = [];
if($stmt = mysqli_prepare($conn, $sql)){
    $search_term = "%{$query}%";
    mysqli_stmt_bind_param($stmt, "ssi", $search_term, $search_term, $_SESSION["user_id"]);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $users[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

header('Content-Type: application/json');
echo json_encode(['users' => $users]);
?>