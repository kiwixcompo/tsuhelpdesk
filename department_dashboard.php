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
    
    $upd = mysqli_prepare($conn, "UPDATE student_ict_complaints SET status=?, admin_response=?, handled_by=?, updated_at=NOW() WHERE complaint_id=? AND forwarded_to=?");
    if($upd) {
        mysqli_stmt_bind_param($upd, "ssiis", $status, $formatted_response, $_SESSION['user_id'], $cid, $dept_full_name);
        if(mysqli_stmt_execute($upd)) {
            $success_message = "Response submitted successfully.";
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
    <link rel="stylesheet" href="css/navbar.css">
    <style>
        .department-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .complaint-card {
            border-left: 4px solid #007bff;
            margin-bottom: 1rem;
        }
        .complaint-card.urgent {
            border-left-color: #dc3545;
        }
        .complaint-card.treated {
            border-left-color: #28a745;
        }
        .department-name {
            font-size: 1.5rem;
            line-height: 1.2;
            word-wrap: break-word;
            hyphens: auto;
        }
        @media (max-width: 768px) {
            .department-name {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="department-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="mb-2">
                        <i class="fas fa-building mr-3"></i>
                        <span class="department-name"><?php echo htmlspecialchars($_SESSION["full_name"]); ?></span>
                    </h1>
                    <p class="mb-0">Department Complaint Management System</p>
                </div>
                <div class="col-md-4 text-right">
                    <div class="btn-group">
                        <a href="department_complaints.php" class="btn btn-light"><i class="fas fa-list-alt"></i> Manage Complaints</a>
                        <a href="account.php" class="btn btn-outline-light"><i class="fas fa-user"></i> Profile</a>
                        <a href="change_password.php" class="btn btn-outline-light"><i class="fas fa-key"></i> Change Password</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container main-content">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="fas fa-clipboard-list fa-2x text-primary mb-3"></i>
                        <h3 class="text-primary"><?php echo $total_complaints; ?></h3>
                        <p class="text-muted">Total Complaints</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="fas fa-clock fa-2x text-warning mb-3"></i>
                        <h3 class="text-warning"><?php echo $pending_complaints; ?></h3>
                        <p class="text-muted">Pending</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="fas fa-check-circle fa-2x text-success mb-3"></i>
                        <h3 class="text-success"><?php echo $treated_complaints; ?></h3>
                        <p class="text-muted">Resolved</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Email Notification Preferences -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center"
                         style="background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff;cursor:pointer"
                         data-toggle="collapse" data-target="#notifPrefsBody">
                        <h5 class="mb-0"><i class="fas fa-bell mr-2"></i>Email Notification Preferences</h5>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="collapse" id="notifPrefsBody">
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                Choose which events trigger an email to your account.
                                You will always see in-app notifications regardless of these settings.
                            </p>
                            <form id="notifPrefsForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="custom-control custom-switch mb-3">
                                            <input type="checkbox" class="custom-control-input" id="pref_forwarded"
                                                   name="on_forwarded"
                                                   <?php echo $dept_notif_prefs['on_forwarded'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_forwarded">
                                                <strong>Complaint forwarded to me</strong><br>
                                                <small class="text-muted">Email when ICT forwards a student complaint to your department</small>
                                            </label>
                                        </div>
                                        <div class="custom-control custom-switch mb-3">
                                            <input type="checkbox" class="custom-control-input" id="pref_ict_response"
                                                   name="on_ict_response"
                                                   <?php echo $dept_notif_prefs['on_ict_response'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_ict_response">
                                                <strong>ICT adds a response</strong><br>
                                                <small class="text-muted">Email when ICT adds feedback or a response to a forwarded complaint</small>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="custom-control custom-switch mb-3">
                                            <input type="checkbox" class="custom-control-input" id="pref_status_change"
                                                   name="on_status_change"
                                                   <?php echo $dept_notif_prefs['on_status_change'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_status_change">
                                                <strong>Status change on forwarded complaint</strong><br>
                                                <small class="text-muted">Email when the status of a complaint forwarded to you is updated</small>
                                            </label>
                                        </div>
                                        <div class="custom-control custom-switch mb-3">
                                            <input type="checkbox" class="custom-control-input" id="pref_new_complaint"
                                                   name="on_new_student_complaint"
                                                   <?php echo $dept_notif_prefs['on_new_student_complaint'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label" for="pref_new_complaint">
                                                <strong>All new ICT complaints</strong><br>
                                                <small class="text-muted">Email for every new student ICT complaint submitted (high volume — off by default)</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm" id="savePrefsBtn">
                                    <i class="fas fa-save mr-1"></i> Save Preferences
                                </button>
                                <span id="prefsSaveMsg" class="ml-2 small" style="display:none"></span>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Forwarded ICT Complaints -->
        <?php if (!empty($forwarded_cases)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-info shadow-sm">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-share-square mr-2"></i>Cases Forwarded From ICT (<?php echo count($forwarded_cases); ?>)</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>#</th>
                                    <th>Student</th>
                                    <th>Category / Issue</th>
                                    <th>Decision Path</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($forwarded_cases as $fc):
                                    $sc = ['Pending'=>'warning','Under Review'=>'info','Resolved'=>'success','Rejected'=>'danger','Auto-Resolved'=>'secondary'];
                                    $bc = $sc[$fc['status']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td><?php echo $fc['complaint_id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($fc['student_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($fc['registration_number']); ?></small><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($fc['email'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($fc['category']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($fc['node_label']); ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted" style="max-width:200px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                               title="<?php echo htmlspecialchars($fc['path_summary']); ?>">
                                            <?php echo htmlspecialchars($fc['path_summary']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $bc; ?>"><?php echo htmlspecialchars($fc['status']); ?></span>
                                        <?php if (!empty($fc['admin_response'])): ?>
                                            <br><small class="text-success"><i class="fas fa-check-circle mr-1"></i>ICT responded</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($fc['created_at'])); ?></td>
                                    <td>
                                        <!-- View button — stores all data as attributes -->
                                        <button type="button" class="btn btn-sm btn-outline-info mr-1 btn-view-fw"
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
                                                data-ict-response="<?php echo htmlspecialchars($fc['admin_response'] ?? '', ENT_QUOTES); ?>"
                                                data-status="<?php echo htmlspecialchars($fc['status'], ENT_QUOTES); ?>"
                                                data-date="<?php echo date('M d, Y H:i', strtotime($fc['created_at'])); ?>"
                                                data-extra="<?php echo htmlspecialchars($fc['extra_fields'] ?? '{}', ENT_QUOTES); ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <!-- Respond button -->
                                        <button type="button" class="btn btn-sm btn-outline-success btn-respond-fw"
                                                data-id="<?php echo $fc['complaint_id']; ?>"
                                                data-student="<?php echo htmlspecialchars($fc['student_name'], ENT_QUOTES); ?>"
                                                data-label="<?php echo htmlspecialchars($fc['node_label'], ENT_QUOTES); ?>"
                                                data-status="<?php echo htmlspecialchars($fc['status'], ENT_QUOTES); ?>">
                                            <i class="fas fa-reply"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Submit New Complaint -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-plus-circle mr-2"></i>Submit New Complaint</h5>
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
                                <label>Complaint Details</label>
                                <textarea name="complaint_text" class="form-control manual-clipboard-init" rows="5" required placeholder="Describe your complaint in detail... (You can paste images directly with Ctrl+V)"></textarea>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle text-info"></i> 
                                    <strong>Tip:</strong> You can paste screenshots directly with Ctrl+V while typing, or use the file input below.
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <label>Attach Images (Optional)</label>
                                <input type="file" id="complaint_images" name="images[]" class="form-control-file" accept="image/*" multiple>
                                <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)</small>
                            </div>
                            

                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="urgentCheck" name="is_urgent">
                                    <label class="custom-control-label" for="urgentCheck">
                                        <i class="fas fa-exclamation-triangle mr-1 text-danger"></i>Mark as Urgent
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" name="submit_complaint" class="btn btn-primary btn-block">
                                <i class="fas fa-paper-plane mr-2"></i>Submit Complaint
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Complaints -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-history mr-2"></i>Recent Department Complaints</h5>
                    </div>
                    <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                        <?php if(empty($complaints)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>No complaints submitted yet.</p>
                                <small>Submit your first complaint using the form on the left.</small>
                            </div>
                        <?php else: ?>
                            <?php foreach(array_slice($complaints, 0, 8) as $complaint): ?>
                                <div class="card complaint-card <?php echo $complaint['is_urgent'] ? 'urgent' : ''; ?> <?php echo $complaint['status'] == 'Treated' ? 'treated' : ''; ?>">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0">
                                                <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" class="text-decoration-none">
                                                    <i class="fas fa-eye mr-1"></i>View Details
                                                </a>
                                                <?php if($complaint['is_urgent']): ?>
                                                    <span class="badge badge-danger ml-1">Urgent</span>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="text-muted"><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></small>
                                        </div>
                                        <p class="text-muted mb-2 small"><?php echo substr(htmlspecialchars($complaint['complaint_text']), 0, 120) . (strlen($complaint['complaint_text']) > 120 ? '...' : ''); ?></p>
                                        
                                        <?php if(!empty($complaint['feedback'])): ?>
                                            <div class="alert alert-info py-2 px-3 mb-2">
                                                <div class="d-flex align-items-start">
                                                    <i class="fas fa-comment-dots text-info mr-2 mt-1"></i>
                                                    <div class="flex-grow-1">
                                                        <strong class="small">Admin Feedback:</strong>
                                                        <p class="mb-0 small"><?php echo substr(htmlspecialchars($complaint['feedback']), 0, 100) . (strlen($complaint['feedback']) > 100 ? '...' : ''); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="badge badge-<?php echo $complaint['status'] == 'Treated' ? 'success' : ($complaint['status'] == 'In Progress' ? 'warning' : 'secondary'); ?>">
                                                <i class="fas fa-<?php echo $complaint['status'] == 'Treated' ? 'check-circle' : ($complaint['status'] == 'In Progress' ? 'clock' : 'hourglass-start'); ?>"></i>
                                                <?php echo $complaint['status']; ?>
                                            </span>
                                            <?php if($complaint['handler_name']): ?>
                                                <small class="text-success">
                                                    <i class="fas fa-user-check"></i> <?php echo htmlspecialchars($complaint['handler_name']); ?>
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock"></i> Awaiting Assignment
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if(count($complaints) > 8): ?>
                                <div class="text-center mt-3">
                                    <small class="text-muted">
                                        Showing 8 of <?php echo count($complaints); ?> complaints. 
                                        <a href="view_complaint.php" class="text-primary">View complaint details</a> for more information.
                                    </small>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Calendar Section -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-calendar-alt mr-2"></i>Complaint Calendar</h5>
                    </div>
                    <div class="card-body">
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
    
    <!-- Forwarded Case View Modal (full details) -->
    <div class="modal fade" id="forwardedViewModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-eye mr-2"></i>Forwarded Case Details</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body" id="fwViewBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="fwViewToRespond">
                        <i class="fas fa-reply mr-1"></i>Respond to This Case
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Forwarded Case Response Modal -->
    <div class="modal fade" id="forwardedReplyModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Respond to Forwarded Case</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="complaint_id" id="fwReplyId">
                        <div class="alert alert-light border">
                            <strong>Student:</strong> <span id="fwReplyStudent"></span><br>
                            <strong>Issue:</strong> <span id="fwReplyLabel"></span>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Update Status</label>
                            <select name="status" id="fwReplyStatus" class="form-control" required>
                                <option value="Under Review">Under Review</option>
                                <option value="Resolved">Resolved</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Your Response</label>
                            <textarea name="dept_response" class="form-control" rows="4" required placeholder="Type the resolution or feedback..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="reply_forwarded" class="btn btn-primary">
                            <i class="fas fa-paper-plane mr-1"></i>Send Response
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

    // Store current view data for "Respond" button inside view modal
    let _currentFwData = {};

    $(document).ready(function() {

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
                    extraHtml = '<h6 class="mt-3">Extra Information Provided by Student</h6><table class="table table-sm table-bordered">';
                    filtered.forEach(([k,v]) => {
                        const label = k.replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase());
                        extraHtml += `<tr><td class="font-weight-bold" style="width:40%">${esc(label)}</td><td>${esc(String(v))}</td></tr>`;
                    });
                    extraHtml += '</table>';
                }
            } catch(e) {}

            const autoHtml = d.auto
                ? `<div class="alert alert-info mt-3"><strong>Auto-Response Shown to Student:</strong><br>${esc(d.auto).replace(/\n/g,'<br>')}</div>`
                : '';
            const ictRespHtml = d.ictResponse
                ? `<div class="alert alert-success mt-3"><strong>ICT Response:</strong><br>${esc(d.ictResponse).replace(/\n/g,'<br>')}</div>`
                : '';
            const descHtml = d.desc
                ? `<h6 class="mt-3">Additional Details from Student</h6><p class="text-muted">${esc(d.desc).replace(/\n/g,'<br>')}</p>`
                : '';

            const body = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Student Information</h6>
                        <p><strong>Name:</strong> ${esc(d.student)}</p>
                        <p><strong>Reg No:</strong> ${esc(d.reg)}</p>
                        <p><strong>Email:</strong> ${esc(d.email)}</p>
                        <p><strong>Programme:</strong> ${esc(d.programme || 'N/A')}</p>
                        <p><strong>Department:</strong> ${esc(d.dept || 'N/A')}</p>
                        <p><strong>Faculty:</strong> ${esc(d.faculty || 'N/A')}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Complaint Information</h6>
                        <p><strong>Category:</strong> ${esc(d.category)}</p>
                        <p><strong>Issue:</strong> ${esc(d.label)}</p>
                        <p><strong>Status:</strong> <span class="badge badge-${bc}">${esc(d.status)}</span></p>
                        <p><strong>Submitted:</strong> ${esc(d.date)}</p>
                    </div>
                </div>
                <hr>
                <h6>Decision Path Taken by Student</h6>
                <p class="text-muted small">${esc(d.path)}</p>
                ${descHtml}${autoHtml}${ictRespHtml}${extraHtml}`;

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
                    msg.text('✓ Saved').css('color','#28a745').show();
                } else {
                    msg.text('✗ ' + (res.message || 'Failed')).css('color','#dc3545').show();
                }
                btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Preferences');
                setTimeout(() => msg.fadeOut(), 3000);
            }, 'json').fail(function() {
                msg.text('✗ Request failed').css('color','#dc3545').show();
                btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Preferences');
            });
        });
    });
    </script>
</body>
</html>

<?php
?>