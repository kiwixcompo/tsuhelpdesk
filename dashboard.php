<?php
// Start output buffering at the very top
ob_start();
session_start();
require_once "config.php";
require_once "includes/notifications.php";
require_once "calendar_helper.php";

// Initialize arrays and variables
$complaints = [];
$unread_count = 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'latest';

// Calculate base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$script_path = dirname($_SERVER['SCRIPT_NAME']);
$base_url = rtrim($protocol . $host . $script_path, '/') . '/';

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    // Clean buffer before redirect
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("location: index.php");
    exit;
}

// Fetch app settings for header use
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

// Get user's full name if not set in session
if(!isset($_SESSION["full_name"])){
    $sql = "SELECT full_name, email, phone FROM users WHERE user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)){
                $_SESSION["full_name"] = $row["full_name"];
                $_SESSION["email"] = $row["email"];
                $_SESSION["phone"] = $row["phone"];
            }
        }
        mysqli_stmt_close($stmt);
    }
} else {
    // Fetch email and phone if not already in session
    if(!isset($_SESSION["email"]) || !isset($_SESSION["phone"])){
        $sql = "SELECT email, phone FROM users WHERE user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                if($row = mysqli_fetch_assoc($result)){
                    $_SESSION["email"] = $row["email"];
                    $_SESSION["phone"] = $row["phone"];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Ensure session email and phone are up-to-date
if (!isset($_SESSION['email']) || !isset($_SESSION['phone']) || empty($_SESSION['email']) || empty($_SESSION['phone'])) {
    $sql = "SELECT email, phone FROM users WHERE user_id = ?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($result)) {
                $_SESSION['email'] = $row['email'];
                $_SESSION['phone'] = $row['phone'];
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Process complaint submission
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["submit_complaint"])){
    $student_id = isset($_POST["student_id"]) ? trim($_POST["student_id"]) : null;
    $department_name = isset($_POST["department_name"]) ? trim($_POST["department_name"]) : null;
    $staff_name = isset($_POST["staff_name"]) ? trim($_POST["staff_name"]) : null;
    $complaint_text = trim($_POST["complaint_text"]);
    $is_urgent = isset($_POST["is_urgent"]) ? 1 : 0;
    $is_payment_related = isset($_POST["is_payment_related"]) ? 1 : 0;
    $is_i4cus = isset($_POST["is_i4cus"]) ? 1 : 0;
    $is_staff_complaint = isset($_POST["is_staff_complaint"]) ? 1 : 0;
    $lodged_by = $_SESSION["user_id"];
    
    // Handle multiple image uploads
    $image_paths = array();
    if(isset($_FILES["complaint_images"]) && !empty($_FILES["complaint_images"]["name"][0])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        
        // Create uploads directory if it doesn't exist
        if (!file_exists($target_dir)) {
            if (!mkdir($target_dir, 0755, true)) {
                $error_message = "Failed to create uploads directory.";
            }
        }
        
        // Check if directory is writable
        if (!is_writable($target_dir)) {
            $error_message = "Uploads directory is not writable.";
        }
        
        if (!isset($error_message)) {
            // Loop through each uploaded file
            foreach($_FILES["complaint_images"]["tmp_name"] as $key => $tmp_name){
                if($_FILES["complaint_images"]["error"][$key] == 0){
                    $filename = $_FILES["complaint_images"]["name"][$key];
                    $filetype = $_FILES["complaint_images"]["type"][$key];
                    $filesize = $_FILES["complaint_images"]["size"][$key];
                    
                    // Verify file extension
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if(!array_key_exists($ext, $allowed)) continue;
                    
                    // Verify file size - 5MB maximum
                    $maxsize = 5 * 1024 * 1024;
                    if($filesize > $maxsize) continue;
                    
                    // Verify MIME type
                    if(in_array($filetype, $allowed)){
                        $new_filename = uniqid() . "." . $ext;
                        $target_file = $target_dir . $new_filename;
                        
                        if(move_uploaded_file($tmp_name, $target_file)){
                            // Set proper file permissions
                            chmod($target_file, 0644);
                            // Store just the filename
                            $image_paths[] = $new_filename;
                        }
                    }
                }
            }
        }
    }
    
    if(!isset($error_message) && ((!empty($student_id) && !$is_staff_complaint) || (!empty($department_name) && !empty($staff_name) && $is_staff_complaint)) && !empty($complaint_text)){
        $image_paths_str = !empty($image_paths) ? implode(",", $image_paths) : null;
        $sql = "INSERT INTO complaints (student_id, department_name, staff_name, complaint_text, is_urgent, is_payment_related, is_i4cus, is_staff_complaint, image_path, lodged_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "ssssiiiisi", $student_id, $department_name, $staff_name, $complaint_text, $is_urgent, $is_payment_related, $is_i4cus, $is_staff_complaint, $image_paths_str, $lodged_by);
            
            if(mysqli_stmt_execute($stmt)){
                // Clean buffer and redirect to prevent form resubmission
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header("Location: dashboard.php?success=1");
                exit();
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        } else {
            // Handle prepare failure
            $error_message = "Database error. Please try again later.";
            error_log("Failed to prepare statement: " . mysqli_error($conn));
        }
    } else if(!isset($error_message)) {
        $error_message = "Please fill all required fields.";
    }
}

// Show success message after redirect
if(isset($_GET['success']) && $_GET['success'] == 1) {
    $success_message = "Complaint submitted successfully.";
}

// Process complaint deletion
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_complaints"])){
    $complaint_ids = isset($_POST["complaint_ids"]) ? $_POST["complaint_ids"] : [];
    
    if(!empty($complaint_ids)){
        $placeholders = str_repeat('?,', count($complaint_ids) - 1) . '?';
        $types = str_repeat('i', count($complaint_ids));
        
        // For staff, only allow deleting their own complaints
        if($_SESSION["role_id"] != 1){
            $sql = "DELETE FROM complaints WHERE complaint_id IN ($placeholders) AND lodged_by = ?";
            $types .= 'i';
            $complaint_ids[] = $_SESSION["user_id"];
        } else {
            $sql = "DELETE FROM complaints WHERE complaint_id IN ($placeholders)";
        }
        
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, $types, ...$complaint_ids);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "Selected complaints deleted successfully.";
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please select complaints to delete.";
    }
}

// Build the query for complaints
$where_conditions = [];
$params = [];
$param_types = "";

// Restrict to own complaints for non-admins
if ($_SESSION["role_id"] != 1) {
    $where_conditions[] = "c.lodged_by = ?";
    $params[] = $_SESSION["user_id"];
    $param_types .= "i";
}

// Search functionality - now searches by complaint ID, student ID, or complaint text
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$is_searching = !empty($search);

if ($is_searching) {
    // Search by complaint ID, student ID or complaint text - show ALL complaints including treated
    // Use CAST to handle complaint_id comparison properly
    $search_escaped = mysqli_real_escape_string($conn, $search);
    $where_conditions[] = "(CAST(c.complaint_id AS CHAR) LIKE ? OR c.student_id LIKE ? OR c.complaint_text LIKE ?)";
    $params[] = "%$search_escaped%";
    $params[] = "%$search_escaped%";
    $params[] = "%$search_escaped%";
    $param_types .= "sss";
} else {
    // Only show non-treated complaints when not searching
    $where_conditions[] = "c.status != 'Treated'";
}

// Status filter
$feedback_filter = isset($_GET['feedback_filter']) ? $_GET['feedback_filter'] : 'all';
if ($is_searching) {
    // When searching, ignore status filter to show all matching complaints
    $feedback_filter = 'all';
} else if ($feedback_filter != 'all') {
    switch($feedback_filter) {
        case 'treated':
            $where_conditions[] = "c.status = 'Treated'";
            break;
        case 'pending':
            $where_conditions[] = "c.status = 'Pending'";
            break;
        case 'with_feedback':
            $where_conditions[] = "c.feedback IS NOT NULL AND c.feedback != ''";
            break;
        case 'resolved':
            $where_conditions[] = "c.status = 'Treated' AND c.feedback_type = 'resolved'";
            break;
        case 'incomplete':
            $where_conditions[] = "c.status = 'Needs More Info' OR c.feedback_type = 'incomplete'";
            break;
        case 'no_feedback':
            $where_conditions[] = "(c.feedback IS NULL OR c.feedback = '')";
            break;
    }
}

// Date filter
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$today = date('Y-m-d');
$viewing_past_date = false;

// Date filter - apply date filters when specified
if (!empty($filter_date)) {
    // If a specific date filter is applied
    $where_conditions[] = "DATE(c.created_at) = ?";
    $params[] = $filter_date;
    $param_types .= "s";
    
    // Check if we're viewing a past date
    if ($filter_date < $today) {
        $viewing_past_date = true;
    }
} else {
    // Apply date range filters if specified
    if (!empty($start_date)) {
        $where_conditions[] = "DATE(c.created_at) >= ?";
        $params[] = $start_date;
        $param_types .= "s";
    }
    
    if (!empty($end_date)) {
        $where_conditions[] = "DATE(c.created_at) <= ?";
        $params[] = $end_date;
        $param_types .= "s";
    }
    
    // Only restrict to today or future if no date filters are applied and not searching
    if (!$is_searching && empty($start_date) && empty($end_date) && $feedback_filter == 'all') {
        $where_conditions[] = "DATE(c.created_at) >= ?";
        $params[] = $today;
        $param_types .= "s";
    }
}

// Build WHERE clause
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Add sorting
$order_by = "c.is_urgent DESC, "; // Always prioritize urgent complaints
$order_by .= $sort == 'oldest' ? "c.created_at ASC" : "c.created_at DESC";

// Fetch complaints
$sql = "SELECT c.*, u.full_name as handler_name 
        FROM complaints c 
        LEFT JOIN users u ON c.handled_by = u.user_id 
        $where_clause 
        ORDER BY $order_by";

if(!empty($params)){
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            while($row = mysqli_fetch_assoc($result)){
                $complaints[] = $row;
            }
        } else {
            // Log the error for debugging
            error_log("Dashboard SQL Error: " . mysqli_error($conn));
            error_log("Dashboard SQL Query: " . $sql);
            error_log("Dashboard Params: " . print_r($params, true));
            error_log("Dashboard Param Types: " . $param_types);
            // Show error in development mode
            if(isset($_GET['debug']) && $_SESSION["role_id"] == 1){
                echo "<div class='alert alert-danger'>SQL Error: " . mysqli_error($conn) . "</div>";
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("Dashboard Prepare Error: " . mysqli_error($conn));
        error_log("Dashboard SQL: " . $sql);
        if(isset($_GET['debug']) && $_SESSION["role_id"] == 1){
            echo "<div class='alert alert-danger'>Prepare Error: " . mysqli_error($conn) . "</div>";
        }
    }
} else {
    $result = mysqli_query($conn, $sql);
    if($result){
        while($row = mysqli_fetch_assoc($result)){
            $complaints[] = $row;
        }
    } else {
        // Log the error for debugging
        error_log("Dashboard SQL Error (no params): " . mysqli_error($conn));
        error_log("Dashboard SQL Query: " . $sql);
        if(isset($_GET['debug']) && $_SESSION["role_id"] == 1){
            echo "<div class='alert alert-danger'>SQL Error: " . mysqli_error($conn) . "</div>";
        }
    }
}

// Count unread messages
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

// Initialize counts
$total_complaints = count($complaints);
$pending_count = 0;
foreach($complaints as $complaint){
    if($complaint['status'] == 'Pending'){
        $pending_count++;
    }
}

// IMPROVED: Helper function to get image URL with fallback
function getImageUrl($image) {
    $image = trim($image);
    if (empty($image) || $image === '0') {
        return '';
    }
    
    // Clean the filename
    $filename = basename($image);
    
    // Use the public image serving script for better error handling
    return 'public_image.php?img=' . urlencode($filename);
}

// Alternative direct path function (for fallback)
function getDirectImagePath($image) {
    $image = trim($image);
    if (empty($image) || $image === '0') {
        return '';
    }
    
    // Clean the filename
    $filename = basename($image);
    
    // Always use the public image serving script
    return 'public_image.php?img=' . urlencode($filename);
}

// If show_previous=1 is set, show untreated complaints from previous days
if(isset($_GET['show_previous']) && $_GET['show_previous'] == '1') {
    $where_conditions = ["c.status != 'Treated'"];
    if ($_SESSION["role_id"] != 1) {
        $where_conditions[] = "c.lodged_by = ?";
        $params = [$_SESSION["user_id"]];
        $param_types = "i";
    } else {
        $params = [];
        $param_types = "";
    }
    $where_conditions[] = "DATE(c.created_at) < ?";
    $params[] = $today;
    $param_types .= "s";
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    // Fetch previous complaints
    $sql = "SELECT c.*, u.full_name as handler_name FROM complaints c LEFT JOIN users u ON c.handled_by = u.user_id $where_clause ORDER BY c.created_at DESC";
    $complaints = [];
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            while($row = mysqli_fetch_assoc($result)){
                $complaints[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($app_name); ?></title>
    
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
    <link rel="stylesheet" href="css/responsive-fix.css">
    <script src="js/auto-logout.js"></script>
    
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
        
        /* Gallery Styles */
        .gallery-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        .gallery-item {
            position: relative;
            width: 100px;
            height: 100px;
            overflow: hidden;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            cursor: pointer;
            border: 1px solid #dee2e6;
        }
        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .gallery-item img.zoomed {
            transform: scale(2);
            cursor: zoom-out;
        }
        .image-count-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(0,0,0,0.7);
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .view-all-btn {
            margin-top: 10px;
        }
        
        /* Image error handling */
        .image-error {
            background: #f8f9fa;
            border: 1px dashed #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
            width: 100%;
            height: 100%;
            border-radius: 4px;
        }
        
        /* Loading placeholder */
        .image-loading {
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            width: 100%;
            height: 100%;
        }
        
        /* Navbar improvements */
        .navbar-nav .nav-link {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
        }
        
        .navbar-nav .nav-link i {
            margin-right: 0.5rem;
            font-size: 1rem;
        }
        
        /* Icon-only items */
        .navbar-nav .nav-link.icon-only {
            justify-content: center;
            padding: 0.5rem 0.75rem;
            min-width: 40px;
        }
        
        .navbar-nav .nav-link.icon-only i {
            margin-right: 0;
            font-size: 1.1rem;
        }
        
        .navbar-nav .badge {
            font-size: 0.65rem;
            min-width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .navbar-nav .nav-item.active .nav-link {
            background-color: rgba(255,255,255,0.1);
            border-radius: 4px;
        }
        
        /* Tooltip styling */
        .tooltip-inner {
            background-color: #333;
            color: #fff;
            border-radius: 4px;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <!-- App Branding Section (Logo + Name) - Hidden template -->
    <div class="app-branding d-none" id="app-branding">
        <?php if($app_logo && file_exists($app_logo)): ?>
            <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="app-logo">
        <?php endif; ?>
        <span class="app-name"><?php echo htmlspecialchars($app_name); ?></span>
    </div>

    <?php include 'includes/navbar.php'; ?>

    <?php
    // Set up dashboard header variables
    $page_title = 'Dashboard';
    $page_subtitle = 'Manage complaints and track system activity';
    $page_icon = 'fas fa-tachometer-alt';
    $show_breadcrumb = false;
    
    // Get quick stats for header
    $total_complaints = 0;
    $pending_complaints = 0;
    $treated_complaints = 0;
    
    // Count total complaints
    $sql = "SELECT COUNT(*) as total FROM complaints";
    $result = mysqli_query($conn, $sql);
    if($row = mysqli_fetch_assoc($result)){
        $total_complaints = $row['total'];
    }
    
    // Count pending complaints
    $sql = "SELECT COUNT(*) as total FROM complaints WHERE status = 'Pending'";
    $result = mysqli_query($conn, $sql);
    if($row = mysqli_fetch_assoc($result)){
        $pending_complaints = $row['total'];
    }
    
    // Count treated complaints
    $sql = "SELECT COUNT(*) as total FROM complaints WHERE status = 'Treated'";
    $result = mysqli_query($conn, $sql);
    if($row = mysqli_fetch_assoc($result)){
        $treated_complaints = $row['total'];
    }
    
    // Set up quick stats
    $quick_stats = [
        ['number' => $total_complaints, 'label' => 'Total Complaints'],
        ['number' => $pending_complaints, 'label' => 'Pending'],
        ['number' => $treated_complaints, 'label' => 'Resolved']
    ];
    
    include 'includes/dashboard_header.php';
    ?>

    <div class="container">
        <?php if(empty($_SESSION["email"]) || empty($_SESSION["phone"])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>Important:</strong> Your profile is missing an email address or phone number. Please update your <a href="account.php" class="alert-link">account profile</a> to ensure you can recover your password in the future.
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        

        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>Submit New Complaint</h4>
                    </div>
                    <div class="card-body">
                        <?php if(isset($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        <?php if(isset($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <!-- Changed form action to dashboard.php -->
                        <form method="post" action="dashboard.php" enctype="multipart/form-data">
                            <?php if($_SESSION["role_id"] == 1): // Only show for admins ?>
                            <div class="form-group">
                                <div class="custom-control custom-checkbox mb-3">
                                    <input type="checkbox" class="custom-control-input" id="staffComplaintCheck" name="is_staff_complaint" onchange="toggleComplaintType(this)">
                                    <label class="custom-control-label" for="staffComplaintCheck">This is a staff/department complaint</label>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div id="studentComplaintFields">
                                <div class="form-group">
                                    <label>Student ID (Matric Number, JAMB Number, Phone Number or App ID)</label>
                                    <input type="text" name="student_id" class="form-control" required>
                                </div>
                            </div>

                            <?php if($_SESSION["role_id"] == 1): // Only show for admins ?>
                            <div id="staffComplaintFields" style="display: none;">
                                <div class="form-group">
                                    <label>Department Name</label>
                                    <input type="text" name="department_name" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label>Staff Name</label>
                                    <input type="text" name="staff_name" class="form-control">
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label>Complaint Details</label>
                                <textarea name="complaint_text" class="form-control" rows="5" required placeholder="Describe your complaint... (You can paste images directly with Ctrl+V)"></textarea>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle text-info"></i> 
                                    <strong>Tip:</strong> You can paste screenshots directly with Ctrl+V while typing, or click the attachment icon to browse files.
                                </small>
                            </div>
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="paymentCheck" name="is_payment_related">
                                    <label class="custom-control-label" for="paymentCheck">This is a payment-related issue</label>
                                </div>
                            </div>
                            <?php if($_SESSION["role_id"] == 1): // Only show for admins ?>
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="i4cusCheck" name="is_i4cus">
                                    <label class="custom-control-label" for="i4cusCheck">This complaint requires i4Cus handling</label>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="urgentCheck" name="is_urgent">
                                    <label class="custom-control-label" for="urgentCheck">Mark as Urgent</label>
                                </div>
                            </div>
                            <button type="submit" name="submit_complaint" class="btn btn-primary">Submit Complaint</button>
                        </form>

                        <script>
                        function toggleComplaintType(checkbox) {
                            const studentFields = document.getElementById('studentComplaintFields');
                            const staffFields = document.getElementById('staffComplaintFields');
                            const studentIdInput = document.querySelector('input[name="student_id"]');
                            const deptInput = document.querySelector('input[name="department_name"]');
                            const staffInput = document.querySelector('input[name="staff_name"]');
                            
                            if (checkbox.checked) {
                                studentFields.style.display = 'none';
                                staffFields.style.display = 'block';
                                studentIdInput.required = false;
                                deptInput.required = true;
                                staffInput.required = true;
                            } else {
                                studentFields.style.display = 'block';
                                staffFields.style.display = 'none';
                                studentIdInput.required = true;
                                deptInput.required = false;
                                staffInput.required = false;
                            }
                        }
                        </script>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h4>Search & Filter</h4>
                    </div>
                    <div class="card-body">
                        <form method="get" action="dashboard.php">
                            <div class="form-group">
                                <label>Search by Complaint ID or Student ID</label>
                                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Enter Complaint ID, Student ID, or keywords">
                                <small class="form-text text-muted">Search will show all complaints (including treated ones) that match your query</small>
                            </div>
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                            </div>
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                            </div>
                            <div class="form-group">
                                <label>Filter by Status</label>
                                <select name="feedback_filter" class="form-control">
                                    <option value="all" <?php echo (!isset($_GET['feedback_filter']) || $_GET['feedback_filter'] == 'all') ? 'selected' : ''; ?>>All Complaints</option>
                                    <option value="treated" <?php echo (isset($_GET['feedback_filter']) && $_GET['feedback_filter'] == 'treated') ? 'selected' : ''; ?>>Treated</option>
                                    <option value="pending" <?php echo (isset($_GET['feedback_filter']) && $_GET['feedback_filter'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="with_feedback" <?php echo (isset($_GET['feedback_filter']) && $_GET['feedback_filter'] == 'with_feedback') ? 'selected' : ''; ?>>With Feedback</option>
                                    <option value="resolved" <?php echo (isset($_GET['feedback_filter']) && $_GET['feedback_filter'] == 'resolved') ? 'selected' : ''; ?>>Resolved (Response Only)</option>
                                    <option value="incomplete" <?php echo (isset($_GET['feedback_filter']) && $_GET['feedback_filter'] == 'incomplete') ? 'selected' : ''; ?>>Incomplete Information</option>
                                    <option value="no_feedback" <?php echo (isset($_GET['feedback_filter']) && $_GET['feedback_filter'] == 'no_feedback') ? 'selected' : ''; ?>>No Feedback</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Sort By</label>
                                <select name="sort" class="form-control">
                                    <option value="latest" <?php echo $sort == 'latest' ? 'selected' : ''; ?>>Latest First</option>
                                    <option value="oldest" <?php echo $sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <a href="dashboard.php" class="btn btn-secondary">Clear Filters</a>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4>Recent Complaints</h4>
                    </div>
                    <div class="card-body">
                        <?php if(isset($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        <?php if(isset($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <span class="badge badge-primary">Total Active: <?php echo $total_complaints; ?></span>
                            <span class="badge badge-warning">Pending: <?php echo $pending_count; ?></span>
                            <a href="archives.php" class="badge badge-success">View Archives</a>
                        </div>

                        <?php if(!empty($complaints)): ?>
                            <form method="post" id="complaintForm">
                                <div class="mb-3">
                                    <button type="submit" name="delete_complaints" class="btn btn-danger btn-sm" 
                                            onclick="return confirm('Are you sure you want to delete the selected complaints?');">
                                        Delete Selected
                                    </button>
                                </div>
                                <div class="list-group">
                                    <div class="list-group-item bg-light">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="selectAll">
                                            <label class="form-check-label" for="selectAll">Select All</label>
                                        </div>
                                    </div>
                                    <?php foreach($complaints as $complaint): 
                                        // Only show complaints from the last 24 hours in the sidebar
                                        $created_at = strtotime($complaint['created_at']);
                                        if ($created_at < strtotime('-24 hours')) continue;
                                        $images = [];
                                        $img_count = 0;
                                        if(!empty($complaint['image_path']) && $complaint['image_path'] !== '0') {
                                            $images = array_filter(explode(",", $complaint['image_path']));
                                            $images = array_map('trim', $images);
                                            $images = array_filter($images); // Remove empty values
                                            $img_count = count($images);
                                        }
                                    ?>
                                        <div class="list-group-item <?php echo $complaint['is_urgent'] ? 'border-danger' : ''; ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="d-flex align-items-center">
                                                    <?php 
                                                    // Show checkbox if user is admin or if complaint was lodged by current user
                                                    if($_SESSION["role_id"] == 1 || $complaint['lodged_by'] == $_SESSION["user_id"]): 
                                                    ?>
                                                    <div class="form-check mr-3">
                                                        <input type="checkbox" class="form-check-input complaint-checkbox" 
                                                               name="complaint_ids[]" value="<?php echo $complaint['complaint_id']; ?>">
                                                    </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($complaint['created_at'])); ?></small>
                                                        <?php if($complaint['is_urgent']): ?>
                                                            <span class="badge badge-danger ml-2">Urgent</span>
                                                        <?php endif; ?>
                                                        <span class="complaint-status status-<?php echo strtolower($complaint['status']); ?> ml-2">
                                                            <?php echo $complaint['status']; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                                       class="btn btn-sm btn-info">View Details</a>
                                                </div>
                                            </div>
                                            <p class="mb-1"><?php echo substr($complaint['complaint_text'], 0, 100); ?>...</p>
                                            <small>ID: <?php echo $complaint['student_id']; ?></small>
                                            <?php if($complaint['feedback']): ?>
                                                <div class="feedback-box mt-2">
                                                    <small class="text-muted">Feedback:</small>
                                                    <p class="mb-0"><?php echo $complaint['feedback']; ?></p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if($img_count > 0): ?>
                                                <div class="mt-2">
                                                    <strong>Attached Images:</strong>
                                                    <div class="gallery-container">
                                                        <?php foreach($images as $index => $image): 
                                                            if($index < 3): 
                                                                $image_url = getImageUrl($image);
                                                                $direct_path = getDirectImagePath($image);
                                                        ?>
                                                                <div class="gallery-item" onclick="toggleZoom(this)">
                                                                    <img src="<?php echo htmlspecialchars($direct_path); ?>" 
                                                                         alt="Complaint Image <?php echo $index+1; ?>"
                                                                         loading="lazy"
                                                                         onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-error\'>Image not available</div>';">
                                                                    <?php if($img_count > 3 && $index == 2): ?>
                                                                        <div class="image-count-badge">+<?php echo $img_count - 3; ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <?php if($img_count > 3): ?>
                                                        <button type="button" class="btn btn-sm btn-info view-all-btn" 
                                                                onclick="showGalleryModal(<?php echo htmlspecialchars(json_encode(array_map('getDirectImagePath', $images))); ?>)">
                                                            <i class="fas fa-images"></i> View All (<?php echo $img_count; ?>)
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </form>
                        <?php else: ?>
                            <?php
                            if(empty($complaints)) {
                                // Check for untreated complaints from previous days
                                $prev_where = ["c.status != 'Treated'"];
                                if ($_SESSION["role_id"] != 1) {
                                    $prev_where[] = "c.lodged_by = ?";
                                    $prev_params = [$_SESSION["user_id"]];
                                    $prev_types = "i";
                                } else {
                                    $prev_params = [];
                                    $prev_types = "";
                                }
                                $prev_where[] = "DATE(c.created_at) < ?";
                                $prev_params[] = $today;
                                $prev_clause = "WHERE " . implode(" AND ", $prev_where);
                                $prev_sql = "SELECT COUNT(*) as cnt FROM complaints c $prev_clause";
                                if(!empty($prev_types) && strlen($prev_types) == count($prev_params)) {
                                    if($stmt = mysqli_prepare($conn, $prev_sql)){
                                        mysqli_stmt_bind_param($stmt, $prev_types, ...$prev_params);
                                        if(mysqli_stmt_execute($stmt)){
                                            $result = mysqli_stmt_get_result($stmt);
                                            if($row = mysqli_fetch_assoc($result)){
                                                $prev_count = $row['cnt'];
                                            }
                                        }
                                        mysqli_stmt_close($stmt);
                                    }
                                } else if (empty($prev_types) && empty($prev_params)) {
                                    // No parameters, so use mysqli_query directly
                                    $result = mysqli_query($conn, $prev_sql);
                                    if($result && $row = mysqli_fetch_assoc($result)){
                                        $prev_count = $row['cnt'];
                                    }
                                }
                                if(!empty($prev_count)) {
                                    // Show message and button
                                    echo '<div class="text-center py-5">';
                                    echo '<i class="fas fa-info-circle text-warning" style="font-size: 48px;"></i>';
                                    echo '<h4 class="mt-3">No Complaints Lodged Today</h4>';
                                    echo '<p class="text-muted">There are complaints from previous days that still need attention.</p>';
                                    echo '<a href="dashboard.php?show_previous=1" class="btn btn-primary">View Previous Complaints</a>';
                                    echo '</div>';
                                } else {
                                    // Default all caught up message
                                    echo '<div class="text-center py-5">';
                                    echo '<i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>';
                                    echo '<h4 class="mt-3">All Caught Up!</h4>';
                                    echo '<p class="text-muted">Great job! All complaints have been handled successfully. <br>Check the Archives section to view past complaints.</p>';
                                    echo '</div>';
                                }
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Search Results Section -->
        <?php if ($is_searching || $feedback_filter != 'all' || !empty($start_date) || !empty($end_date) || !empty($filter_date)): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4>
                            <?php if ($is_searching): ?>
                                Search Results for "<?php echo htmlspecialchars($search); ?>"
                            <?php elseif (!empty($start_date) || !empty($end_date) || !empty($filter_date)): ?>
                                Date Filter Results
                                <?php if (!empty($filter_date)): ?>
                                    - <?php echo date('M d, Y', strtotime($filter_date)); ?>
                                <?php elseif (!empty($start_date) || !empty($end_date)): ?>
                                    <?php if (!empty($start_date)): ?>
                                        - From <?php echo date('M d, Y', strtotime($start_date)); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($end_date)): ?>
                                        <?php echo !empty($start_date) ? ' to ' : '- Until '; ?><?php echo date('M d, Y', strtotime($end_date)); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                Filtered Results - <?php echo ucfirst(str_replace('_', ' ', $feedback_filter)); ?>
                            <?php endif; ?>
                            <span class="badge badge-info ml-2"><?php echo count($complaints); ?> found</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (empty($complaints)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-search text-muted" style="font-size: 48px;"></i>
                                <h5 class="mt-3">No Results Found</h5>
                                <p class="text-muted">
                                    <?php if ($is_searching): ?>
                                        No complaints found matching "<?php echo htmlspecialchars($search); ?>"
                                    <?php elseif (!empty($start_date) || !empty($end_date) || !empty($filter_date)): ?>
                                        No complaints found for the selected date range.
                                    <?php else: ?>
                                        No complaints found with the selected filter.
                                    <?php endif; ?>
                                </p>
                                <a href="dashboard.php" class="btn btn-primary">Clear Search</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Student ID</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($complaints as $complaint): ?>
                                        <tr class="<?php echo $complaint['is_urgent'] ? 'table-warning' : ''; ?>">
                                            <td>
                                                <strong>#<?php echo $complaint['complaint_id']; ?></strong>
                                                <?php if($complaint['is_urgent']): ?>
                                                    <span class="badge badge-danger ml-1">Urgent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($complaint['student_id']); ?></td>
                                            <td>
                                                <?php echo substr(htmlspecialchars($complaint['complaint_text']), 0, 100); ?>
                                                <?php if(strlen($complaint['complaint_text']) > 100): ?>...<?php endif; ?>
                                                <?php if($complaint['feedback']): ?>
                                                    <div class="mt-1">
                                                        <small class="text-success">
                                                            <i class="fas fa-comment"></i> Has Feedback
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="complaint-status status-<?php echo strtolower($complaint['status']); ?>">
                                                    <?php echo $complaint['status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y h:i A', strtotime($complaint['created_at'])); ?></td>
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
            </div>
        </div>
        <?php endif; ?>
        </div>
    
        <!-- Gallery Modal -->
        <div class="modal fade" id="galleryModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Complaint Images</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body text-center">
                        <img id="mainGalleryImage" src="" class="img-fluid" style="max-height: 70vh;">
                    </div>
                    <div class="modal-footer justify-content-center">
                        <div class="thumbnails d-flex flex-wrap justify-content-center" id="galleryThumbnails"></div>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-secondary" onclick="navigateGallery(-1)">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        <span id="galleryImageCount"></span>
                        <button type="button" class="btn btn-secondary" onclick="navigateGallery(1)">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    
        <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
        <script src="js/clipboard-paste.js"></script>
        <script src="assets/js/session_timeout.js"></script>
        <script src="assets/js/auto_refresh_complaints.js"></script>
        <script>
        $(document).ready(function() {
            // Handle select all checkbox
            $('#selectAll').change(function() {
                $('.complaint-checkbox').prop('checked', $(this).prop('checked'));
            });
    
            // Update select all checkbox state when individual checkboxes change
            $('.complaint-checkbox').change(function() {
                if ($('.complaint-checkbox:checked').length == $('.complaint-checkbox').length) {
                    $('#selectAll').prop('checked', true);
                } else {
                    $('#selectAll').prop('checked', false);
                }
            });
        });

        // Toggle zoom on individual images
        function toggleZoom(element) {
            const img = element.querySelector('img');
            if (img && !img.classList.contains('error')) {
                img.classList.toggle('zoomed');
                img.style.cursor = img.classList.contains('zoomed') ? 'zoom-out' : 'zoom-in';
            }
        }
        
        // Show gallery modal with all images
        function showGalleryModal(images) {
            if (!images || images.length === 0) return;
            
            const modal = $('#galleryModal');
            const mainImg = $('#mainGalleryImage');
            const thumbContainer = $('#galleryThumbnails');
            const countSpan = $('#galleryImageCount');
            
            // Clear previous thumbnails
            thumbContainer.empty();
            
            // Set first image as main
            mainImg.attr('src', images[0]);
            mainImg.attr('onerror', "this.onerror=null; this.src='data:image/svg+xml;charset=utf-8,' + encodeURIComponent('<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"300\" height=\"200\"><rect width=\"300\" height=\"200\" fill=\"#f8f9fa\" stroke=\"#dee2e6\"/><text x=\"150\" y=\"100\" text-anchor=\"middle\" dy=\".3em\" fill=\"#6c757d\" font-family=\"Arial\" font-size=\"16\">Image not found</text></svg>');");
            countSpan.text(`1 of ${images.length}`);
            
            // Create thumbnails
            images.forEach((img, index) => {
                const thumb = $(`<img src="${img}" class="gallery-thumb m-1" style="width: 80px; height: 60px; object-fit: cover; cursor: pointer; border: 2px solid #ddd;" onerror="this.onerror=null; this.style.display='none';">`);
                thumb.on('click', () => {
                    mainImg.attr('src', img);
                    countSpan.text(`${index + 1} of ${images.length}`);
                });
                thumbContainer.append(thumb);
            });
            
            modal.modal('show');
        }
        
        function navigateGallery(direction) {
            const images = $('.gallery-thumb').map(function() { return $(this).attr('src'); }).get();
            if (images.length === 0) return;
            
            const currentSrc = $('#mainGalleryImage').attr('src');
            const currentIndex = images.indexOf(currentSrc);
            const newIndex = (currentIndex + direction + images.length) % images.length;
            
            $('#mainGalleryImage').attr('src', images[newIndex]);
            $('#galleryImageCount').text(`${newIndex + 1} of ${images.length}`);
        }
        </script>
        
        <!-- Complaint Calendar at the bottom of the page -->
        <div class="row mt-4 mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Complaints Calendar</h5>
                        <?php if (isset($_GET['filter_date'])): ?>
                            <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query(array_diff_key($_GET, ['filter_date' => ''])); ?>" class="btn btn-sm btn-light ml-2 float-right">Clear Date Filter</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php echo generateComplaintCalendar($conn, $_SESSION["role_id"]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- === AUTO-REFRESH NEW COMPLAINTS === -->
    <script>
    // === AUTO-REFRESH NEW COMPLAINTS ===
    (function() {
        // Expose userId and userRoleId for checkbox logic
        var userId = <?php echo $_SESSION["user_id"]; ?>;
        var userRoleId = <?php echo $_SESSION["role_id"]; ?>;
        // Helper to render a complaint (must match PHP output)
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
            return `<div class=\"list-group-item border-warning new-complaint\"><div class=\"d-flex justify-content-between align-items-center\"><div class=\"d-flex align-items-center\">${checkbox}<div><small class=\"text-muted\">${complaint.created_at_fmt}</small>${urgentBadge}<span class=\"complaint-status status-${complaint.status.toLowerCase()} ml-2\">${complaint.status}</span></div></div><div><a href=\"view_complaint.php?id=${complaint.complaint_id}\" class=\"btn btn-sm btn-info\">View Details</a></div></div><p class=\"mb-1\">${complaint.complaint_text.substring(0,100)}...</p><small>ID: ${complaint.student_id}</small>${feedbackBox}${imagesHtml}</div>`;
        }
        function getLastComplaintId() {
            let first = $('.list-group .list-group-item').not('.bg-light').first();
            let checkbox = first.find('input.complaint-checkbox');
            if (checkbox.length) return parseInt(checkbox.val());
            let idMatch = first.html() && first.html().match(/view_complaint.php\?id=(\d+)/);
            return idMatch ? parseInt(idMatch[1]) : 0;
        }
        autoRefreshComplaints({
            container: '.list-group',
            afterSelector: '.bg-light',
            getLastId: getLastComplaintId,
            renderComplaint: renderComplaint,
            userId: userId,
            userRoleId: userRoleId
        });
    })();
    </script>
</body>
</html>
<?php
// End output buffering at the very bottom
ob_end_flush();
?>