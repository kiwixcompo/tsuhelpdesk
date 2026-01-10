<?php
session_start();
// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}
require_once "config.php";

// Fetch app settings for header use
$app_name = 'TSU ICT Complaint Desk'; // Default value
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

// Fetch user's super admin status if not set in session
if(!isset($_SESSION["is_super_admin"])){
    $sql = "SELECT is_super_admin FROM users WHERE user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)){
                $_SESSION["is_super_admin"] = $row["is_super_admin"];
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Get user's full name
$sql = "SELECT full_name FROM users WHERE user_id = ?";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            $_SESSION["full_name"] = $row["full_name"];
        }
    }
    mysqli_stmt_close($stmt);
}

// Get grouping parameter
$group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'staff';
$time_filter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';

// Build date conditions based on time filter
$date_condition = "";
switch($time_filter) {
    case 'today':
        $date_condition = "AND DATE(c.created_at) = CURDATE()";
        break;
    case 'week':
        $date_condition = "AND YEARWEEK(c.created_at) = YEARWEEK(CURDATE())";
        break;
    case 'month':
        $date_condition = "AND MONTH(c.created_at) = MONTH(CURDATE()) AND YEAR(c.created_at) = YEAR(CURDATE())";
        break;
    default:
        $date_condition = "";
}

// Build query based on grouping
$archived_complaints = [];
$user_condition = "";

switch($group_by) {
    case 'staff':
        $sql = "SELECT 
                u.user_id,
                u.full_name,
                COUNT(*) as total_complaints,
                SUM(CASE WHEN DATE(c.created_at) = CURDATE() THEN 1 ELSE 0 END) as today_count,
                SUM(CASE WHEN YEARWEEK(c.created_at) = YEARWEEK(CURDATE()) THEN 1 ELSE 0 END) as week_count,
                SUM(CASE WHEN MONTH(c.created_at) = MONTH(CURDATE()) AND YEAR(c.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as month_count,
                GROUP_CONCAT(c.complaint_id) as complaint_ids
                FROM complaints c
                JOIN users u ON c.lodged_by = u.user_id
                WHERE c.status = 'Treated' $date_condition
                GROUP BY u.user_id, u.full_name
                ORDER BY total_complaints DESC";
        break;
    case 'date':
        $sql = "SELECT 
                DATE(c.created_at) as complaint_date,
                COUNT(*) as total_complaints,
                GROUP_CONCAT(DISTINCT c.complaint_id) as complaint_ids
                FROM complaints c
                WHERE c.status = 'Treated' $date_condition
                GROUP BY DATE(c.created_at)
                ORDER BY complaint_date DESC";
        break;
    case 'handler':
        if($_SESSION["role_id"] == 1) {
            $sql = "SELECT 
                    u.user_id,
                    u.full_name,
                    COUNT(*) as total_complaints,
                    SUM(CASE WHEN DATE(c.created_at) = CURDATE() THEN 1 ELSE 0 END) as today_count,
                    SUM(CASE WHEN YEARWEEK(c.created_at) = YEARWEEK(CURDATE()) THEN 1 ELSE 0 END) as week_count,
                    SUM(CASE WHEN MONTH(c.created_at) = MONTH(CURDATE()) AND YEAR(c.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as month_count,
                    GROUP_CONCAT(c.complaint_id) as complaint_ids
                    FROM complaints c
                    JOIN users u ON c.handled_by = u.user_id
                    WHERE c.status = 'Treated' $date_condition
                    GROUP BY u.user_id, u.full_name
                    ORDER BY total_complaints DESC";
        } else {
            // For staff, show their complaints grouped by who handled them
            $sql = "SELECT 
                    u.user_id,
                    u.full_name,
                    COUNT(*) as total_complaints,
                    SUM(CASE WHEN DATE(c.created_at) = CURDATE() THEN 1 ELSE 0 END) as today_count,
                    SUM(CASE WHEN YEARWEEK(c.created_at) = YEARWEEK(CURDATE()) THEN 1 ELSE 0 END) as week_count,
                    SUM(CASE WHEN MONTH(c.created_at) = MONTH(CURDATE()) AND YEAR(c.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as month_count,
                    GROUP_CONCAT(c.complaint_id) as complaint_ids
                    FROM complaints c
                    JOIN users u ON c.handled_by = u.user_id
                    WHERE c.status = 'Treated' $date_condition
                    GROUP BY u.user_id, u.full_name
                    ORDER BY total_complaints DESC";
        }
        break;
}

$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        $archived_complaints[] = $row;
    }
}

// Get complaint details if a group is selected
$selected_group = isset($_GET['group_id']) ? $_GET['group_id'] : null;
$complaints_detail = [];

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$total_complaints = 0;

if($selected_group) {
    // Get complaint IDs from the selected group
    $complaint_ids = explode(',', $selected_group);
    $placeholders = str_repeat('?,', count($complaint_ids) - 1) . '?';
    
    // Count total complaints for pagination
    $count_sql = "SELECT COUNT(*) as total FROM complaints WHERE complaint_id IN ($placeholders) AND status = 'Treated'";
    if($stmt = mysqli_prepare($conn, $count_sql)){
        $types = str_repeat('i', count($complaint_ids));
        mysqli_stmt_bind_param($stmt, $types, ...$complaint_ids);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)){
                $total_complaints = $row['total'];
            }
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get complaint details
    $sql = "SELECT c.*, u1.full_name as lodged_by_name, u2.full_name as handler_name 
            FROM complaints c 
            LEFT JOIN users u1 ON c.lodged_by = u1.user_id 
            LEFT JOIN users u2 ON c.handled_by = u2.user_id 
            WHERE c.complaint_id IN ($placeholders) AND c.status = 'Treated' 
            ORDER BY c.created_at DESC 
            LIMIT $per_page OFFSET $offset";
    if($stmt = mysqli_prepare($conn, $sql)){
        $types = str_repeat('i', count($complaint_ids));
        mysqli_stmt_bind_param($stmt, $types, ...$complaint_ids);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            while($row = mysqli_fetch_assoc($result)){
                $complaints_detail[] = $row;
            }
        }
        mysqli_stmt_close($stmt);
    }
}

// Count unread messages
$unread_count = 0;
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archives - <?php echo htmlspecialchars($app_name); ?></title>
    
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
            color: white;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <!-- Dynamic App Branding in Navbar -->
            <div class="app-branding navbar-brand">
                <?php if($app_logo && file_exists($app_logo)): ?>
                    <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="app-logo">
                <?php endif; ?>
                <span class="app-name"><?php echo htmlspecialchars($app_name); ?></span>
            </div>
            
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <?php if($_SESSION["role_id"] == 5): ?>
                            <a class="nav-link" href="i4cus_staff_dashboard.php">Dashboard</a>
                        <?php elseif($_SESSION["role_id"] == 3): ?>
                            <a class="nav-link" href="director_dashboard.php">Dashboard</a>
                        <?php elseif($_SESSION["role_id"] == 4): ?>
                            <a class="nav-link" href="dvc_dashboard.php">Dashboard</a>
                        <?php else: ?>
                            <a class="nav-link" href="dashboard.php">Dashboard</a>
                        <?php endif; ?>
                    </li>
                    <?php if($_SESSION["role_id"] == 1): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="admin.php">Admin Panel</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">Users</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
                            Messages
                            <?php if($unread_count > 0): ?>
                                <span class="badge badge-light"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item active">
                        <a class="nav-link" href="archives.php">Archives</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="account.php">
                            <i class="fas fa-user-circle"></i>
                            <?php echo htmlspecialchars($_SESSION["full_name"]); ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <h4 class="mb-2 mb-md-0">Archived Complaints</h4>
                            <div class="d-flex flex-wrap gap-2">
                                <div class="btn-group mb-2" role="group">
                                    <a href="?group_by=staff<?php echo $time_filter != 'all' ? '&time_filter='.$time_filter : ''; ?>" 
                                       class="btn btn-<?php echo $group_by == 'staff' ? 'primary' : 'secondary'; ?> btn-sm">
                                        Group by Staff
                                    </a>
                                    <a href="?group_by=date<?php echo $time_filter != 'all' ? '&time_filter='.$time_filter : ''; ?>" 
                                       class="btn btn-<?php echo $group_by == 'date' ? 'primary' : 'secondary'; ?> btn-sm">
                                        Group by Date
                                    </a>
                                    <a href="?group_by=handler<?php echo $time_filter != 'all' ? '&time_filter='.$time_filter : ''; ?>" 
                                       class="btn btn-<?php echo $group_by == 'handler' ? 'primary' : 'secondary'; ?> btn-sm">
                                        Group by Handler
                                    </a>
                                </div>
                                <div class="btn-group mb-2" role="group">
                                    <a href="?group_by=<?php echo $group_by; ?>" 
                                       class="btn btn-<?php echo $time_filter == 'all' ? 'primary' : 'secondary'; ?> btn-sm">
                                        All Time
                                    </a>
                                    <a href="?group_by=<?php echo $group_by; ?>&time_filter=today" 
                                       class="btn btn-<?php echo $time_filter == 'today' ? 'primary' : 'secondary'; ?> btn-sm">
                                        Today
                                    </a>
                                    <a href="?group_by=<?php echo $group_by; ?>&time_filter=week" 
                                       class="btn btn-<?php echo $time_filter == 'week' ? 'primary' : 'secondary'; ?> btn-sm">
                                        This Week
                                    </a>
                                    <a href="?group_by=<?php echo $group_by; ?>&time_filter=month" 
                                       class="btn btn-<?php echo $time_filter == 'month' ? 'primary' : 'secondary'; ?> btn-sm">
                                        This Month
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($archived_complaints)): ?>
                            <div class="list-group">
                                <?php foreach($archived_complaints as $group): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <?php if($group_by == 'date'): ?>
                                                    <h5 class="mb-1"><?php echo date('F d, Y', strtotime($group['complaint_date'])); ?></h5>
                                                    <span class="badge badge-primary">Total: <?php echo $group['total_complaints']; ?></span>
                                                <?php else: ?>
                                                    <h5 class="mb-1"><?php echo htmlspecialchars($group['full_name']); ?></h5>
                                                    <div>
                                                        <span class="badge badge-primary">Total: <?php echo $group['total_complaints']; ?></span>
                                                        <?php if($_SESSION["role_id"] == 1 || $_SESSION["is_super_admin"]): ?>
                                                        <span class="badge badge-info">Today: <?php echo $group['today_count']; ?></span>
                                                        <span class="badge badge-success">This Week: <?php echo $group['week_count']; ?></span>
                                                        <span class="badge badge-warning">This Month: <?php echo $group['month_count']; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <a href="?group_by=<?php echo $group_by; ?>&time_filter=<?php echo $time_filter; ?>&group_id=<?php echo urlencode($group['complaint_ids']); ?>" 
                                               class="btn btn-sm btn-info">
                                                <i class="fas fa-eye"></i> View Details
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-archive fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No archived complaints found for the selected criteria.</p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(!empty($complaints_detail)): ?>
                            <div class="mt-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5>Complaint Details</h5>
                                    <a href="?group_by=<?php echo $group_by; ?>&time_filter=<?php echo $time_filter; ?>" 
                                       class="btn btn-sm btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Back to Groups
                                    </a>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Student ID</th>
                                                <th>Complaint</th>
                                                <th>Lodged By</th>
                                                <th>Handled By</th>
                                                <th>Status</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($complaints_detail as $complaint): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y h:i A', strtotime($complaint['created_at'])); ?></td>
                                                    <td><?php echo htmlspecialchars($complaint['student_id']); ?></td>
                                                    <td>
                                                        <span data-toggle="tooltip" title="<?php echo htmlspecialchars($complaint['complaint_text']); ?>">
                                                            <?php echo substr(htmlspecialchars($complaint['complaint_text']), 0, 50) . (strlen($complaint['complaint_text']) > 50 ? '...' : ''); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($complaint['lodged_by_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($complaint['handler_name'] ?? 'N/A'); ?></td>
                                                    <td><span class="badge badge-success">Treated</span></td>
                                                    <td>
                                                        <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                                           class="btn btn-sm btn-info">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if($total_complaints > $per_page): ?>
                                <nav aria-label="Complaints pagination">
                                    <ul class="pagination justify-content-center">
                                        <?php
                                        $total_pages = ceil($total_complaints / $per_page);
                                        $current_url = "?group_by={$group_by}&time_filter={$time_filter}&group_id=" . urlencode($selected_group);
                                        
                                        // Previous button
                                        if($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?php echo $current_url; ?>&page=<?php echo ($page - 1); ?>">Previous</a>
                                            </li>
                                        <?php endif;
                                        
                                        // Page numbers
                                        for($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo $current_url; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor;
                                        
                                        // Next button
                                        if($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?php echo $current_url; ?>&page=<?php echo ($page + 1); ?>">Next</a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                                <?php endif; ?>
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
        $(document).ready(function() {
            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();
            
            // Auto-hide alerts after 5 seconds
            $('.alert').delay(5000).fadeOut();
        });
    </script>
</body>
</html>