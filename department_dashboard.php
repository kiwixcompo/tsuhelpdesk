<?php
session_start();

// Only allow departments (role_id = 7)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 7){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";
require_once "calendar_helper.php";

$success_message = "";
$error_message = "";

// Handle complaint submission
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["submit_complaint"])){
    $complaint_text = trim($_POST["complaint_text"]);
    $is_urgent = isset($_POST["is_urgent"]) ? 1 : 0;
    $is_payment_related = 0; // Departments don't handle payment issues
    
    if(!empty($complaint_text)){
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
    
    <script>
        $(document).ready(function() {
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
        });
    </script>
</body>
</html>