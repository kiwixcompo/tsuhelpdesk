<?php
session_start();
require_once "config.php";
require_once "includes/notifications.php";

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// Handle mark as read
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['mark_read'])) {
        $notification_id = intval($_POST['notification_id']);
        markNotificationAsRead($conn, $notification_id, $_SESSION["user_id"]);
    } elseif (isset($_POST['mark_all_read'])) {
        markAllNotificationsAsRead($conn, $_SESSION["user_id"]);
    }
    header("Location: notifications.php");
    exit;
}

// Get all notifications for the user
$sql = "SELECT n.*, c.student_id, c.complaint_text 
        FROM notifications n 
        JOIN complaints c ON n.complaint_id = c.complaint_id 
        WHERE n.user_id = ? 
        ORDER BY n.created_at DESC 
        LIMIT 50";

$notifications = [];
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $notifications[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get unread count
$unread_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);

// Fetch app settings
$app_name = 'TSU ICT Complaint Desk';
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - <?php echo htmlspecialchars($app_name); ?></title>
    
    <!-- Dynamic Favicon -->
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    
    <style>
        .notification-item {
            border-left: 4px solid #007bff;
            transition: all 0.3s ease;
        }
        .notification-item.unread {
            background-color: #f8f9fa;
            border-left-color: #28a745;
        }
        .notification-item:hover {
            background-color: #e9ecef;
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
        }
        .notification-feedback {
            background-color: #17a2b8;
            color: white;
        }
        .notification-reply {
            background-color: #28a745;
            color: white;
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
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4>
                            <i class="fas fa-bell mr-2"></i>
                            Notifications
                            <?php if($unread_count > 0): ?>
                                <span class="badge badge-success ml-2"><?php echo $unread_count; ?> unread</span>
                            <?php endif; ?>
                        </h4>
                        <?php if($unread_count > 0): ?>
                        <form method="post" style="display: inline;">
                            <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-check-double"></i> Mark All Read
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($notifications)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-bell-slash text-muted" style="font-size: 48px;"></i>
                                <h5 class="mt-3">No Notifications</h5>
                                <p class="text-muted">You don't have any notifications yet.</p>
                                <a href="<?php 
                                    $dashboard_url = 'dashboard.php';
                                    switch($_SESSION["role_id"]) {
                                        case 3: $dashboard_url = 'director_dashboard.php'; break;
                                        case 4: $dashboard_url = 'dvc_dashboard.php'; break;
                                        case 5: $dashboard_url = 'i4cus_staff_dashboard.php'; break;
                                        case 6: $dashboard_url = 'payment_admin_dashboard.php'; break;
                                        case 7: $dashboard_url = 'department_dashboard.php'; break;
                                        default: $dashboard_url = 'dashboard.php'; break;
                                    }
                                    echo $dashboard_url;
                                ?>" class="btn btn-primary">Go to Dashboard</a>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach($notifications as $notification): ?>
                                <div class="list-group-item notification-item <?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
                                    <div class="d-flex align-items-start">
                                        <div class="notification-icon notification-<?php echo $notification['type'] == 'feedback_given' ? 'feedback' : 'reply'; ?>">
                                            <i class="fas <?php echo $notification['type'] == 'feedback_given' ? 'fa-comment' : 'fa-reply'; ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                    <p class="mb-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                                    <small class="text-muted">
                                                        Complaint #<?php echo $notification['complaint_id']; ?> - 
                                                        <?php echo substr(htmlspecialchars($notification['complaint_text']), 0, 50); ?>...
                                                    </small>
                                                </div>
                                                <div class="text-right">
                                                    <small class="text-muted d-block">
                                                        <?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?>
                                                    </small>
                                                    <div class="mt-2">
                                                        <a href="view_complaint.php?id=<?php echo $notification['complaint_id']; ?>" 
                                                           class="btn btn-sm btn-primary"
                                                           onclick="markNotificationAsRead(<?php echo $notification['notification_id']; ?>, <?php echo $notification['complaint_id']; ?>)">View</a>
                                                        <?php if (!$notification['is_read']): ?>
                                                        <form method="post" style="display: inline;">
                                                            <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                                            <button type="submit" name="mark_read" class="btn btn-sm btn-outline-secondary">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
    function markNotificationAsRead(notificationId, complaintId) {
        // Mark as read via AJAX
        $.ajax({
            url: 'mark_notification_read.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({notification_id: notificationId}),
            success: function(response) {
                if (response.success) {
                    // Update the notification appearance immediately
                    const notificationElement = $(`a[onclick*="${notificationId}"]`).closest('.notification-item');
                    notificationElement.removeClass('unread');
                    
                    // Remove the mark as read button if it exists
                    const markReadButton = notificationElement.find('button[name="mark_read"]').closest('form');
                    markReadButton.remove();
                    
                    // Update the unread count in the header
                    const unreadBadge = $('.badge-success');
                    if (unreadBadge.length > 0) {
                        const currentCount = parseInt(unreadBadge.text().split(' ')[0]) || 0;
                        const newCount = Math.max(0, currentCount - 1);
                        
                        if (newCount > 0) {
                            unreadBadge.text(newCount + ' unread');
                        } else {
                            unreadBadge.remove();
                            // Also remove the "Mark All Read" button if no unread notifications
                            $('button[name="mark_all_read"]').closest('form').remove();
                        }
                    }
                }
                
                // Navigate to the complaint page
                window.location.href = 'view_complaint.php?id=' + complaintId;
            },
            error: function() {
                // Still navigate even if marking as read fails
                window.location.href = 'view_complaint.php?id=' + complaintId;
            }
        });
        
        // Prevent the default link behavior
        return false;
    }
    </script>
</body>
</html>