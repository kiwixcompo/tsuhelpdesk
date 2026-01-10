<?php
session_start();
require_once "config.php";
header('Content-Type: application/json');

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get filters from GET params
$last_id = isset($_GET['last_id']) && is_numeric($_GET['last_id']) ? intval($_GET['last_id']) : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$department = isset($_GET['department']) ? $_GET['department'] : '';
$is_i4cus = isset($_GET['is_i4cus']) ? intval($_GET['is_i4cus']) : null;
$is_payment_related = isset($_GET['is_payment_related']) ? intval($_GET['is_payment_related']) : null;
$role_id = isset($_SESSION['role_id']) ? intval($_SESSION['role_id']) : null;

$where = [];
$params = [];
$param_types = '';

if ($last_id > 0) {
    $where[] = "c.complaint_id > ?";
    $params[] = $last_id;
    $param_types .= 'i';
}
if ($status !== '') {
    $where[] = "c.status = ?";
    $params[] = $status;
    $param_types .= 's';
}
if ($department !== '') {
    $where[] = "c.department = ?";
    $params[] = $department;
    $param_types .= 's';
}
if ($is_i4cus !== null) {
    $where[] = "c.is_i4cus = ?";
    $params[] = $is_i4cus;
    $param_types .= 'i';
}
if ($is_payment_related !== null) {
    $where[] = "c.is_payment_related = ?";
    $params[] = $is_payment_related;
    $param_types .= 'i';
}

// Role-based filtering (optional, can be expanded as needed)
if ($role_id === 2) { // Staff - only regular complaints
    $where[] = "c.is_i4cus = 0 AND c.is_payment_related = 0";
} else if ($role_id === 5) { // i4Cus Staff
    $where[] = "c.is_i4cus = 1";
} else if ($role_id === 6) { // Payment Admin
    $where[] = "c.is_payment_related = 1";
} else if ($role_id === 0) { // Regular user
    $where[] = "c.lodged_by = ?";
    $params[] = $_SESSION['user_id'];
    $param_types .= 'i';
}

$where_clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT c.*, u1.full_name AS lodged_by_name, u2.full_name AS handler_name FROM complaints c LEFT JOIN users u1 ON c.lodged_by = u1.user_id LEFT JOIN users u2 ON c.handled_by = u2.user_id $where_clause ORDER BY c.complaint_id DESC LIMIT 20";

$complaints = [];
if($stmt = mysqli_prepare($conn, $sql)){
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    }
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $complaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

echo json_encode(['complaints' => $complaints]); 