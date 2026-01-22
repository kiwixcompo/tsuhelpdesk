<?php
session_start();

// Only allow Deputy Director ICT (role_id = 8)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 8){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";
require_once "calendar_helper.php";

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

// Initialize variables
$search_id = isset($_GET['search_id']) ? trim($_GET['search_id']) : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Base WHERE conditions - only show i4Cus complaints
$where = ["is_i4cus = 1"];
if ($search_id) {
    $where[] = "student_id LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%'";
} 
if ($filter_status) {
    $where[] = "status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
} 
if ($filter_date) {
    $where[] = "DATE(created_at) = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
}

// Separate active and archived complaints
$where_active = $where;
$where_archived = $where;
$where_active[] = "status != 'Treated'";
$where_archived[] = "status = 'Treated'";

// Build WHERE clauses
$where_clause_active = $where_active ? 'WHERE ' . implode(' AND ', $where_active) : '';
$where_clause_archived = $where_archived ? 'WHERE ' . implode(' AND ', $where_archived) : '';

// Fetch paginated active i4Cus complaints with lodger name
$sql_active = "SELECT c.*, u.full_name as lodged_by_name, h.full_name as handler_name
               FROM complaints c 
               LEFT JOIN users u ON c.lodged_by = u.user_id 
               LEFT JOIN users h ON c.handled_by = h.user_id
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
$sql_archived = "SELECT c.*, u.full_name as lodged_by_name, h.full_name as handler_name
                FROM complaints c 
                LEFT JOIN users u ON c.lodged_by = u.user_id 
                LEFT JOIN users h ON c.handled_by = h.user_id
                $where_clause_archived 
                ORDER BY c.created_at DESC";
$result_archived = mysqli_query($conn, $sql_archived);
$archived_complaints = [];
while($row = mysqli_fetch_assoc($result_archived)){
    $archived_complaints[] = $row;
}

// Get statistics for i4Cus complaints
$total_i4cus = 0;
$treated_i4cus = 0;
$pending_i4cus = 0;

$sql = "SELECT COUNT(*) as total FROM complaints WHERE is_i4cus = 1";
$result = mysqli_query($conn, $sql);
if($row = mysqli_fetch_assoc($result)){
    $total_i4cus = $row['total'];
}

$sql = "SELECT COUNT(*) as total FROM complaints WHERE is_i4cus = 1 AND status = 'Treated'";
$result = mysqli_query($conn, $sql);
if($row = mysqli_fetch_assoc($result)){
    $treated_i4cus = $row['total'];
}

$sql = "SELECT COUNT(*) as total FROM complaints WHERE is_i4cus = 1 AND status != 'Treated'";
$result = mysqli_query($conn, $sql);
if($row = mysqli_fetch_assoc($result)){
    $pending_i4cus = $row['total'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deputy Director ICT Dashboard - <?php echo htmlspecialchars($app_name); ?></title>
    
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
</head>
<body>
    <?php 
    // Set page variables for dashboard header
    $page_title = 'Deputy Director ICT Dashboard';
    $page_subtitle = 'Monitor and manage i4Cus communications and follow-ups';
    $page_icon = 'fas fa-user-cog';
    $breadcrumb_items = [
        ['title' => 'i4Cus Management', 'url' => '#']
    ];
    
    include 'includes/navbar.php'; 
    include 'includes/dashboard_header.php';
    ?>

    <div class="container main-content">
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-white bg-info mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Total i4Cus Complaints</h5>
                        <p class="card-text display-4"><?php echo $total_i4cus; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-success mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Treated Complaints</h5>
                        <p class="card-text display-4"><?php echo $treated_i4cus; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-warning mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Pending Complaints</h5>
                        <p class="card-text display-4"><?php echo $pending_i4cus; ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">i4Cus Complaints Management</h4>
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
                    <div class="form-group mr-2">
                        <input type="date" name="filter_date" class="form-control" value="<?php echo htmlspecialchars($filter_date ?? ''); ?>">
                    </div>
                    <button type="submit" class="btn btn-info">Search/Filter</button>
                    <a href="deputy_director_dashboard.php" class="btn btn-secondary ml-2">Clear</a>
                </form>
                
                <ul class="nav nav-tabs mb-3" id="complaintTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="active-tab" data-toggle="tab" href="#active" role="tab">Active Complaints</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="archive-tab" data-toggle="tab" href="#archive" role="tab">Treated Archive</a>
                    </li>
                </ul>
                
                <div class="tab-content" id="complaintTabsContent">
                    <div class="tab-pane fade show active" id="active" role="tabpanel">
                        <?php if (empty($active_complaints)): ?>
                            <div class="alert alert-info">
                                No active i4Cus complaints found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Student ID</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Lodged By</th>
                                            <th>Handler</th>
                                            <th>Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($active_complaints as $row): ?>
                                        <tr>
                                            <td><?php echo $row['complaint_id']; ?></td>
                                            <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars(substr($row['complaint_text'] ?? '', 0, 50)) . '...'; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $row['status'] == 'Treated' ? 'success' : ($row['status'] == 'Pending' ? 'warning' : 'info'); ?>">
                                                    <?php echo htmlspecialchars($row['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['lodged_by_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['handler_name'] ?? 'Unassigned'); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                            <td>
                                                <a href="view_complaint.php?id=<?php echo $row['complaint_id']; ?>" class="btn btn-sm btn-info">View</a>
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
                                            <a class="page-link" href="?page=<?php echo $i; ?>&search_id=<?php echo urlencode($search_id); ?>&filter_status=<?php echo urlencode($filter_status); ?>&filter_date=<?php echo urlencode($filter_date); ?>"><?php echo $i; ?></a>
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
                                No treated i4Cus complaints found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Student ID</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Lodged By</th>
                                            <th>Handler</th>
                                            <th>Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($archived_complaints as $row): ?>
                                        <tr class="table-success">
                                            <td><?php echo $row['complaint_id']; ?></td>
                                            <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars(substr($row['complaint_text'] ?? '', 0, 50)) . '...'; ?></td>
                                            <td><span class="badge badge-success">Treated</span></td>
                                            <td><?php echo htmlspecialchars($row['lodged_by_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['handler_name'] ?? 'Unassigned'); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                            <td>
                                                <a href="view_complaint.php?id=<?php echo $row['complaint_id']; ?>" class="btn btn-sm btn-info">View</a>
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
        
        <!-- Report Generation -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0">Generate i4Cus Reports</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <button class="btn btn-success btn-block" onclick="generateReport('pending')">
                            <i class="fas fa-file-excel"></i> Export Pending Complaints
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button class="btn btn-info btn-block" onclick="generateReport('treated')">
                            <i class="fas fa-file-excel"></i> Export Treated Complaints
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
    function generateReport(type) {
        const params = new URLSearchParams();
        params.append('type', type);
        params.append('is_i4cus', '1');
        
        // Add current filters
        const searchId = '<?php echo addslashes($search_id); ?>';
        const filterStatus = '<?php echo addslashes($filter_status); ?>';
        const filterDate = '<?php echo addslashes($filter_date); ?>';
        
        if (searchId) params.append('search_id', searchId);
        if (filterStatus) params.append('filter_status', filterStatus);
        if (filterDate) params.append('filter_date', filterDate);
        
        window.open('api/export_complaints.php?' + params.toString(), '_blank');
    }
    </script>
</body>
</html>