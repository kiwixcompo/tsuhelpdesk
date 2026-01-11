<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Check if student is logged in
if(!isset($_SESSION["student_loggedin"]) || $_SESSION["student_loggedin"] !== true){
    header("location: student_login.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";

$success_msg = $error_msg = "";

// Handle complaint submission
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_complaint'])){
    $course_codes = $_POST['course_code'];
    $course_titles = $_POST['course_title'];
    $complaint_types = $_POST['complaint_type'];
    $descriptions = $_POST['description'];
    
    $complaints_added = 0;
    $errors = [];
    
    for($i = 0; $i < count($course_codes); $i++){
        if(!empty($course_codes[$i]) && !empty($course_titles[$i]) && !empty($complaint_types[$i])){
            $sql = "INSERT INTO student_complaints (student_id, course_code, course_title, complaint_type, description) VALUES (?, ?, ?, ?, ?)";
            
            if($stmt = mysqli_prepare($conn, $sql)){
                $description = !empty($descriptions[$i]) ? $descriptions[$i] : null;
                mysqli_stmt_bind_param($stmt, "issss", $_SESSION["student_id"], $course_codes[$i], $course_titles[$i], $complaint_types[$i], $description);
                
                if(mysqli_stmt_execute($stmt)){
                    $complaints_added++;
                } else {
                    $errors[] = "Failed to add complaint for " . $course_codes[$i];
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
    
    if($complaints_added > 0){
        $success_msg = "$complaints_added complaint(s) submitted successfully!";
    }
    if(!empty($errors)){
        $error_msg = implode(", ", $errors);
    }
}

// Fetch student's complaints
$complaints = [];
$sql = "SELECT * FROM student_complaints WHERE student_id = ? ORDER BY created_at DESC";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["student_id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $complaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get notification count for student
$notification_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["student_id"]);
}

// End output buffering and flush
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar-brand, .navbar-nav .nav-link {
            color: white !important;
        }
        .navbar-nav .nav-link:hover {
            color: #f8f9fa !important;
        }
        .dashboard-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .card-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.5rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border: none;
            border-radius: 10px;
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 10px;
            color: white;
        }
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            border-radius: 10px;
        }
        .form-control {
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .complaint-row {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #dee2e6;
        }
        .status-badge {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
        }
        .status-pending { background: #2196f3; color: white; }
        .status-under-review { background: #17a2b8; color: white; }
        .status-resolved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        .welcome-section {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-graduation-cap mr-2"></i>
                TSU Student Portal
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <!-- Notifications -->
                    <li class="nav-item dropdown mr-3">
                        <a class="nav-link dropdown-toggle" href="#" id="notificationDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if($notification_count > 0): ?>
                                <span class="badge badge-danger badge-pill ml-1" id="notificationBadge"><?php echo $notification_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="notificationDropdown" style="min-width: 300px;">
                            <h6 class="dropdown-header">
                                <i class="fas fa-bell mr-2"></i> Recent Notifications
                            </h6>
                            <div id="notificationList">
                                <div class="dropdown-item text-center">
                                    <i class="fas fa-spinner fa-spin"></i> Loading...
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-center" href="notifications.php">
                                <i class="fas fa-eye mr-2"></i> View All Notifications
                            </a>
                        </div>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown">
                            <i class="fas fa-user mr-1"></i>
                            <?php echo htmlspecialchars($_SESSION["student_name"]); ?>
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="student_change_password.php">
                                <i class="fas fa-key mr-2"></i>Change Password
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="student_logout.php">
                                <i class="fas fa-sign-out-alt mr-2"></i>Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <div class="row">
                <div class="col-md-8">
                    <h2><i class="fas fa-user-graduate mr-2"></i>Welcome, <?php echo htmlspecialchars($_SESSION["student_name"]); ?>!</h2>
                    <p class="mb-2"><strong>Registration Number:</strong> <?php echo htmlspecialchars($_SESSION["student_reg_number"]); ?></p>
                    <p class="mb-2"><strong>Programme:</strong> <?php echo htmlspecialchars($_SESSION["student_programme"]); ?></p>
                    <p class="mb-0"><strong>Department:</strong> <?php echo htmlspecialchars($_SESSION["student_department"]); ?></p>
                </div>
                <div class="col-md-4 text-right">
                    <i class="fas fa-clipboard-list fa-4x opacity-50"></i>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if(!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Lodge New Complaint -->
        <div class="card dashboard-card">
            <div class="card-header">
                <h4 class="mb-0"><i class="fas fa-plus-circle mr-2"></i>Lodge Result Verification Complaint</h4>
            </div>
            <div class="card-body">
                <form method="post" id="complaintForm">
                    <div id="complaintsContainer">
                        <div class="complaint-row" data-index="0">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Course Code *</label>
                                        <input type="text" name="course_code[]" class="form-control" placeholder="e.g., CSC301" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Course Title *</label>
                                        <input type="text" name="course_title[]" class="form-control" placeholder="e.g., Data Structures" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Complaint Type *</label>
                                        <select name="complaint_type[]" class="form-control" required>
                                            <option value="">Select Type</option>
                                            <option value="FA">Fail Absent (FA)</option>
                                            <option value="F">Fail (F)</option>
                                            <option value="Incorrect Grade">Incorrect Grade</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="button" class="btn btn-danger btn-block remove-complaint" style="display: none;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <div class="form-group">
                                        <label>Additional Description (Optional)</label>
                                        <textarea name="description[]" class="form-control" rows="2" placeholder="Provide any additional details about your complaint..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <button type="button" class="btn btn-success" id="addComplaint">
                                <i class="fas fa-plus mr-2"></i>Add Another Course
                            </button>
                        </div>
                        <div class="col-md-6 text-right">
                            <button type="submit" name="submit_complaint" class="btn btn-primary">
                                <i class="fas fa-paper-plane mr-2"></i>Submit Complaints
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- My Complaints -->
        <div class="card dashboard-card">
            <div class="card-header">
                <h4 class="mb-0"><i class="fas fa-list mr-2"></i>My Complaints</h4>
            </div>
            <div class="card-body">
                <?php if(empty($complaints)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No complaints submitted yet.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Title</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($complaints as $complaint): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($complaint['course_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($complaint['course_title']); ?></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo htmlspecialchars($complaint['complaint_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $complaint['status'])); ?>">
                                                <?php echo htmlspecialchars($complaint['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($complaint['created_at'])); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    data-toggle="modal" data-target="#complaintModal<?php echo $complaint['complaint_id']; ?>">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </td>
                                    </tr>
                                    
                                    <!-- Complaint Details Modal -->
                                    <div class="modal fade" id="complaintModal<?php echo $complaint['complaint_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Complaint Details</h5>
                                                    <button type="button" class="close" data-dismiss="modal">
                                                        <span>&times;</span>
                                                    </button>
                                                </div>
                                                <div class="modal-body">
                                                    <p><strong>Course:</strong> <?php echo htmlspecialchars($complaint['course_code'] . ' - ' . $complaint['course_title']); ?></p>
                                                    <p><strong>Type:</strong> <?php echo htmlspecialchars($complaint['complaint_type']); ?></p>
                                                    <p><strong>Status:</strong> 
                                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $complaint['status'])); ?>">
                                                            <?php echo htmlspecialchars($complaint['status']); ?>
                                                        </span>
                                                    </p>
                                                    <p><strong>Submitted:</strong> <?php echo date('M d, Y h:i A', strtotime($complaint['created_at'])); ?></p>
                                                    
                                                    <?php if(!empty($complaint['description'])): ?>
                                                        <p><strong>Description:</strong></p>
                                                        <p class="text-muted"><?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if(!empty($complaint['admin_response'])): ?>
                                                        <hr>
                                                        <p><strong>Admin Response:</strong></p>
                                                        <p class="text-info"><?php echo nl2br(htmlspecialchars($complaint['admin_response'])); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        $(document).ready(function() {
            let complaintIndex = 1;
            
            // Add new complaint row
            $('#addComplaint').click(function() {
                const newRow = `
                    <div class="complaint-row" data-index="${complaintIndex}">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Course Code *</label>
                                    <input type="text" name="course_code[]" class="form-control" placeholder="e.g., CSC301" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Course Title *</label>
                                    <input type="text" name="course_title[]" class="form-control" placeholder="e.g., Data Structures" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Complaint Type *</label>
                                    <select name="complaint_type[]" class="form-control" required>
                                        <option value="">Select Type</option>
                                        <option value="FA">Fail Absent (FA)</option>
                                        <option value="F">Fail (F)</option>
                                        <option value="Incorrect Grade">Incorrect Grade</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="button" class="btn btn-danger btn-block remove-complaint">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>Additional Description (Optional)</label>
                                    <textarea name="description[]" class="form-control" rows="2" placeholder="Provide any additional details about your complaint..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                $('#complaintsContainer').append(newRow);
                complaintIndex++;
                updateRemoveButtons();
            });
            
            // Remove complaint row
            $(document).on('click', '.remove-complaint', function() {
                $(this).closest('.complaint-row').remove();
                updateRemoveButtons();
            });
            
            // Update remove button visibility
            function updateRemoveButtons() {
                const rows = $('.complaint-row');
                if(rows.length > 1) {
                    $('.remove-complaint').show();
                } else {
                    $('.remove-complaint').hide();
                }
            }
            
            // Form validation
            $('#complaintForm').submit(function(e) {
                let hasValidComplaint = false;
                
                $('.complaint-row').each(function() {
                    const courseCode = $(this).find('input[name="course_code[]"]').val().trim();
                    const courseTitle = $(this).find('input[name="course_title[]"]').val().trim();
                    const complaintType = $(this).find('select[name="complaint_type[]"]').val();
                    
                    if(courseCode && courseTitle && complaintType) {
                        hasValidComplaint = true;
                    }
                });
                
                if(!hasValidComplaint) {
                    e.preventDefault();
                    alert('Please fill in at least one complete complaint (Course Code, Title, and Type).');
                }
            });
            
            // Auto-dismiss alerts
            $('.alert').delay(5000).fadeOut();
            
            // Load notifications when dropdown is shown
            $('#notificationDropdown').on('show.bs.dropdown', function() {
                loadNotifications();
            });
        });
        
        // Notification functions
        function loadNotifications() {
            $('#notificationList').html('<div class="dropdown-item text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
            
            $.ajax({
                url: 'get_notifications_dropdown.php',
                type: 'GET',
                dataType: 'json',
                timeout: 10000,
                success: function(data) {
                    if (data && data.success) {
                        displayNotifications(data.notifications);
                        updateNotificationBadge(data.unread_count);
                    } else {
                        $('#notificationList').html('<div class="dropdown-item text-center text-danger">Error: ' + (data.error || 'Unknown error') + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    let errorMsg = 'Error loading notifications';
                    
                    if (status === 'timeout') {
                        errorMsg = 'Request timed out';
                    } else if (xhr.status === 401) {
                        errorMsg = 'Session expired';
                        setTimeout(() => window.location.href = 'student_login.php', 2000);
                    } else if (xhr.status === 500) {
                        errorMsg = 'Server error';
                    } else if (xhr.status === 0) {
                        errorMsg = 'Network error';
                    }
                    
                    $('#notificationList').html('<div class="dropdown-item text-center text-danger">' + errorMsg + '</div>');
                }
            });
        }
        
        function displayNotifications(notifications) {
            const listContainer = $('#notificationList');
            
            if (!notifications || notifications.length === 0) {
                listContainer.html('<div class="dropdown-item text-center text-muted">No notifications</div>');
                return;
            }
            
            let html = '';
            try {
                notifications.forEach(function(notification) {
                    if (!notification || !notification.notification_id) {
                        return;
                    }
                    
                    const isUnread = notification.is_read == 0;
                    const unreadClass = isUnread ? 'unread' : '';
                    const unreadIcon = isUnread ? '<span class="text-primary">●</span> ' : '';
                    
                    const timeAgo = getTimeAgo(notification.created_at);
                    const title = notification.title || 'Notification';
                    const message = notification.message || '';
                    
                    html += `
                        <div class="dropdown-item notification-item ${unreadClass}" onclick="handleNotificationClick(${notification.notification_id}, ${notification.complaint_id})" style="cursor: pointer; border-left: 3px solid ${isUnread ? '#007bff' : 'transparent'};">
                            <div style="font-weight: 600; color: #1e3c72; margin-bottom: 4px;">
                                ${unreadIcon}${escapeHtml(title)}
                            </div>
                            <div style="font-size: 0.85rem; color: #6c757d; margin-bottom: 4px;">
                                ${escapeHtml(message)}
                            </div>
                            <div style="font-size: 0.75rem; color: #adb5bd;">
                                ${timeAgo}
                            </div>
                        </div>
                    `;
                });
                
                listContainer.html(html);
            } catch (error) {
                console.error('Error displaying notifications:', error);
                listContainer.html('<div class="dropdown-item text-center text-danger">Error displaying notifications</div>');
            }
        }
        
        function handleNotificationClick(notificationId, complaintId) {
            // Mark as read and redirect
            $.ajax({
                url: 'mark_notification_read.php',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({notification_id: notificationId}),
                success: function(response) {
                    if (response.success) {
                        updateNotificationBadge(Math.max(0, parseInt($('#notificationBadge').text() || 0) - 1));
                    }
                    // For students, redirect to student dashboard or complaint view
                    if (complaintId) {
                        window.location.href = 'student_dashboard.php#complaint-' + complaintId;
                    } else {
                        window.location.reload();
                    }
                },
                error: function() {
                    // Still redirect even if marking as read fails
                    window.location.reload();
                }
            });
        }
        
        function updateNotificationBadge(count) {
            const badge = $('#notificationBadge');
            if (count > 0) {
                badge.text(count).show();
            } else {
                badge.hide();
            }
        }
        
        function getTimeAgo(dateString) {
            const now = new Date();
            const date = new Date(dateString);
            const diffInSeconds = Math.floor((now - date) / 1000);
            
            if (diffInSeconds < 60) return 'Just now';
            if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + 'm ago';
            if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + 'h ago';
            if (diffInSeconds < 604800) return Math.floor(diffInSeconds / 86400) + 'd ago';
            
            return date.toLocaleDateString();
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        });
    </script>
</body>
</html>