<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Only allow departments (role_id = 7)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 7){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";
require_once "includes/notification_prefs.php";
require_once "calendar_helper.php";

// Load this department's notification preferences
ensureNotifPrefsTable($conn);
$dept_notif_prefs = getUserNotifPrefs($conn, $_SESSION['user_id']);

// Initialize notification count
$notification_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
}

// Ensure forwarded_to column exists before any query uses it (MySQL 5.x compatible)
$_col = mysqli_query($conn, "SHOW COLUMNS FROM student_ict_complaints LIKE 'forwarded_to'");
if ($_col && mysqli_num_rows($_col) === 0) {
    mysqli_query($conn, "ALTER TABLE student_ict_complaints ADD COLUMN forwarded_to VARCHAR(255) NULL DEFAULT NULL");
}

$success_message = "";
$error_message = "";

// Handle complaint submission
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["submit_complaint"])){
    $complaint_text = trim($_POST["complaint_text"]);
    $is_urgent = isset($_POST["is_urgent"]) ? 1 : 0;
    $is_payment_related = 0; // Departments don't handle payment issues
    
    if(!empty($complaint_text)){
        // ── Duplicate check: same department, same text, within 24 hours, still open ──
        $dup_check = mysqli_prepare($conn,
            "SELECT complaint_id, status FROM complaints
             WHERE lodged_by = ? AND complaint_text = ?
               AND status NOT IN ('Treated')
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             LIMIT 1");
        if ($dup_check) {
            mysqli_stmt_bind_param($dup_check, 'is', $_SESSION['user_id'], $complaint_text);
            mysqli_stmt_execute($dup_check);
            $dup_row = mysqli_fetch_assoc(mysqli_stmt_get_result($dup_check));
            mysqli_stmt_close($dup_check);
            if ($dup_row) {
                $error_message = "You have already submitted this complaint (ID: #" . $dup_row['complaint_id'] . ", Status: " . $dup_row['status'] . "). Please await a response before submitting again.";
                goto skip_complaint_insert;
            }
        }
        // Handle image uploads
        $image_paths = array();
        if(isset($_FILES["images"]) && !empty($_FILES["images"]["name"][0])){
            $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
            $target_dir = "uploads/";
            
            // Create uploads directory if it doesn't exist
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            
            // Loop through each uploaded file
            foreach($_FILES["images"]["tmp_name"] as $key => $tmp_name){
                if($_FILES["images"]["error"][$key] == 0){
                    $filename = $_FILES["images"]["name"][$key];
                    $filetype = $_FILES["images"]["type"][$key];
                    $filesize = $_FILES["images"]["size"][$key];
                    
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
                            chmod($target_file, 0644);
                            $image_paths[] = $new_filename;
                        }
                    }
                }
            }
        }
        
        $images_str = !empty($image_paths) ? implode(",", $image_paths) : null;
        
        // Insert complaint with department information
        $sql = "INSERT INTO complaints (student_id, complaint_text, image_path, lodged_by, is_urgent, is_payment_related, department_name, staff_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            // For departments, use department name as student_id and set department info
            $department_identifier = "DEPT_" . $_SESSION["username"];
            $department_name = $_SESSION["full_name"];
            $staff_name = "Department Staff";
            
            mysqli_stmt_bind_param($stmt, "sssiisss", 
                $department_identifier, 
                $complaint_text, 
                $images_str, 
                $_SESSION["user_id"], 
                $is_urgent, 
                $is_payment_related,
                $department_name,
                $staff_name
            );
            
            if(mysqli_stmt_execute($stmt)){
                $complaint_id = mysqli_insert_id($conn);
                $success_message = "Complaint submitted successfully! Complaint ID: #$complaint_id";
                
                // Create notification for admins
                $notification_title = "New Department Complaint";
                $notification_message = "New complaint from " . $department_name . " (ID: #$complaint_id)";
                
                // Notify all admins
                $admin_sql = "SELECT user_id FROM users WHERE role_id = 1";
                $admin_result = mysqli_query($conn, $admin_sql);
                while($admin = mysqli_fetch_assoc($admin_result)){
                    createNotification($conn, $admin['user_id'], $complaint_id, 'new_complaint', $notification_title, $notification_message);
                }
                
                // Departments don't create payment-related complaints
                
            } else {
                $error_message = "Error submitting complaint. Please try again.";
            }
            
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Database error. Please try again later.";
        }
    } else {
        $error_message = "Please enter complaint details.";
    }
    skip_complaint_insert:
}

// Get department's complaints
$complaints = array();
$sql = "SELECT c.*, u.full_name as handler_name 
        FROM complaints c 
        LEFT JOIN users u ON c.handled_by = u.user_id 
        WHERE c.lodged_by = ? 
        ORDER BY c.created_at DESC";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $complaints = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

// Get statistics
$total_complaints = count($complaints);
$pending_complaints = count(array_filter($complaints, function($c) { return $c['status'] != 'Treated'; }));
$treated_complaints = count(array_filter($complaints, function($c) { return $c['status'] == 'Treated'; }));

// --- Fetch forwarded cases from ICT ---
// forwarded_to stores the department's full_name (VARCHAR), not user_id
$forwarded_cases = [];
$dept_full_name = $_SESSION['full_name'] ?? '';
if (!empty($dept_full_name)) {
    $fw_sql = "SELECT c.*, CONCAT(s.first_name, ' ', s.last_name) as student_name,
                       s.registration_number, s.email,
                       sd.department_name, f.faculty_name, p.programme_name
               FROM student_ict_complaints c
               JOIN students s ON c.student_id = s.student_id
               LEFT JOIN student_departments sd ON s.department_id = sd.department_id
               LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
               LEFT JOIN programmes p ON s.programme_id = p.programme_id
               WHERE c.forwarded_to = ? ORDER BY c.created_at DESC";
    if ($fw_stmt = mysqli_prepare($conn, $fw_sql)) {
        mysqli_stmt_bind_param($fw_stmt, "s", $dept_full_name);
        mysqli_stmt_execute($fw_stmt);
        $fw_res = mysqli_stmt_get_result($fw_stmt);
        $forwarded_cases = mysqli_fetch_all($fw_res, MYSQLI_ASSOC);
        mysqli_stmt_close($fw_stmt);
    }
}

// Handle department response to forwarded case
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["reply_forwarded"])) {
    $cid = (int)$_POST["complaint_id"];
    $status = $_POST["status"];
    $response = trim($_POST["dept_response"]);
    
    // Format the response and update the DB
    $formatted_response = "Response from " . $_SESSION['full_name'] . ":\n" . $response;
    
    // Process response image uploads
    $reply_image_paths = array();
    if(isset($_FILES["reply_images"]) && !empty($_FILES["reply_images"]["name"][0])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        foreach($_FILES["reply_images"]["tmp_name"] as $key => $tmp_name){
            if($_FILES["reply_images"]["error"][$key] == 0){
                $filename = $_FILES["reply_images"]["name"][$key];
                $filetype = $_FILES["reply_images"]["type"][$key];
                $filesize = $_FILES["reply_images"]["size"][$key];
                
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if(!array_key_exists($ext, $allowed)) continue;
                
                $maxsize = 5 * 1024 * 1024;
                if($filesize > $maxsize) continue;
                
                if(in_array($filetype, $allowed)){
                    $new_filename = "reply_" . uniqid() . "." . $ext;
                    $target_file = $target_dir . $new_filename;
                    
                    if(move_uploaded_file($tmp_name, $target_file)){
                        chmod($target_file, 0644);
                        $reply_image_paths[] = $target_file;
                    }
                }
            }
        }
    }
    
    // Append attached images to the response
    if (!empty($reply_image_paths)) {
        foreach ($reply_image_paths as $path) {
            $formatted_response .= "\n[Attached Image: " . $path . "]";
        }
    }
    
    $upd = mysqli_prepare($conn, "UPDATE student_ict_complaints SET status=?, admin_response=?, handled_by=?, updated_at=NOW() WHERE complaint_id=? AND forwarded_to=?");
    if($upd) {
        mysqli_stmt_bind_param($upd, "ssiis", $status, $formatted_response, $_SESSION['user_id'], $cid, $dept_full_name);
        if(mysqli_stmt_execute($upd)) {
            $success_message = "Response submitted successfully.";

            // Notify student via in-app + email
            $st_q = mysqli_prepare($conn,
                "SELECT c.student_id, c.node_label, s.email, s.first_name, s.last_name
                 FROM student_ict_complaints c
                 JOIN students s ON c.student_id = s.student_id
                 WHERE c.complaint_id = ?");
            if ($st_q) {
                mysqli_stmt_bind_param($st_q, 'i', $cid);
                mysqli_stmt_execute($st_q);
                $st_row = mysqli_fetch_assoc(mysqli_stmt_get_result($st_q));
                mysqli_stmt_close($st_q);
                if ($st_row) {
                    $sid     = $st_row['student_id'];
                    $topic   = $st_row['node_label'];
                    $s_email = $st_row['email'] ?? '';
                    $s_name  = trim(($st_row['first_name'] ?? '') . ' ' . ($st_row['last_name'] ?? ''));

                    // In-app notification
                    $notif_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'student_notifications'");
                    if ($notif_tbl && mysqli_num_rows($notif_tbl) > 0) {
                        $ns = mysqli_prepare($conn,
                            "INSERT INTO student_notifications (student_id, complaint_id, title, message, created_at)
                             VALUES (?,?,'Response on Your ICT Complaint',?,NOW())");
                        if ($ns) {
                            $nm = "Your complaint regarding \"$topic\" has received a response from the department.";
                            mysqli_stmt_bind_param($ns, 'iis', $sid, $cid, $nm);
                            mysqli_stmt_execute($ns);
                            mysqli_stmt_close($ns);
                        }
                    }

                    // Email notification
                    if (!empty($s_email)) {
                        require_once "includes/logger.php";
                        $email_subject = "Response on Your ICT Complaint #$cid — TSU ICT Help Desk";
                        $email_body    = "Dear $s_name,\n\n"
                                       . "Your ICT complaint regarding \"$topic\" (ID: #$cid) has received a response from the department.\n\n"
                                       . "Status  : $status\n"
                                       . "Response: " . mb_substr($response, 0, 500) . "\n\n"
                                       . "Please log in to your student portal to view the full details:\n"
                                       . "https://helpdesk.tsuniversity.ng/student_dashboard.php\n\n"
                                       . "-- TSU ICT Help Desk";
                        @app_mail($s_email, $email_subject, $email_body);
                    }
                }
            }

            // PRG redirect
            header("Location: department_dashboard.php");
            exit;
        } else {
            $error_message = "Failed to submit response.";
        }
        mysqli_stmt_close($upd);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Dashboard - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/responsive-fix.css">
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        .department-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(30, 60, 114, 0.1);
        }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        .complaint-card {
            border-left: 4px solid #007bff;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-radius: 8px;
        }
        .complaint-card.urgent {
            border-left-color: #dc3545;
        }
        .complaint-card.treated {
            border-left-color: #28a745;
        }
        .department-name {
            font-size: 1.75rem;
            line-height: 1.3;
            word-wrap: break-word;
            hyphens: auto;
            font-weight: 700;
        }
        @media (max-width: 768px) {
            .department-name {
                font-size: 1.35rem;
            }
            .header-actions {
                margin-top: 1rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                justify-content: flex-start !important;
            }
            .header-actions .btn {
                flex: 1 1 auto;
                margin-bottom: 0.5rem;
            }
        }
        
        /* Modal Fix */
        body.modal-open { overflow: auto !important; padding-right: 0 !important; }

        /* Notification settings visibility enhancement */
        #notifPrefsBody {
            background-color: #ffffff;
        }
        #notifPrefsBody .text-muted {
            color: #4a5568 !important; /* Rich slate gray for high contrast visibility */
            font-weight: 500;
        }
        #notifPrefsBody .text-dark {
            color: #1a202c !important; /* Deep dark charcoal for high visibility */
            font-weight: 700;
        }
        #notifPrefsBody .custom-control-label {
            cursor: pointer;
            line-height: 1.4;
            padding-left: 5px;
        }
        #notifPrefsBody .custom-control-input:checked ~ .custom-control-label::before {
            background-color: var(--primary-blue);
            border-color: var(--primary-blue);
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="department-header">
        <div class="container-fluid px-3 px-md-4 px-xl-5">
            <div class="row align-items-center">
                <div class="col-md-7 col-lg-8">
                    <h1 class="mb-2 d-flex align-items-center flex-wrap">
                        <i class="fas fa-building mr-3 mb-2 mb-md-0"></i>
                        <span class="department-name"><?php echo htmlspecialchars($_SESSION["full_name"]); ?></span>
                    </h1>
                    <p class="mb-0 text-white-50">Department Complaint Management System</p>
                </div>
                <div class="col-md-5 col-lg-4 text-left text-md-right header-actions">
                    <div class="btn-group flex-wrap w-100 w-md-auto">
                        <a href="department_complaints.php" class="btn btn-light btn-sm font-weight-bold"><i class="fas fa-list-alt mr-1"></i> Complaints</a>
                        <a href="account.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fas fa-user mr-1"></i> Profile</a>
                        <a href="change_password.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fas fa-key mr-1"></i> Password</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-3 px-md-4 px-xl-5 main-content mb-5">
        <div class="row mb-4">
            <div class="col-12 col-md-4 mb-3 mb-md-0">
                <div class="card stat-card text-center h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="fas fa-clipboard-list fa-2x text-primary mb-3"></i>
                        <h2 class="text-primary font-weight-bold"><?php echo $total_complaints; ?></h2>
                        <p class="text-muted mb-0 font-weight-bold text-uppercase small">Total Complaints</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4 mb-3 mb-md-0">
                <div class="card stat-card text-center h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="fas fa-clock fa-2x text-warning mb-3"></i>
                        <h2 class="text-warning font-weight-bold"><?php echo $pending_complaints; ?></h2>
                        <p class="text-muted mb-0 font-weight-bold text-uppercase small">Pending</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="card stat-card text-center h-100">
                    <div class="card-body d-flex flex-column justify-content-center">
                        <i class="fas fa-check-circle fa-2x text-success mb-3"></i>
                        <h2 class="text-success font-weight-bold"><?php echo $treated_complaints; ?></h2>
                        <p class="text-muted mb-0 font-weight-bold text-uppercase small">Resolved</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header d-flex justify-content-between align-items-center"
                         style="background:linear-gradient(135deg, var(--light-blue) 0%, #f0f8ff 100%); color: var(--primary-blue); cursor:pointer; border-radius: 10px;"
                         data-toggle="collapse" data-target="#notifPrefsBody">
                        <h5 class="mb-0 font-weight-bold" style="color:var(--primary-blue)!important"><i class="fas fa-bell mr-2"></i>Email Notification Preferences</h5>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="collapse" id="notifPrefsBody">
                        <div class="card-body bg-white rounded-bottom">
                            <p class="text-muted small mb-4">
                                Choose which events trigger an email to your account.
                                You will always see in-app notifications regardless of these settings.
                            </p>
                            <form id="notifPrefsForm">
                                <div class="row">
                                    <div class="col-12 col-md-6">
                                        <div class="custom-control custom-switch mb-4">
                                            <input type="checkbox" class="custom-control-input" id="pref_forwarded"
                                                   name="on_forwarded"
                                                   <?php echo $dept_notif_prefs['on_forwarded'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_forwarded">
                                                <strong class="text-dark">Complaint forwarded to me</strong><br>
                                                <small class="text-muted">Email when ICT forwards a student complaint to your department</small>
                                            </label>
                                        </div>
                                        <div class="custom-control custom-switch mb-4 mb-md-3">
                                            <input type="checkbox" class="custom-control-input" id="pref_ict_response"
                                                   name="on_ict_response"
                                                   <?php echo $dept_notif_prefs['on_ict_response'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_ict_response">
                                                <strong class="text-dark">ICT adds a response</strong><br>
                                                <small class="text-muted">Email when ICT adds feedback or a response to a forwarded complaint</small>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="custom-control custom-switch mb-4">
                                            <input type="checkbox" class="custom-control-input" id="pref_status_change"
                                                   name="on_status_change"
                                                   <?php echo $dept_notif_prefs['on_status_change'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_status_change">
                                                <strong class="text-dark">Status change on forwarded complaint</strong><br>
                                                <small class="text-muted">Email when the status of a complaint forwarded to you is updated</small>
                                            </label>
                                        </div>
                                        <div class="custom-control custom-switch mb-3">
                                            <input type="checkbox" class="custom-control-input" id="pref_new_complaint"
                                                   name="on_new_student_complaint"
                                                   <?php echo $dept_notif_prefs['on_new_student_complaint'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_new_complaint">
                                                <strong class="text-dark">All new ICT complaints</strong><br>
                                                <small class="text-muted">Email for every new student ICT complaint submitted (high volume — off by default)</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <hr>
                                <div class="d-flex align-items-center">
                                    <button type="submit" class="btn btn-primary" id="savePrefsBtn">
                                        <i class="fas fa-save mr-1"></i> Save Preferences
                                    </button>
                                    <span id="prefsSaveMsg" class="ml-3 font-weight-bold" style="display:none"></span>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($forwarded_cases)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-info shadow-sm">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center rounded-top">
                        <h5 class="mb-0 font-weight-bold"><i class="fas fa-share-square mr-2"></i>Cases Forwarded From ICT (<?php echo count($forwarded_cases); ?>)</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-mobile-cards mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>#</th>
                                    <th>Student</th>
                                    <th>Category / Issue</th>
                                    <th>Decision Path</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th style="width: 120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($forwarded_cases as $fc):
                                    $sc = ['Pending'=>'warning','Under Review'=>'info','Resolved'=>'success','Rejected'=>'danger','Auto-Resolved'=>'secondary'];
                                    $bc = $sc[$fc['status']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td data-label="#"><strong><?php echo $fc['complaint_id']; ?></strong></td>
                                    <td data-label="Student">
                                        <div class="font-weight-bold text-dark"><?php echo htmlspecialchars($fc['student_name']); ?></div>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($fc['registration_number']); ?></small>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($fc['email'] ?? ''); ?></small>
                                    </td>
                                    <td data-label="Category / Issue">
                                        <div class="font-weight-bold text-primary"><?php echo htmlspecialchars($fc['category']); ?></div>
                                        <span class="badge badge-light border text-muted mt-1" style="white-space: normal; text-align: left;"><?php echo htmlspecialchars($fc['node_label']); ?></span>
                                    </td>
                                    <td data-label="Decision Path">
                                        <small class="text-muted d-block" style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap"
                                               title="<?php echo htmlspecialchars($fc['path_summary']); ?>">
                                            <?php echo htmlspecialchars($fc['path_summary']); ?>
                                        </small>
                                    </td>
                                    <td data-label="Status">
                                        <span class="badge badge-<?php echo $bc; ?> px-2 py-1"><?php echo htmlspecialchars($fc['status']); ?></span>
                                        <?php if (!empty($fc['admin_response'])): ?>
                                            <div class="mt-1"><small class="text-success font-weight-bold"><i class="fas fa-check-circle mr-1"></i>ICT responded</small></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Date" class="text-nowrap">
                                        <i class="far fa-calendar-alt text-muted mr-1"></i> <?php echo date('M d, Y', strtotime($fc['created_at'])); ?>
                                    </td>
                                    <td data-label="Actions" class="action-col">
                                        <div class="d-flex flex-wrap gap-1 justify-content-md-start justify-content-end">
                                            <button type="button" class="btn btn-sm btn-outline-info mr-1 mb-1 btn-view-fw" title="View Details"
                                                    data-id="<?php echo $fc['complaint_id']; ?>"
                                                    data-student="<?php echo htmlspecialchars($fc['student_name'], ENT_QUOTES); ?>"
                                                    data-reg="<?php echo htmlspecialchars($fc['registration_number'], ENT_QUOTES); ?>"
                                                    data-email="<?php echo htmlspecialchars($fc['email'] ?? '', ENT_QUOTES); ?>"
                                                    data-dept="<?php echo htmlspecialchars($fc['department_name'] ?? '', ENT_QUOTES); ?>"
                                                    data-faculty="<?php echo htmlspecialchars($fc['faculty_name'] ?? '', ENT_QUOTES); ?>"
                                                    data-programme="<?php echo htmlspecialchars($fc['programme_name'] ?? '', ENT_QUOTES); ?>"
                                                    data-category="<?php echo htmlspecialchars($fc['category'], ENT_QUOTES); ?>"
                                                    data-label="<?php echo htmlspecialchars($fc['node_label'], ENT_QUOTES); ?>"
                                                    data-path="<?php echo htmlspecialchars($fc['path_summary'], ENT_QUOTES); ?>"
                                                    data-desc="<?php echo htmlspecialchars($fc['description'] ?? '', ENT_QUOTES); ?>"
                                                    data-auto="<?php echo htmlspecialchars($fc['auto_response'] ?? '', ENT_QUOTES); ?>"
                                                    data-attachment="<?php echo htmlspecialchars($fc['attachment_path'] ?? '', ENT_QUOTES); ?>"
                                                    data-ict-response="<?php echo htmlspecialchars($fc['admin_response'] ?? '', ENT_QUOTES); ?>"
                                                    data-status="<?php echo htmlspecialchars($fc['status'], ENT_QUOTES); ?>"
                                                    data-date="<?php echo date('M d, Y H:i', strtotime($fc['created_at'])); ?>"
                                                    data-extra="<?php echo htmlspecialchars($fc['extra_fields'] ?? '{}', ENT_QUOTES); ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-success mb-1 btn-respond-fw" title="Respond"
                                                    data-id="<?php echo $fc['complaint_id']; ?>"
                                                    data-student="<?php echo htmlspecialchars($fc['student_name'], ENT_QUOTES); ?>"
                                                    data-label="<?php echo htmlspecialchars($fc['node_label'], ENT_QUOTES); ?>"
                                                    data-status="<?php echo htmlspecialchars($fc['status'], ENT_QUOTES); ?>">
                                                <i class="fas fa-reply"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-success shadow-sm">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center rounded-top">
                        <h5 class="mb-0 font-weight-bold"><i class="fas fa-share-square mr-2"></i>Cases Forwarded From ICT</h5>
                    </div>
                    <div class="card-body text-center py-5">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h5 class="text-success font-weight-bold">All Caught Up!</h5>
                        <p class="text-muted mb-0">There are no complaints forwarded to your department right now. Great job!</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12 col-lg-6 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h5 class="mb-0 text-primary font-weight-bold"><i class="fas fa-plus-circle mr-2"></i>Submit New Complaint</h5>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($success_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                                <button type="button" class="close" data-dismiss="alert">
                                    <span>&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(!empty($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error_message; ?>
                                <button type="button" class="close" data-dismiss="alert">
                                    <span>&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="department_dashboard.php" enctype="multipart/form-data">
                            <div class="form-group">
                                <label class="font-weight-bold text-muted">Complaint Details</label>
                                <textarea name="complaint_text" class="form-control manual-clipboard-init" rows="5" required placeholder="Describe your complaint in detail... (You can paste images directly with Ctrl+V)"></textarea>
                                <small class="form-text text-muted mt-2">
                                    <i class="fas fa-info-circle text-info"></i> 
                                    <strong>Tip:</strong> You can paste screenshots directly with Ctrl+V while typing, or use the file input below.
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label class="font-weight-bold text-muted">Attach Images (Optional)</label>
                                <div class="custom-file mb-2">
                                    <input type="file" id="complaint_images" name="images[]" class="custom-file-input" accept="image/*" multiple>
                                    <label class="custom-file-label" for="complaint_images">Choose files...</label>
                                </div>
                                <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)</small>
                            </div>
                            
                            <div class="form-group my-4">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="urgentCheck" name="is_urgent">
                                    <label class="custom-control-label font-weight-bold text-danger" for="urgentCheck">
                                        Mark as Urgent Issue
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" name="submit_complaint" class="btn btn-primary btn-block py-2 font-weight-bold">
                                <i class="fas fa-paper-plane mr-2"></i>Submit Complaint
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-primary font-weight-bold"><i class="fas fa-history mr-2"></i>Recent Activity</h5>
                        <span class="badge badge-light border text-muted"><?php echo count($complaints); ?> Total</span>
                    </div>
                    <div class="card-body bg-light" style="max-height: 600px; overflow-y: auto;">
                        <?php if(empty($complaints)): ?>
                            <div class="text-center text-muted py-5">
                                <div class="p-4 bg-white rounded-circle d-inline-block shadow-sm mb-3">
                                    <i class="fas fa-inbox fa-3x text-primary opacity-50"></i>
                                </div>
                                <p class="font-weight-bold mb-1">No complaints submitted yet.</p>
                                <small>Submit your first complaint using the form on the left.</small>
                            </div>
                        <?php else: ?>
                            <?php foreach(array_slice($complaints, 0, 8) as $complaint): ?>
                                <div class="card complaint-card bg-white <?php echo $complaint['is_urgent'] ? 'urgent' : ''; ?> <?php echo $complaint['status'] == 'Treated' ? 'treated' : ''; ?>">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0 font-weight-bold">
                                                <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" class="text-primary text-decoration-none">
                                                    <i class="fas fa-hashtag text-muted mr-1"></i><?php echo $complaint['complaint_id']; ?> - View Details
                                                </a>
                                                <?php if($complaint['is_urgent']): ?>
                                                    <span class="badge badge-danger ml-2 px-2 py-1"><i class="fas fa-bolt mr-1"></i>Urgent</span>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="text-muted"><i class="far fa-clock mr-1"></i><?php echo date('M j, g:i A', strtotime($complaint['created_at'])); ?></small>
                                        </div>
                                        <p class="text-dark mb-3 small bg-light p-2 rounded"><?php echo substr(htmlspecialchars($complaint['complaint_text']), 0, 120) . (strlen($complaint['complaint_text']) > 120 ? '...' : ''); ?></p>
                                        
                                        <?php if(!empty($complaint['feedback'])): ?>
                                            <div class="alert alert-success py-2 px-3 mb-3 border-0">
                                                <div class="d-flex align-items-start">
                                                    <i class="fas fa-comment-dots text-success mr-2 mt-1"></i>
                                                    <div class="flex-grow-1">
                                                        <strong class="small text-success">Admin Feedback:</strong>
                                                        <p class="mb-0 small text-dark mt-1"><?php echo substr(htmlspecialchars($complaint['feedback']), 0, 100) . (strlen($complaint['feedback']) > 100 ? '...' : ''); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-between align-items-center mt-2 border-top pt-2">
                                            <span class="badge badge-<?php echo $complaint['status'] == 'Treated' ? 'success' : ($complaint['status'] == 'In Progress' ? 'warning' : 'secondary'); ?> px-2 py-1">
                                                <i class="fas fa-<?php echo $complaint['status'] == 'Treated' ? 'check-circle' : ($complaint['status'] == 'In Progress' ? 'tools' : 'hourglass-half'); ?> mr-1"></i>
                                                <?php echo $complaint['status']; ?>
                                            </span>
                                            <?php if($complaint['handler_name']): ?>
                                                <small class="text-success font-weight-bold">
                                                    <i class="fas fa-user-check mr-1"></i> <?php echo htmlspecialchars($complaint['handler_name']); ?>
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted font-italic">
                                                    <i class="fas fa-user-clock mr-1"></i> Unassigned
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if(count($complaints) > 8): ?>
                                <div class="text-center mt-3 mb-2">
                                    <a href="department_complaints.php" class="btn btn-sm btn-outline-primary rounded-pill px-4">
                                        View All <?php echo count($complaints); ?> Complaints <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-2">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white">
                        <h5 class="mb-0 text-primary font-weight-bold"><i class="fas fa-calendar-alt mr-2"></i>Complaint Calendar</h5>
                    </div>
                    <div class="card-body p-0 p-md-3">
                        <?php 
                        // Show calendar for department complaints only
                        echo generateComplaintCalendar($conn, $_SESSION["role_id"], " AND lodged_by = " . $_SESSION["user_id"]); 
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="js/clipboard-paste.js"></script>
    
    <div class="modal fade" id="forwardedViewModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-info text-white border-0">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-clipboard-list mr-2"></i>Forwarded Case Details</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4" id="fwViewBody"></div>
                <div class="modal-footer bg-light border-0 rounded-bottom">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success font-weight-bold shadow-sm" id="fwViewToRespond">
                        <i class="fas fa-reply mr-1"></i> Respond to Case
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="forwardedReplyModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white border-0">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-reply-all mr-2"></i>Submit Response</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body p-4">
                        <input type="hidden" name="complaint_id" id="fwReplyId">
                        
                        <div class="bg-light p-3 rounded mb-4 border">
                            <div class="d-flex align-items-start mb-2">
                                <i class="fas fa-user-graduate text-primary mt-1 mr-2"></i>
                                <div>
                                    <span class="text-muted small text-uppercase font-weight-bold d-block">Student</span>
                                    <span id="fwReplyStudent" class="font-weight-bold text-dark"></span>
                                </div>
                            </div>
                            <div class="d-flex align-items-start">
                                <i class="fas fa-exclamation-circle text-warning mt-1 mr-2"></i>
                                <div>
                                    <span class="text-muted small text-uppercase font-weight-bold d-block">Issue Category</span>
                                    <span id="fwReplyLabel" class="text-dark"></span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-primary">Update Status</label>
                            <select name="status" id="fwReplyStatus" class="form-control border-primary shadow-sm" required>
                                <option value="Under Review">Under Review</option>
                                <option value="Resolved">Resolved</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                        
                        <div class="form-group mb-0">
                            <label class="font-weight-bold text-primary">Department Response</label>
                            <textarea name="dept_response" class="form-control border-primary shadow-sm manual-clipboard-init" rows="5" required placeholder="Type the resolution, instructions, or feedback for ICT and the student..."></textarea>
                            <input type="file" id="fw_reply_images" name="reply_images[]" accept="image/*" multiple style="display:none;">
                            <small class="form-text text-muted mt-2"><i class="fas fa-info-circle mr-1"></i> This response will be visible to ICT admins. You can paste screenshots with Ctrl+V.</small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 rounded-bottom">
                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="reply_forwarded" class="btn btn-primary font-weight-bold shadow-sm">
                            <i class="fas fa-paper-plane mr-1"></i> Send Response
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str || '');
        return d.innerHTML;
    }

    function parseResponseImagesJS(text) {
        if (!text) return '';
        let escaped = esc(text);
        const pattern = /\[Attached Image: (uploads\/[a-zA-Z0-9_\/.-]+)\]/g;
        escaped = escaped.replace(pattern, '<div class="mt-2"><img src="$1" class="img-thumbnail" style="max-height: 150px; cursor: pointer; border: 1px solid #dee2e6;" onclick="showImageModal(\'$1\')"></div>');
        return escaped.replace(/\n/g, '<br>');
    }

    function showImageModal(src) {
        $('#modalImage').attr('src', src);
        $('#imageModal').modal('show');
    }

    // Store current view data for "Respond" button inside view modal
    let _currentFwData = {};

    $(document).ready(function() {

        // Custom file input label update
        $('.custom-file-input').on('change', function() {
            let files = $(this)[0].files;
            let label = files.length > 1 ? files.length + ' files selected' : (files.length == 1 ? files[0].name : 'Choose files...');
            $(this).next('.custom-file-label').html(label);
        });

        // ── View button ──────────────────────────────────────
        $(document).on('click', '.btn-view-fw', function() {
            const d = $(this).data();
            _currentFwData = d;

            const statusColors = {
                'Pending':'warning','Under Review':'info','Resolved':'success',
                'Rejected':'danger','Auto-Resolved':'secondary'
            };
            const bc = statusColors[d.status] || 'secondary';

            // Parse extra fields
            let extraHtml = '';
            try {
                const ef = JSON.parse(d.extra || '{}');
                const filtered = Object.entries(ef).filter(([k,v]) => v !== '' && v !== null && k !== 'ai_category');
                if (filtered.length) {
                    extraHtml = '<h6 class="mt-4 font-weight-bold text-primary border-bottom pb-2">Extra Information Provided by Student</h6><div class="table-responsive"><table class="table table-sm table-bordered mt-3">';
                    filtered.forEach(([k,v]) => {
                        const label = k.replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase());
                        extraHtml += `<tr><td class="font-weight-bold bg-light" style="width:40%">${esc(label)}</td><td>${esc(String(v))}</td></tr>`;
                    });
                    extraHtml += '</table></div>';
                }
            } catch(e) {}

            const autoHtml = d.auto
                ? `<div class="alert alert-info mt-4 border-info shadow-sm"><strong class="d-block mb-2 text-info"><i class="fas fa-robot mr-2"></i>Auto-Response Shown to Student:</strong><p class="mb-0 small">${esc(d.auto).replace(/\n/g,'<br>')}</p></div>`
                : '';
            const ictRespHtml = d.ictResponse
                ? `<div class="alert alert-success mt-4 border-success shadow-sm"><strong class="d-block mb-2 text-success"><i class="fas fa-user-shield mr-2"></i>ICT Response:</strong><p class="mb-0 text-dark">${parseResponseImagesJS(d.ictResponse)}</p></div>`
                : '';
            const descHtml = d.desc
                ? `<h6 class="mt-4 font-weight-bold text-primary border-bottom pb-2">Additional Details from Student</h6><p class="text-dark bg-light p-3 rounded mt-2 border">${esc(d.desc).replace(/\n/g,'<br>')}</p>`
                : '';
            const attachmentHtml = d.attachment
                ? `<h6 class="mt-4 font-weight-bold text-primary border-bottom pb-2">Attachment</h6><p class="mt-2"><a href="${esc(d.attachment)}" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-file-download mr-1"></i> View Attached File</a></p>`
                : '';

            const body = `
                <div class="row">
                    <div class="col-md-6 mb-3 mb-md-0">
                        <div class="card h-100 border-0 bg-light">
                            <div class="card-body p-3">
                                <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-graduate mr-2"></i>Student Info</h6>
                                <p class="mb-1 text-muted small text-uppercase">Name</p>
                                <p class="font-weight-bold text-dark mb-2">${esc(d.student)}</p>
                                
                                <p class="mb-1 text-muted small text-uppercase">Registration</p>
                                <p class="font-weight-bold text-dark mb-2"><code>${esc(d.reg)}</code></p>
                                
                                <p class="mb-1 text-muted small text-uppercase">Email</p>
                                <p class="font-weight-bold text-dark mb-2">${esc(d.email)}</p>
                                
                                <p class="mb-1 text-muted small text-uppercase">Programme</p>
                                <p class="font-weight-bold text-dark mb-0">${esc(d.programme || 'N/A')}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-0 bg-light">
                            <div class="card-body p-3">
                                <h6 class="font-weight-bold text-primary mb-3"><i class="fas fa-file-alt mr-2"></i>Complaint Info</h6>
                                <p class="mb-1 text-muted small text-uppercase">Category</p>
                                <p class="font-weight-bold text-dark mb-2">${esc(d.category)}</p>
                                
                                <p class="mb-1 text-muted small text-uppercase">Specific Issue</p>
                                <p class="font-weight-bold text-dark mb-2">${esc(d.label)}</p>
                                
                                <p class="mb-1 text-muted small text-uppercase">Current Status</p>
                                <p class="mb-2"><span class="badge badge-${bc} px-2 py-1">${esc(d.status)}</span></p>
                                
                                <p class="mb-1 text-muted small text-uppercase">Date Submitted</p>
                                <p class="font-weight-bold text-dark mb-0">${esc(d.date)}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <h6 class="mt-4 font-weight-bold text-primary border-bottom pb-2">Decision Path Taken by Student</h6>
                <div class="p-3 bg-light rounded mt-2 border-left border-info" style="border-width: 4px !important;">
                    <p class="text-dark small mb-0 font-weight-bold">${esc(d.path).split(' > ').join(' <i class="fas fa-chevron-right text-muted mx-1"></i> ')}</p>
                </div>
                
                ${descHtml}${attachmentHtml}${autoHtml}${ictRespHtml}${extraHtml}
                <div id="fwRepliesContainer" style="display:none;" class="mt-4"></div>`;

            $('#fwViewBody').html(body);
            $('#forwardedViewModal').modal('show');
        });

        // "Respond" button inside view modal
        $('#fwViewToRespond').click(function() {
            $('#forwardedViewModal').modal('hide');
            setTimeout(function() {
                openRespondModal(_currentFwData);
            }, 300);
        });

        // ── Respond button ───────────────────────────────────
        $(document).on('click', '.btn-respond-fw', function() {
            openRespondModal($(this).data());
        });

        function openRespondModal(d) {
            $('#fwReplyId').val(d.id);
            $('#fwReplyStudent').text(d.student);
            $('#fwReplyLabel').text(d.label);
            $('#fwReplyStatus').val(d.status);
            $('#forwardedReplyModal').modal('show');
        }
        
        // Initialize clipboard paste for complaint textarea
        if (window.clipboardPasteHandler) {
            const complaintTextarea = document.querySelector('textarea[name="complaint_text"]');
            const complaintFileInput = document.getElementById('complaint_images');
            if (complaintTextarea && complaintFileInput && typeof initializeClipboardPaste === 'function') {
                initializeClipboardPaste(complaintTextarea, complaintFileInput);
            }
        }

        // Initialize clipboard paste for forwarded reply response
        if (window.clipboardPasteHandler) {
            const replyTextarea = document.querySelector('textarea[name="dept_response"]');
            const replyFileInput = document.getElementById('fw_reply_images');
            if (replyTextarea && replyFileInput && typeof initializeClipboardPaste === 'function') {
                initializeClipboardPaste(replyTextarea, replyFileInput);
            }
        }
        
        // Auto-dismiss alerts
        $('.alert').delay(5000).fadeOut();

        // ── Notification preferences form ────────────────────
        $('#notifPrefsForm').submit(function(e) {
            e.preventDefault();
            const btn = $('#savePrefsBtn');
            const msg = $('#prefsSaveMsg');
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');
            msg.hide();

            $.post('api/save_notification_prefs.php', $(this).serialize(), function(res) {
                if (res.success) {
                    msg.text('✓ Saved successfully').removeClass('text-danger').addClass('text-success').show();
                } else {
                    msg.text('✗ ' + (res.message || 'Failed to save')).removeClass('text-success').addClass('text-danger').show();
                }
                btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Preferences');
                setTimeout(() => msg.fadeOut(), 4000);
            }, 'json').fail(function() {
                msg.text('✗ Request failed. Try again.').removeClass('text-success').addClass('text-danger').show();
                btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Preferences');
            });
        });

        function loadIctRepliesDept(complaintId) {
            const container = $('#fwRepliesContainer');
            if (container.length === 0) return;
            container.hide().empty();
            $.getJSON('api/get_ict_replies.php', { complaint_id: complaintId }, function(res) {
                if (res.success && res.replies && res.replies.length > 0) {
                    let repliesHtml = `
                        <hr>
                        <h6 class="text-primary font-weight-bold mb-3"><i class="fas fa-comments mr-2"></i>Conversation History</h6>
                        <div style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                    `;
                    res.replies.forEach(reply => {
                        const isStudent = reply.sender_type === 'student';
                        const icon = isStudent ? 'fa-user-graduate' : 'fa-user-shield';
                        const color = isStudent ? 'success' : 'primary';
                        const senderTitle = isStudent ? 'Student' : 'Staff';
                        
                        let imagesHtml = '';
                        if (reply.reply_images) {
                            const images = reply.reply_images.split(',').filter(Boolean);
                            if (images.length > 0) {
                                imagesHtml += '<div class="mt-2 d-flex flex-wrap" style="gap: 8px;">';
                                images.forEach(img => {
                                    const imgUrl = 'public_image.php?img=' + encodeURIComponent(img.trim());
                                    imagesHtml += `
                                        <div style="cursor: pointer;" onclick="window.open('${imgUrl}', '_blank')">
                                            <img src="${imgUrl}" class="img-thumbnail" style="max-height: 60px; max-width: 90px; object-fit: cover;">
                                        </div>
                                    `;
                                });
                                imagesHtml += '</div>';
                            }
                        }
                        
                        repliesHtml += `
                            <div class="mb-2 p-2 bg-light rounded shadow-sm border-left border-${color}" style="border-left-width:3px!important; font-size:0.85rem;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="font-weight-bold text-${color}">
                                        <i class="fas ${icon} mr-1"></i> ${esc(reply.sender_name)} (${senderTitle})
                                    </span>
                                    <small class="text-muted" style="font-size: 0.7rem;">${reply.created_at}</small>
                                </div>
                                <p class="mb-0 text-dark" style="white-space: pre-line; line-height: 1.4;">${reply.reply_text}</p>
                                ${imagesHtml}
                            </div>
                        `;
                    });
                    repliesHtml += '</div>';
                    container.html(repliesHtml).show();
                }
            });
        }

        // Load replies when forwarded view modal is shown
        $(document).on('shown.bs.modal', '#forwardedViewModal', function() {
            if (_currentFwData && _currentFwData.id) {
                loadIctRepliesDept(_currentFwData.id);
            }
        });
    });
    </script>

    <!-- Image Attachment Lightbox Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white border-0">
                    <h5 class="modal-title font-weight-bold">Response Image Viewer</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center p-3">
                    <img id="modalImage" src="" class="img-fluid rounded" alt="Attachment Image" style="max-height: 80vh;">
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
?>