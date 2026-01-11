<?php
// Dashboard Header Component
// Usage: include this file after setting $page_title, $page_subtitle, and $page_icon variables

// Set default values if not provided
$page_title = $page_title ?? 'Dashboard';
$page_subtitle = $page_subtitle ?? 'Welcome to your dashboard';
$page_icon = $page_icon ?? 'fas fa-tachometer-alt';
$show_breadcrumb = $show_breadcrumb ?? true;
$breadcrumb_items = $breadcrumb_items ?? [];

// Get current time for greeting
$current_hour = date('H');
if ($current_hour < 12) {
    $greeting = 'Good Morning';
    $greeting_icon = 'fas fa-sun';
} elseif ($current_hour < 17) {
    $greeting = 'Good Afternoon';
    $greeting_icon = 'fas fa-sun';
} else {
    $greeting = 'Good Evening';
    $greeting_icon = 'fas fa-moon';
}

// Get user role name
$role_names = [
    1 => 'Administrator',
    2 => 'Staff Member',
    3 => 'Director',
    4 => 'Deputy Vice Chancellor',
    5 => 'i4Cus Staff',
    6 => 'Payment Administrator',
    7 => 'Department Staff'
];
$user_role = $role_names[$_SESSION["role_id"]] ?? 'User';
?>

<style>
/* Prevent horizontal overflow */
html, body {
    overflow-x: hidden;
    max-width: 100%;
}

.dashboard-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    padding: 2rem 0;
    margin-bottom: 2rem;
    border-radius: 0 0 20px 20px;
    position: relative;
    overflow: hidden;
    width: 100%;
    margin-left: calc(-50vw + 50%);
    margin-right: calc(-50vw + 50%);
}

.dashboard-header::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 200px;
    height: 200px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 50%;
    transform: translate(50px, -50px);
}

.dashboard-header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 150px;
    height: 150px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%;
    transform: translate(-50px, 50px);
}

.dashboard-header-content {
    position: relative;
    z-index: 2;
    padding: 0 15px;
}

.page-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.page-title-icon {
    background: rgba(255, 255, 255, 0.2);
    padding: 1rem;
    border-radius: 15px;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 60px;
    min-height: 60px;
}

.page-subtitle {
    font-size: 1.1rem;
    opacity: 0.9;
    margin-bottom: 1rem;
}

.greeting-section {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    opacity: 0.8;
    margin-bottom: 1.5rem;
}

.user-info-header {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 1rem 1.5rem;
    display: inline-flex;
    align-items: center;
    gap: 1rem;
}

.user-avatar-header {
    width: 50px;
    height: 50px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.user-details {
    display: flex;
    flex-direction: column;
}

.user-name-header {
    font-weight: 600;
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 250px;
}

.user-role-header {
    font-size: 0.9rem;
    opacity: 0.8;
}

.breadcrumb-modern {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    padding: 0.75rem 1rem;
    margin-top: 1.5rem;
}

.breadcrumb-modern .breadcrumb {
    background: none;
    margin-bottom: 0;
    padding: 0;
}

.breadcrumb-modern .breadcrumb-item {
    color: rgba(255, 255, 255, 0.8);
}

.breadcrumb-modern .breadcrumb-item.active {
    color: white;
    font-weight: 600;
}

.breadcrumb-modern .breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    color: rgba(255, 255, 255, 0.6);
}

.breadcrumb-modern .breadcrumb-item a {
    color: rgba(255, 255, 255, 0.9);
    text-decoration: none;
    transition: color 0.3s ease;
}

.breadcrumb-modern .breadcrumb-item a:hover {
    color: white;
}

.quick-stats {
    display: flex;
    gap: 1rem;
    margin-top: 1.5rem;
    flex-wrap: wrap;
}

.quick-stat-item {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border-radius: 12px;
    padding: 1rem;
    min-width: 120px;
    text-align: center;
    transition: transform 0.3s ease;
}

.quick-stat-item:hover {
    transform: translateY(-2px);
}

.quick-stat-number {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.quick-stat-label {
    font-size: 0.85rem;
    opacity: 0.8;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .dashboard-header {
        padding: 1.5rem 0;
        margin-bottom: 1.5rem;
        margin-left: calc(-50vw + 50%);
        margin-right: calc(-50vw + 50%);
    }
    
    .page-title {
        font-size: 1.8rem;
        flex-direction: column;
        text-align: center;
        gap: 0.5rem;
    }
    
    .page-title-icon {
        min-width: 50px;
        min-height: 50px;
        font-size: 1.2rem;
    }
    
    .user-info-header {
        flex-direction: column;
        text-align: center;
        gap: 0.75rem;
    }
    
    .quick-stats {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .quick-stat-item {
        min-width: 100px;
        flex: 1;
        max-width: 120px;
    }
}

@media (max-width: 576px) {
    .dashboard-header {
        margin-left: calc(-50vw + 50%);
        margin-right: calc(-50vw + 50%);
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .page-subtitle {
        font-size: 1rem;
    }
    
    .user-info-header {
        padding: 0.75rem 1rem;
    }
    
    .user-avatar-header {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .quick-stats {
        gap: 0.5rem;
    }
    
    .quick-stat-item {
        min-width: 80px;
        padding: 0.75rem;
    }
    
    .quick-stat-number {
        font-size: 1.2rem;
    }
}
</style>

<div class="dashboard-header">
    <div class="container">
        <div class="dashboard-header-content">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <!-- Greeting -->
                    <div class="greeting-section">
                        <i class="<?php echo $greeting_icon; ?>"></i>
                        <span><?php echo $greeting; ?>, <?php echo htmlspecialchars($_SESSION["full_name"] ?? "User"); ?>!</span>
                    </div>
                    
                    <!-- Page Title -->
                    <div class="page-title">
                        <div class="page-title-icon">
                            <i class="<?php echo $page_icon; ?>"></i>
                        </div>
                        <div>
                            <div><?php echo htmlspecialchars($page_title); ?></div>
                            <?php if (!empty($page_subtitle)): ?>
                                <div class="page-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 text-lg-right text-center mt-3 mt-lg-0">
                    <!-- User Info Card -->
                    <div class="user-info-header">
                        <div class="user-avatar-header">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-details">
                            <div class="user-name-header"><?php echo htmlspecialchars($_SESSION["full_name"] ?? "User"); ?></div>
                            <div class="user-role-header"><?php echo $user_role; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Breadcrumb -->
            <?php if ($show_breadcrumb && !empty($breadcrumb_items)): ?>
                <div class="breadcrumb-modern">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item">
                                <a href="<?php echo $dashboard_file ?? 'dashboard.php'; ?>">
                                    <i class="fas fa-home mr-1"></i> Home
                                </a>
                            </li>
                            <?php foreach ($breadcrumb_items as $index => $item): ?>
                                <?php if ($index === count($breadcrumb_items) - 1): ?>
                                    <li class="breadcrumb-item active" aria-current="page">
                                        <?php echo htmlspecialchars($item['title']); ?>
                                    </li>
                                <?php else: ?>
                                    <li class="breadcrumb-item">
                                        <a href="<?php echo htmlspecialchars($item['url']); ?>">
                                            <?php echo htmlspecialchars($item['title']); ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                </div>
            <?php endif; ?>
            
            <!-- Quick Stats (if provided) -->
            <?php if (isset($quick_stats) && !empty($quick_stats)): ?>
                <div class="quick-stats">
                    <?php foreach ($quick_stats as $stat): ?>
                        <div class="quick-stat-item">
                            <div class="quick-stat-number"><?php echo htmlspecialchars($stat['number']); ?></div>
                            <div class="quick-stat-label"><?php echo htmlspecialchars($stat['label']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>