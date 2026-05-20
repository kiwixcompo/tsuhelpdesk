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
require_once "includes/notifications.php";
require_once "calendar_helper.php";

// Initialize notification count
$notification_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
}

// Fetch app settings
$app_name = 'TSU ICT Help Desk'; // Default value
$app_logo = '';
$app_favicon = '';

$sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('app_name', 'app_logo', 'app_favicon')";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        switch($row['setting_key']) {
            case 'app_name':
                $app_name = $row['setting_value'] ?: 'TSU ICT Help Desk';
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
    $feedback = trim(is_array($_POST["feedback"] ?? '') ? implode("\n", $_POST["feedback"]) : ($_POST["feedback"] ?? ''));
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
            // Create notification and send email when feedback is given
            if (!empty($feedback)) {
                require_once "includes/notifications.php";
                require_once "includes/logger.php";

                // Get the user who lodged the complaint + their email
                $user_sql = "SELECT u.user_id, u.email, u.full_name FROM complaints c JOIN users u ON c.lodged_by = u.user_id WHERE c.complaint_id = ?";
                if ($user_stmt = mysqli_prepare($conn, $user_sql)) {
                    mysqli_stmt_bind_param($user_stmt, "i", $complaint_id);
                    mysqli_stmt_execute($user_stmt);
                    $user_result = mysqli_stmt_get_result($user_stmt);
                    if ($user_row = mysqli_fetch_assoc($user_result)) {
                        $lodged_by   = $user_row['user_id'];
                        $lodger_name = $user_row['full_name'];
                        $lodger_email = $user_row['email'] ?? '';

                        // In-app notification
                        $notification_title = "Admin Feedback Given on Your Complaint";
                        $notification_message = "Your complaint #$complaint_id has received feedback from admin. Status: $status";
                        createNotification($conn, $lodged_by, $complaint_id, 'feedback_given', $notification_title, $notification_message);

                        // Email notification
                        if (!empty($lodger_email)) {
                            $email_subject = "Response on Your Complaint #$complaint_id — TSU ICT Help Desk";
                            $email_body    = "Dear $lodger_name,\n\n"
                                           . "Your complaint (ID: #$complaint_id) has received a response.\n\n"
                                           . "Status  : $status\n"
                                           . "Response: " . mb_substr($feedback, 0, 500) . "\n\n"
                                           . "Please log in to view the full details and reply if needed:\n"
                                           . "https://helpdesk.tsuniversity.ng/\n\n"
                                           . "-- TSU ICT Help Desk";
                            @app_mail($lodger_email, $email_subject, $email_body);
                        }
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


// Build WHERE clause based on filters
$view = isset($_GET['view']) ? $_GET['view'] : 'all';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$search_id = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'desc';

$where_conditions = [];

// Apply specific view rules if not searching
if ($filter_user > 0) {
    $where_conditions[] = "c.lodged_by = " . $filter_user;
} elseif ($search_id) {
    $where_conditions[] = "(c.student_id LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%' OR c.complaint_text LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%')";
} else {
    // Default base view filter
    switch($view) {
        case 'resolved':
            $where_conditions[] = "(c.status = 'Treated' OR c.feedback_type = 'resolved')";
            break;
        case 'incomplete':
            $where_conditions[] = "(c.status = 'Needs More Info' OR c.feedback_type = 'incomplete')";
            break;
        case 'payment':
            $where_conditions[] = "c.is_payment_related = 1";
            break;
        case 'i4cus':
            $where_conditions[] = "c.is_i4cus = 1";
            break;
        case 'feedback':
            $where_conditions[] = "(c.feedback IS NOT NULL AND c.feedback != '')";
            break;
        case 'all':
        default:
            $where_conditions[] = "c.status = 'Pending'";
            break;
    }
}

// Combine conditions
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

if ($filter_date) {
    $where_clause .= ($where_clause ? ' AND ' : 'WHERE ') . "DATE(c.created_at) = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
}

// Add Type filtering overriding some views
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

// --- Dynamic Pagination Logic Setup ---
$default_limit = 20;
$allowed_limits = [10, 20, 50, 100, 'all'];
$per_page = isset($_GET['limit']) ? $_GET['limit'] : $default_limit;

if (!in_array($per_page, $allowed_limits)) {
    $per_page = $default_limit;
}

// Get total number of complaints for the CURRENT VIEW to calculate pagination
$count_sql = "SELECT COUNT(*) as total FROM complaints c $where_clause";
$total_view_complaints = 0;
if($count_result = mysqli_query($conn, $count_sql)){
    if($row = mysqli_fetch_assoc($count_result)){
        $total_view_complaints = $row['total'];
    }
}

if ($per_page === 'all') {
    $total_pages = 1;
    $page = 1;
    $limit_clause = "";
} else {
    $per_page = (int)$per_page;
    $total_pages = ceil($total_view_complaints / $per_page);
    
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
    
    $offset = ($page - 1) * $per_page;
    if ($offset < 0) $offset = 0;
    
    $limit_clause = "LIMIT $per_page OFFSET $offset";
}

// Fetch complaints based on view with pagination limit
$complaints = [];
$sql = "SELECT c.*, u.full_name as handler_name, u1.full_name as lodged_by_name 
        FROM complaints c 
        LEFT JOIN users u ON c.handled_by = u.user_id 
        LEFT JOIN users u1 ON c.lodged_by = u1.user_id 
        $where_clause
        ORDER BY $order_by 
        $limit_clause";

if($stmt = mysqli_prepare($conn, $sql)){
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $complaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Fetch pending complaints for the SIDEBAR (decoupled from pagination)
$pending_previous_days = [];
$pending_today = [];
$today = date('Y-m-d');

$pending_sql = "SELECT * FROM complaints WHERE status = 'Pending' ORDER BY created_at DESC";
if($pending_result = mysqli_query($conn, $pending_sql)){
    while($c = mysqli_fetch_assoc($pending_result)){
        $created_date = date('Y-m-d', strtotime($c['created_at']));
        if ($created_date < $today) {
            $pending_previous_days[] = $c;
        } else {
            $pending_today[] = $c;
        }
    }
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
    
    if (strpos($image, '/') !== false) {
        $image = basename($image);
    }
    return 'public_image.php?img=' . urlencode($image);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?php echo htmlspecialchars($app_name); ?></title>
    
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/responsive-fix.css">
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    
    <style>
        /* ── Admin page — only page-specific rules here.
           All responsive/table/modal rules live in css/responsive-fix.css ── */

        /* Gallery zoom */
        .gallery-image { transition: transform .3s; cursor: zoom-in; max-height: 300px; object-fit: contain; }
        .gallery-image.zoomed { transform: scale(2); cursor: zoom-out; z-index: 1000; position: relative; }

        /* Sidebar — sticky on desktop, static on mobile */
        .sidebar-col {
            position: sticky;
            top: 80px;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
        }
        @media (max-width: 991px) {
            .sidebar-col { position: static; max-height: none; }
        }

        /* Status badge */
        .complaint-status {
            display: inline-block;
            white-space: nowrap;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: .75rem;
            font-weight: 600;
            min-width: 70px;
            text-align: center;
        }

        /* Keep body scrollable when modal open */
        body.modal-open { overflow: auto !important; padding-right: 0 !important; }
        .modal-dialog { margin: 5vh auto; }
        
        /* Pagination fix */
        .pagination { margin-bottom: 0; }
        .page-link { color: var(--primary-blue); padding: 0.5rem 0.75rem; }
        .page-item.active .page-link { background-color: var(--primary-blue); border-color: var(--primary-blue); }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <?php
    // Set up admin dashboard header variables
    $page_title = 'Admin Panel';
    $page_subtitle = 'Manage complaints, users, and system settings';
    $page_icon = 'fas fa-cogs';
    $show_breadcrumb = false;
    
    // Get absolute stats for admin header (decoupled from active filters/pagination)
    $header_total_complaints = mysqli_query($conn, "SELECT COUNT(*) as c FROM complaints")->fetch_assoc()['c'] ?? 0;
    $header_total_users = count($users);
    $header_pending_complaints = count($pending_today) + count($pending_previous_days);
    
    // Set up quick stats
    $quick_stats = [
        ['number' => $header_total_complaints, 'label' => 'Total Complaints'],
        ['number' => $header_pending_complaints, 'label' => 'Pending'],
        ['number' => $header_total_users, 'label' => 'Users']
    ];
    
    include 'includes/dashboard_header.php';
    ?>

    <div class="container-fluid px-3 px-md-4 px-xl-5">
        <?php if(isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if(isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        


        <div class="mb-4">
            <div class="btn-group w-100 flex-wrap">
                <a href="?view=all" class="btn btn-<?php echo (!isset($_GET['view']) || $_GET['view'] == 'all') ? 'primary' : 'secondary'; ?> mb-1">
                    All Complaints
                </a>
                <a href="?view=payment" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'payment') ? 'primary' : 'secondary'; ?> mb-1">
                    Payment-Related
                </a>
                <a href="?view=i4cus" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'i4cus') ? 'primary' : 'secondary'; ?> mb-1">
                    i4Cus Issues
                </a>
                <a href="?view=feedback" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'feedback') ? 'primary' : 'secondary'; ?> mb-1">
                    With Feedback
                </a>
                <a href="?view=incomplete" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'incomplete') ? 'primary' : 'secondary'; ?> mb-1">
                    More Info Required
                </a>
                <a href="?view=resolved" class="btn btn-<?php echo (isset($_GET['view']) && $_GET['view'] == 'resolved') ? 'primary' : 'secondary'; ?> mb-1">
                    Response Only
                </a>
            </div>
        </div>

        <div class="mb-4">
            <div class="card bg-light">
                <div class="card-body py-2">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Student Complaints Reporting</h6>
                            <small class="text-muted">Generate comprehensive reports for result verification complaints</small>
                        </div>
                        <div class="col-md-4 text-right mt-2 mt-md-0">
                            <a href="student_complaints_report.php" class="btn btn-info btn-sm">
                                <i class="fas fa-chart-bar mr-1"></i> View Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div class="card bg-light">
                <div class="card-body py-2">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-0"><i class="fas fa-user-graduate mr-2"></i>Student Management</h6>
                            <small class="text-muted">View, edit, and manage student accounts and information</small>
                        </div>
                        <div class="col-md-4 text-right mt-2 mt-md-0">
                            <a href="manage_students.php" class="btn btn-success btn-sm">
                                <i class="fas fa-users mr-1"></i> Manage Students
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div class="card bg-light">
                <div class="card-body py-2">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-0"><i class="fas fa-clipboard-list mr-2"></i>Student Complaints Management</h6>
                            <small class="text-muted">Comprehensive management of student result verification complaints with filtering, status updates, and export capabilities</small>
                        </div>
                        <div class="col-md-4 text-right mt-2 mt-md-0">
                            <a href="enhanced_student_complaints_report.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-cogs mr-1"></i> Manage Complaints
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div class="card bg-light">
                <div class="card-body py-2">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="mb-0"><i class="fas fa-headset mr-2"></i>ICT & Portal Complaints</h6>
                            <small class="text-muted">Student ICT complaints submitted via the decision tree wizard — login issues, payments, course registration, printing and more</small>
                        </div>
                        <div class="col-md-4 text-right mt-2 mt-md-0">
                            <?php
                            // Show pending count badge — only if table exists
                            $ict_pending = 0;
                            $tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'student_ict_complaints'");
                            if ($tbl_check && mysqli_num_rows($tbl_check) > 0) {
                                $ict_r = mysqli_query($conn, "SELECT COUNT(*) c FROM student_ict_complaints WHERE status='Pending'");
                                if ($ict_r && $ict_row = mysqli_fetch_assoc($ict_r)) $ict_pending = (int)$ict_row['c'];
                            }
                            ?>
                            <a href="ict_complaints_admin.php" class="btn btn-warning btn-sm">
                                <i class="fas fa-headset mr-1"></i> Manage ICT Complaints
                                <?php if ($ict_pending > 0): ?>
                                    <span class="badge badge-danger ml-1"><?php echo $ict_pending; ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3 align-items-end">
            <div class="col-md-6 mb-3 mb-md-0">
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
            <div class="col-md-6 d-flex justify-content-md-end">
                <form method="get" class="form-inline w-100 justify-content-md-end">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                    <?php if($search_id): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_id); ?>">
                    <?php endif; ?>
                    
                    <div class="d-flex w-100 w-md-auto align-items-center mb-2 mb-md-0">
                        <label class="mr-2 font-weight-bold text-muted">Sort:</label>
                        <select name="sort" class="form-control form-control-sm flex-grow-1 mr-md-3" onchange="this.form.submit()">
                            <option value="desc" <?php if(!isset($_GET['sort']) || $_GET['sort'] == 'desc') echo 'selected'; ?>>Newest First</option>
                            <option value="asc" <?php if(isset($_GET['sort']) && $_GET['sort'] == 'asc') echo 'selected'; ?>>Oldest First</option>
                        </select>
                    </div>
                    
                    <div class="d-flex w-100 w-md-auto align-items-center">
                        <label class="mr-2 font-weight-bold text-muted">Type:</label>
                        <select name="type" class="form-control form-control-sm flex-grow-1" onchange="this.form.submit()">
                            <option value="all" <?php if(!isset($_GET['type']) || $_GET['type'] == 'all') echo 'selected'; ?>>All</option>
                            <option value="payment" <?php if(isset($_GET['type']) && $_GET['type'] == 'payment') echo 'selected'; ?>>Payment Related</option>
                            <option value="i4cus" <?php if(isset($_GET['type']) && $_GET['type'] == 'i4cus') echo 'selected'; ?>>i4Cus Issues</option>
                            <option value="incomplete" <?php if(isset($_GET['type']) && $_GET['type'] == 'incomplete') echo 'selected'; ?>>More Info Required</option>
                            <option value="neither" <?php if(isset($_GET['type']) && $_GET['type'] == 'neither') echo 'selected'; ?>>Neither Payment nor i4Cus</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-12 col-lg-8 admin-main-col">
                <div class="card">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                        <h4 class="mb-2 mb-md-0">
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
                                echo ' <a href="admin.php" class="btn btn-sm btn-outline-light ml-2 text-dark"><i class="fas fa-times"></i> Clear Filter</a>';
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
                            <span class="badge badge-light text-primary ml-2"><?php echo $total_view_complaints; ?></span>
                        </h4>
                        <?php if ($_SESSION["role_id"] == 1 || $_SESSION["role_id"] == 3): // Super Admin or Director ?>
                            <div>
                                <button type="button" class="btn btn-success btn-sm w-100" data-toggle="modal" data-target="#exportModal">
                                    <i class="fas fa-download"></i> Export Complaints
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($filter_user > 0): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                Showing <?php echo $total_view_complaints; ?> complaint(s) submitted by this user.
                                <?php if ($total_view_complaints > 0): ?>
                                    <small class="d-block mt-1">This includes all complaints (pending, in progress, and treated) up to the limit.</small>
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
                            <form method="post" id="bulkActionsForm">
                                <div class="mb-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                                    <div class="mb-2 mb-md-0">
                                        <button type="submit" name="bulk_delete" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete the selected complaints?');">
                                            <i class="fas fa-trash"></i> Delete Selected
                                        </button>
                                        <small class="text-muted ml-2 d-none d-md-inline">Select complaints using checkboxes</small>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <label class="mr-2 mb-0 font-weight-bold text-muted" style="white-space: nowrap;">Show:</label>
                                        <select class="form-control form-control-sm" style="width: auto; cursor: pointer;" 
                                                onchange="window.location.href='?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>&limit='+this.value">
                                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                                            <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20 entries</option>
                                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                                            <option value="all" <?php echo $per_page === 'all' ? 'selected' : ''; ?>>All entries</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover table-mobile-cards" id="complaintsTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th style="width:40px"><input type="checkbox" id="selectAll" title="Select All"></th>
                                                <th style="width:95px">Date</th>
                                                <th style="width:140px">Student ID</th>
                                                <th>Complaint</th>
                                                <th style="width:95px">Status</th>
                                                <th style="width:85px">Priority</th>
                                                <th style="width:110px">Lodged By</th>
                                                <th style="width:110px">Handler</th>
                                                <th style="width:70px">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($complaints as $complaint): ?>
                                            <tr class="<?php echo $complaint['is_urgent'] ? 'table-danger' : ''; ?>" data-complaint-id="<?php echo $complaint['complaint_id']; ?>">
                                                <td data-label="Select">
                                                    <input type="checkbox" name="complaint_ids[]" value="<?php echo $complaint['complaint_id']; ?>" class="complaint-checkbox">
                                                </td>
                                                <td data-label="Date"><?php echo date('M d, Y', strtotime($complaint['created_at'])); ?></td>
                                                <?php
                                                    $sid_full = htmlspecialchars($complaint['student_id'] ?? '');
                                                    $sid_display = mb_strlen($sid_full) > 20
                                                        ? mb_substr($sid_full, 0, 20) . '…'
                                                        : $sid_full;
                                                ?>
                                                <td data-label="Student ID" title="<?php echo $sid_full; ?>" style="cursor:default"><?php echo $sid_display; ?></td>
                                            <td data-label="Complaint">
                                                <?php echo substr($complaint['complaint_text'], 0, 50); ?>...
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
                                            <td data-label="Status">
                                                <span class="complaint-status status-<?php echo strtolower($complaint['status']); ?>">
                                                    <?php echo $complaint['status']; ?>
                                                </span>
                                            </td>
                                            <td data-label="Priority">
                                                <?php if($complaint['is_urgent']): ?>
                                                    <span class="badge badge-danger">Urgent</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Normal</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Lodged By"><strong><?php echo $complaint['lodged_by_name'] ?? '-'; ?></strong></td>
                                            <td data-label="Handler"><?php echo $complaint['handler_name'] ?? 'Not assigned'; ?></td>
                                            <td data-label="Action" class="action-col">
                                                <button type="button" class="btn btn-outline-primary btn-sm p-1" data-toggle="modal" 
                                                        data-target="#updateModal<?php echo $complaint['complaint_id']; ?>" title="Update Complaint">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </td>
                                        </tr>

                                        <div class="modal fade" id="updateModal<?php echo $complaint['complaint_id']; ?>" tabindex="-1" data-backdrop="false" data-keyboard="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Update Complaint</h5>
                                                        <button type="button" class="close text-white" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <form method="post" enctype="multipart/form-data">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="complaint_id" value="<?php echo $complaint['complaint_id']; ?>">
                                                            <div class="form-group">
                                                                <label>Student ID</label>
                                                                <p class="form-control-plaintext font-weight-bold border-bottom pb-2"><?php echo $complaint['student_id']; ?></p>
                                                            </div>
                                                            <div class="form-group">
                                                                <label>Complaint Details</label>
                                                                <p class="form-control-plaintext bg-light p-2 rounded"><?php echo $complaint['complaint_text']; ?></p>
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
                                                                    <label class="custom-control-label text-danger font-weight-bold" for="urgentCheck<?php echo $complaint['complaint_id']; ?>">
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
                                                        <div class="modal-footer bg-light">
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
                                </div></form>
                            
                            <?php if($total_pages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center flex-wrap">
                                    <?php 
                                        $page_params = $_GET;
                                        if(isset($page_params['page'])) unset($page_params['page']);
                                        $qs = http_build_query($page_params);
                                        $qs = $qs ? '&' . $qs : '';
                                    ?>
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1 . $qs; ?>" tabindex="-1">Previous</a>
                                    </li>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if($start_page > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="?page=1'.$qs.'">1</a></li>';
                                        if($start_page > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }
                                    
                                    for($i = $start_page; $i <= $end_page; $i++): 
                                    ?>
                                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i . $qs; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php 
                                    if($end_page < $total_pages) {
                                        if($end_page < $total_pages - 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.$qs.'">'.$total_pages.'</a></li>';
                                    }
                                    ?>
                                    
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1 . $qs; ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>

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
            <div class="col-12 col-lg-4 admin-sidebar-col sidebar-col">
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
                        $total_complaints_arr = [];
                        $treated_complaints = [];
                        $pending_complaints_arr = [];
                        
                        if($stats_result){
                            while($row = mysqli_fetch_assoc($stats_result)){
                                $staff_names[] = $row['full_name'];
                                $total_complaints_arr[] = $row['total_complaints'];
                                $treated_complaints[] = $row['treated_complaints'];
                                $pending_complaints_arr[] = $row['pending_complaints'];
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
                            <button type="submit" name="create_user" class="btn btn-primary w-100">Create User</button>
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
                                    <div class="list-group-item px-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($staff['full_name']); ?></h6>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <span class="badge badge-primary mr-1">Total: <?php echo $staff['total_complaints']; ?></span>
                                                    <span class="badge badge-info mr-1">Today: <?php echo $staff['today_count']; ?></span>
                                                    <span class="badge badge-success mr-1">Week: <?php echo $staff['week_count']; ?></span>
                                                </div>
                                            </div>
                                            <a href="?staff_id=<?php echo $staff['user_id']; ?>" class="btn btn-sm btn-info text-nowrap ml-2">
                                                View
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
                                <table class="table table-hover table-mobile-cards">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Student ID</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($staff_complaints as $complaint): ?>
                                            <tr>
                                                <td data-label="Date"><?php echo date('M d, y', strtotime($complaint['created_at'])); ?></td>
                                                <td data-label="Student ID"><?php echo htmlspecialchars($complaint['student_id']); ?></td>
                                                <td data-label="Complaint">
                                                    <?php echo substr(htmlspecialchars($complaint['complaint_text']), 0, 40) . '...'; ?>
                                                    <?php if($complaint['image_path']): ?>
                                                        <?php 
                                                        $images = array_filter(explode(",", $complaint['image_path'])); 
                                                        $img_count = count($images);
                                                        $processed_images = array_map('getImagePath', $images);
                                                        ?>
                                                        <div class="mt-2">
                                                            <button type="button" class="btn btn-link p-0 attachment-btn" onclick="showGalleryModal(<?php echo htmlspecialchars(json_encode($processed_images)); ?>)">
                                                                <i class="fas fa-paperclip"></i> <?php echo $img_count; ?>
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Status">
                                                    <span class="badge badge-<?php 
                                                        echo $complaint['status'] == 'Treated' ? 'success' : 
                                                            ($complaint['status'] == 'In Progress' ? 'warning' : 'secondary'); 
                                                    ?>">
                                                        <?php echo $complaint['status']; ?>
                                                    </span>
                                                </td>
                                                <td data-label="Action" class="action-col">
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
                            <span class="badge badge-primary font-weight-normal py-1 px-2 mb-1 text-white">Total Pending: <?php echo count($pending_today) + count($pending_previous_days); ?></span>
                            <span class="badge badge-warning font-weight-normal py-1 px-2 mb-1" style="cursor:pointer;" data-toggle="collapse" data-target="#pendingPrevDaysList" aria-expanded="false" aria-controls="pendingPrevDaysList">
                                Pending from Previous Days: <?php echo count($pending_previous_days); ?>
                            </span>
                        </div>
                        <div class="collapse" id="pendingPrevDaysList">
                            <div class="card card-body p-0 border-0">
                                <?php if(count($pending_previous_days) > 0): ?>
                                    <ul class="list-group mb-2">
                                        <?php foreach($pending_previous_days as $c): ?>
                                            <li class="list-group-item d-flex justify-content-between align-items-center p-2">
                                                <span class="small">
                                                    <?php echo date('M d, Y h:i A', strtotime($c['created_at'])); ?> - <strong><?php echo htmlspecialchars($c['student_id'] ?? ''); ?></strong>
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

    <div class="modal fade" id="galleryModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Attachments</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body text-center">
                    <div id="galleryImages" class="d-flex flex-wrap justify-content-center"></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="imageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Image View</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="img-fluid" alt="Complaint Image">
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="js/clipboard-paste.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    let myChart = null;
    const staffNames = <?php echo json_encode($staff_names); ?>;
    const totalComplaintsArr = <?php echo json_encode($total_complaints_arr); ?>;
    const treatedComplaints = <?php echo json_encode($treated_complaints); ?>;
    const pendingComplaintsArr = <?php echo json_encode($pending_complaints_arr); ?>;

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
                    data: totalComplaintsArr,
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
                    data: pendingComplaintsArr,
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
    });
    </script>
    
    <div class="container-fluid mt-4 mb-4">
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

    <div class="modal fade" id="exportModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export Complaints</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form id="exportForm">
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="font-weight-bold text-muted">Export Format</label>
                            <select name="format" class="form-control">
                                <option value="csv">CSV (Excel Compatible)</option>
                                <option value="json">JSON (Developer Format)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-muted">Date Range (Optional)</label>
                            <div class="row">
                                <div class="col-md-6 mb-2 mb-md-0">
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
                            <label class="font-weight-bold text-muted">Status Filter</label>
                            <select name="status" class="form-control">
                                <option value="all">All Statuses</option>
                                <option value="Pending">Pending Only</option>
                                <option value="In Progress">In Progress Only</option>
                                <option value="Treated">Treated Only</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="font-weight-bold text-muted">Type Filter</label>
                            <select name="type" class="form-control">
                                <option value="all">All Types</option>
                                <option value="payment">Payment Related Only</option>
                                <option value="urgent">Urgent Only</option>
                                <option value="department">Department Complaints Only</option>
                            </select>
                        </div>
                        
                        <?php if ($filter_user > 0): ?>
                            <div class="alert alert-info py-2 px-3">
                                <i class="fas fa-info-circle mr-1"></i>
                                <strong>User Filter Active:</strong> Export will include only complaints from the currently filtered user.
                            </div>
                            <input type="hidden" name="filter_user" value="<?php echo $filter_user; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group mb-0">
                            <div class="custom-control custom-checkbox mt-2">
                                <input type="checkbox" class="custom-control-input" id="includePersonalData">
                                <label class="custom-control-label text-muted" for="includePersonalData">
                                    I confirm that this export is for legitimate business purposes and will be handled according to data protection policies.
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success" id="exportBtn" disabled>
                            <i class="fas fa-download mr-1"></i> Export Complaints
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
        $('#exportBtn').html('<i class="fas fa-spinner fa-spin mr-1"></i> Exporting...').prop('disabled', true);
        
        // Create a temporary link to download the file
        const link = document.createElement('a');
        link.href = exportUrl;
        link.download = '';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Reset button after a delay
        setTimeout(function() {
            $('#exportBtn').html('<i class="fas fa-download mr-1"></i> Export Complaints').prop('disabled', false);
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