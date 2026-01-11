<?php
// Navbar component - include this on all pages
// Make sure to include notifications.php before this file

// Get notification count if not already set
if (!isset($notification_count)) {
    require_once "includes/notifications.php";
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
}

// Get unread messages count if not already set
if (!isset($unread_count)) {
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
}
?>

<!-- Include navbar CSS -->
<link rel="stylesheet" href="css/navbar.css">

<!-- Session timeout script -->
<script>
// Enable session timeout for logged-in users
var sessionTimeoutEnabled = true;
</script>
<script src="js/session-timeout.js"></script>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <!-- Dynamic navbar brand with logo -->
        <div class="navbar-brand">
            <?php 
            // Set default values if not defined
            $app_logo = $app_logo ?? '';
            $app_name = $app_name ?? 'TSU ICT Help Desk';
            
            if($app_logo && file_exists($app_logo)): ?>
                <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" style="height: 30px; margin-right: 8px; object-fit: contain;">
            <?php endif; ?>
            <span><?php echo htmlspecialchars($app_name); ?></span>
        </div>
        
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item <?php 
                    $dashboard_file = '';
                    switch($_SESSION["role_id"]) {
                        case 3: $dashboard_file = 'director_dashboard.php'; break;
                        case 4: $dashboard_file = 'dvc_dashboard.php'; break;
                        case 5: $dashboard_file = 'i4cus_staff_dashboard.php'; break;
                        case 6: $dashboard_file = 'payment_admin_dashboard.php'; break;
                        case 7: $dashboard_file = 'department_dashboard.php'; break;
                        default: $dashboard_file = 'dashboard.php'; break;
                    }
                    echo basename($_SERVER['PHP_SELF']) == $dashboard_file ? 'active' : ''; 
                ?>">
                    <a class="nav-link" href="<?php echo $dashboard_file; ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                
                <?php if($_SESSION["role_id"] == 1): ?>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="admin.php">
                        <i class="fas fa-cogs"></i> Admin Panel
                    </a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users"></i> Users
                    </a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'manage_students.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="manage_students.php">
                        <i class="fas fa-user-graduate"></i> Students
                    </a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'student_complaints_report.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="student_complaints_report.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
                <?php if($_SESSION["is_super_admin"]): ?>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="settings.php">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>
                
                <?php if($_SESSION["role_id"] == 3): ?>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'director_dashboard.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="director_dashboard.php">
                        <i class="fas fa-user-tie"></i> Director Dashboard
                    </a>
                </li>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'student_complaints_report.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="student_complaints_report.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if($_SESSION["role_id"] == 4): ?>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'dvc_dashboard.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="dvc_dashboard.php">
                        <i class="fas fa-graduation-cap"></i> DVC Dashboard
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if($_SESSION["role_id"] == 5): ?>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'i4cus_staff_dashboard.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="i4cus_staff_dashboard.php">
                        <i class="fas fa-laptop-code"></i> i4Cus Dashboard
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if($_SESSION["role_id"] == 6): ?>
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'payment_admin_dashboard.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="payment_admin_dashboard.php">
                        <i class="fas fa-credit-card"></i> Payment Dashboard
                    </a>
                </li>
                <?php endif; ?>
                

                
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'suggestions.php' ? 'active' : ''; ?>">
                    <a class="nav-link" href="suggestions.php">
                        <i class="fas fa-lightbulb"></i> Suggestions
                    </a>
                </li>
                
                <!-- Icon-only items with tooltips -->
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>">
                    <a class="nav-link position-relative icon-only" href="messages.php" title="Messages" data-toggle="tooltip" data-placement="bottom">
                        <i class="fas fa-envelope"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="badge badge-light position-absolute" style="top: -5px; right: -10px; font-size: 0.7rem;"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                
                <li class="nav-item dropdown <?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>">
                    <a class="nav-link position-relative icon-only dropdown-toggle" href="#" id="notificationDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if($notification_count > 0): ?>
                            <span class="badge badge-warning position-absolute notification-badge" id="notificationBadge"><?php echo $notification_count; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right notification-dropdown" aria-labelledby="notificationDropdown">
                        <h6 class="dropdown-header">
                            <i class="fas fa-bell mr-1"></i> Recent Notifications
                        </h6>
                        <div id="notificationList">
                            <div class="dropdown-item text-center">
                                <i class="fas fa-spinner fa-spin"></i> Loading...
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="notifications.php">
                            <i class="fas fa-eye mr-1"></i> View All Notifications
                        </a>
                    </div>
                </li>
                
                <li class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'account.php' ? 'active' : ''; ?>">
                    <a class="nav-link icon-only" href="account.php" title="<?php echo isset($_SESSION["full_name"]) ? htmlspecialchars($_SESSION["full_name"]) : "My Account"; ?>" data-toggle="tooltip" data-placement="bottom">
                        <i class="fas fa-user-circle"></i>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link icon-only" href="logout.php" title="Logout" data-toggle="tooltip" data-placement="bottom">
                        <i class="fas fa-sign-out-alt"></i>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<script>
// Initialize tooltips for navbar
$(document).ready(function(){
    $('[data-toggle="tooltip"]').tooltip();
    
    // Load notifications when dropdown is shown (Bootstrap event)
    $('#notificationDropdown').on('show.bs.dropdown', function() {
        loadNotifications();
    });
    
    // Also load on click as fallback
    $('#notificationDropdown').on('click', function(e) {
        if (!$(this).next('.dropdown-menu').hasClass('show')) {
            loadNotifications();
        }
    });
});

function loadNotifications() {
    $('#notificationList').html('<div class="dropdown-item text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    
    $.ajax({
        url: 'get_notifications_dropdown.php',
        type: 'GET',
        dataType: 'json',
        timeout: 10000, // 10 second timeout
        success: function(data) {
            console.log('Notification response:', data); // Debug log
            if (data && data.success) {
                displayNotifications(data.notifications);
                updateNotificationBadge(data.unread_count);
            } else {
                console.error('Notification error:', data);
                $('#notificationList').html('<div class="dropdown-item text-center text-danger">Error: ' + (data.error || 'Unknown error') + '</div>');
            }
        },
        error: function(xhr, status, error) {
            console.error('Notification loading error:', status, error, xhr.responseText);
            let errorMsg = 'Error loading notifications';
            
            if (status === 'timeout') {
                errorMsg = 'Request timed out';
            } else if (xhr.status === 401) {
                errorMsg = 'Session expired';
                // Redirect to login
                setTimeout(() => window.location.href = 'index.php', 2000);
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
                console.warn('Invalid notification object:', notification);
                return;
            }
            
            const isUnread = notification.is_read == 0;
            const unreadClass = isUnread ? 'unread' : '';
            const unreadIcon = isUnread ? '<span class="notification-icon"></span>' : '';
            
            const timeAgo = getTimeAgo(notification.created_at);
            const title = notification.title || 'Notification';
            const message = notification.message || '';
            
            html += `
                <div class="notification-item ${unreadClass}" onclick="handleNotificationClick(${notification.notification_id}, ${notification.complaint_id})" onmouseenter="markAsReadOnView(${notification.notification_id}, ${isUnread})">
                    <div class="notification-title">
                        ${unreadIcon}${escapeHtml(title)}
                    </div>
                    <div class="notification-message">
                        ${escapeHtml(message)}
                    </div>
                    <div class="notification-time">
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
    // Mark as read and update UI immediately
    $.ajax({
        url: 'mark_notification_read.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({notification_id: notificationId}),
        success: function(response) {
            if (response.success) {
                // Update the notification appearance
                const notificationElement = $(`[onclick*="${notificationId}"]`);
                notificationElement.removeClass('unread');
                notificationElement.find('.notification-icon').remove();
                
                // Update badge count
                const currentBadge = $('#notificationBadge');
                const currentCount = parseInt(currentBadge.text()) || 0;
                const newCount = Math.max(0, currentCount - 1);
                
                if (newCount > 0) {
                    currentBadge.text(newCount).show();
                } else {
                    currentBadge.hide();
                }
            }
            
            // Redirect to complaint
            window.location.href = 'view_complaint.php?id=' + complaintId;
        },
        error: function() {
            // Still redirect even if marking as read fails
            window.location.href = 'view_complaint.php?id=' + complaintId;
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

function markAsReadOnView(notificationId, isUnread) {
    if (!isUnread) return; // Already read
    
    // Mark as read after a short delay (user has viewed it)
    setTimeout(function() {
        $.ajax({
            url: 'mark_notification_read.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({notification_id: notificationId}),
            success: function(response) {
                if (response.success) {
                    // Update the notification appearance
                    const notificationElement = $(`[onclick*="${notificationId}"]`);
                    notificationElement.removeClass('unread');
                    notificationElement.find('.notification-icon').remove();
                    
                    // Update badge count
                    const currentBadge = $('#notificationBadge');
                    const currentCount = parseInt(currentBadge.text()) || 0;
                    const newCount = Math.max(0, currentCount - 1);
                    
                    if (newCount > 0) {
                        currentBadge.text(newCount).show();
                    } else {
                        currentBadge.hide();
                    }
                }
            }
        });
    }, 1000); // 1 second delay to ensure user has seen it
}
</script>