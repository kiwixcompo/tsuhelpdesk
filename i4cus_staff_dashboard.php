<?php
session_start();

// Only allow i4Cus Staff (role_id = 5)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 5){
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

// Base WHERE conditions
$where = ["is_i4cus = 1"];
if ($search_id) {
    $where[] = "student_id LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%'";
    // When searching by student ID, include all complaints regardless of status
} else if ($filter_status) {
    // Only apply status filter when not searching by student ID
    $where[] = "status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
} else {
    // By default, only show non-treated complaints unless explicitly filtered
    $where[] = "status != 'Treated'";
}

// Add date filter if provided
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

// Fetch paginated active complaints with lodger name
$sql_active = "SELECT c.*, u.full_name as lodged_by_name 
               FROM complaints c 
               LEFT JOIN users u ON c.lodged_by = u.user_id 
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
$sql_archived = "SELECT c.*, u.full_name as lodged_by_name 
                FROM complaints c 
                LEFT JOIN users u ON c.lodged_by = u.user_id 
                $where_clause_archived 
                ORDER BY c.created_at DESC";
$result_archived = mysqli_query($conn, $sql_archived);
$archived_complaints = [];
while($row = mysqli_fetch_assoc($result_archived)){
    $archived_complaints[] = $row;
}

// Helper function to normalize image paths
function getImagePath($image) {
    $image = trim($image);
    if ($image === '') return '';
    
    // Extract just the filename if it contains a path
    if (strpos($image, '/') !== false) {
        $image = basename($image);
    }
    
    // Return the path to public_image.php with the encoded filename
    return 'public_image.php?img=' . urlencode($image);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>i4Cus Staff Dashboard - <?php echo htmlspecialchars($app_name); ?></title>
    
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
    <style>
        .app-logo {
            height: 30px;
            margin-right: 10px;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <?php 
    // Set page variables for dashboard header
    $page_title = 'i4Cus Staff Dashboard';
    $page_subtitle = 'Manage i4Cus complaints and technical issues';
    $page_icon = 'fas fa-laptop-code';
    $breadcrumb_items = [
        ['title' => 'i4Cus Management', 'url' => '#']
    ];
    
    include 'includes/navbar.php'; 
    include 'includes/dashboard_header.php';
    ?>
    <div class="container main-content">

        <!-- Forwarded Student Result Verification Complaints -->
        <?php
        $fwd_student_i4 = [];
        $fwd_sc_col_i4 = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'forwarded_to'");
        if ($fwd_sc_col_i4 && mysqli_num_rows($fwd_sc_col_i4) > 0) {
            $my_uid_i4 = (int)$_SESSION['user_id'];
            $fwd_sc_sql_i4 = "SELECT sc.*, CONCAT(s.first_name,' ',s.last_name) AS student_name,
                                     s.registration_number, s.email
                              FROM student_complaints sc
                              JOIN students s ON sc.student_id = s.student_id
                              WHERE sc.forwarded_to = ?
                              ORDER BY sc.created_at DESC";
            if ($fsc_i4 = mysqli_prepare($conn, $fwd_sc_sql_i4)) {
                mysqli_stmt_bind_param($fsc_i4, 'i', $my_uid_i4);
                mysqli_stmt_execute($fsc_i4);
                $fwd_student_i4 = mysqli_fetch_all(mysqli_stmt_get_result($fsc_i4), MYSQLI_ASSOC);
                mysqli_stmt_close($fsc_i4);
            }
        }
        ?>
        <?php if (!empty($fwd_student_i4)): ?>
        <div class="card mb-4 border-info">
            <div class="card-header" style="background:#d1ecf1;color:#0c5460">
                <h5 class="mb-0">
                    <i class="fas fa-graduation-cap mr-2"></i>
                    Student Complaints Forwarded to You
                    <span class="badge badge-info ml-2"><?php echo count($fwd_student_i4); ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Course</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fwd_student_i4 as $fsc_row):
                            $sc_colors = ['Pending'=>'warning','Under Review'=>'info','Resolved'=>'success','Rejected'=>'danger'];
                            $sc_bc = $sc_colors[$fsc_row['status']] ?? 'secondary';
                        ?>
                            <tr>
                                <td><?php echo $fsc_row['complaint_id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($fsc_row['student_name']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($fsc_row['registration_number']); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($fsc_row['course_code']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($fsc_row['course_title']); ?></small>
                                </td>
                                <td><span class="badge badge-secondary"><?php echo htmlspecialchars($fsc_row['complaint_type']); ?></span></td>
                                <td><span class="badge badge-<?php echo $sc_bc; ?>"><?php echo htmlspecialchars($fsc_row['status']); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($fsc_row['created_at'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info"
                                            data-toggle="modal"
                                            data-target="#fwdScI4Modal<?php echo $fsc_row['complaint_id']; ?>">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <div class="modal fade" id="fwdScI4Modal<?php echo $fsc_row['complaint_id']; ?>" tabindex="-1">
                                <div class="modal-dialog"><div class="modal-content">
                                    <div class="modal-header" style="background:#d1ecf1;color:#0c5460">
                                        <h5 class="modal-title"><i class="fas fa-graduation-cap mr-2"></i>Student Complaint #<?php echo $fsc_row['complaint_id']; ?></h5>
                                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    </div>
                                    <div class="modal-body">
                                        <p><strong>Student:</strong> <?php echo htmlspecialchars($fsc_row['student_name']); ?> (<?php echo htmlspecialchars($fsc_row['registration_number']); ?>)</p>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($fsc_row['email']); ?></p>
                                        <p><strong>Course:</strong> <?php echo htmlspecialchars($fsc_row['course_code'] . ' — ' . $fsc_row['course_title']); ?></p>
                                        <p><strong>Type:</strong> <?php echo htmlspecialchars($fsc_row['complaint_type']); ?></p>
                                        <p><strong>Status:</strong> <span class="badge badge-<?php echo $sc_bc; ?>"><?php echo htmlspecialchars($fsc_row['status']); ?></span></p>
                                        <p><strong>Submitted:</strong> <?php echo date('M d, Y H:i', strtotime($fsc_row['created_at'])); ?></p>
                                        <?php if (!empty($fsc_row['description'])): ?>
                                            <hr><p><strong>Description:</strong></p>
                                            <p class="text-muted"><?php echo nl2br(htmlspecialchars($fsc_row['description'])); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($fsc_row['admin_response'])): ?>
                                            <hr><div class="alert alert-success"><strong>Admin Response:</strong><br><?php echo nl2br(htmlspecialchars($fsc_row['admin_response'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                    </div>
                                </div></div>
                            </div>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Search & Filter i4Cus Complaints</h4>
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
                    <button type="submit" class="btn btn-info">Search/Filter</button>
                </form>
                <ul class="nav nav-tabs mb-3" id="complaintTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="active-tab" data-toggle="tab" href="#active" role="tab">Active Complaints</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="archive-tab" data-toggle="tab" href="#archive" role="tab">Archive</a>
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
                                            <th>Student ID</th>
                                            <th>Department</th>
                                            <th>Staff Name</th>
                                            <th>Lodged By</th>
                                            <th style="width: 30%;">Complaint</th>
                                            <th>Status</th>
                                            <th>Date Lodged</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($active_complaints as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['department_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['staff_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['lodged_by_name'] ?? ''); ?></td>
                                            <td style="width: 30%;"><?php echo htmlspecialchars($row['complaint_text'] ?? ''); ?>
<?php if (!empty($row['image_path'])): ?>
    <div class="mt-2 d-flex flex-wrap">
        <?php foreach(explode(",", $row['image_path']) as $img): ?>
            <div class="mr-2 mb-2">
                <a href="<?php echo getImagePath($img); ?>" target="_blank">
                    <img src="<?php echo htmlspecialchars(getImagePath($img)); ?>" class="img-thumbnail" alt="Complaint Image" style="max-height: 80px; width: 80px; object-fit: cover;">
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</td>
                                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                            <td>
                                                <a href="view_complaint.php?id=<?php echo $row['complaint_id']; ?>&i4cus=1" class="btn btn-sm btn-info">View/Treat</a>
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
                                            <a class="page-link" href="?page=<?php echo $i; ?>&search_id=<?php echo urlencode($search_id); ?>&filter_status=<?php echo urlencode($filter_status); ?>"><?php echo $i; ?></a>
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
                                No archived i4Cus complaints found.
                            </div>
                        <?php else: ?>
                            <h5 class="mb-3 text-success">Treated i4Cus Complaints</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Student ID</th>
                                            <th>Department</th>
                                            <th>Staff Name</th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Date Lodged</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach($archived_complaints as $row): ?>
                                        <tr class="table-success">
                                            <td><?php echo $row['complaint_id']; ?></td>
                                            <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['staff_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['complaint_text']); ?></td>
                                            <td><span class="badge badge-success">Treated</span></td>
                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                            <td>
                                                <a href="view_complaint.php?id=<?php echo $row['complaint_id']; ?>&i4cus=1" class="btn btn-sm btn-info">View</a>
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
        
        <!-- Complaint Calendar at the bottom of the page -->
        <div class="row mt-4 mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <?php echo generateComplaintCalendar($conn, $_SESSION["role_id"], " AND is_i4cus = 1"); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <!-- Clear date filter button -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check if we have a date filter active
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('filter_date')) {
            // Add a clear filter button
            const clearBtn = document.createElement('button');
            clearBtn.className = 'btn btn-secondary ml-2';
            clearBtn.innerText = 'Clear Date Filter';
            clearBtn.onclick = function(e) {
                e.preventDefault();
                // Remove filter_date param and redirect
                urlParams.delete('filter_date');
                window.location.href = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            };
            document.querySelector('form.form-inline').appendChild(clearBtn);
        }
    });
    </script>
    <script src="assets/js/auto_refresh_complaints.js"></script>
    <script>
    $(function() {
        var userId = <?php echo $_SESSION["user_id"]; ?>;
        var userRoleId = <?php echo $_SESSION["role_id"]; ?>;
        function renderComplaint(complaint, userId, userRoleId) {
            let imagesHtml = '';
            let imgCount = 0;
            let images = [];
            if (complaint.image_path && complaint.image_path !== '0') {
                images = complaint.image_path.split(',').map(s => s.trim()).filter(Boolean);
                imgCount = images.length;
            }
            if (imgCount > 0) {
                imagesHtml = '<div class="mt-2 d-flex flex-wrap">' + images.map(img => {
                    let directPath = 'public_image.php?img=' + encodeURIComponent(img.split('/').pop());
                    return `<div class=\"mr-2 mb-2\"><a href=\"${directPath}\" target=\"_blank\"><img src=\"${directPath}\" class=\"img-thumbnail\" alt=\"Complaint Image\" style=\"max-height: 80px; width: 80px; object-fit: cover;\"></a></div>`;
                }).join('') + '</div>';
            }
            return `<tr class=\"new-complaint\"><td>${complaint.student_id || ''}</td><td>${complaint.department_name || ''}</td><td>${complaint.staff_name || ''}</td><td>${complaint.lodged_by_name || ''}</td><td style=\"width: 30%;\">${complaint.complaint_text || ''}${imagesHtml}</td><td>${complaint.status}</td><td>${complaint.created_at ? complaint.created_at.substr(0,10) : ''}</td><td><a href=\"view_complaint.php?id=${complaint.complaint_id}&i4cus=1\" class=\"btn btn-sm btn-info\">View/Treat</a></td></tr>`;
        }
        function getLastComplaintId() {
            let first = $('#active table tbody tr').first();
            let idMatch = first.find('a.btn-info').attr('href');
            if (idMatch) {
                let match = idMatch.match(/id=(\d+)/);
                if (match) return parseInt(match[1]);
            }
            return 0;
        }
        autoRefreshComplaints({
            container: '#active table tbody',
            afterSelector: 'tr:first',
            getLastId: getLastComplaintId,
            renderComplaint: renderComplaint,
            userId: userId,
            userRoleId: userRoleId
        });
    });
    </script>
</body>
</html>
