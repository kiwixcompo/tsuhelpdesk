<?php
session_start();
require_once "../config.php";

// Check if user is logged in and has appropriate permissions
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || 
   !in_array($_SESSION["role_id"], [1, 3, 8])) { // 1 = Admin, 3 = Director, 8 = Deputy Director ICT
    http_response_code(403);
    echo json_encode(['error' => 'Access denied - Admin, Director, or Deputy Director ICT role required']);
    exit;
}

// Get filter parameters
$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';

// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = '';

if ($filter_user > 0) {
    $where_conditions[] = "c.lodged_by = ?";
    $params[] = $filter_user;
    $param_types .= 'i';
}

if (!empty($filter_date_from)) {
    $where_conditions[] = "DATE(c.created_at) >= ?";
    $params[] = $filter_date_from;
    $param_types .= 's';
}

if (!empty($filter_date_to)) {
    $where_conditions[] = "DATE(c.created_at) <= ?";
    $params[] = $filter_date_to;
    $param_types .= 's';
}

if (!empty($filter_status) && $filter_status !== 'all') {
    $where_conditions[] = "c.status = ?";
    $params[] = $filter_status;
    $param_types .= 's';
}

if (!empty($filter_type) && $filter_type !== 'all') {
    switch ($filter_type) {
        case 'payment':
            $where_conditions[] = "c.is_payment_related = 1";
            break;
        case 'urgent':
            $where_conditions[] = "c.is_urgent = 1";
            break;
        case 'department':
            $where_conditions[] = "u1.role_id = 7";
            break;
    }
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Build the query
$sql = "SELECT 
            c.complaint_id,
            c.student_id,
            c.complaint_text,
            c.status,
            c.is_urgent,
            c.is_payment_related,
            c.created_at,
            c.updated_at,
            c.feedback,
            u1.full_name as lodged_by_name,
            u1.username as lodged_by_username,
            u2.full_name as handler_name,
            r1.role_name as lodger_role,
            r2.role_name as handler_role
        FROM complaints c
        LEFT JOIN users u1 ON c.lodged_by = u1.user_id
        LEFT JOIN users u2 ON c.handled_by = u2.user_id
        LEFT JOIN roles r1 ON u1.role_id = r1.role_id
        LEFT JOIN roles r2 ON u2.role_id = r2.role_id
        $where_clause
        ORDER BY c.created_at DESC";

// Execute query
$complaints = [];
if (!empty($params)) {
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $complaints[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
} else {
    $result = mysqli_query($conn, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $complaints[] = $row;
        }
    }
}

// Generate filename
$filename = 'complaints_export_' . date('Y-m-d_H-i-s');
if ($filter_user > 0) {
    $filename .= '_user_' . $filter_user;
}
if (!empty($filter_date_from) || !empty($filter_date_to)) {
    $filename .= '_date_' . ($filter_date_from ?: 'start') . '_to_' . ($filter_date_to ?: 'end');
}

if ($format === 'json') {
    // JSON Export
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
    
    echo json_encode([
        'export_info' => [
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $_SESSION['full_name'],
            'total_records' => count($complaints),
            'filters_applied' => [
                'user_id' => $filter_user ?: null,
                'date_from' => $filter_date_from ?: null,
                'date_to' => $filter_date_to ?: null,
                'status' => $filter_status ?: null,
                'type' => $filter_type ?: null
            ]
        ],
        'complaints' => $complaints
    ], JSON_PRETTY_PRINT);
    
} else {
    // CSV Export (default)
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    $headers = [
        'Complaint ID',
        'Student ID',
        'Complaint Text',
        'Status',
        'Priority',
        'Type',
        'Submitted Date',
        'Last Updated',
        'Lodged By',
        'Lodged By Username',
        'Lodger Role',
        'Handler',
        'Handler Role',
        'Feedback'
    ];
    
    fputcsv($output, $headers);
    
    // CSV Data
    foreach ($complaints as $complaint) {
        $row = [
            $complaint['complaint_id'],
            $complaint['student_id'],
            $complaint['complaint_text'],
            $complaint['status'],
            $complaint['is_urgent'] ? 'Urgent' : 'Normal',
            $complaint['is_payment_related'] ? 'Payment Related' : 'General',
            $complaint['created_at'],
            $complaint['updated_at'],
            $complaint['lodged_by_name'],
            $complaint['lodged_by_username'],
            $complaint['lodger_role'],
            $complaint['handler_name'] ?: 'Unassigned',
            $complaint['handler_role'] ?: 'N/A',
            $complaint['feedback'] ?: 'No feedback'
        ];
        
        fputcsv($output, $row);
    }
    
    fclose($output);
}
?>