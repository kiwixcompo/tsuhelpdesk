<?php
// Start output buffering to prevent header issues
ob_start();
session_start();

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "calendar_helper.php";

// Fetch app settings
$app_name = 'TSU ICT Complaint Desk'; // Default value
$app_logo = '';
$app_favicon = '';

$sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('app_name', 'app_logo', 'app_favicon')";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        switch($row['setting_key']) {
            case 'app_name':
                $app_name = $row['setting_value'] ?: 'TSU ICT Complaint Desk';
                break;
            case 'app_logo':
                $app_logo = $row['setting_value'];
                break;
            case 'app_favicon':
                $app_favicon = $row['setting_value'];
                break;
        }
    }
}

// Fetch user's super admin status if not set in session
if(!isset($_SESSION["is_super_admin"])){
    $sql = "SELECT is_super_admin FROM users WHERE user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)){
                $_SESSION["is_super_admin"] = $row["is_super_admin"] ?? 0;
            } else {
                $_SESSION["is_super_admin"] = 0;
            }
        } else {
            $_SESSION["is_super_admin"] = 0;
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION["is_super_admin"] = 0;
    }
}

// Process bulk delete
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["bulk_delete"])){
    if(isset($_POST["complaint_ids"]) && is_array($_POST["complaint_ids"]) && !empty($_POST["complaint_ids"])){
        $complaint_ids = array_map('intval', $_POST["complaint_ids"]);
        $placeholders = str_repeat('?,', count($complaint_ids) - 1) . '?';
        $types = str_repeat('i', count($complaint_ids));
        
        $sql = "DELETE FROM complaints WHERE complaint_id IN ($placeholders)";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, $types, ...$complaint_ids);
            
            if(mysqli_stmt_execute($stmt)){
                $deleted_count = mysqli_stmt_affected_rows($stmt);
                // Clean buffer before redirect
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header("Location: admin.php?success=bulk_deleted&count=" . $deleted_count);
                exit();
            } else{
                $error_message = "Failed to delete complaints. Please try again.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please select at least one complaint to delete.";
    }
}

// Process complaint status update
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_complaint"])){
    $complaint_id = $_POST["complaint_id"];
    $status = $_POST["status"];
    $feedback = trim($_POST["feedback"]);
    $feedback_type = !empty($_POST["feedback_type"]) ? $_POST["feedback_type"] : NULL;
    $is_urgent = isset($_POST["is_urgent"]) ? 1 : 0;
    $is_payment_related = isset($_POST["is_payment_related"]) ? 1 : 0;
    $is_i4cus = isset($_POST["is_i4cus"]) ? 1 : 0;
    $handled_by = $_SESSION["user_id"];
    
    // Handle admin feedback image uploads
    $admin_feedback_image_paths = array();
    if(isset($_FILES["admin_feedback_images"]) && !empty($_FILES["admin_feedback_images"]["name"][0])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        
        // Create uploads directory if it doesn't exist
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        // Loop through each uploaded file
        foreach($_FILES["admin_feedback_images"]["tmp_name"] as $key => $tmp_name){
            if($_FILES["admin_feedback_images"]["error"][$key] == 0){
                $filename = $_FILES["admin_feedback_images"]["name"][$key];
                $filetype = $_FILES["admin_feedback_images"]["type"][$key];
                $filesize = $_FILES["admin_feedback_images"]["size"][$key];
                
                // Verify file extension
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if(!array_key_exists($ext, $allowed)) continue;
                
                // Verify file size - 5MB maximum
                $maxsize = 5 * 1024 * 1024;
                if($filesize > $maxsize) continue;
                
                // Verify MIME type
                if(in_array($filetype, $allowed)){
                    $new_filename = "admin_feedback_" . uniqid() . "." . $ext;
                    $target_file = $target_dir . $new_filename;
                    
                    if(move_uploaded_file($tmp_name, $target_file)){
                        chmod($target_file, 0644);
                        $admin_feedback_image_paths[] = $new_filename;
                    }
                }
            }
        }
    }
    
    $admin_feedback_images_str = !empty($admin_feedback_image_paths) ? implode(",", $admin_feedback_image_paths) : null;
    
    $sql = "UPDATE complaints SET status = ?, feedback = ?, feedback_type = ?, feedback_images = ?, is_urgent = ?, is_payment_related = ?, is_i4cus = ?, handled_by = ? WHERE complaint_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ssssiiiis", $status, $feedback, $feedback_type, $admin_feedback_images_str, $is_urgent, $is_payment_related, $is_i4cus, $handled_by, $complaint_id);
        
        if(mysqli_stmt_execute($stmt)){
            // Create notification for the user who lodged the complaint if feedback is given
            if (!empty($feedback)) {
                require_once "includes/notifications.php";
                
                // Get the user who lodged the complaint
                $user_sql = "SELECT lodged_by FROM complaints WHERE complaint_id = ?";
                if ($user_stmt = mysqli_prepare($conn, $user_sql)) {
                    mysqli_stmt_bind_param($user_stmt, "i", $complaint_id);
                    mysqli_stmt_execute($user_stmt);
                    $user_result = mysqli_stmt_get_result($user_stmt);
                    if ($user_row = mysqli_fetch_assoc($user_result)) {
                        $lodged_by = $user_row['lodged_by'];
                        $notification_title = "Admin Feedback Given on Your Complaint";
                        $notification_message = "Your complaint #$complaint_id has received feedback from admin. Status: $status";
                        createNotification($conn, $lodged_by, $complaint_id, 'feedback_given', $notification_title, $notification_message);
                    }
                    mysqli_stmt_close($user_stmt);
                }
            }
            
            // Redirect to prevent form resubmission
            header("Location: admin.php?success=complaint_updated&id=" . $complaint_id);
            exit();
        } else{
            $error_message = "Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Process user creation
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["create_user"])){
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);
    $full_name = trim($_POST["full_name"]);
    $role_id = $_POST["role_id"];
    
    if(!empty($username) && !empty($password) && !empty($full_name)){
        $md5_password = md5($password);
        $sql = "INSERT INTO users (username, password, full_name, role_id) VALUES (?, ?, ?, ?)";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "sssi", $username, $md5_password, $full_name, $role_id);
            
            if(mysqli_stmt_execute($stmt)){
                header("Location: admin.php?success=user_created");
                exit();
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Process user deletion (super admin only)
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_user"]) && $_SESSION["is_super_admin"]){
    $user_id = $_POST["user_id"];
    
    // Prevent super admin from deleting themselves
    if($user_id != $_SESSION["user_id"]){
        $sql = "DELETE FROM users WHERE user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            
            if(mysqli_stmt_execute($stmt)){
                header("Location: admin.php?success=user_deleted");
                exit();
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "You cannot delete your own account.";
    }
}

// Process user password reset (super admin only)
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["reset_password"]) && $_SESSION["is_super_admin"]){
    $user_id = $_POST["user_id"];
    $new_password = trim($_POST["new_password"]);
    
    if(!empty($new_password)){
        $md5_password = md5($new_password);
        $sql = "UPDATE users SET password = ? WHERE user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "si", $md5_password, $user_id);
            
            if(mysqli_stmt_execute($stmt)){
                header("Location: admin.php?success=password_reset");
                exit();
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please enter a new password.";
    }
}

// Handle success messages from redirects
if(isset($_GET['success'])) {
    switch($_GET['success']) {
        case 'complaint_updated':
            $success_message = "Complaint updated successfully.";
            break;
        case 'bulk_deleted':
            $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
            $success_message = "$count complaint(s) deleted successfully.";
            break;
        case 'user_created':
            $success_message = "User created successfully.";
            break;
        case 'user_deleted':
            $success_message = "User deleted successfully.";
            break;
        case 'password_reset':
            $success_message = "Password reset successfully.";
            break;
    }
}

// Initialize complaints array
$complaints = [];

// Fetch all complaints based on view
$view = isset($_GET['view']) ? $_GET['view'] : 'all';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$search_id = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
$where_clause = "";

// Build WHERE clause based on filters
$where_conditions = [];

// Check if filtering by specific user
if ($filter_user > 0) {
    $where_conditions[] = "c.lodged_by = " . $filter_user;
} elseif ($search_id) {
    // Search by student ID - show all statuses when searching
    $where_conditions[] = "(c.student_id LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%' OR c.complaint_text LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%')";
} else {
    // Default: show only pending complaints
    $where_conditions[] = "c.status = 'Pending'";
}

// Combine conditions
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Add date filter if specified
if ($filter_date) {
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "DATE(c.created_at) = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
}

// In PHP, update the $where_clause and $order_by based on GET params:
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'desc';

// ... after $where_clause is built ...
if ($type == 'payment') {
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "c.is_payment_related = 1";
} elseif ($type == 'i4cus') {
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "c.is_i4cus = 1";
} elseif ($type == 'incomplete') {
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "(c.status = 'Needs More Info' OR c.feedback_type = 'incomplete')";
} elseif ($type == 'neither') {
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "c.is_payment_related = 0 AND c.is_i4cus = 0";
}
$order_by = "c.created_at " . ($sort == 'asc' ? 'ASC' : 'DESC');

$sql = "SELECT c.*, u.full_name as handler_name, u1.full_name as lodged_by_name 
        FROM complaints c 
        LEFT JOIN users u ON c.handled_by = u.user_id 
        LEFT JOIN users u1 ON c.lodged_by = u1.user_id 
        $where_clause
        ORDER BY $order_by";

if($stmt = mysqli_prepare($conn, $sql)){
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $complaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Fetch all users
$users = [];
$sql = "SELECT u.*, r.role_name FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        ORDER BY u.created_at DESC";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        $users[] = $row;
    }
}

// Fetch roles for dropdown
$roles = [];
$sql = "SELECT * FROM roles";
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    $roles[] = $row;
}

// Get staff statistics
$staff_stats = [];
$sql = "SELECT 
        u.user_id,
        u.full_name,
        COUNT(*) as total_complaints,
        SUM(CASE WHEN DATE(c.created_at) = CURDATE() THEN 1 ELSE 0 END) as today_count,
        SUM(CASE WHEN YEARWEEK(c.created_at) = YEARWEEK(CURDATE()) THEN 1 ELSE 0 END) as week_count,
        SUM(CASE WHEN MONTH(c.created_at) = MONTH(CURDATE()) AND YEAR(c.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as month_count,
        GROUP_CONCAT(c.complaint_id) as complaint_ids
        FROM complaints c
        JOIN users u ON c.lodged_by = u.user_id
        WHERE u.role_id != 1
        GROUP BY u.user_id, u.full_name
        ORDER BY total_complaints DESC";

$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        $staff_stats[] = $row;
    }
}

// Get complaints for selected staff member
$selected_staff = isset($_GET['staff_id']) ? $_GET['staff_id'] : null;
$staff_complaints = [];
if($selected_staff) {
    $sql = "SELECT c.*, 
            u1.full_name as lodged_by_name,
            u2.full_name as handler_name
            FROM complaints c
            LEFT JOIN users u1 ON c.lodged_by = u1.user_id
            LEFT JOIN users u2 ON c.handled_by = u2.user_id
            WHERE c.lodged_by = ?
            ORDER BY c.created_at DESC";
    
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $selected_staff);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            while($row = mysqli_fetch_assoc($result)){
                $staff_complaints[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Count unread messages
$unread_count = 0;
$messages_sql = "SELECT COUNT(*) as count FROM messages WHERE (recipient_id = ? OR is_broadcast = 1) AND is_read = 0";
if($stmt = mysqli_prepare($conn, $messages_sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            $unread_count = $row['count'];
        }
    }
    mysqli_stmt_close($stmt);
}

// Helper function to normalize image paths
function getImagePath($image) {
    $image = trim($image);
    if ($image === '') return '';
    
    // Extract just the filename if it contains a path
    if (strpos($image, '/') !== false) {
        $image = basename($image);
    }
    
    // Return the path to public_image.php with the encoded filename
    // Use 'img' parameter as expected by public_image.php
    return 'public_image.php?img=' . urlencode($image);
}

// In the sidebar, show the number of pending complaints from previous days
$pending_previous_days = [];
$pending_today = [];
$today = date('Y-m-d');
foreach ($complaints as $c) {
    $created_date = date('Y-m-d', strtotime($c['created_at']));
    if ($c['status'] == 'Pending') {
        if ($created_date < $today) {
            $pending_previous_days[] = $c;
        } else {
            $pending_today[] = $c;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?php echo htmlspecialchars($app_name); ?></title>
    
    <!-- Dynamic Favicon -->
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    
    <!-- Load jQuery first before any other scripts -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    
    <style>
        .app-branding {
            display: flex;
            align-items: center;
        }
        .app-logo {
            height: 40px;
            margin-right: 10px;
            object-fit: contain;
        }
        .app-name {
            font-size: 1.25rem;
            font-weight: bold;
        }
        /* Add zoom functionality to gallery */
        .gallery-image {
            transition: transform 0.3s ease;
            cursor: zoom-in;
            max-height: 300px;
            object-fit: contain;
        }
        .gallery-image.zoomed {
            transform: scale(2);
            cursor: zoom-out;
            z-index: 1000;
            position: relative;
        }
        .sidebar-col {
            position: sticky;
            top: 80px;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            z-index: 1;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
        }
        .sidebar-card {
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        /* Ensure main content has proper spacing */
        .col-md-8 {
            padding-right: 15px;
            z-index: 2;
        }
        /* Fix table responsiveness */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        /* Ensure action buttons don't get hidden */
        .table td:last-child,
        .table th:last-child {
            position: relative;
            z-index: 3;
            white-space: nowrap;
        }
        @media (max-width: 991.98px) {
            .sidebar-col {
                position: static;
                top: auto;
                max-height: none;
            }
        }
        @media (min-width: 992px) {
            .row {
                display: flex;
                flex-wrap: wrap;
            }
            .col-md-8 {
                flex: 0 0 70%;
                max-width: 70%;
                padding-right: 15px;
            }
            .col-md-4 {
                flex: 0 0 30%;
                max-width: 30%;
                padding-left: 10px;
            }
        }
        /* Ensure main content takes priority */
        @media (min-width: 1200px) {
            .col-md-8 {
                flex: 0 0 75%;
                max-width: 75%;
            }
            .col-md-4 {
                flex: 0 0 25%;
                max-width: 25%;
            }
        }
        /* Prevent content overflow */
        .card-body {
            overflow-x: visible;
        }
        /* Ensure buttons stay visible */
        .btn-sm {
            min-width: 32px;
            padding: 0.25rem 0.5rem;
        }
        /* Fix for action column */
        .table td:last-child {
            padding-right: 1rem !important;
        }
        /* Make table responsive without horizontal scroll */
        .table-responsive {
            overflow-x: visible;
        }
        /* Ensure table fits within container */
        .table {
            table-layout: auto;
            width: 100%;
        }
        /* Make table cells wrap text instead of forcing horizontal scroll */
        .table td {
            word-wrap: break-word;
            word-break: break-word;
            white-space: normal;
            max-width: 200px;
        }
        /* Keep action column compact */
        .table td:last-child {
            white-space: nowrap;
            width: 1%;
            max-width: none;
        }
        /* Remove specific column widths - let table auto-size */
        .table {
            table-layout: fixed;
            width: 100%;
        }
        /* Checkbox column - minimal width */
        .table th:first-child, .table td:first-child { 
            width: 40px;
            text-align: center;
        }
        /* Date column */
        .table th:nth-child(2), .table td:nth-child(2) { 
            width: 90px;
            font-size: 0.85rem;
        }
        /* Student ID */
        .table th:nth-child(3), .table td:nth-child(3) { 
            width: 110px;
            font-size: 0.85rem;
        }
        /* Complaint - flexible width */
        .table th:nth-child(4), .table td:nth-child(4) { 
            width: auto;
            min-width: 200px;
        }
        /* Status - fixed width to prevent wrapping */
        .table th:nth-child(5), .table td:nth-child(5) { 
            width: 100px;
            text-align: center;
        }
        /* Priority */
        .table th:nth-child(6), .table td:nth-child(6) { 
            width: 80px;
            text-align: center;
        }
        /* Lodged By */
        .table th:nth-child(7), .table td:nth-child(7) { 
            width: 110px;
        }
        /* Handler */
        .table th:nth-child(8), .table td:nth-child(8) { 
            width: 110px;
        }
        /* Action */
        .table th:nth-child(9), .table td:nth-child(9) { 
            width: 50px;
            text-align: center;
        }
        /* Status badge styling - prevent wrapping */
        .complaint-status {
            display: inline-block;
            white-space: nowrap;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            min-width: 80px;
            text-align: center;
        }
        /* Priority badge styling */
        .badge {
            white-space: nowrap;
            font-size: 0.75rem;
        }
        /* CRITICAL FIX: Hide modal backdrop completely */
        .modal-backdrop {
            display: none !important;
        }
        /* Keep body scrollable when modal is open */
        body.modal-open {
            overflow: auto !important;
            padding-right: 0 !important;
        }
        /* Style modal for visibility without backdrop */
        .modal.show {
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1050;
        }
        .modal-dialog {
            z-index: 1051;
        }
        /* Ensure modal content is clickable */
        .modal-content {
            position: relative;
            z-index: 1052;
            background-color: #fff;
            border: 1px solid rgba(0,0,0,.2);
            border-radius: 0.3rem;
            box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,.5);
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <?php if(isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if(isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        


        <div class="mb-4">
            <div class="btn-group w-100">
                <a href="?view=all" class="btn btn-<?php echo (!isset($_GET['view']) || $_GET['view'] == 'all') ? 'primary' : 'secondary'; ?>">
                    All Complaints
                </a>
                <a href="?view=payment" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'payment') ? 'primary' : 'secondary'; ?>">
                    Payment-Related
                </a>
                <a href="?view=i4cus" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'i4cus') ? 'primary' : 'secondary'; ?>">
                    i4Cus Issues
                </a>
                <a href="?view=feedback" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'feedback') ? 'primary' : 'secondary'; ?>">
                    With Feedback
                </a>
                <a href="?view=incomplete" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'incomplete') ? 'primary' : 'secondary'; ?>">
                    More Info Required
                </a>
                <a href="?view=resolved" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'resolved') ? 'primary' : 'secondary'; ?>">
                    Response Only
                </a>
            </div>
        </div>

        <!-- Reports Section -->
        <div class="mb-4">
            <div class="card bg-light">
                <div class="card-body py-2">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Student Complaints Reporting</h6>
                            <small class="text-muted">Generate comprehensive reports for result verification complaints</small>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="student_complaints_report.php" class="btn btn-info btn-sm">
                                <i class="fas fa-chart-bar mr-1"></i> View Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Management Section -->
        <div class="mb-4">
            <div class="card bg-light">
                <div class="card-body py-2">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-0"><i class="fas fa-user-graduate mr-2"></i>Student Management</h6>
                            <small class="text-muted">View, edit, and manage student accounts and information</small>
                        </div>
                        <div class="col-md-4 text-right">
                            <a href="manage_students.php" class="btn btn-success btn-sm">
                                <i class="fas fa-users mr-1"></i> Manage Students
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter Section -->
        <div class="row mb-3">
            <div class="col-md-6">
                <form method="get" class="form-inline">
                    <div class="input-group w-100">
                        <input type="text" name="search" class="form-control" placeholder="Search by Student ID or Complaint Text" value="<?php echo htmlspecialchars($search_id); ?>">
                        <div class="input-group-append">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <?php if($search_id): ?>
                                <a href="admin.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-md-6">
                <form method="get" class="form-inline">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                    <?php if($search_id): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_id); ?>">
                    <?php endif; ?>
                    <label class="mr-2 font-weight-bold">Sort:</label>
                    <select name="sort" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                        <option value="desc" <?php if(!isset($_GET['sort']) || $_GET['sort'] == 'desc') echo 'selected'; ?>>Newest First</option>
                        <option value="asc" <?php if(isset($_GET['sort']) && $_GET['sort'] == 'asc') echo 'selected'; ?>>Oldest First</option>
                    </select>
                    <label class="mr-2 font-weight-bold">Type:</label>
                    <select name="type" class="form-control form-control-sm" onchange="this.form.submit()">
                        <option value="all" <?php if(!isset($_GET['type']) || $_GET['type'] == 'all') echo 'selected'; ?>>All</option>
                        <option value="payment" <?php if(isset($_GET['type']) && $_GET['type'] == 'payment') echo 'selected'; ?>>Payment Related</option>
                        <option value="i4cus" <?php if(isset($_GET['type']) && $_GET['type'] == 'i4cus') echo 'selected'; ?>>i4Cus Issues</option>
                        <option value="incomplete" <?php if(isset($_GET['type']) && $_GET['type'] == 'incomplete') echo 'selected'; ?>>More Info Required</option>
                        <option value="neither" <?php if(isset($_GET['type']) && $_GET['type'] == 'neither') echo 'selected'; ?>>Neither Payment nor i4Cus</option>
                    </select>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>
                            <?php
                            if ($filter_user > 0) {
                                // Get user name for the filter
                                $user_name = 'Unknown User';
                                $user_sql = "SELECT full_name FROM users WHERE user_id = ?";
                                if ($user_stmt = mysqli_prepare($conn, $user_sql)) {
                                    mysqli_stmt_bind_param($user_stmt, "i", $filter_user);
                                    if (mysqli_stmt_execute($user_stmt)) {
                                        $user_result = mysqli_stmt_get_result($user_stmt);
                                        if ($user_row = mysqli_fetch_assoc($user_result)) {
                                            $user_name = $user_row['full_name'];
                                        }
                                    }
                                    mysqli_stmt_close($user_stmt);
                                }
                                echo 'Complaints by ' . htmlspecialchars($user_name);
                                echo ' <a href="admin.php" class="btn btn-sm btn-outline-secondary ml-2"><i class="fas fa-times"></i> Clear Filter</a>';
                            } else {
                                switch($view) {
                                    case 'payment':
                                        echo 'Payment-Related Complaints';
                                        break;
                                    case 'i4cus':
                                        echo 'i4Cus Issues';
                                        break;
                                    case 'feedback':
                                        echo 'Complaints with Feedback';
                                        break;
                                    case 'incomplete':
                                        echo 'Complaints Requiring More Information';
                                        break;
                                    case 'resolved':
                                        echo 'Resolved Complaints (Response Only)';
                                        break;
                                    default:
                                        echo 'All Active Complaints';
                                }
                            }
                            ?>
                        </h4>
                        <?php if ($_SESSION["role_id"] == 1 || $_SESSION["role_id"] == 3): // Super Admin or Director ?>
                            <div class="float-right">
                                <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#exportModal">
                                    <i class="fas fa-download"></i> Export Complaints
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($filter_user > 0): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                Showing <?php echo count($complaints); ?> complaint(s) submitted by this user.
                                <?php if (count($complaints) > 0): ?>
                                    <small class="d-block mt-1">This includes all complaints (pending, in progress, and treated).</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(empty($complaints)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <?php if ($search_id): ?>
                                    <h5 class="text-muted">No Results Found</h5>
                                    <p class="text-muted">No complaints found matching "<?php echo htmlspecialchars($search_id); ?>"</p>
                                    <a href="admin.php" class="btn btn-primary mt-2">
                                        <i class="fas fa-arrow-left"></i> Back to All Complaints
                                    </a>
                                <?php elseif ($filter_user > 0): ?>
                                    <h5 class="text-muted">This user has not submitted any complaints</h5>
                                    <p class="text-muted">No complaints found for the selected user.</p>
                                <?php else: ?>
                                    <h5 class="text-muted">No complaints found in this category</h5>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- Bulk Actions Form -->
                            <form method="post" id="bulkActionsForm">
                                <div class="mb-3">
                                    <button type="submit" name="bulk_delete" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete the selected complaints?');">
                                        <i class="fas fa-trash"></i> Delete Selected
                                    </button>
                                    <small class="text-muted ml-2">Select complaints using checkboxes</small>
                                </div>
                                
                                <table class="table table-sm table-hover">
                                    <thead class="thead-light">
                                        <tr>
                                            <th><input type="checkbox" id="selectAll" title="Select All"></th>
                                            <th>Date</th>
                                            <th>Student ID</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Priority</th>
                                            <th>Lodged By</th>
                                            <th>Handler</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($complaints as $complaint): ?>
                                        <tr class="<?php echo $complaint['is_urgent'] ? 'table-danger' : ''; ?>">
                                            <td>
                                                <input type="checkbox" name="complaint_ids[]" value="<?php echo $complaint['complaint_id']; ?>" class="complaint-checkbox">
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($complaint['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($complaint['student_id'] ?? ''); ?></td>
                                        <td>
                                            <?php echo substr($complaint['complaint_text'], 0, 50); ?>...
                                            <!-- MODIFIED: Enhanced attachment display -->
                                            <?php if($complaint['image_path']): ?>
                                                <?php 
                                                $images = array_filter(explode(",", $complaint['image_path'])); 
                                                $img_count = count($images);
                                                $processed_images = array_map('getImagePath', $images);
                                                ?>
                                                <div class="mt-2">
                                                    <button type="button" class="btn btn-link p-0 attachment-btn" onclick="showGalleryModal(<?php echo htmlspecialchars(json_encode($processed_images)); ?>)">
                                                        <i class="fas fa-paperclip"></i> <?php echo $img_count; ?> Attachment<?php echo $img_count > 1 ? 's' : ''; ?>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="complaint-status status-<?php echo strtolower($complaint['status']); ?>">
                                                <?php echo $complaint['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($complaint['is_urgent']): ?>
                                                <span class="badge badge-danger">Urgent</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Normal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo $complaint['lodged_by_name'] ?? '-'; ?></strong></td>
                                        <td><?php echo $complaint['handler_name'] ?? 'Not assigned'; ?></td>
                                        <td>
                                            <button type="button" class="btn btn-outline-primary btn-sm p-1" data-toggle="modal" 
                                                    data-target="#updateModal<?php echo $complaint['complaint_id']; ?>" title="Update Complaint">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Update Modal -->
                                    <div class="modal fade" id="updateModal<?php echo $complaint['complaint_id']; ?>" tabindex="-1" data-backdrop="false" data-keyboard="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Update Complaint</h5>
                                                    <button type="button" class="close" data-dismiss="modal">
                                                        <span>&times;</span>
                                                    </button>
                                                </div>
                                                <form method="post" enctype="multipart/form-data">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="complaint_id" value="<?php echo $complaint['complaint_id']; ?>">
                                                        <div class="form-group">
                                                            <label>Student ID</label>
                                                            <p class="form-control-plaintext"><?php echo $complaint['student_id']; ?></p>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Complaint Details</label>
                                                            <p class="form-control-plaintext"><?php echo $complaint['complaint_text']; ?></p>
                                                        </div>
                                                        <?php if($complaint['image_path']): ?>
                                                        <div class="form-group">
                                                            <label>Attached Images</label>
                                                            <div class="mt-2 d-flex flex-wrap">
                                                                <?php foreach(explode(",", $complaint['image_path']) as $image): ?>
                                                                    <div class="mr-2 mb-2">
                                                                        <img src="<?php echo htmlspecialchars(getImagePath($image)); ?>" 
                                                                             class="img-thumbnail" alt="Complaint Image"
                                                                             style="max-height: 100px; cursor: pointer;"
                                                                             onclick="showImageModal('<?php echo htmlspecialchars(getImagePath($image)); ?>')">
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                        <div class="form-group">
                                                            <label>Priority</label>
                                                            <div class="custom-control custom-checkbox">
                                                                <input type="checkbox" class="custom-control-input" id="urgentCheck<?php echo $complaint['complaint_id']; ?>" 
                                                                       name="is_urgent" <?php echo $complaint['is_urgent'] ? 'checked' : ''; ?>>
                                                                <label class="custom-control-label" for="urgentCheck<?php echo $complaint['complaint_id']; ?>">
                                                                    Mark as Urgent
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Status</label>
                                                            <select name="status" class="form-control" required>
                                                                <option value="Pending" <?php echo $complaint['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                                                <option value="Treated" <?php echo $complaint['status'] == 'Treated' ? 'selected' : ''; ?>>Treated</option>
                                                                <option value="Needs More Info" <?php echo $complaint['status'] == 'Needs More Info' ? 'selected' : ''; ?>>Needs More Info</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Feedback Type</label>
                                                            <select name="feedback_type" class="form-control">
                                                                <option value="">No Feedback</option>
                                                                <option value="resolved" <?php echo $complaint['feedback_type'] == 'resolved' ? 'selected' : ''; ?>>Resolved (Response Only)</option>
                                                                <option value="incomplete" <?php echo $complaint['feedback_type'] == 'incomplete' ? 'selected' : ''; ?>>Incomplete Information</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Feedback</label>
                                                            <textarea name="feedback" class="form-control" rows="3"><?php echo $complaint['feedback']; ?></textarea>
                                                        </div>
                                                        <div class="form-group">
                                                            <label for="admin_feedback_images_<?php echo $complaint['complaint_id']; ?>">Attach Images to Feedback (Optional)</label>
                                                            <input type="file" id="admin_feedback_images_<?php echo $complaint['complaint_id']; ?>" name="admin_feedback_images[]" class="form-control-file" accept="image/*" multiple>
                                                            <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)<br>
                                                            <strong>💡 Tip:</strong> Paste screenshots with Ctrl+V or drag & drop images!</small>
                                                        </div>
                                                        <div class="form-group">
                                                            <div class="custom-control custom-checkbox">
                                                                <input type="checkbox" class="custom-control-input" id="paymentCheck<?php echo $complaint['complaint_id']; ?>" 
                                                                       name="is_payment_related" <?php echo $complaint['is_payment_related'] ? 'checked' : ''; ?>>
                                                                <label class="custom-control-label" for="paymentCheck<?php echo $complaint['complaint_id']; ?>">
                                                                    Mark as Payment-Related
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="form-group">
                                                            <div class="custom-control custom-checkbox">
                                                                <input type="checkbox" class="custom-control-input" id="i4cusCheck<?php echo $complaint['complaint_id']; ?>" 
                                                                       name="is_i4cus" <?php echo $complaint['is_i4cus'] ? 'checked' : ''; ?>>
                                                                <label class="custom-control-label" for="i4cusCheck<?php echo $complaint['complaint_id']; ?>">
                                                                    Mark as i4Cus Issue
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                        <button type="submit" name="update_complaint" class="btn btn-primary">Update</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                        
                        <!-- Select All JavaScript -->
                        <script>
                        document.getElementById('selectAll').addEventListener('change', function() {
                            const checkboxes = document.querySelectorAll('.complaint-checkbox');
                            checkboxes.forEach(checkbox => checkbox.checked = this.checked);
                        });
                        </script>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 sidebar-col">
                <div class="card sidebar-card">
                    <div class="card-header">
                        <h4>Complaint Statistics</h4>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <select id="chartType" class="form-control" onchange="updateChartType()">
                                <option value="pie">Pie Chart</option>
                                <option value="bar">Bar Chart</option>
                                <option value="line">Line Chart</option>
                            </select>
                        </div>
                        <div style="height: 400px;">
                            <canvas id="statsChart"></canvas>
                        </div>
                        
                        <?php
                        // Get complaint statistics
                        $stats_sql = "SELECT 
                            u.full_name,
                            COUNT(*) as total_complaints,
                            SUM(CASE WHEN c.status = 'Treated' THEN 1 ELSE 0 END) as treated_complaints,
                            SUM(CASE WHEN c.status = 'Pending' THEN 1 ELSE 0 END) as pending_complaints
                            FROM complaints c
                            JOIN users u ON c.lodged_by = u.user_id
                            WHERE u.role_id != 1
                            GROUP BY u.user_id, u.full_name";
                        
                        $stats_result = mysqli_query($conn, $stats_sql);
                        $staff_names = [];
                        $total_complaints = [];
                        $treated_complaints = [];
                        $pending_complaints = [];
                        
                        if($stats_result){
                            while($row = mysqli_fetch_assoc($stats_result)){
                                $staff_names[] = $row['full_name'];
                                $total_complaints[] = $row['total_complaints'];
                                $treated_complaints[] = $row['treated_complaints'];
                                $pending_complaints[] = $row['pending_complaints'];
                            }
                        }
                        ?>
                    </div>
                </div>

                <?php if(isset($_SESSION["is_super_admin"]) && $_SESSION["is_super_admin"]): ?>
                <div class="card sidebar-card">
                    <div class="card-header">
                        <h4>Create New User</h4>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role_id" class="form-control" required>
                                    <?php foreach($roles as $role): ?>
                                        <option value="<?php echo $role['role_id']; ?>"><?php echo $role['role_name']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
                        </form>
                    </div>
                </div>

                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h4>Staff Complaint Statistics</h4>
                    </div>
                    <div class="card-body">
                        <h5 class="mb-4">Staff Complaint Statistics</h5>
                        <?php if(!empty($staff_stats)): ?>
                            <div class="list-group mb-4">
                                <?php foreach($staff_stats as $staff): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($staff['full_name']); ?></h6>
                                                <div>
                                                    <span class="badge badge-primary">Total: <?php echo $staff['total_complaints']; ?></span>
                                                    <span class="badge badge-info">Today: <?php echo $staff['today_count']; ?></span>
                                                    <span class="badge badge-success">This Week: <?php echo $staff['week_count']; ?></span>
                                                    <span class="badge badge-warning">This Month: <?php echo $staff['month_count']; ?></span>
                                                </div>
                                            </div>
                                            <a href="?staff_id=<?php echo $staff['user_id']; ?>" class="btn btn-sm btn-info">
                                                View Complaints
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No staff complaints found.</p>
                        <?php endif; ?>

                        <?php if(!empty($staff_complaints)): ?>
                            <h5 class="mb-3">Staff Member's Complaints</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Student ID</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Handled By</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($staff_complaints as $complaint): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y h:i A', strtotime($complaint['created_at'])); ?></td>
                                                <td><?php echo htmlspecialchars($complaint['student_id']); ?></td>
                                                <td>
                                                    <?php echo substr(htmlspecialchars($complaint['complaint_text']), 0, 50) . '...'; ?>
                                                    <!-- MODIFIED: Enhanced attachment display -->
                                                    <?php if($complaint['image_path']): ?>
                                                        <?php 
                                                        $images = array_filter(explode(",", $complaint['image_path'])); 
                                                        $img_count = count($images);
                                                        $processed_images = array_map('getImagePath', $images);
                                                        ?>
                                                        <div class="mt-2">
                                                            <button type="button" class="btn btn-link p-0 attachment-btn" onclick="showGalleryModal(<?php echo htmlspecialchars(json_encode($processed_images)); ?>)">
                                                                <i class="fas fa-paperclip"></i> <?php echo $img_count; ?> Attachment<?php echo $img_count > 1 ? 's' : ''; ?>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        echo $complaint['status'] == 'Treated' ? 'success' : 
                                                            ($complaint['status'] == 'In Progress' ? 'warning' : 'secondary'); 
                                                    ?>">
                                                        <?php echo $complaint['status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $complaint['handler_name'] ? htmlspecialchars($complaint['handler_name']) : 'Not assigned'; ?></td>
                                                <td>
                                                    <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                                       class="btn btn-sm btn-info">View Details</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4>Pending Complaints</h4>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <span class="badge badge-primary">Total Pending: <?php echo count($pending_today) + count($pending_previous_days); ?></span>
                            <span class="badge badge-warning" style="cursor:pointer;" data-toggle="collapse" data-target="#pendingPrevDaysList" aria-expanded="false" aria-controls="pendingPrevDaysList">
                                Pending from Previous Days: <?php echo count($pending_previous_days); ?>
                            </span>
                        </div>
                        <div class="collapse" id="pendingPrevDaysList">
                            <div class="card card-body">
                                <?php if(count($pending_previous_days) > 0): ?>
                                    <ul class="list-group mb-2">
                                        <?php foreach($pending_previous_days as $c): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                                <span>
                                                    <?php echo date('M d, Y h:i A', strtotime($c['created_at'])); ?> - <?php echo htmlspecialchars($c['student_id'] ?? ''); ?>
                                                </span>
                                                <a href="#updateModal<?php echo $c['complaint_id']; ?>" data-toggle="modal" class="btn btn-sm btn-primary">Treat</a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="text-muted">No pending complaints from previous days.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gallery Modal with zoom functionality -->
    <div class="modal fade" id="galleryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Attachments</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body text-center">
                    <div id="galleryImages" class="d-flex flex-wrap justify-content-center"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Single Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Image View</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="img-fluid" alt="Complaint Image">
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery already loaded in head -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="js/clipboard-paste.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/auto_refresh_complaints.js"></script>
    <script>
    let myChart = null;
    const staffNames = <?php echo json_encode($staff_names); ?>;
    const totalComplaints = <?php echo json_encode($total_complaints); ?>;
    const treatedComplaints = <?php echo json_encode($treated_complaints); ?>;
    const pendingComplaints = <?php echo json_encode($pending_complaints); ?>;

    function createChart(type) {
        const ctx = document.getElementById('statsChart').getContext('2d');
        
        if (myChart) {
            myChart.destroy();
        }

        const config = {
            type: type,
            data: {
                labels: staffNames,
                datasets: [{
                    label: 'Total Complaints',
                    data: totalComplaints,
                    backgroundColor: 'rgba(255, 99, 132, 0.7)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }, {
                    label: 'Treated Complaints',
                    data: treatedComplaints,
                    backgroundColor: 'rgba(75, 192, 192, 0.7)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }, {
                    label: 'Pending Complaints',
                    data: pendingComplaints,
                    backgroundColor: 'rgba(255, 206, 86, 0.7)',
                    borderColor: 'rgba(255, 206, 86, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        display: type !== 'pie'
                    }
                }
            }
        };

        myChart = new Chart(ctx, config);
    }

    function updateChartType() {
        const type = document.getElementById('chartType').value;
        createChart(type);
    }

    // Create initial chart
    document.addEventListener('DOMContentLoaded', function() {
        createChart('pie');
    });

    // Gallery zoom functionality
    function showGalleryModal(images) {
        const gallery = document.getElementById('galleryImages');
        gallery.innerHTML = '';
        
        images.forEach(img => {
            const imgContainer = document.createElement('div');
            imgContainer.className = 'position-relative m-2';
            
            const imgElem = document.createElement('img');
            imgElem.src = img;
            imgElem.className = 'img-thumbnail gallery-image';
            imgElem.style.maxHeight = '300px';
            imgElem.style.cursor = 'zoom-in';
            imgElem.onclick = function() {
                this.classList.toggle('zoomed');
            };
            
            imgContainer.appendChild(imgElem);
            gallery.appendChild(imgContainer);
        });
        
        $('#galleryModal').modal('show');
    }
    
    // Single image modal functionality
    function showImageModal(imageSrc) {
        document.getElementById('modalImage').src = imageSrc;
        $('#imageModal').modal('show');
    }

    $(function() {
        var userId = <?php echo $_SESSION["user_id"]; ?>;
        var userRoleId = <?php echo $_SESSION["role_id"]; ?>;
        // Helper to render a complaint row (adapt as needed for admin.php)
        function renderComplaint(complaint, userId, userRoleId) {
            let urgentBadge = complaint.is_urgent ? '<span class="badge badge-danger ml-2">Urgent</span>' : '';
            let feedbackBox = complaint.feedback ? `<div class="feedback-box mt-2"><small class="text-muted">Feedback:</small><p class="mb-0">${complaint.feedback}</p></div>` : '';
            let imagesHtml = '';
            let imgCount = 0;
            let images = [];
            if (complaint.image_path && complaint.image_path !== '0') {
                images = complaint.image_path.split(',').map(s => s.trim()).filter(Boolean);
                imgCount = images.length;
            }
            if (imgCount > 0) {
                let galleryItems = images.slice(0,3).map((img, idx) => {
                    let directPath = 'public_image.php?img=' + encodeURIComponent(img.split('/').pop());
                    let badge = (imgCount > 3 && idx === 2) ? `<div class=\"image-count-badge\">+${imgCount-3}</div>` : '';
                    return `<div class=\"gallery-item\" onclick=\"toggleZoom(this)\"><img src=\"${directPath}\" alt=\"Complaint Image ${idx+1}\" loading=\"lazy\" onerror=\"this.onerror=null; this.parentElement.innerHTML='<div class=\\'image-error\\'>Image not available</div>'\;\">${badge}</div>`;
                }).join('');
                let allImages = images.map(img => 'public_image.php?img=' + encodeURIComponent(img.split('/').pop()));
                let viewAllBtn = imgCount > 3 ? `<button type=\"button\" class=\"btn btn-sm btn-info view-all-btn\" onclick=\"showGalleryModal(${JSON.stringify(allImages)})\"><i class=\"fas fa-images\"></i> View All (${imgCount})</button>` : '';
                imagesHtml = `<div class=\"mt-2\"><strong>Attached Images:</strong><div class=\"gallery-container\">${galleryItems}</div>${viewAllBtn}</div>`;
            }
            let checkbox = (userRoleId == 1 || complaint.lodged_by == userId) ? `<div class=\"form-check mr-3\"><input type=\"checkbox\" class=\"form-check-input complaint-checkbox\" name=\"complaint_ids[]\" value=\"${complaint.complaint_id}\"></div>` : '';
            // For admin, assume complaints are in a table with id #complaintsTable
            return `<tr class=\"new-complaint\"><td>${complaint.created_at_fmt}</td><td>${complaint.student_id}</td><td>${complaint.complaint_text.substring(0,50)}...</td><td>${complaint.status}</td><td>${complaint.is_urgent ? 'Urgent' : 'Normal'}</td><td><strong>${complaint.lodged_by_name || '-'}</strong></td><td><button type=\"button\" class=\"btn btn-outline-primary btn-sm p-1\" data-toggle=\"modal\" 
                    data-target=\"#updateModal${complaint.complaint_id}\" title=\"Update Complaint\">
                    <i class=\"fas fa-edit\"></i>
                </button></td></tr>`;
        }
        function getLastComplaintId() {
            let first = $('#complaintsTable tbody tr').first();
            let idMatch = first.html() && first.html().match(/view_complaint.php\?id=(\d+)/);
            if (idMatch) return parseInt(idMatch[1]);
            // Fallback: try to get from data attribute if available
            let dataId = first.data('complaint-id');
            return dataId ? parseInt(dataId) : 0;
        }
        autoRefreshComplaints({
            container: '#complaintsTable tbody',
            afterSelector: 'tr:first',
            getLastId: getLastComplaintId,
            renderComplaint: renderComplaint,
            userId: userId,
            userRoleId: userRoleId
        });
    });
    </script>
    
    <!-- Complaint Calendar at the bottom of the page -->
    <div class="container mt-4 mb-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <?php echo generateComplaintCalendar($conn, $_SESSION["role_id"]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export Complaints</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form id="exportForm">
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Export Format</label>
                            <select name="format" class="form-control">
                                <option value="csv">CSV (Excel Compatible)</option>
                                <option value="json">JSON (Developer Format)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Date Range (Optional)</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="date" name="date_from" class="form-control" placeholder="From Date">
                                    <small class="form-text text-muted">From Date</small>
                                </div>
                                <div class="col-md-6">
                                    <input type="date" name="date_to" class="form-control" placeholder="To Date">
                                    <small class="form-text text-muted">To Date</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Status Filter</label>
                            <select name="status" class="form-control">
                                <option value="all">All Statuses</option>
                                <option value="Pending">Pending Only</option>
                                <option value="In Progress">In Progress Only</option>
                                <option value="Treated">Treated Only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Type Filter</label>
                            <select name="type" class="form-control">
                                <option value="all">All Types</option>
                                <option value="payment">Payment Related Only</option>
                                <option value="urgent">Urgent Only</option>
                                <option value="department">Department Complaints Only</option>
                            </select>
                        </div>
                        
                        <?php if ($filter_user > 0): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>User Filter Active:</strong> Export will include only complaints from the currently filtered user.
                            </div>
                            <input type="hidden" name="filter_user" value="<?php echo $filter_user; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="includePersonalData">
                                <label class="custom-control-label" for="includePersonalData">
                                    I confirm that this export is for legitimate business purposes and will be handled according to data protection policies.
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="exportBtn" disabled>
                            <i class="fas fa-download"></i> Export Complaints
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Export functionality
    $('#includePersonalData').change(function() {
        $('#exportBtn').prop('disabled', !this.checked);
    });
    
    $('#exportForm').on('submit', function(e) {
        e.preventDefault();
        
        if (!$('#includePersonalData').is(':checked')) {
            alert('Please confirm that this export is for legitimate business purposes.');
            return;
        }
        
        const formData = new FormData(this);
        const params = new URLSearchParams();
        
        for (let [key, value] of formData.entries()) {
            if (value) {
                params.append(key, value);
            }
        }
        
        // Add current filters if they exist
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('filter_user')) {
            params.append('filter_user', urlParams.get('filter_user'));
        }
        
        const exportUrl = 'api/export_complaints.php?' + params.toString();
        
        // Show loading state
        $('#exportBtn').html('<i class="fas fa-spinner fa-spin"></i> Exporting...').prop('disabled', true);
        
        // Create a temporary link to download the file
        const link = document.createElement('a');
        link.href = exportUrl;
        link.download = '';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Reset button after a delay
        setTimeout(function() {
            $('#exportBtn').html('<i class="fas fa-download"></i> Export Complaints').prop('disabled', false);
            $('#exportModal').modal('hide');
        }, 2000);
    });
    
    // Custom modal handling to prevent backdrop issues
    $(document).ready(function() {
        // Override all modal buttons to use custom modal opening
        $('[data-toggle="modal"][data-target^="#updateModal"]').off('click').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var targetModal = $(this).data('target');
            
            // Remove any existing backdrop
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css({
                'overflow': 'auto',
                'padding-right': '0'
            });
            
            // Show modal without backdrop
            $(targetModal).modal({
                backdrop: false,
                keyboard: true,
                show: true
            });
            
            // Ensure modal is visible
            $(targetModal).addClass('show').css('display', 'block');
        });
        
        // Handle modal close buttons
        $('.modal .close, [data-dismiss="modal"]').off('click').on('click', function(e) {
            e.preventDefault();
            var modal = $(this).closest('.modal');
            modal.removeClass('show').css('display', 'none');
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css({
                'overflow': 'auto',
                'padding-right': '0'
            });
        });
        
        // Handle ESC key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('.modal.show').removeClass('show').css('display', 'none');
                $('.modal-backdrop').remove();
                $('body').removeClass('modal-open').css({
                    'overflow': 'auto',
                    'padding-right': '0'
                });
            }
        });
    });
    </script>
</body>
</html>