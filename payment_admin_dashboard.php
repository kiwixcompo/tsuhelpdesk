<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Only allow Payment Admin (role_id = 6)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 6){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";
require_once "includes/notification_prefs.php";
require_once "calendar_helper.php";

// Load notification preferences
ensureNotifPrefsTable($conn);
$notif_prefs = getUserNotifPrefs($conn, $_SESSION['user_id'], 6);

// ── Handle inline treat/respond from modal ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['treat_payment_complaint'])) {
    $cid      = (int) ($_POST['complaint_id'] ?? 0);
    $status   = $_POST['status'] ?? 'Pending';
    $feedback = trim($_POST['feedback'] ?? '');

    // Self-heal: add feedback_images column if missing
    $col_chk = mysqli_query($conn, "SHOW COLUMNS FROM complaints LIKE 'feedback_images'");
    if ($col_chk && mysqli_num_rows($col_chk) === 0) {
        mysqli_query($conn, "ALTER TABLE complaints ADD COLUMN feedback_images TEXT NULL DEFAULT NULL AFTER feedback");
    }

    // Handle image uploads
    $img_paths = [];
    if (!empty($_FILES['feedback_images']['name'][0])) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $allowed = ['jpg','jpeg','png','gif'];
        foreach ($_FILES['feedback_images']['tmp_name'] as $k => $tmp) {
            if ($_FILES['feedback_images']['error'][$k] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['feedback_images']['name'][$k], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            if ($_FILES['feedback_images']['size'][$k] > 5 * 1024 * 1024) continue;
            $fname = 'pay_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $fname)) $img_paths[] = $fname;
        }
    }
    $imgs_str = !empty($img_paths) ? implode(',', $img_paths) : null;

    $sql = "UPDATE complaints SET status=?, feedback=?, feedback_images=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, 'sssii', $status, $feedback, $imgs_str, $_SESSION['user_id'], $cid);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    header("Location: payment_admin_dashboard.php?treated=1");
    exit;
}

// Initialize notification count
$notification_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
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

// Initialize variables
$search_id = isset($_GET['search_id']) ? trim($_GET['search_id']) : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Base WHERE conditions - only show payment-related complaints
$base_where = ["is_payment_related = 1"];
if ($search_id) {
    $base_where[] = "student_id LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%'";
}
if ($filter_status) {
    $base_where[] = "status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
}
if ($filter_date) {
    $base_where[] = "DATE(created_at) = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
}

// Separate active and archived complaints completely
$where_active = $base_where;
$where_active[] = "status != 'Treated'";

$where_archived = $base_where;
$where_archived[] = "status = 'Treated'";

// Build WHERE clauses
$where_clause_active = $where_active ? 'WHERE ' . implode(' AND ', $where_active) : '';
$where_clause_archived = $where_archived ? 'WHERE ' . implode(' AND ', $where_archived) : '';

// Fetch paginated active complaints with lodger name
$sql_active = "SELECT c.*, u.full_name as lodged_by_name 
               FROM complaints c 
               LEFT JOIN users u ON c.lodged_by = u.user_id 
               $where_clause_active 
               ORDER BY c.created_at DESC LIMIT $per_page OFFSET $offset";
$result_active = mysqli_query($conn, $sql_active);
$active_complaints = [];
while($row = mysqli_fetch_assoc($result_active)){
    $active_complaints[] = $row;
}

// Count total active complaints for pagination
$sql_count = "SELECT COUNT(*) as total FROM complaints $where_clause_active";
$result_count = mysqli_query($conn, $sql_count);
$total_active = ($row = mysqli_fetch_assoc($result_count)) ? $row['total'] : 0;
$total_pages = ceil($total_active / $per_page);

// Fetch archived complaints (all treated) with lodger name
$sql_archived = "SELECT c.*, u.full_name as lodged_by_name 
                FROM complaints c 
                LEFT JOIN users u ON c.lodged_by = u.user_id 
                $where_clause_archived 
                ORDER BY c.created_at DESC";
$result_archived = mysqli_query($conn, $sql_archived);
$archived_complaints = [];
while($row = mysqli_fetch_assoc($result_archived)){
    $archived_complaints[] = $row;
}

// Helper function to get image path
function getImagePath($path) {
    if (strpos($path, 'http') === 0) {
        return $path; // Already a full URL
    } else {
        return "serve_image.php?path=" . urlencode($path);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Admin Dashboard - <?php echo htmlspecialchars($app_name); ?></title>
    
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
    <link rel="stylesheet" href="css/navbar.css">
    <script src="js/session-timeout.js"></script>
    
    <style>
        .app-logo {
            height: 30px;
            margin-right: 10px;
            object-fit: contain;
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
    </style>
</head>
<body>
    <?php 
    // Set page variables for dashboard header
    $page_title = 'Payment Admin Dashboard';
    $page_subtitle = 'Manage payment-related complaints and issues';
    $page_icon = 'fas fa-credit-card';
    $breadcrumb_items = [
        ['title' => 'Payment Management', 'url' => '#']
    ];
    
    include 'includes/navbar.php'; 
    include 'includes/dashboard_header.php';
    ?>
    <div class="container main-content">

        <?php renderNotifPrefsCard($notif_prefs, 6, 'paymentNotifPrefs'); ?>

        <!-- Forwarded ICT Complaints -->
        <?php
        // Fetch ICT complaints forwarded to Payment Admin role (role-based, not user-specific)
        $fwd_ict = [];
        $fwd_col = mysqli_query($conn, "SHOW COLUMNS FROM student_ict_complaints LIKE 'forwarded_to'");
        if ($fwd_col && mysqli_num_rows($fwd_col) > 0) {
            $fwd_sql = "SELECT c.*, CONCAT(s.first_name,' ',s.last_name) AS student_name,
                               s.registration_number, s.email
                        FROM student_ict_complaints c
                        JOIN students s ON c.student_id = s.student_id
                        WHERE c.forwarded_to = 'payment'
                        ORDER BY c.created_at DESC";
            $fr = mysqli_query($conn, $fwd_sql);
            if ($fr) $fwd_ict = mysqli_fetch_all($fr, MYSQLI_ASSOC);
        }
        ?>
        <?php if (!empty($fwd_ict)): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header" style="background:#fff3cd;border-bottom:none">
                <h5 class="mb-0" style="color:#856404!important">
                    <i class="fas fa-share-square mr-2"></i>
                    ICT Complaints Forwarded to You
                    <span class="badge badge-warning ml-2" style="background-color:#856404;color:#fff"><?php echo count($fwd_ict); ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Category / Issue</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fwd_ict as $fi):
                            $fsc = ['Pending'=>'warning','Under Review'=>'info','Resolved'=>'success','Rejected'=>'danger','Auto-Resolved'=>'secondary'];
                            $fbc = $fsc[$fi['status']] ?? 'secondary';
                        ?>
                            <tr>
                                <td><?php echo $fi['complaint_id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($fi['student_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($fi['registration_number']); ?></small>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($fi['category']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($fi['node_label']); ?></small>
                                </td>
                                <td><span class="badge badge-<?php echo $fbc; ?>"><?php echo htmlspecialchars($fi['status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($fi['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary btn-view-respond-fwd"
                                            data-id="<?php echo $fi['complaint_id']; ?>"
                                            data-label="<?php echo htmlspecialchars($fi['node_label'], ENT_QUOTES); ?>"
                                            data-category="<?php echo htmlspecialchars($fi['category'], ENT_QUOTES); ?>"
                                            data-status="<?php echo htmlspecialchars($fi['status'], ENT_QUOTES); ?>"
                                            data-student="<?php echo htmlspecialchars($fi['student_name'], ENT_QUOTES); ?>"
                                            data-reg="<?php echo htmlspecialchars($fi['registration_number'], ENT_QUOTES); ?>"
                                            data-email="<?php echo htmlspecialchars($fi['email'], ENT_QUOTES); ?>"
                                            data-date="<?php echo date('M d, Y', strtotime($fi['created_at'])); ?>"
                                            data-path="<?php echo htmlspecialchars($fi['path_summary'] ?? '', ENT_QUOTES); ?>"
                                            data-desc="<?php echo htmlspecialchars($fi['description'] ?? '', ENT_QUOTES); ?>"
                                            data-attachment="<?php echo htmlspecialchars($fi['attachment_path'] ?? '', ENT_QUOTES); ?>"
                                            data-extra="<?php echo htmlspecialchars($fi['extra_fields'] ?? '{}', ENT_QUOTES); ?>"
                                            data-response="<?php echo htmlspecialchars($fi['admin_response'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-eye mr-1"></i>View & Respond
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Forwarded Student Result Verification Complaints -->
        <?php
        $fwd_student = [];
        $fwd_sc_col = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'forwarded_to'");
        if ($fwd_sc_col && mysqli_num_rows($fwd_sc_col) > 0) {
            // student_complaints.forwarded_to is INT (user_id FK)
            // Show all complaints forwarded to any Payment Admin (role_id=6)
            $fwd_sc_sql = "SELECT sc.*, CONCAT(s.first_name,' ',s.last_name) AS student_name,
                                  s.registration_number, s.email
                           FROM student_complaints sc
                           JOIN students s ON sc.student_id = s.student_id
                           JOIN users u ON u.user_id = sc.forwarded_to AND u.role_id = 6
                           ORDER BY sc.created_at DESC";
            $fsc_r = mysqli_query($conn, $fwd_sc_sql);
            if ($fsc_r) $fwd_student = mysqli_fetch_all($fsc_r, MYSQLI_ASSOC);
        }
        ?>
        <?php if (!empty($fwd_student)): ?>
        <div class="card mb-4 border-info">
            <div class="card-header" style="background:#d1ecf1;border-bottom:none">
                <h5 class="mb-0" style="color:#0c5460!important">
                    <i class="fas fa-graduation-cap mr-2"></i>
                    Student Complaints Forwarded to You
                    <span class="badge badge-info ml-2" style="background-color:#0c5460;color:#fff"><?php echo count($fwd_student); ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fwd_student as $fsc): ?>
                            <tr>
                                <td><?php echo $fsc['complaint_id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($fsc['student_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($fsc['registration_number']); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($fsc['course_code']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($fsc['course_title']); ?></small>
                                </td>
                                <td><span class="badge badge-secondary"><?php echo htmlspecialchars($fsc['complaint_type']); ?></span></td>
                                <td>
                                    <?php
                                    $sc_colors = ['Pending'=>'warning','Under Review'=>'info','Resolved'=>'success','Rejected'=>'danger'];
                                    $sc_bc = $sc_colors[$fsc['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge badge-<?php echo $sc_bc; ?>"><?php echo htmlspecialchars($fsc['status']); ?></span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($fsc['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info"
                                            data-toggle="modal"
                                            data-target="#fwdScModal<?php echo $fsc['complaint_id']; ?>">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <!-- View modal -->
                            <div class="modal fade" id="fwdScModal<?php echo $fsc['complaint_id']; ?>" tabindex="-1">
                                <div class="modal-dialog"><div class="modal-content">
                                    <div class="modal-header" style="background:#d1ecf1;color:#0c5460">
                                        <h5 class="modal-title"><i class="fas fa-graduation-cap mr-2"></i>Student Complaint #<?php echo $fsc['complaint_id']; ?></h5>
                                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    </div>
                                    <div class="modal-body">
                                        <p><strong>Student:</strong> <?php echo htmlspecialchars($fsc['student_name']); ?> (<?php echo htmlspecialchars($fsc['registration_number']); ?>)</p>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($fsc['email']); ?></p>
                                        <p><strong>Course:</strong> <?php echo htmlspecialchars($fsc['course_code'] . ' — ' . $fsc['course_title']); ?></p>
                                        <p><strong>Type:</strong> <?php echo htmlspecialchars($fsc['complaint_type']); ?></p>
                                        <p><strong>Status:</strong> <span class="badge badge-<?php echo $sc_bc; ?>"><?php echo htmlspecialchars($fsc['status']); ?></span></p>
                                        <p><strong>Submitted:</strong> <?php echo date('M d, Y H:i', strtotime($fsc['created_at'])); ?></p>
                                        <?php if (!empty($fsc['description'])): ?>
                                            <hr><p><strong>Description:</strong></p>
                                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($fsc['description'])); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($fsc['admin_response'])): ?>
                                            <hr><div class="alert alert-success"><strong>Admin Response:</strong><br><?php echo nl2br(htmlspecialchars($fsc['admin_response'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                    </div>
                                </div></div>
                            </div>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Search & Filter Payment Complaints</h4>
            </div>
            <div class="card-body">
                <form method="get" class="form-inline mb-3">
                    <div class="form-group mr-2">
                        <input type="text" name="search_id" class="form-control" placeholder="Student ID" value="<?php echo htmlspecialchars($search_id ?? ''); ?>">
                    </div>
                    <div class="form-group mr-2">
                        <select name="filter_status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="Pending" <?php if($filter_status=='Pending') echo 'selected'; ?>>Pending</option>
                            <option value="Treated" <?php if($filter_status=='Treated') echo 'selected'; ?>>Treated</option>
                            <option value="Needs More Info" <?php if($filter_status=='Needs More Info') echo 'selected'; ?>>Needs More Info</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-info">Search/Filter</button>
                </form>
                
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">Complaints List</h5>
                            </div>
                            <div class="card-body">
                                <ul class="nav nav-tabs mb-3" id="complaintTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="active-tab" data-toggle="tab" href="#active" role="tab">Active Complaints</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="archive-tab" data-toggle="tab" href="#archive" role="tab">Archive</a>
                                    </li>
                                </ul>
                                <div class="tab-content" id="complaintTabsContent">
                                    <div class="tab-pane fade show active" id="active" role="tabpanel">
                                        <?php if (empty($active_complaints)): ?>
                                            <div class="alert alert-info">
                                                No active payment-related complaints found.
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Student ID</th>
                                                            <th>Department</th>
                                                            <th>Staff Name</th>
                                                            <th>Lodged By</th>
                                                            <th style="width: 30%;">Complaint</th>
                                                            <th>Status</th>
                                                            <th>Date Lodged</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach($active_complaints as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($row['department_name'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($row['staff_name'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($row['lodged_by_name'] ?? ''); ?></td>
                                                            <td style="width: 30%;"><?php echo htmlspecialchars($row['complaint_text'] ?? ''); ?>
<?php 
    if (!empty($row['image_path']) && $row['image_path'] !== '0'): 
        $images = array_filter(explode(",", $row['image_path']));
        $images = array_map('trim', $images);
        $images = array_filter($images); // Remove empty values
        $img_count = count($images);
        if ($img_count > 0):
?>
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
<?php 
        endif;
    endif; 
?>
</td>
                                                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                                            <td>
                                                                <button class="btn btn-sm btn-primary btn-view-pay-complaint"
                                                                        data-id="<?php echo $row['complaint_id']; ?>"
                                                                        data-student="<?php echo htmlspecialchars($row['student_id'] ?? '', ENT_QUOTES); ?>"
                                                                        data-dept="<?php echo htmlspecialchars($row['department_name'] ?? '', ENT_QUOTES); ?>"
                                                                        data-staff="<?php echo htmlspecialchars($row['staff_name'] ?? '', ENT_QUOTES); ?>"
                                                                        data-lodgedby="<?php echo htmlspecialchars($row['lodged_by_name'] ?? '', ENT_QUOTES); ?>"
                                                                        data-text="<?php echo htmlspecialchars($row['complaint_text'] ?? '', ENT_QUOTES); ?>"
                                                                        data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES); ?>"
                                                                        data-date="<?php echo date('Y-m-d', strtotime($row['created_at'])); ?>"
                                                                        data-feedback="<?php echo htmlspecialchars($row['feedback'] ?? '', ENT_QUOTES); ?>"
                                                                        data-images="<?php echo htmlspecialchars($row['image_path'] ?? '', ENT_QUOTES); ?>">
                                                                    <i class="fas fa-eye mr-1"></i>View & Treat
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <!-- Pagination -->
                                            <?php if ($total_pages > 1): ?>
                                            <nav aria-label="Page navigation">
                                                <ul class="pagination">
                                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                                        <li class="page-item <?php if($i == $page) echo 'active'; ?>">
                                                            <a class="page-link" href="?page=<?php echo $i; ?>&search_id=<?php echo urlencode($search_id); ?>&filter_status=<?php echo urlencode($filter_status); ?>"><?php echo $i; ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                </ul>
                                            </nav>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tab-pane fade" id="archive" role="tabpanel">
                                        <?php if (empty($archived_complaints)): ?>
                                            <div class="alert alert-info">
                                                No archived payment-related complaints found.
                                            </div>
                                        <?php else: ?>
                                            <h5 class="mb-3 text-success">Treated Payment Complaints</h5>
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>ID</th>
                                                            <th>Student ID</th>
                                                            <th>Department</th>
                                                            <th>Staff Name</th>
                                                            <th>Complaint</th>
                                                            <th>Status</th>
                                                            <th>Date Lodged</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach($archived_complaints as $row): ?>
                                                        <tr class="table-success">
                                                            <td><?php echo $row['complaint_id']; ?></td>
                                                            <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['staff_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['complaint_text']); ?></td>
                                                            <td><span class="badge badge-success">Treated</span></td>
                                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                                            <td>
                                                                <a href="view_complaint.php?id=<?php echo $row['complaint_id']; ?>&payment=1" class="btn btn-sm btn-info">View</a>
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
                    </div>

                </div>
            </div>
        </div>
        
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
                        <?php 
                        // Pass the viewing_past_date flag to exclude past dates from the main view
                        echo generateComplaintCalendar($conn, $_SESSION["role_id"], " AND is_payment_related = 1"); 
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="js/clipboard-paste.js"></script>
    
    <!-- Gallery Modal -->
    <div class="modal fade" id="galleryModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Image Gallery</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <img id="mainGalleryImage" class="img-fluid" style="max-height: 400px;" alt="Gallery Image">
                        <div class="mt-2">
                            <span id="galleryImageCount"></span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center mb-3">
                        <button class="btn btn-sm btn-outline-secondary mr-2" onclick="navigateGallery(-1)">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="navigateGallery(1)">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div id="galleryThumbnails" class="d-flex flex-wrap justify-content-center"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Image handling scripts -->
    <script>
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
    
    <!-- Auto-refresh complaints script disabled -->
    <!-- <script src="assets/js/auto_refresh_complaints.js"></script> -->
    <script>
    $(function() {
        var userId = <?php echo $_SESSION["user_id"]; ?>;
        var userRoleId = <?php echo $_SESSION["role_id"]; ?>;
        function renderComplaint(complaint, userId, userRoleId) {
            let imagesHtml = '';
            let imgCount = 0;
            let images = [];
            if (complaint.image_path && complaint.image_path !== '0') {
                images = complaint.image_path.split(',').map(s => s.trim()).filter(Boolean);
                imgCount = images.length;
            }
            if (imgCount > 0) {
                imagesHtml = '<div class="mt-2"><strong>Attached Images:</strong><div class="gallery-container">' + images.slice(0,3).map((img, idx) => {
                    let directPath = 'public_image.php?img=' + encodeURIComponent(img.split('/').pop());
                    let badge = (imgCount > 3 && idx === 2) ? `<div class=\"image-count-badge\">+${imgCount-3}</div>` : '';
                    return `<div class=\"gallery-item\" onclick=\"toggleZoom(this)\"><img src=\"${directPath}\" alt=\"Complaint Image ${idx+1}\" loading=\"lazy\" onerror=\"this.onerror=null; this.parentElement.innerHTML='<div class=\\'image-error\\'>Image not available</div>'\;\">${badge}</div>`;
                }).join('') + '</div>';
                let allImages = images.map(img => 'public_image.php?img=' + encodeURIComponent(img.split('/').pop()));
                let viewAllBtn = imgCount > 3 ? `<button type=\"button\" class=\"btn btn-sm btn-info view-all-btn\" onclick=\"showGalleryModal(${JSON.stringify(allImages)})\"><i class=\"fas fa-images\"></i> View All (${imgCount})</button>` : '';
                imagesHtml += viewAllBtn + '</div>';
            }
            return `<tr class=\"new-complaint\"><td>${complaint.student_id || ''}</td><td>${complaint.department_name || ''}</td><td>${complaint.staff_name || ''}</td><td>${complaint.lodged_by_name || ''}</td><td style=\"width: 30%;\">${complaint.complaint_text || ''}${imagesHtml}</td><td>${complaint.status}</td><td>${complaint.created_at ? complaint.created_at.substr(0,10) : ''}</td><td><a href=\"view_complaint.php?id=${complaint.complaint_id}&payment=1\" class=\"btn btn-sm btn-info\">View/Treat</a></td></tr>`;
        }
        function getLastComplaintId() {
            let first = $('#active table tbody tr').first();
            let idMatch = first.find('a.btn-info').attr('href');
            if (idMatch) {
                let match = idMatch.match(/id=(\d+)/);
                if (match) return parseInt(match[1]);
            }
            return 0;
        }
        // autoRefreshComplaints({
        //     container: '#active table tbody',
        //     afterSelector: 'tr:first',
        //     getLastId: getLastComplaintId,
        //     renderComplaint: renderComplaint,
        //     userId: userId,
        //     userRoleId: userRoleId
        // });
    });
    </script>
    
    <!-- End of page scripts -->

    <!-- View & Respond Modal for Forwarded ICT Complaints -->
    <div class="modal fade" id="viewRespondFwdModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#856404,#b8860b);color:#fff">
                    <h5 class="modal-title" id="vrfModalTitle"><i class="fas fa-credit-card mr-2"></i>ICT Complaint Details</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div id="vrfDetailsBody" class="mb-4"></div>
                    <hr>
                    <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-reply mr-2"></i>Respond to this Complaint</h6>
                    <div class="form-group">
                        <label class="font-weight-bold">Status</label>
                        <select id="vrfStatus" class="form-control">
                            <option value="Under Review">Under Review</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Response / Feedback</label>
                        <textarea id="vrfText" class="form-control manual-clipboard-init" rows="4"
                                  placeholder="Type your response here. Paste screenshots with Ctrl+V."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Attach Images <span class="text-muted font-weight-normal">(optional)</span></label>
                        <input type="file" id="vrfImages" class="form-control-file" accept="image/*" multiple>
                        <div id="vrfImgPreview" class="d-flex flex-wrap mt-2"></div>
                    </div>
                    <input type="hidden" id="vrfComplaintId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="vrfSubmitBtn">
                        <i class="fas fa-paper-plane mr-1"></i>Send Response
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View & Treat Modal for Payment Complaints -->
    <div class="modal fade" id="viewPayComplaintModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff">
                    <h5 class="modal-title" id="vpcModalTitle"><i class="fas fa-credit-card mr-2"></i>Payment Complaint</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div id="vpcDetailsBody" class="mb-4"></div>
                    <div id="vpcRespondSection">
                        <hr>
                        <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-reply mr-2"></i>Treat this Complaint</h6>
                        <form method="post" id="vpcForm" enctype="multipart/form-data">
                            <input type="hidden" name="complaint_id" id="vpcComplaintId">
                            <div class="form-group">
                                <label class="font-weight-bold">Update Status</label>
                                <select name="status" id="vpcStatus" class="form-control" required>
                                    <option value="Pending">Pending</option>
                                    <option value="Treated">Treated</option>
                                    <option value="Needs More Info">Needs More Info</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold">Feedback / Response</label>
                                <textarea name="feedback" id="vpcFeedback" class="form-control manual-clipboard-init" rows="4"
                                          placeholder="Your feedback to the complaint lodger. Paste screenshots with Ctrl+V."></textarea>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold">Attach Images <span class="text-muted font-weight-normal">(optional)</span></label>
                                <input type="file" name="feedback_images[]" id="vpcImages" class="form-control-file" accept="image/*" multiple>
                                <div id="vpcImgPreview" class="d-flex flex-wrap mt-2"></div>
                            </div>
                            <div class="modal-footer px-0 pb-0">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                <button type="submit" name="treat_payment_complaint" class="btn btn-success">
                                    <i class="fas fa-check mr-1"></i>Submit Treatment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Respond to Forwarded ICT Complaint Modal (legacy — kept for backward compat) -->
    <div class="modal fade" id="respondIctModalPay" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-reply mr-2"></i>Respond to Complaint</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="respondIctMetaPay"></p>
                    <input type="hidden" id="respondIctIdPay">
                    <div class="form-group">
                        <label class="font-weight-bold">Status</label>
                        <select id="respondIctStatusPay" class="form-control">
                            <option value="Under Review">Under Review</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Response / Feedback</label>
                        <textarea id="respondIctTextPay" class="form-control manual-clipboard-init" rows="4"
                                  placeholder="Type your response here. You can also paste screenshots with Ctrl+V."></textarea>
                        <small class="text-muted"><i class="fas fa-info-circle mr-1"></i>Tip: Paste screenshots directly with Ctrl+V while typing</small>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Attach Images <span class="text-muted font-weight-normal">(optional)</span></label>
                        <input type="file" id="respondIctImagesPay" name="response_images[]"
                               class="form-control-file" accept="image/*" multiple>
                        <small class="text-muted">JPG, PNG, GIF — max 5MB each</small>
                    </div>
                    <div id="respondIctPreviewPay" class="d-flex flex-wrap mt-2"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="respondIctSubmitPay">
                        <i class="fas fa-paper-plane mr-1"></i>Send Response
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ── View & Respond: Forwarded ICT complaints ─────────
    $(document).on('click', '.btn-view-respond-fwd', function() {
        const d = $(this).data();
        const sc = {'Pending':'warning','Under Review':'info','Resolved':'success','Rejected':'danger','Auto-Resolved':'secondary'};
        const bc = sc[d.status] || 'secondary';
        const respHtml = d.response
            ? `<div class="alert alert-success mt-3"><strong>Current Response:</strong><br>${esc(d.response).replace(/\n/g,'<br>')}</div>`
            : '';
        const attachmentHtml = d.attachment
            ? `<hr><h6 class="text-muted text-uppercase" style="font-size:.72rem">Attachment</h6><p><a href="${esc(d.attachment)}" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-file-download mr-1"></i> View Attached File</a></p>`
            : '';
        const body = `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase" style="font-size:.72rem">Student</h6>
                    <p class="mb-1"><strong>${esc(d.student)}</strong></p>
                    <p class="mb-1 text-muted small">${esc(d.reg)}</p>
                    <p class="mb-1 text-muted small">${esc(d.email)}</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase" style="font-size:.72rem">Complaint</h6>
                    <p class="mb-1"><strong>${esc(d.category)}</strong></p>
                    <p class="mb-1">${esc(d.label)}</p>
                    <p class="mb-1"><span class="badge badge-${bc}">${esc(d.status)}</span></p>
                    <p class="mb-1 text-muted small">${esc(d.date)}</p>
                </div>
            </div>
            ${d.path ? `<hr><h6 class="text-muted text-uppercase" style="font-size:.72rem">Decision Path</h6><p class="text-muted small">${esc(d.path)}</p>` : ''}
            ${d.desc ? `<hr><h6 class="text-muted text-uppercase" style="font-size:.72rem">Additional Details from Student</h6><div class="p-3 bg-light rounded" style="white-space:pre-wrap;word-break:break-word">${esc(d.desc)}</div>` : ''}
            ${attachmentHtml}
            ${(() => {
                let extraHtml = '';
                try {
                    const ef = JSON.parse(d.extra || '{}');
                    const filtered = Object.entries(ef).filter(([k,v]) => v !== '' && v !== null && k !== 'ai_category' && k !== 'jamb_login_password');
                    if (filtered.length) {
                        extraHtml = '<hr><h6 class="text-muted text-uppercase" style="font-size:.72rem">Information Provided by Student</h6><table class="table table-sm table-bordered mt-2">';
                        filtered.forEach(([k,v]) => {
                            const label = k.replace(/_/g,' ').replace(/\\b\\w/g, l => l.toUpperCase());
                            extraHtml += '<tr><td class="font-weight-bold bg-light" style="width:40%">' + esc(label) + '</td><td>' + esc(String(v)) + '</td></tr>';
                        });
                        extraHtml += '</table>';
                    }
                } catch(e) {}
                return extraHtml;
            })()}
            ${respHtml}`;
        $('#vrfModalTitle').html('<i class="fas fa-credit-card mr-2"></i>ICT Complaint #' + d.id + ' — ' + esc(d.label));
        $('#vrfDetailsBody').html(body);
        $('#vrfComplaintId').val(d.id);
        $('#vrfStatus').val(d.status === 'Pending' ? 'Under Review' : d.status);
        $('#vrfText').val('');
        $('#vrfImages').val('');
        $('#vrfImgPreview').empty();
        const resolved = ['Resolved','Rejected','Auto-Resolved'];
        // Show respond section only if not resolved
        $('#viewRespondFwdModal .modal-footer #vrfSubmitBtn').toggle(!resolved.includes(d.status));
        $('#viewRespondFwdModal').modal('show');
        $('#viewRespondFwdModal').one('shown.bs.modal', function() {
            const ta = document.getElementById('vrfText');
            const fi = document.getElementById('vrfImages');
            if (ta && fi && typeof initializeClipboardPaste === 'function') initializeClipboardPaste(ta, fi);
        });
    });

    $('#vrfImages').on('change', function() {
        const p = $('#vrfImgPreview'); p.empty();
        Array.from(this.files).forEach(f => {
            const r = new FileReader();
            r.onload = e => p.append(`<div class="mr-2 mb-2"><img src="${e.target.result}" style="max-height:80px;max-width:100px;border-radius:4px;border:1px solid #dee2e6"></div>`);
            r.readAsDataURL(f);
        });
    });

    $('#vrfSubmitBtn').on('click', function() {
        const id     = $('#vrfComplaintId').val();
        const status = $('#vrfStatus').val();
        const text   = $('#vrfText').val().trim();
        const btn    = $(this);
        if (!text && !$('#vrfImages')[0].files.length) {
            alert('Please enter a response or attach an image.');
            return;
        }
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Sending...');
        const fd = new FormData();
        fd.append('complaint_id', id);
        fd.append('status', status);
        fd.append('response', text);
        Array.from($('#vrfImages')[0].files).forEach(f => fd.append('response_images[]', f));
        $.ajax({
            url: 'api/ict_complaint_respond.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    $('#viewRespondFwdModal').modal('hide');
                    location.reload();
                } else {
                    alert(res.message || 'Failed to send response.');
                    btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-1"></i>Send Response');
                }
            },
            error: function() {
                alert('Network error. Please try again.');
                btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-1"></i>Send Response');
            }
        });
    });

    // ── View & Treat: Payment complaints ─────────────────
    $(document).on('click', '.btn-view-pay-complaint', function() {
        const d = $(this).data();
        const sc = {'Pending':'warning','Treated':'success','Needs More Info':'info'};
        const bc = sc[d.status] || 'secondary';
        const feedbackHtml = d.feedback
            ? `<div class="alert alert-success mt-3"><strong>Previous Feedback:</strong><br>${esc(d.feedback).replace(/\n/g,'<br>')}</div>`
            : '';

        // Build images HTML
        let imagesHtml = '';
        if (d.images && d.images !== '0' && d.images !== '') {
            const imgs = d.images.split(',').map(s => s.trim()).filter(Boolean);
            if (imgs.length > 0) {
                const thumbs = imgs.map(img => {
                    const url = 'public_image.php?img=' + encodeURIComponent(img.split('/').pop());
                    return `<a href="${url}" target="_blank" class="mr-2 mb-2 d-inline-block">
                        <img src="${url}" style="max-height:80px;max-width:100px;border-radius:4px;border:1px solid #dee2e6;object-fit:cover"
                             onerror="this.style.display='none'">
                    </a>`;
                }).join('');
                imagesHtml = `<hr><h6 class="text-muted text-uppercase" style="font-size:.72rem">Attached Images</h6>
                    <div class="d-flex flex-wrap">${thumbs}</div>`;
            }
        }

        const body = `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase" style="font-size:.72rem">Complaint Info</h6>
                    <p class="mb-1"><strong>Student ID:</strong> ${esc(d.student)}</p>
                    <p class="mb-1"><strong>Department:</strong> ${esc(d.dept || 'N/A')}</p>
                    <p class="mb-1"><strong>Staff:</strong> ${esc(d.staff || 'N/A')}</p>
                    <p class="mb-1"><strong>Lodged By:</strong> ${esc(d.lodgedby || 'N/A')}</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase" style="font-size:.72rem">Status</h6>
                    <p class="mb-1"><span class="badge badge-${bc}">${esc(d.status)}</span></p>
                    <p class="mb-1 text-muted small">${esc(d.date)}</p>
                </div>
            </div>
            <hr>
            <h6 class="text-muted text-uppercase" style="font-size:.72rem">Complaint Text</h6>
            <div class="p-3 bg-light rounded" style="white-space:pre-wrap;word-break:break-word">${esc(d.text)}</div>
            ${imagesHtml}
            ${feedbackHtml}`;
        $('#vpcModalTitle').html('<i class="fas fa-credit-card mr-2"></i>Payment Complaint #' + d.id);
        $('#vpcDetailsBody').html(body);
        $('#vpcComplaintId').val(d.id);
        $('#vpcStatus').val(d.status);
        $('#vpcFeedback').val('');
        $('#vpcImages').val('');
        $('#vpcImgPreview').empty();
        // Hide respond section if already treated
        $('#vpcRespondSection').toggle(d.status !== 'Treated');
        $('#viewPayComplaintModal').modal('show');
        $('#viewPayComplaintModal').one('shown.bs.modal', function() {
            const ta = document.getElementById('vpcFeedback');
            const fi = document.getElementById('vpcImages');
            if (ta && fi && typeof initializeClipboardPaste === 'function') initializeClipboardPaste(ta, fi);
        });
    });

    $('#vpcImages').on('change', function() {
        const p = $('#vpcImgPreview'); p.empty();
        Array.from(this.files).forEach(f => {
            const r = new FileReader();
            r.onload = e => p.append(`<div class="mr-2 mb-2"><img src="${e.target.result}" style="max-height:80px;max-width:100px;border-radius:4px;border:1px solid #dee2e6"></div>`);
            r.readAsDataURL(f);
        });
    });

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str || '');
        return d.innerHTML;
    }

    $(document).on('click', '.btn-respond-ict-pay', function() {        const id    = $(this).data('id');
        const label = $(this).data('label');
        $('#respondIctIdPay').val(id);
        $('#respondIctMetaPay').html('<strong>Complaint #' + id + ':</strong> ' + $('<div>').text(label).html());
        $('#respondIctTextPay').val('');
        $('#respondIctStatusPay').val('Under Review');
        $('#respondIctPreviewPay').empty();
        $('#respondIctImagesPay').val('');
        $('#respondIctModalPay').modal('show');

        $('#respondIctModalPay').one('shown.bs.modal', function() {
            const ta = document.getElementById('respondIctTextPay');
            const fi = document.getElementById('respondIctImagesPay');
            if (ta && fi && typeof initializeClipboardPaste === 'function') {
                initializeClipboardPaste(ta, fi);
            }
        });
    });

    $('#respondIctImagesPay').on('change', function() {
        const preview = $('#respondIctPreviewPay');
        preview.empty();
        Array.from(this.files).forEach(file => {
            const reader = new FileReader();
            reader.onload = e => {
                preview.append(`<div class="mr-2 mb-2"><img src="${e.target.result}" style="max-height:80px;max-width:100px;border-radius:4px;border:1px solid #dee2e6"></div>`);
            };
            reader.readAsDataURL(file);
        });
    });

    $('#respondIctSubmitPay').click(function() {
        const id       = $('#respondIctIdPay').val();
        const response = $('#respondIctTextPay').val().trim();
        const status   = $('#respondIctStatusPay').val();
        const btn      = $(this);

        if (!response) { alert('Please enter a response.'); return; }

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Sending…');

        const fd = new FormData();
        fd.append('complaint_id', id);
        fd.append('response', response);
        fd.append('status', status);
        Array.from(document.getElementById('respondIctImagesPay').files).forEach(f => fd.append('response_images[]', f));

        $.ajax({
            url: 'api/ict_complaint_respond.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    $('#respondIctModalPay').modal('hide');
                    location.reload();
                } else {
                    alert('Error: ' + (res.message || 'Failed'));
                    btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-1"></i>Send Response');
                }
            },
            error: function() {
                alert('Request failed.');
                btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-1"></i>Send Response');
            }
        });
    });
    </script>
</body>
</html>

<?php
?>