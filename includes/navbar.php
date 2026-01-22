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

// Determine current dashboard file
$dashboard_file = '';
switch($_SESSION["role_id"]) {
    case 3: $dashboard_file = 'director_dashboard.php'; break;
    case 4: $dashboard_file = 'dvc_dashboard.php'; break;
    case 5: $dashboard_file = 'i4cus_staff_dashboard.php'; break;
    case 6: $dashboard_file = 'payment_admin_dashboard.php'; break;
    case 7: $dashboard_file = 'department_dashboard.php'; break;
    case 8: $dashboard_file = 'deputy_director_dashboard.php'; break;
    default: $dashboard_file = 'dashboard.php'; break;
}
?>

<style>
/* Prevent horizontal overflow */
html, body {
    overflow-x: hidden;
    max-width: 100%;
}

.container {
    max-width: 100%;
    padding-left: 15px;
    padding-right: 15px;
}

.modern-navbar {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    box-shadow: 0 2px 20px rgba(30, 60, 114, 0.15);
    padding: 0.75rem 0;
    position: sticky;
    top: 0;
    z-index: 1030;
    width: 100%;
}

.navbar-brand-modern {
    display: flex;
    align-items: center;
    color: white !important;
    font-weight: 600;
    font-size: 1.1rem;
    text-decoration: none;
}

.navbar-brand-modern:hover {
    color: #e8f4fd !important;
    text-decoration: none;
}

.brand-logo {
    height: 32px;
    width: 32px;
    margin-right: 10px;
    border-radius: 6px;
}

.navbar-toggler-modern {
    border: none;
    padding: 4px 8px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    transition: all 0.3s ease;
}

.navbar-toggler-modern:hover {
    background: rgba(255, 255, 255, 0.2);
}

.navbar-toggler-modern .navbar-toggler-icon {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.8%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='m4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
}

.navbar-nav-modern {
    align-items: center;
}

.nav-section {
    display: flex;
    align-items: center;
    margin-right: 1rem;
}

.nav-section:last-child {
    margin-right: 0;
}

.nav-section-divider {
    width: 1px;
    height: 24px;
    background: rgba(255, 255, 255, 0.2);
    margin: 0 1rem;
}

.nav-link-modern {
    color: rgba(255, 255, 255, 0.9) !important;
    padding: 0.5rem 0.75rem !important;
    border-radius: 8px;
    transition: all 0.3s ease;
    font-weight: 500;
    display: flex;
    align-items: center;
    text-decoration: none;
    margin: 0 2px;
}

.nav-link-modern:hover {
    color: white !important;
    background: rgba(255, 255, 255, 0.15);
    transform: translateY(-1px);
    text-decoration: none;
}

.nav-link-modern.active {
    background: rgba(255, 255, 255, 0.2);
    color: white !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.nav-link-modern i {
    margin-right: 6px;
    font-size: 0.9rem;
}

.nav-link-icon-only {
    padding: 0.5rem !important;
    position: relative;
}

.nav-link-icon-only i {
    margin-right: 0;
    font-size: 1.1rem;
}

.notification-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    background: #ff4757;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 0.7rem;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
    border: 2px solid #1e3c72;
}

.message-badge {
    background: #2ed573;
}

.dropdown-menu-modern {
    border: none;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    padding: 0.5rem 0;
    margin-top: 0.5rem;
    min-width: 280px;
}

.dropdown-header-modern {
    padding: 0.75rem 1rem;
    font-weight: 600;
    color: #1e3c72;
    border-bottom: 1px solid #e9ecef;
    margin-bottom: 0.5rem;
}

.dropdown-item-modern {
    padding: 0.75rem 1rem;
    transition: all 0.2s ease;
    border-radius: 0;
}

.dropdown-item-modern:hover {
    background: #f8f9fa;
    color: #1e3c72;
}

.user-dropdown {
    display: flex;
    align-items: center;
    color: white !important;
    text-decoration: none;
    padding: 0.5rem 0.75rem;
    border-radius: 25px;
    background: rgba(255, 255, 255, 0.1);
    transition: all 0.3s ease;
}

.user-dropdown:hover {
    background: rgba(255, 255, 255, 0.2);
    color: white !important;
    text-decoration: none;
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 8px;
    font-size: 1rem;
}

.user-info {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.user-name {
    font-weight: 600;
    font-size: 0.9rem;
    line-height: 1.2;
    white-space: nowrap;
    overflow: visible;
    text-overflow: unset;
    max-width: none;
}

.user-role {
    font-size: 0.75rem;
    opacity: 0.8;
    line-height: 1;
}

/* Mobile Responsive */
@media (max-width: 991.98px) {
    .navbar-collapse {
        background: rgba(30, 60, 114, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 12px;
        margin-top: 1rem;
        padding: 1rem;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        max-width: calc(100vw - 30px);
    }
    
    .nav-section {
        flex-direction: column;
        align-items: flex-start;
        margin-right: 0;
        margin-bottom: 1rem;
        width: 100%;
    }
    
    .nav-section-divider {
        display: none;
    }
    
    .nav-link-modern {
        width: 100%;
        justify-content: flex-start;
        margin: 2px 0;
        padding: 0.75rem 1rem !important;
    }
    
    .user-dropdown {
        width: 100%;
        justify-content: flex-start;
        border-radius: 8px;
        margin-top: 1rem;
        padding: 1rem;
    }
    
    .dropdown-menu-modern {
        position: static !important;
        transform: none !important;
        box-shadow: none;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        margin-top: 0.5rem;
        width: 100%;
        max-width: 100%;
    }
    
    .dropdown-item-modern {
        color: white;
    }
    
    .dropdown-item-modern:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }
}

@media (max-width: 576px) {
    .modern-navbar {
        padding: 0.5rem 0;
    }
    
    .navbar-brand-modern {
        font-size: 1rem;
    }
    
    .brand-logo {
        height: 28px;
        width: 28px;
    }
    
    .container {
        padding-left: 10px;
        padding-right: 10px;
    }
    
    .navbar-collapse {
        max-width: calc(100vw - 20px);
    }
}
</style>

<!-- Session timeout script -->
<script>
// Enable session timeout for logged-in users
var sessionTimeoutEnabled = true;
</script>
<script src="js/session-timeout.js"></script>

<nav class="navbar navbar-expand-lg modern-navbar">
    <div class="container">
        <!-- Brand -->
        <a class="navbar-brand-modern" href="<?php echo $dashboard_file; ?>">
            <?php 
            // Set default values if not defined
            $app_logo = $app_logo ?? '';
            $app_name = $app_name ?? 'TSU ICT Help Desk';
            
            if($app_logo && file_exists($app_logo)): ?>
                <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="brand-logo">
            <?php else: ?>
                <div class="brand-logo" style="background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-graduation-cap"></i>
                </div>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($app_name); ?></span>
        </a>
        
        <!-- Mobile toggle -->
        <button class="navbar-toggler navbar-toggler-modern" type="button" data-toggle="collapse" data-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <!-- Navigation Content -->
        <div class="collapse navbar-collapse" id="navbarContent">
            <div class="navbar-nav-modern ml-auto d-flex align-items-center">
                
                <!-- Main Navigation Section -->
                <div class="nav-section">
                    <a class="nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == $dashboard_file ? 'active' : ''; ?>" href="<?php echo $dashboard_file; ?>">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                    
                    <?php if($_SESSION["role_id"] == 1): // Admin ?>
                        <a class="nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'admin.php' ? 'active' : ''; ?>" href="admin.php">
                            <i class="fas fa-cogs"></i> Admin
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- Management Section -->
                <?php if(in_array($_SESSION["role_id"], [1, 3])): // Admin or Director ?>
                <div class="nav-section-divider d-none d-lg-block"></div>
                <div class="nav-section">
                    <?php if($_SESSION["role_id"] == 1): ?>
                        <a class="nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" href="users.php">
                            <i class="fas fa-users"></i> Staff
                        </a>
                        <a class="nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'manage_students.php' ? 'active' : ''; ?>" href="manage_students.php">
                            <i class="fas fa-user-graduate"></i> Students
                        </a>
                    <?php endif; ?>
                    
                    <a class="nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'student_complaints_report.php' ? 'active' : ''; ?>" href="student_complaints_report.php">
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </div>
                <?php endif; ?>
                
                <!-- Tools Section -->
                <div class="nav-section-divider d-none d-lg-block"></div>
                <div class="nav-section">
                    <a class="nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'suggestions.php' ? 'active' : ''; ?>" href="suggestions.php">
                        <i class="fas fa-lightbulb"></i> Suggestions
                    </a>
                    
                    <?php if($_SESSION["role_id"] == 1 && $_SESSION["is_super_admin"]): ?>
                        <a class="nav-link-modern <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                    <?php endif; ?>
                </div>
                
                <!-- Communication Section -->
                <div class="nav-section-divider d-none d-lg-block"></div>
                <div class="nav-section">
                    <a class="nav-link-modern nav-link-icon-only <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>" href="messages.php" title="Messages" data-toggle="tooltip" data-placement="bottom">
                        <i class="fas fa-envelope"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="notification-badge message-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <div class="nav-item dropdown">
                        <a class="nav-link-modern nav-link-icon-only dropdown-toggle" href="#" id="notificationDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if($notification_count > 0): ?>
                                <span class="notification-badge" id="notificationBadge"><?php echo $notification_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right dropdown-menu-modern" aria-labelledby="notificationDropdown">
                            <h6 class="dropdown-header-modern">
                                <i class="fas fa-bell mr-2"></i> Recent Notifications
                            </h6>
                            <div id="notificationList">
                                <div class="dropdown-item-modern text-center">
                                    <i class="fas fa-spinner fa-spin"></i> Loading...
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item-modern text-center" href="notifications.php">
                                <i class="fas fa-eye mr-2"></i> View All Notifications
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- User Section -->
                <div class="nav-section-divider d-none d-lg-block"></div>
                <div class="nav-section">
                    <div class="nav-item dropdown">
                        <a class="user-dropdown dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <div class="user-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="user-info d-none d-lg-block">
                                <div class="user-name"><?php echo htmlspecialchars($_SESSION["full_name"] ?? "User"); ?></div>
                                <div class="user-role">
                                    <?php 
                                    $role_names = [
                                        1 => 'Administrator',
                                        2 => 'Staff',
                                        3 => 'Director',
                                        4 => 'DVC',
                                        5 => 'i4Cus Staff',
                                        6 => 'Payment Admin',
                                        7 => 'Department',
                                        8 => 'Deputy Director ICT'
                                    ];
                                    echo $role_names[$_SESSION["role_id"]] ?? 'User';
                                    ?>
                                </div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right dropdown-menu-modern" aria-labelledby="userDropdown">
                            <h6 class="dropdown-header-modern">
                                <i class="fas fa-user-circle mr-2"></i> <?php echo htmlspecialchars($_SESSION["full_name"] ?? "User"); ?>
                            </h6>
                            <a class="dropdown-item-modern" href="account.php">
                                <i class="fas fa-user-edit mr-2"></i> My Profile
                            </a>
                            <a class="dropdown-item-modern" href="change_password.php">
                                <i class="fas fa-key mr-2"></i> Change Password
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item-modern text-danger" href="logout.php">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
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

<script>
// Initialize tooltips and modern navbar functionality
$(document).ready(function(){
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Load notifications when dropdown is shown
    $('#notificationDropdown').on('show.bs.dropdown', function() {
        loadNotifications();
    });
    
    // Handle mobile menu better
    $('.navbar-toggler-modern').click(function() {
        $(this).toggleClass('active');
    });
    
    // Close mobile menu when clicking outside
    $(document).click(function(e) {
        if (!$(e.target).closest('.navbar').length) {
            $('.navbar-collapse').removeClass('show');
            $('.navbar-toggler-modern').removeClass('active');
        }
    });
    
    // Smooth scroll for anchor links
    $('a[href^="#"]').on('click', function(event) {
        var target = $(this.getAttribute('href'));
        if( target.length ) {
            event.preventDefault();
            $('html, body').stop().animate({
                scrollTop: target.offset().top - 80
            }, 1000);
        }
    });
});

function loadNotifications() {
    $('#notificationList').html('<div class="dropdown-item-modern text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    
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
                $('#notificationList').html('<div class="dropdown-item-modern text-center text-danger">Error: ' + (data.error || 'Unknown error') + '</div>');
            }
        },
        error: function(xhr, status, error) {
            let errorMsg = 'Error loading notifications';
            
            if (status === 'timeout') {
                errorMsg = 'Request timed out';
            } else if (xhr.status === 401) {
                errorMsg = 'Session expired';
                setTimeout(() => window.location.href = 'index.php', 2000);
            } else if (xhr.status === 500) {
                errorMsg = 'Server error';
            } else if (xhr.status === 0) {
                errorMsg = 'Network error';
            }
            
            $('#notificationList').html('<div class="dropdown-item-modern text-center text-danger">' + errorMsg + '</div>');
        }
    });
}

function displayNotifications(notifications) {
    const listContainer = $('#notificationList');
    
    if (!notifications || notifications.length === 0) {
        listContainer.html('<div class="dropdown-item-modern text-center text-muted">No notifications</div>');
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
            const unreadIcon = isUnread ? '<span class="notification-icon"></span>' : '';
            
            const timeAgo = getTimeAgo(notification.created_at);
            const title = notification.title || 'Notification';
            const message = notification.message || '';
            
            html += `
                <div class="dropdown-item-modern notification-item ${unreadClass}" onclick="handleNotificationClick(${notification.notification_id}, ${notification.complaint_id})" style="cursor: pointer; border-left: 3px solid ${isUnread ? '#ff4757' : 'transparent'};">
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
        listContainer.html('<div class="dropdown-item-modern text-center text-danger">Error displaying notifications</div>');
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
            window.location.href = 'view_complaint.php?id=' + complaintId;
        },
        error: function() {
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
</script>