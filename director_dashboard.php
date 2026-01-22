<?php
session_start();

// Only allow director (role_id = 3)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 3){
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

// Always refresh session email and phone from the database for accuracy
$sql = "SELECT email, phone FROM users WHERE user_id = ?";
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            $_SESSION['email'] = $row['email'];
            $_SESSION['phone'] = $row['phone'];
        }
    }
    mysqli_stmt_close($stmt);
}

// Handle message sending
$message_success = $message_error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["send_message"])) {
    $recipient_id = $_POST["recipient_id"];
    $subject = trim($_POST["subject"]);
    $message_text = trim($_POST["message_text"]);
    $is_broadcast = ($recipient_id == "broadcast") ? 1 : 0;
    $recipient_id = ($is_broadcast) ? null : $recipient_id;
    if ($subject && $message_text) {
        $sql = "INSERT INTO messages (sender_id, recipient_id, subject, message_text, is_broadcast) VALUES (?, ?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "isssi", $_SESSION["user_id"], $recipient_id, $subject, $message_text, $is_broadcast);
            if (mysqli_stmt_execute($stmt)) {
                $message_success = "Message sent successfully.";
            } else {
                $message_error = "Failed to send message.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $message_error = "Subject and message are required.";
    }
}

// Date range filter
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$date_filter = '';
if ($start_date && $end_date) {
    $date_filter = " AND DATE(c.created_at) BETWEEN '" . mysqli_real_escape_string($conn, $start_date) . "' AND '" . mysqli_real_escape_string($conn, $end_date) . "'";
} elseif ($start_date) {
    $date_filter = " AND DATE(c.created_at) >= '" . mysqli_real_escape_string($conn, $start_date) . "'";
} elseif ($end_date) {
    $date_filter = " AND DATE(c.created_at) <= '" . mysqli_real_escape_string($conn, $end_date) . "'";
}

// Calculate 7 weeks ago date
$seven_weeks_ago = date('Y-m-d', strtotime('-7 weeks'));

// Fetch analytics data
$total_complaints = $treated_complaints = $pending_complaints = $staff_count = 0;
$sql = "SELECT COUNT(*) as total FROM complaints WHERE 1=1 $date_filter";
$result = mysqli_query($conn, $sql);
if($row = mysqli_fetch_assoc($result)){
    $total_complaints = $row['total'];
}
$sql = "SELECT COUNT(*) as total FROM complaints WHERE status = 'Treated' $date_filter";
$result = mysqli_query($conn, $sql);
if($row = mysqli_fetch_assoc($result)){
    $treated_complaints = $row['total'];
}
$sql = "SELECT COUNT(*) as total FROM complaints WHERE status = 'Pending' $date_filter";
$result = mysqli_query($conn, $sql);
if($row = mysqli_fetch_assoc($result)){
    $pending_complaints = $row['total'];
}
$sql = "SELECT COUNT(*) as total FROM users WHERE role_id = 2";
$result = mysqli_query($conn, $sql);
if($row = mysqli_fetch_assoc($result)){
    $staff_count = $row['total'];
}

// Complaints by staff (for chart)
$staff_complaints = [];
$staff_names = [];
$staff_counts = [];
$sql = "SELECT u.full_name, COUNT(c.complaint_id) as count FROM users u LEFT JOIN complaints c ON u.user_id = c.lodged_by WHERE u.role_id = 2 GROUP BY u.user_id, u.full_name ORDER BY count DESC";
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    $staff_complaints[] = $row;
    $staff_names[] = $row['full_name'];
    $staff_counts[] = (int)$row['count'];
}

// Fetch users for messaging
$users = [];
$sql = "SELECT user_id, full_name, username, role_id FROM users ORDER BY full_name ASC";
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    if ($row['user_id'] != $_SESSION['user_id']) { // Exclude director himself
        $users[] = $row;
    }
}

// Complaint search/filter
$search_id = isset($_GET['search_id']) ? trim($_GET['search_id']) : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_department = isset($_GET['filter_department']) ? $_GET['filter_department'] : '';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$where = [];
if ($search_id) {
    $where[] = "c.student_id LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%'";
}
if ($filter_status) {
    $where[] = "c.status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
}
if ($filter_department) {
    $where[] = "c.department = '" . mysqli_real_escape_string($conn, $filter_department) . "'";
}
if ($filter_date) {
    $where[] = "DATE(c.created_at) = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
}
if ($date_filter) {
    $where[] = substr($date_filter, 5); // Remove leading ' AND '
}
$where_clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// For main complaints table: only show complaints from last 7 weeks unless searching by student ID
$complaints = [];
$main_where = $where;
if (!$search_id) {
    $main_where[] = "DATE(c.created_at) >= '$seven_weeks_ago'";
}
$main_where_clause = $main_where ? ('WHERE ' . implode(' AND ', $main_where)) : '';
$sql = "SELECT c.*, u1.full_name AS lodged_by_name, u2.full_name AS handler_name FROM complaints c LEFT JOIN users u1 ON c.lodged_by = u1.user_id LEFT JOIN users u2 ON c.handled_by = u2.user_id $main_where_clause AND c.status != 'Treated' ORDER BY c.created_at DESC";
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    $complaints[] = $row;
}

// For archive: only show treated complaints from last 7 weeks unless searching by student ID
$archive_complaints = [];
$archive_where = $where;
$archive_where[] = "c.status = 'Treated'";
if (!$search_id) {
    $archive_where[] = "DATE(c.created_at) >= '$seven_weeks_ago'";
}
$archive_where_clause = $archive_where ? ('WHERE ' . implode(' AND ', $archive_where)) : '';
$sql = "SELECT c.*, u1.full_name AS lodged_by_name, u2.full_name AS handler_name FROM complaints c LEFT JOIN users u1 ON c.lodged_by = u1.user_id LEFT JOIN users u2 ON c.handled_by = u2.user_id $archive_where_clause ORDER BY c.created_at DESC";
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    $archive_complaints[] = $row;
}

// PAGINATION for Search & Filter Complaints
$complaints_per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $complaints_per_page;
// Count total complaints for pagination
$count_sql = "SELECT COUNT(*) as total FROM complaints c LEFT JOIN users u1 ON c.lodged_by = u1.user_id LEFT JOIN users u2 ON c.handled_by = u2.user_id $main_where_clause AND c.status != 'Treated'";
$count_result = mysqli_query($conn, $count_sql);
$total_complaints_filtered = 0;
if($count_row = mysqli_fetch_assoc($count_result)){
    $total_complaints_filtered = $count_row['total'];
}
$sql = "SELECT c.*, u1.full_name AS lodged_by_name, u2.full_name AS handler_name FROM complaints c LEFT JOIN users u1 ON c.lodged_by = u1.user_id LEFT JOIN users u2 ON c.handled_by = u2.user_id $main_where_clause AND c.status != 'Treated' ORDER BY c.created_at DESC LIMIT $complaints_per_page OFFSET $offset";
$complaints = [];
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    $complaints[] = $row;
}

// PAGINATION for Archive (Treated Complaints)
$archive_per_page = 10;
$archive_page = isset($_GET['archive_page']) ? max(1, intval($_GET['archive_page'])) : 1;
$archive_offset = ($archive_page - 1) * $archive_per_page;
// Count total archive complaints for pagination
$archive_count_sql = "SELECT COUNT(*) as total FROM complaints c LEFT JOIN users u1 ON c.lodged_by = u1.user_id LEFT JOIN users u2 ON c.handled_by = u2.user_id $archive_where_clause";
$archive_count_result = mysqli_query($conn, $archive_count_sql);
$total_archive_complaints = 0;
if($archive_count_row = mysqli_fetch_assoc($archive_count_result)){
    $total_archive_complaints = $archive_count_row['total'];
}
$sql = "SELECT c.*, u1.full_name AS lodged_by_name, u2.full_name AS handler_name FROM complaints c LEFT JOIN users u1 ON c.lodged_by = u1.user_id LEFT JOIN users u2 ON c.handled_by = u2.user_id $archive_where_clause ORDER BY c.created_at DESC LIMIT $archive_per_page OFFSET $archive_offset";
$archive_complaints = [];
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    $archive_complaints[] = $row;
}

// Handle i4cus complaint submission by director
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["submit_i4cus_complaint"]) && $_SESSION["role_id"] == 3){
    $complaint_text = trim($_POST["complaint_text"]);
    $is_urgent = isset($_POST["is_urgent"]) ? 1 : 0;
    $is_i4cus = 1;
    $lodged_by = $_SESSION["user_id"];
    // Handle image uploads
    $image_paths = array();
    if(isset($_FILES["complaint_images"])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        foreach($_FILES["complaint_images"]["tmp_name"] as $key => $tmp_name){
            if($_FILES["complaint_images"]["error"][$key] == 0){
                $filename = $_FILES["complaint_images"]["name"][$key];
                $filetype = $_FILES["complaint_images"]["type"][$key];
                $filesize = $_FILES["complaint_images"]["size"][$key];
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                if(!array_key_exists($ext, $allowed)) continue;
                $maxsize = 5 * 1024 * 1024;
                if($filesize > $maxsize) continue;
                if(in_array($filetype, $allowed)){
                    $new_filename = uniqid() . "." . $ext;
                    $target_file = $target_dir . $new_filename;
                    if(move_uploaded_file($tmp_name, $target_file)){
                        $image_paths[] = $target_file;
                    }
                }
            }
        }
    }
    if(!empty($complaint_text)){
        $image_paths_str = !empty($image_paths) ? implode(",", $image_paths) : null;
        $sql = "INSERT INTO complaints (complaint_text, is_urgent, is_i4cus, lodged_by, image_path) VALUES (?, ?, ?, ?, ?)";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "siiss", $complaint_text, $is_urgent, $is_i4cus, $lodged_by, $image_paths_str);
            if(mysqli_stmt_execute($stmt)){
                $success_message_i4cus = "i4Cus complaint submitted successfully.";
            } else{
                $error_message_i4cus = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message_i4cus = "Please fill all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Director Dashboard - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .clickable-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            cursor: pointer;
        }
        .clickable-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .clickable-card a {
            text-decoration: none;
            color: inherit;
        }
        .clickable-card a:hover {
            text-decoration: none;
            color: inherit;
        }
        .complaint-row:hover {
            background-color: #f8f9fa !important;
            transform: scale(1.01);
            transition: all 0.2s ease-in-out;
        }
        .complaint-row {
            transition: all 0.2s ease-in-out;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <?php
    // Set up director dashboard header variables
    $page_title = 'Director Dashboard';
    $page_subtitle = 'Oversee system operations and manage complaints';
    $page_icon = 'fas fa-user-tie';
    $show_breadcrumb = false;
    
    // Set up quick stats for director
    $quick_stats = [
        ['number' => $total_complaints, 'label' => 'Total Complaints'],
        ['number' => $treated_complaints, 'label' => 'Treated'],
        ['number' => $pending_complaints, 'label' => 'Pending'],
        ['number' => $staff_count, 'label' => 'Staff Members']
    ];
    
    include 'includes/dashboard_header.php';
    ?>

    <div class="container-fluid">
        <h2 class="mb-4">Welcome, Director <?php echo htmlspecialchars($_SESSION["full_name"] ?? ""); ?></h2>
        <?php if((empty($_SESSION['email']) || empty($_SESSION['phone'])) && isset($_SESSION['user_id'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Reminder:</strong> Please update your email and phone number in your <a href="account.php" class="alert-link">profile</a> for password recovery and important notifications.
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        

        <form method="get" class="form-inline mb-4">
            <div class="form-group mr-2">
                <label for="start_date" class="mr-2">Start Date</label>
                <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="form-group mr-2">
                <label for="end_date" class="mr-2">End Date</label>
                <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <button type="submit" class="btn btn-info">Apply Date Filter</button>
            <a href="director_dashboard.php" class="btn btn-secondary ml-2">Reset</a>
        </form>
        <div class="row mb-4">
            <div class="col-md-3">
                <a href="?filter_status=" style="text-decoration: none; color: inherit;">
                    <div class="card text-white bg-info mb-3 clickable-card">
                        <div class="card-body">
                            <h5 class="card-title">Total Complaints</h5>
                            <p class="card-text display-4"><?php echo $total_complaints; ?></p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="?filter_status=Treated" style="text-decoration: none; color: inherit;">
                    <div class="card text-white bg-success mb-3 clickable-card">
                        <div class="card-body">
                            <h5 class="card-title">Treated Complaints</h5>
                            <p class="card-text display-4"><?php echo $treated_complaints; ?></p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="?filter_status=Pending" style="text-decoration: none; color: inherit;">
                    <div class="card text-white bg-warning mb-3 clickable-card">
                        <div class="card-body">
                            <h5 class="card-title">Pending Complaints</h5>
                            <p class="card-text display-4"><?php echo $pending_complaints; ?></p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-secondary mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Staff Count</h5>
                        <p class="card-text display-4"><?php echo $staff_count; ?></p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Chart Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h4>Complaints Lodged by Staff (Chart)</h4>
            </div>
            <div class="card-body">
                <canvas id="staffChart" height="100"></canvas>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header">
                <h4>Complaints Lodged by Staff</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Staff Name</th>
                                <th>Complaints Lodged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($staff_complaints as $staff): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($staff['full_name']); ?></td>
                                    <td><?php echo $staff['count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- Messaging Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h4>Send Message</h4>
            </div>
            <div class="card-body">
                <?php if($message_success): ?><div class="alert alert-success"><?php echo $message_success; ?></div><?php endif; ?>
                <?php if($message_error): ?><div class="alert alert-danger"><?php echo $message_error; ?></div><?php endif; ?>
                <form method="post">
                    <div class="form-group">
                        <label>Recipient</label>
                        <select name="recipient_id" class="form-control" required>
                            <option value="broadcast">Broadcast (All Users)</option>
                            <?php foreach($users as $user): ?>
                                <option value="<?php echo $user['user_id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message_text" class="form-control" rows="3" required></textarea>
                    </div>
                    <button type="submit" name="send_message" class="btn btn-primary">Send Message</button>
                </form>
            </div>
        </div>
        
        <!-- Reports Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h4><i class="fas fa-chart-bar mr-2"></i>Student Complaints Reports</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h6 class="mb-2">Result Verification Complaints Analysis</h6>
                        <p class="text-muted mb-0">Generate comprehensive reports for student complaints related to result verification. View detailed statistics, filter by department, and export data to Excel format.</p>
                    </div>
                    <div class="col-md-4 text-right">
                        <a href="student_complaints_report.php" class="btn btn-info">
                            <i class="fas fa-chart-bar mr-2"></i>View Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Complaint Search/Filter Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h4>Search & Filter Complaints</h4>
                <button class="btn btn-success float-right" onclick="exportTableToCSV('complaints.csv')">Export CSV</button>
                <button class="btn btn-danger float-right mr-2" onclick="window.print()">Export PDF</button>
            </div>
            <div class="card-body">
                <form method="get" class="form-inline mb-3">
                    <div class="form-group mr-2">
                        <input type="text" name="search_id" class="form-control" placeholder="Student ID" value="<?php echo htmlspecialchars($search_id); ?>">
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
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="complaintsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Complaint</th>
                                <th>Status</th>
                                <th>Handler</th>
                                <th>Feedback</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($complaints as $complaint): ?>
                                <tr class="complaint-row" data-complaint-id="<?php echo $complaint['complaint_id']; ?>" style="cursor: pointer;">
                                    <td><?php echo date('Y-m-d H:i', strtotime($complaint['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars(substr($complaint['complaint_text'],0,50)); ?>...</td>
                                    <td><?php echo htmlspecialchars($complaint['status']); ?></td>
                                    <td><?php echo $complaint['handler_name'] ? htmlspecialchars($complaint['handler_name']) : 'Not assigned'; ?></td>
                                    <td><?php echo $complaint['feedback'] ? htmlspecialchars($complaint['feedback']) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination for Search & Filter Complaints -->
                <?php
                $total_pages = ceil($total_complaints_filtered / $complaints_per_page);
                if($total_pages > 1): ?>
                <nav aria-label="Complaints pagination">
                  <ul class="pagination">
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                      <li class="page-item <?php if($i == $page) echo 'active'; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">Page <?php echo $i; ?></a>
                      </li>
                    <?php endfor; ?>
                  </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header">
                <h4>Archive (Treated Complaints)</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Complaint</th>
                                <th>Status</th>
                                <th>Handler</th>
                                <th>Feedback</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($archive_complaints as $complaint): ?>
                                <tr class="complaint-row" data-complaint-id="<?php echo $complaint['complaint_id']; ?>" style="cursor: pointer;">
                                    <td><?php echo htmlspecialchars($complaint['created_at'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(substr($complaint['complaint_text'] ?? '',0,50)); ?>...</td>
                                    <td><?php echo htmlspecialchars($complaint['status'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['handler_name'] ?? 'Not assigned'); ?></td>
                                    <td><?php echo htmlspecialchars($complaint['feedback'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination for Archive (Treated Complaints) -->
                <?php
                $archive_total_pages = ceil($total_archive_complaints / $archive_per_page);
                if($archive_total_pages > 1): ?>
                <nav aria-label="Archive pagination">
                  <ul class="pagination">
                    <?php for($i = 1; $i <= $archive_total_pages; $i++): ?>
                      <li class="page-item <?php if($i == $archive_page) echo 'active'; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['archive_page' => $i])); ?>#archive">Page <?php echo $i; ?></a>
                      </li>
                    <?php endfor; ?>
                  </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
        <?php if($_SESSION["role_id"] == 3): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h4>Submit i4Cus Complaint</h4>
            </div>
            <div class="card-body">
                <?php if(isset($success_message_i4cus)): ?>
                    <div class="alert alert-success"><?php echo $success_message_i4cus; ?></div>
                <?php endif; ?>
                <?php if(isset($error_message_i4cus)): ?>
                    <div class="alert alert-danger"><?php echo $error_message_i4cus; ?></div>
                <?php endif; ?>
                <form method="post" action="director_dashboard.php" enctype="multipart/form-data">
                    <input type="hidden" name="is_i4cus" value="1">
                    <div class="form-group">
                        <label>Complaint Details</label>
                        <textarea name="complaint_text" class="form-control" rows="5" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Attach Images (Optional)</label>
                        <input type="file" name="complaint_images[]" class="form-control-file" accept="image/*" multiple>
                        <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)</small>
                    </div>
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="urgentCheckI4cus" name="is_urgent">
                            <label class="custom-control-label" for="urgentCheckI4cus">Mark as Urgent</label>
                        </div>
                    </div>
                    <button type="submit" name="submit_i4cus_complaint" class="btn btn-primary">Submit i4Cus Complaint</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Complaint Details Modal -->
    <div class="modal fade" id="complaintModal" tabindex="-1" role="dialog" aria-labelledby="complaintModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="complaintModalLabel">Complaint Details</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="complaintModalBody">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/auto_refresh_complaints.js"></script>
    <script>
    // Chart.js for staff complaints
    const staffNames = <?php echo json_encode($staff_names); ?>;
    const staffCounts = <?php echo json_encode($staff_counts); ?>;
    const ctx = document.getElementById('staffChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: staffNames,
            datasets: [{
                label: 'Complaints Lodged',
                data: staffCounts,
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
    // Export table to CSV
    function exportTableToCSV(filename) {
        var csv = [];
        var rows = document.querySelectorAll("#complaintsTable tr");
        for (var i = 0; i < rows.length; i++) {
            var row = [], cols = rows[i].querySelectorAll("td, th");
            for (var j = 0; j < cols.length; j++)
                row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
            csv.push(row.join(","));
        }
        // Download CSV
        var csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
        var downloadLink = document.createElement("a");
        downloadLink.download = filename;
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = "none";
        document.body.appendChild(downloadLink);
        downloadLink.click();
        document.body.removeChild(downloadLink);
    }
    
    // Complaint modal functionality
    $(document).ready(function() {
        $('.complaint-row').click(function() {
            var complaintId = $(this).data('complaint-id');
            $('#complaintModal').modal('show');
            
            // Show loading spinner
            $('#complaintModalBody').html('<div class="text-center"><div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div></div>');
            
            // Fetch complaint details
            $.ajax({
                url: 'get_complaint_details.php',
                method: 'GET',
                data: { complaint_id: complaintId },
                dataType: 'json',
                success: function(data) {
                    var modalContent = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="font-weight-bold">Complaint ID</h6>
                                <p>${data.complaint_id}</p>
                                
                                <h6 class="font-weight-bold">Status</h6>
                                <p><span class="badge badge-${getStatusBadgeClass(data.status)}">${data.status}</span></p>
                                
                                <h6 class="font-weight-bold">Student ID</h6>
                                <p>${data.student_id}</p>
                                
                                <h6 class="font-weight-bold">Department</h6>
                                <p>${data.department_name}</p>
                                
                                <h6 class="font-weight-bold">Staff Name</h6>
                                <p>${data.staff_name}</p>
                                
                                <h6 class="font-weight-bold">Urgent</h6>
                                <p><span class="badge badge-${data.is_urgent === 'Yes' ? 'danger' : 'secondary'}">${data.is_urgent}</span></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="font-weight-bold">Lodged By</h6>
                                <p>${data.lodged_by_name}</p>
                                
                                <h6 class="font-weight-bold">Handler</h6>
                                <p>${data.handler_name}</p>
                                
                                <h6 class="font-weight-bold">Created At</h6>
                                <p>${data.created_at}</p>
                                
                                <h6 class="font-weight-bold">Updated At</h6>
                                <p>${data.updated_at}</p>
                                
                                <h6 class="font-weight-bold">Type</h6>
                                <p>
                                    ${data.is_payment_related === 'Yes' ? '<span class="badge badge-warning mr-1">Payment Related</span>' : ''}
                                    ${data.is_i4cus === 'Yes' ? '<span class="badge badge-info mr-1">i4Cus</span>' : ''}
                                    ${data.is_staff_complaint === 'Yes' ? '<span class="badge badge-primary">Staff Complaint</span>' : ''}
                                    ${data.is_payment_related === 'No' && data.is_i4cus === 'No' && data.is_staff_complaint === 'No' ? '<span class="badge badge-secondary">Regular</span>' : ''}
                                </p>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6 class="font-weight-bold">Complaint Details</h6>
                                <div class="border rounded p-3 bg-light">
                                    <p class="mb-0">${data.complaint_text.replace(/\n/g, '<br>')}</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6 class="font-weight-bold">Feedback</h6>
                                <div class="border rounded p-3 bg-light">
                                    <p class="mb-0">${data.feedback.replace(/\n/g, '<br>')}</p>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Add images if any
                    if (data.images && data.images.length > 0) {
                        modalContent += `
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6 class="font-weight-bold">Attached Images</h6>
                                    <div class="row">
                        `;
                        data.images.forEach(function(imageUrl) {
                            modalContent += `
                                <div class="col-md-4 mb-2">
                                    <img src="${imageUrl}" class="img-fluid rounded" style="max-height: 200px; cursor: pointer;" onclick="openImageModal('${imageUrl}')" alt="Complaint Image">
                                </div>
                            `;
                        });
                        modalContent += `
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    
                    $('#complaintModalBody').html(modalContent);
                },
                error: function(xhr, status, error) {
                    $('#complaintModalBody').html('<div class="alert alert-danger">Error loading complaint details. Please try again.</div>');
                }
            });
        });
    });
    
    // Function to get badge class based on status
    function getStatusBadgeClass(status) {
        switch(status) {
            case 'Pending': return 'warning';
            case 'Treated': return 'success';
            case 'Needs More Info': return 'info';
            default: return 'secondary';
        }
    }
    
    // Function to open image in larger modal
    function openImageModal(imageUrl) {
        var imageModal = `
            <div class="modal fade" id="imageModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Image View</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body text-center">
                            <img src="${imageUrl}" class="img-fluid" alt="Complaint Image">
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Remove existing image modal if any
        $('#imageModal').remove();
        
        // Add new modal to body
        $('body').append(imageModal);
        
        // Show modal
        $('#imageModal').modal('show');
        
        // Remove modal from DOM when hidden
        $('#imageModal').on('hidden.bs.modal', function() {
            $(this).remove();
        });
    }
    $(function() {
        var userId = <?php echo $_SESSION["user_id"]; ?>;
        var userRoleId = <?php echo $_SESSION["role_id"]; ?>;
        function renderComplaint(complaint, userId, userRoleId) {
            let urgentBadge = complaint.is_urgent ? '<span class="badge badge-danger ml-2">Urgent</span>' : '';
            let feedbackBox = complaint.feedback ? `<div class="feedback-box mt-2"><small class="text-muted">Feedback:</small><p class="mb-0">${complaint.feedback}</p></div>` : '';
            let imagesHtml = '';
            let imgCount = 0;
            let images = [];
            if (complaint.image_path && complaint.image_path !== '0') {
                images = complaint.image_path.split(',').map(s => s.trim()).filter(Boolean);
                imgCount = images.length;
            }
            if (imgCount > 0) {
                let galleryItems = images.slice(0,3).map((img, idx) => {
                    let directPath = 'public_image.php?img=' + encodeURIComponent(img.split('/').pop());
                    let badge = (imgCount > 3 && idx === 2) ? `<div class=\"image-count-badge\">+${imgCount-3}</div>` : '';
                    return `<div class=\"gallery-item\" onclick=\"toggleZoom(this)\"><img src=\"${directPath}\" alt=\"Complaint Image ${idx+1}\" loading=\"lazy\" onerror=\"this.onerror=null; this.parentElement.innerHTML='<div class=\\'image-error\\'>Image not available</div>'\;\">${badge}</div>`;
                }).join('');
                let allImages = images.map(img => 'public_image.php?img=' + encodeURIComponent(img.split('/').pop()));
                let viewAllBtn = imgCount > 3 ? `<button type=\"button\" class=\"btn btn-sm btn-info view-all-btn\" onclick=\"showGalleryModal(${JSON.stringify(allImages)})\"><i class=\"fas fa-images\"></i> View All (${imgCount})</button>` : '';
                imagesHtml = `<div class=\"mt-2\"><strong>Attached Images:</strong><div class=\"gallery-container\">${galleryItems}</div>${viewAllBtn}</div>`;
            }
            let checkbox = (userRoleId == 1 || complaint.lodged_by == userId) ? `<div class=\"form-check mr-3\"><input type=\"checkbox\" class=\"form-check-input complaint-checkbox\" name=\"complaint_ids[]\" value=\"${complaint.complaint_id}\"></div>` : '';
            return `<tr class=\"new-complaint\"><td>${complaint.created_at_fmt}</td><td>${complaint.complaint_text.substring(0,50)}...</td><td>${complaint.status}</td><td>${complaint.handler_name || 'Not assigned'}</td><td>${complaint.feedback || '-'}</td></tr>`;
        }
        function getLastComplaintId() {
            let first = $('#complaintsTable tbody tr').first();
            let idMatch = first.html() && first.html().match(/view_complaint.php\?id=(\d+)/);
            if (idMatch) return parseInt(idMatch[1]);
            let dataId = first.data('complaint-id');
            return dataId ? parseInt(dataId) : 0;
        }
        autoRefreshComplaints({
            container: '#complaintsTable tbody',
            afterSelector: 'tr:first',
            getLastId: getLastComplaintId,
            renderComplaint: renderComplaint,
            userId: userId,
            userRoleId: userRoleId
        });
    });
    </script>
    
    <!-- Complaint Calendar at the bottom of the page -->
    <div class="container mt-4 mb-4">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body">
                        <?php echo generateComplaintCalendar($conn, $_SESSION["role_id"]); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>