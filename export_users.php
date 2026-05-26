<?php
// Load global security settings and headers
require_once __DIR__ . '/security-config.php';

session_start();

// Check if user is logged in and is admin (role_id = 1)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    header("location: index.php");
    exit;
}

require_once "config.php";

// Get role filter if specified
$role_filter = isset($_GET['role_id']) ? $_GET['role_id'] : 'all';

// Fetch users based on role filter
if ($role_filter === 'all' || !is_numeric($role_filter)) {
    $sql = "SELECT u.full_name, u.username, r.role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            ORDER BY r.role_name ASC, u.full_name ASC";
    $stmt = mysqli_prepare($conn, $sql);
} else {
    $role_id = (int)$role_filter;
    $sql = "SELECT u.full_name, u.username, r.role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.role_id = ? 
            ORDER BY u.full_name ASC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $role_id);
}

// Execute query
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}

// Get the role name for filename if a single role is selected
$filename_suffix = "all_roles";
if ($role_filter !== 'all' && is_numeric($role_filter)) {
    $role_id = (int)$role_filter;
    $role_sql = "SELECT role_name FROM roles WHERE role_id = ?";
    if ($role_stmt = mysqli_prepare($conn, $role_sql)) {
        mysqli_stmt_bind_param($role_stmt, "i", $role_id);
        mysqli_stmt_execute($role_stmt);
        $role_res = mysqli_stmt_get_result($role_stmt);
        if ($role_row = mysqli_fetch_assoc($role_res)) {
            $filename_suffix = strtolower(str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9 ]/', '', $role_row['role_name'])));
        }
        mysqli_stmt_close($role_stmt);
    }
}

// Generate filename
$filename = "users_export_" . $filename_suffix . "_" . date("Ymd_His") . ".csv";

// Force download headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open PHP output stream
$output = fopen('php://output', 'w');

// Prepend UTF-8 BOM so Excel opens it with correct UTF-8 encoding
fwrite($output, "\xEF\xBB\xBF");

// Output CSV headers (Full Name, Username, Role)
fputcsv($output, array('Full Name', 'Username', 'Role'));

// Output data rows
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        fputcsv($output, array(
            $row['full_name'],
            '@' . $row['username'], // Prepend @ to username for standard formatting as displayed in system
            $row['role_name']
        ));
    }
}

// Close output stream
fclose($output);
exit;
?>
