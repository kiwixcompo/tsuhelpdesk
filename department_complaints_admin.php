<?php
ob_start();
session_start();
require_once "config.php";
require_once "includes/notifications.php";
require_once "includes/logger.php";

if (!function_exists('getImagePath')) {
    function getImagePath($image) {
        $image = trim($image);
        if (empty($image)) return '';
        
        // Clean the filename
        $filename = basename($image);
        
        // Always use the public image serving script
        return 'public_image.php?img=' . urlencode($filename);
    }
}

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: staff_login.php"); exit;
}
if (!in_array($_SESSION["role_id"], [1, 3])) {
    header("location: dashboard.php"); exit;
}

$notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
$app_name = 'TSU ICT Help Desk';
$result = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key='app_name'");
if ($result && $row = mysqli_fetch_assoc($result)) $app_name = $row['setting_value'] ?: $app_name;

$success_msg = $error_msg = '';

// Flash messages from redirect
if (!empty($_GET['msg'])) {
    if (($_GET['type'] ?? '') === 'success') {
        $success_msg = htmlspecialchars($_GET['msg']);
    } else {
        $error_msg = htmlspecialchars($_GET['msg']);
    }
}

// Self-heal: Ensure forwarded_to column exists in complaints table
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM complaints LIKE 'forwarded_to'");
if ($col_check && mysqli_num_rows($col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE complaints ADD COLUMN forwarded_to VARCHAR(100) NULL DEFAULT NULL AFTER handled_by");
}

// ── Handle feedback submission ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $cid      = (int) $_POST['complaint_id'];
    $status   = $_POST['status'] ?? 'Pending';
    $response = trim($_POST['feedback'] ?? '');
    $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;
    $forwarded_to = isset($_POST['forwarded_to']) ? trim($_POST['forwarded_to']) : '';
    
    // Fetch previous forwarded_to
    $prev_forwarded_to = '';
    $check_stmt = mysqli_prepare($conn, "SELECT forwarded_to FROM complaints WHERE complaint_id = ?");
    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, 'i', $cid);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_bind_result($check_stmt, $db_forwarded_to);
        if (mysqli_stmt_fetch($check_stmt)) {
            $prev_forwarded_to = $db_forwarded_to ?? '';
        }
        mysqli_stmt_close($check_stmt);
    }
    
    // Handle admin feedback image uploads
    $admin_feedback_image_paths = array();
    if(isset($_FILES["admin_feedback_images"]) && !empty($_FILES["admin_feedback_images"]["name"][0])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        foreach($_FILES["admin_feedback_images"]["tmp_name"] as $key => $tmp_name){
            if($_FILES["admin_feedback_images"]["error"][$key] == 0){
                $filename = $_FILES["admin_feedback_images"]["name"][$key];
                $filetype = $_FILES["admin_feedback_images"]["type"][$key];
                $filesize = $_FILES["admin_feedback_images"]["size"][$key];
                
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if(!array_key_exists($ext, $allowed)) continue;
                
                $maxsize = 5 * 1024 * 1024;
                if($filesize > $maxsize) continue;
                
                if(in_array($filetype, $allowed)){
                    $new_filename = "admin_feedback_" . uniqid() . "." . $ext;
                    $target_file = $target_dir . $new_filename;
                    
                    if(move_uploaded_file($tmp_name, $target_file)){
                        chmod($target_file, 0644);
                        $admin_feedback_image_paths[] = $new_filename;
                    }
                }
            }
        }
    }
    
    $admin_feedback_images_str = !empty($admin_feedback_image_paths) ? implode(",", $admin_feedback_image_paths) : null;

    $upd = mysqli_prepare($conn,
        "UPDATE complaints
         SET status=?, feedback=?, feedback_images=?, is_urgent=?, forwarded_to=?, handled_by=?, updated_at=NOW()
         WHERE complaint_id=?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'ssssiii', $status, $response, $admin_feedback_images_str, $is_urgent, $forwarded_to, $_SESSION['user_id'], $cid);
        if (mysqli_stmt_execute($upd)) {
            $success_msg = "Department Complaint #$cid updated successfully.";
            
            // Handle forwarding notifications if changed
            if ($forwarded_to !== $prev_forwarded_to && !empty($forwarded_to)) {
                if ($forwarded_to === 'director') {
                    // Notify Director (role_id = 3)
                    $dir_res = mysqli_query($conn, "SELECT user_id FROM users WHERE role_id = 3");
                    if ($dir_res) {
                        while ($dir_row = mysqli_fetch_assoc($dir_res)) {
                            createNotification(
                                $conn, 
                                $dir_row['user_id'], 
                                $cid, 
                                'feedback_given', 
                                "Department Complaint Forwarded", 
                                "Department Complaint #$cid has been forwarded to you."
                            );
                        }
                    }
                } elseif ($forwarded_to === 'i4cus') {
                    // Notify i4Cus Staff (role_id = 5)
                    $i4_res = mysqli_query($conn, "SELECT user_id FROM users WHERE role_id = 5");
                    if ($i4_res) {
                        while ($i4_row = mysqli_fetch_assoc($i4_res)) {
                            createNotification(
                                $conn, 
                                $i4_row['user_id'], 
                                $cid, 
                                'feedback_given', 
                                "Department Complaint Forwarded", 
                                "Department Complaint #$cid has been forwarded to i4Cus Staff."
                            );
                        }
                    }
                    
                    // Also notify the Director (role_id = 3) as copied
                    $dir_res = mysqli_query($conn, "SELECT user_id FROM users WHERE role_id = 3");
                    if ($dir_res) {
                        while ($dir_row = mysqli_fetch_assoc($dir_res)) {
                            createNotification(
                                $conn, 
                                $dir_row['user_id'], 
                                $cid, 
                                'feedback_given', 
                                "Copied: Department Complaint", 
                                "Department Complaint #$cid has been forwarded to i4Cus Staff and you have been copied."
                            );
                        }
                    }
                }
            }
            
            // Notify the department user
            $get = mysqli_prepare($conn,
                "SELECT c.lodged_by, u.full_name, u.email FROM complaints c JOIN users u ON c.lodged_by = u.user_id WHERE c.complaint_id=?");
            if ($get) {
                mysqli_stmt_bind_param($get, 'i', $cid);
                mysqli_stmt_execute($get);
                $gr = mysqli_stmt_get_result($get);
                if ($grow = mysqli_fetch_assoc($gr)) {
                    $lodged_by   = $grow['lodged_by'];
                    $lodger_name = $grow['full_name'];
                    $lodger_email = $grow['email'] ?? '';
                    
                    // In-app notification
                    $notification_title = "Response on Your Department Complaint";
                    $notification_message = "Your department complaint #$cid has received feedback from admin. Status: $status";
                    createNotification($conn, $lodged_by, $cid, 'feedback_given', $notification_title, $notification_message);

                    // Send email to department
                    if (!empty($lodger_email)) {
                        $email_subject = "Response on Your Department Complaint #$cid — TSU ICT Help Desk";
                        $email_body    = "Dear $lodger_name,\n\n"
                                       . "Your department complaint (ID: #$cid) has received a response from ICT.\n\n"
                                       . "Status  : $status\n";
                        if ($response) {
                            $email_body .= "Response: " . mb_substr($response, 0, 500) . "\n\n";
                        }
                        $email_body    .= "Please log in to your department dashboard to view the full details:\n"
                                       . "https://helpdesk.tsuniversity.ng/department_dashboard.php\n\n"
                                       . "-- TSU ICT Help Desk";
                        @app_mail($lodger_email, $email_subject, $email_body);
                    }
                }
                mysqli_stmt_close($get);
            }
        } else {
            $error_msg = "Failed to update complaint.";
        }
        mysqli_stmt_close($upd);
    }
    
    $msg_text = $success_msg ?: $error_msg;
    $msg_type = $success_msg ? 'success' : 'error';
    header("Location: department_complaints_admin.php?msg=" . urlencode($msg_text) . "&type=$msg_type");
    exit;
}

// ── Handle delete ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_complaint'])) {
    $cid = (int) $_POST['complaint_id'];
    $del = mysqli_prepare($conn, "DELETE FROM complaints WHERE complaint_id=?");
    if ($del) {
        mysqli_stmt_bind_param($del, 'i', $cid);
        $del_ok = mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        $msg_text = $del_ok ? 'Complaint deleted.' : 'Delete failed.';
        $msg_type = $del_ok ? 'success' : 'error';
    } else {
        $msg_text = 'Database error.'; $msg_type = 'error';
    }
    header("Location: department_complaints_admin.php?msg=" . urlencode($msg_text) . "&type=$msg_type");
    exit;
}

// ── Filters & Pagination ──────────────────────────────────
$f_search   = trim($_GET['search'] ?? '');
$f_status   = $_GET['status']   ?? '';
$f_from     = $_GET['date_from'] ?? '';
$f_to       = $_GET['date_to']   ?? '';

$where = ['u.role_id = 7']; // Only complaints lodged by departments
$params = []; $types = '';

if ($f_search !== '') {
    $where[] = "(u.full_name LIKE ? OR c.complaint_text LIKE ? OR c.complaint_id = ?)";
    $search_param = '%' . $f_search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = is_numeric($f_search) ? (int)$f_search : 0;
    $types .= 'ssi';
}

if ($f_status && $f_status !== 'all') {
    $where[] = 'c.status=?';
    $params[] = $f_status;
    $types .= 's';
}

if ($f_from) {
    $where[] = 'c.created_at>=?';
    $params[] = $f_from.' 00:00:00';
    $types .= 's';
}

if ($f_to) {
    $where[] = 'c.created_at<=?';
    $params[] = $f_to.' 23:59:59';
    $types .= 's';
}

$wc = implode(' AND ', $where);

// --- Dynamic Pagination Setup ---
$default_limit = 10;
$allowed_limits = [10, 20, 50, 100, 'all'];
$per_page = isset($_GET['limit']) ? $_GET['limit'] : $default_limit;
if (!in_array($per_page, $allowed_limits)) {
    $per_page = $default_limit;
}

// Count total matching complaints
$count_sql = "SELECT COUNT(*) as total
              FROM complaints c
              JOIN users u ON c.lodged_by = u.user_id
              WHERE $wc";

$total_filtered_complaints = 0;
$stmt_count = mysqli_prepare($conn, $count_sql);
if ($stmt_count) {
    if ($types) mysqli_stmt_bind_param($stmt_count, $types, ...$params);
    mysqli_stmt_execute($stmt_count);
    $res_count = mysqli_stmt_get_result($stmt_count);
    if ($row_count = mysqli_fetch_assoc($res_count)) {
        $total_filtered_complaints = (int)$row_count['total'];
    }
    mysqli_stmt_close($stmt_count);
}

if ($per_page === 'all') {
    $total_pages = 1;
    $page = 1;
    $limit_clause = "";
} else {
    $per_page = (int)$per_page;
    $total_pages = max(1, ceil($total_filtered_complaints / $per_page));
    
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    if ($page > $total_pages) $page = $total_pages;
    
    $offset = ($page - 1) * $per_page;
    if ($offset < 0) $offset = 0;
    
    $limit_clause = "LIMIT $per_page OFFSET $offset";
}

// Stats
$stats = ['total'=>0,'pending'=>0,'in_progress'=>0,'resolved'=>0];
$sr = mysqli_query($conn, "SELECT
    COUNT(*) total,
    SUM(c.status='Pending') pending,
    SUM(c.status='In Progress') in_progress,
    SUM(c.status='Treated') resolved
    FROM complaints c
    JOIN users u ON c.lodged_by = u.user_id
    WHERE u.role_id = 7");
if ($sr && $row = mysqli_fetch_assoc($sr)) {
    $stats = [
        'total' => (int)$row['total'],
        'pending' => (int)$row['pending'],
        'in_progress' => (int)$row['in_progress'],
        'resolved' => (int)$row['resolved']
    ];
}

// Fetch complaints list
$sql = "SELECT c.*, u.full_name as department_name, u.email as department_email, h.full_name as handler_name
        FROM complaints c
        JOIN users u ON c.lodged_by = u.user_id
        LEFT JOIN users h ON c.handled_by = h.user_id
        WHERE $wc
        ORDER BY c.created_at DESC
        $limit_clause";

$complaints = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $complaints[] = $row;
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Department Complaints — <?php echo htmlspecialchars($app_name); ?></title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="css/style.css">
<style>
.stat-card {
    background:#fff;
    border-radius:10px;
    padding:1.25rem 1.5rem;
    box-shadow:0 2px 10px rgba(30,60,114,.08);
    border-left:4px solid #1e3c72;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(30,60,114,0.15);
}
.stat-num  { font-size:1.8rem; font-weight:700; color:#1e3c72; }
.stat-lbl  { font-size:.8rem; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; }
.path-text { font-size:.8rem; color:#6c757d; max-width:260px; }

/* Modal positioning fix to avoid flicker */
body.modal-open { overflow: auto !important; padding-right: 0 !important; }
.modal-dialog { margin: 5vh auto; }

/* Inline Autocomplete Suggestions */
.textarea-autocomplete-wrapper {
    position: relative;
    width: 100%;
}
.autocomplete-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
    color: transparent;
    white-space: pre-wrap;
    word-wrap: break-word;
    overflow: hidden;
    background: transparent;
    margin: 0;
    box-sizing: border-box;
}
.autocomplete-backdrop .mirrored-text {
    color: transparent;
    white-space: pre-wrap;
    font-family: inherit;
    font-size: inherit;
    font-weight: inherit;
    line-height: inherit;
}
.autocomplete-backdrop .ghost-text {
    color: #868e96;
    opacity: 0.6;
    white-space: pre-wrap;
    font-family: inherit;
    font-size: inherit;
    font-weight: inherit;
    line-height: inherit;
}
</style>
</head>
<body>
<?php
$page_title    = 'Department Complaints';
$page_subtitle = 'Complaints submitted by university departments';
$page_icon     = 'fas fa-building';
$show_breadcrumb = true;
$breadcrumb_items = [
    ['title'=>'Admin','url'=>'admin.php'],
    ['title'=>'Department Complaints','url'=>'']
];
include 'includes/navbar.php';
include 'includes/dashboard_header.php';
?>

<div class="container-fluid pb-5">

<?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo htmlspecialchars($success_msg); ?>
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
<?php endif; ?>
<?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
<?php endif; ?>

<!-- Stats cards -->
<div class="row mb-4">
    <?php foreach ([
        ['Total',        $stats['total'],        '#1e3c72', 'all'],
        ['Pending',      $stats['pending'],       '#e67e22', 'Pending'],
        ['In Progress',  $stats['in_progress'],   '#2980b9', 'In Progress'],
        ['Resolved',     $stats['resolved'],      '#27ae60', 'Treated'],
    ] as [$lbl,$num,$col,$val]): 
        $card_params = $_GET;
        $card_params['status'] = $val;
        $card_params['page'] = 1;
        $card_url = 'department_complaints_admin.php?' . http_build_query($card_params);
    ?>
    <div class="col-6 col-md-3 mb-3">
        <a href="<?php echo htmlspecialchars($card_url); ?>" class="text-decoration-none">
            <div class="stat-card" style="border-left-color:<?php echo $col; ?>; cursor: pointer;">
                <div class="stat-num" style="color:<?php echo $col; ?>"><?php echo (int)$num; ?></div>
                <div class="stat-lbl"><?php echo htmlspecialchars($lbl); ?></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="form-row align-items-end">
            <!-- Search Keyword -->
            <div class="col-md-4 mb-2">
                <label class="small font-weight-bold"><i class="fas fa-search mr-1"></i>Search Keyword</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Department name, ID, or details…" value="<?php echo htmlspecialchars($f_search); ?>">
            </div>
            <!-- Status Filter -->
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">Status</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="all" <?php echo $f_status==='all'||$f_status===''?'selected':''; ?>>All statuses</option>
                    <option value="Pending" <?php echo $f_status==='Pending'?'selected':''; ?>>Pending</option>
                    <option value="In Progress" <?php echo $f_status==='In Progress'?'selected':''; ?>>In Progress</option>
                    <option value="Treated" <?php echo $f_status==='Treated'?'selected':''; ?>>Treated</option>
                </select>
            </div>
            <!-- Date range: From -->
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_from); ?>">
            </div>
            <!-- Date range: To -->
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_to); ?>">
            </div>
            <!-- Submit/Reset Buttons -->
            <div class="col-md-2 mb-2 d-flex">
                <button type="submit" class="btn btn-primary btn-sm btn-block mr-1">
                    <i class="fas fa-filter mr-1"></i>Filter
                </button>
                <a href="department_complaints_admin.php" class="btn btn-secondary btn-sm px-3">
                    <i class="fas fa-sync-alt"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-building mr-2 text-primary"></i>
            Department Complaints Queue (<span id="complaintsCount"><?php echo $total_filtered_complaints; ?></span>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-mobile-cards mb-0">
            <thead class="bg-light">
                <tr>
                    <th style="width: 80px;">#</th>
                    <th>Department</th>
                    <th>Complaint Details</th>
                    <th style="width: 120px;">Status</th>
                    <th style="width: 100px;">Priority</th>
                    <th style="width: 120px;">Date</th>
                    <th style="width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($complaints)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No department complaints found.</td></tr>
            <?php else: ?>
            <?php foreach ($complaints as $c): 
                $sc = ['Pending'=>'warning','In Progress'=>'info','Treated'=>'success'];
                $bc = $sc[$c['status']] ?? 'secondary';
            ?>
                <tr class="<?php echo $c['is_urgent'] ? 'table-danger' : ''; ?>">
                    <td data-label="#"><strong><?php echo $c['complaint_id']; ?></strong></td>
                    <td data-label="Department">
                        <div class="font-weight-bold text-dark"><?php echo htmlspecialchars($c['department_name']); ?></div>
                        <small class="text-muted">ID: <?php echo htmlspecialchars($c['student_id']); ?></small>
                    </td>
                    <td data-label="Complaint Details">
                        <div class="text-dark small bg-light p-2 rounded" style="max-height: 80px; overflow-y: auto;">
                            <?php echo htmlspecialchars($c['complaint_text']); ?>
                        </div>
                        <?php if($c['image_path']): ?>
                            <?php 
                            $images = array_filter(explode(",", $c['image_path'])); 
                            $img_count = count($images);
                            $processed_images = array_map('getImagePath', $images);
                            ?>
                            <div class="mt-2">
                                <button type="button" class="btn btn-link p-0 attachment-btn small text-info" 
                                        onclick="showGalleryModal(<?php echo htmlspecialchars(json_encode($processed_images)); ?>)">
                                    <i class="fas fa-paperclip"></i> <?php echo $img_count; ?> Attachment<?php echo $img_count > 1 ? 's' : ''; ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <span class="badge badge-<?php echo $bc; ?> px-2 py-1"><?php echo htmlspecialchars($c['status'] == 'Treated' ? 'Resolved' : $c['status']); ?></span>
                    </td>
                    <td data-label="Priority">
                        <?php if($c['is_urgent']): ?>
                            <span class="badge badge-danger">Urgent</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Normal</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Date">
                        <i class="far fa-calendar-alt text-muted mr-1"></i> <?php echo date('M d, Y', strtotime($c['created_at'])); ?>
                    </td>
                    <td data-label="Actions" class="action-col">
                        <button class="btn btn-sm btn-primary btn-view-respond"
                                data-id="<?php echo $c['complaint_id']; ?>"
                                data-dept="<?php echo htmlspecialchars($c['department_name'], ENT_QUOTES); ?>"
                                data-dept-id="<?php echo htmlspecialchars($c['student_id'], ENT_QUOTES); ?>"
                                data-text="<?php echo htmlspecialchars($c['complaint_text'], ENT_QUOTES); ?>"
                                data-status="<?php echo htmlspecialchars($c['status'], ENT_QUOTES); ?>"
                                data-urgent="<?php echo $c['is_urgent']; ?>"
                                data-date="<?php echo date('M d, Y H:i', strtotime($c['created_at'])); ?>"
                                data-feedback="<?php echo htmlspecialchars($c['feedback'] ?? '', ENT_QUOTES); ?>"
                                data-attachment="<?php echo htmlspecialchars($c['image_path'] ?? '', ENT_QUOTES); ?>"
                                data-handler="<?php echo htmlspecialchars($c['handler_name'] ?? 'Not assigned', ENT_QUOTES); ?>"
                                data-forwarded="<?php echo htmlspecialchars($c['forwarded_to'] ?? '', ENT_QUOTES); ?>"
                                title="View & Respond">
                            <i class="fas fa-eye mr-1"></i>View & Respond
                        </button>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this department complaint?')">
                            <input type="hidden" name="complaint_id" value="<?php echo $c['complaint_id']; ?>">
                            <button type="submit" name="delete_complaint" class="btn btn-sm btn-outline-danger">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination controls -->
    <?php if ($total_pages > 1 || $per_page !== 'all'): ?>
        <div class="card-footer d-flex flex-column flex-md-row justify-content-between align-items-center bg-white border-top-0 py-3">
            <div class="mb-2 mb-md-0 text-muted small">
                <?php
                if ($total_filtered_complaints > 0) {
                    $start_entry = $per_page === 'all' ? 1 : ($page - 1) * $per_page + 1;
                    $end_entry = $per_page === 'all' ? $total_filtered_complaints : min($page * $per_page, $total_filtered_complaints);
                    echo "Showing " . $start_entry . " to " . $end_entry . " of " . $total_filtered_complaints . " entries";
                } else {
                    echo "Showing 0 to 0 of 0 entries";
                }
                ?>
            </div>
            <div class="d-flex align-items-center mb-2 mb-md-0">
                <label class="mr-2 mb-0 font-weight-bold text-muted small" style="white-space: nowrap;">Show:</label>
                <?php
                $limit_params = $_GET;
                unset($limit_params['limit']);
                unset($limit_params['page']);
                ?>
                <select class="form-control form-control-sm" style="width: auto; cursor: pointer; height: auto !important; padding: 4px 8px !important;" 
                        onchange="window.location.href='?<?php echo http_build_query($limit_params); ?>&page=1&limit='+this.value">
                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                    <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20 entries</option>
                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                    <option value="all" <?php echo $per_page === 'all' ? 'selected' : ''; ?>>All entries</option>
                </select>
            </div>
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $page_params = $_GET;
                        unset($page_params['page']);
                        ?>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($page_params, ['page' => $page - 1])); ?>">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        if ($start_page > 1) {
                            echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($page_params, ['page' => 1])) . '">1</a></li>';
                            if ($start_page > 2) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                        }
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($page_params, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php
                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($page_params, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                        }
                        ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($page_params, ['page' => $page + 1])); ?>">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</div>

<!-- View & Respond Modal -->
<div class="modal fade" id="respondModal" tabindex="-1" role="dialog" data-backdrop="false" data-keyboard="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-building mr-2"></i>Department Complaint Response</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="complaint_id" id="modal_complaint_id">
                <div class="modal-body p-4">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-muted small uppercase font-weight-bold mb-1">Department</h6>
                            <div class="font-weight-bold text-dark" id="modal_dept_name"></div>
                            <small class="text-muted" id="modal_dept_id"></small>
                        </div>
                        <div class="col-md-6 text-md-right mt-2 mt-md-0">
                            <h6 class="text-muted small uppercase font-weight-bold mb-1">Date Submitted</h6>
                            <div class="text-dark" id="modal_date"></div>
                        </div>
                    </div>
                    
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-muted">Complaint text</label>
                        <div class="bg-light p-3 rounded text-dark" id="modal_text" style="white-space: pre-wrap; font-size: 0.95rem; border-left: 4px solid #1e3c72;"></div>
                    </div>
                    
                    <div class="form-group mb-4" id="modal_attachments_group" style="display:none">
                        <label class="font-weight-bold text-muted">Attachments</label>
                        <div class="mt-2 d-flex flex-wrap" id="modal_attachments"></div>
                    </div>

                    <hr class="my-4">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-muted">Complaint Status</label>
                                <select name="status" id="modal_status" class="form-control font-weight-bold" required>
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Treated">Resolved (Treated)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold text-muted">Priority</label>
                                <div class="custom-control custom-checkbox mt-2">
                                    <input type="checkbox" class="custom-control-input" id="modal_urgent" name="is_urgent">
                                    <label class="custom-control-label text-danger font-weight-bold" for="modal_urgent">Mark as Urgent</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group mt-3">
                        <label class="font-weight-bold text-muted mb-2">Response / Feedback to Department</label>
                        <textarea name="feedback" id="modal_feedback" class="form-control manual-clipboard-init" rows="4" placeholder="Write feedback details to send to the department..."></textarea>
                    </div>

                    <div class="form-group mt-3">
                        <label class="font-weight-bold text-muted">Attach Images to Response (Optional)</label>
                        <input type="file" name="admin_feedback_images[]" class="form-control-file" accept="image/*" multiple>
                        <small class="form-text text-muted">Paste screenshots with Ctrl+V or upload JPG, JPEG, PNG, GIF</small>
                    </div>
                    
                    <div class="form-group mt-3">
                        <label class="font-weight-bold text-muted"><i class="fas fa-share mr-1"></i>Forward Department Complaint <span class="text-muted font-weight-normal">(optional)</span></label>
                        <select name="forwarded_to" id="modal_forwarded_to" class="form-control">
                            <option value="">— Do not forward / Leave in general queue —</option>
                            <option value="director">Forward to ICT Director</option>
                            <option value="i4cus">Forward to i4Cus Staff</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" name="submit_feedback" class="btn btn-primary px-4 font-weight-bold">
                        <i class="fas fa-save mr-1"></i>Save Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Image Attachment View Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title font-weight-bold">Attachment View</h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body text-center p-3">
                <img id="modalImage" src="" class="img-fluid rounded" alt="Attachment Image" style="max-height: 80vh;">
            </div>
        </div>
    </div>
</div>

<!-- Gallery Modal -->
<div class="modal fade" id="galleryModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title font-weight-bold">Attachments</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body text-center p-4 bg-light">
                <div id="galleryContent" class="row justify-content-center"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://js.puter.com/v2/"></script>
<script src="js/clipboard-paste.js"></script>

<script>
<?php if (!empty($_SESSION['app_settings']['puter_auth_token'])): ?>
if (typeof puter !== 'undefined') {
    puter.authToken = <?php echo json_encode($_SESSION['app_settings']['puter_auth_token']); ?>;
    try {
        localStorage.setItem('puter-auth-token', puter.authToken);
        localStorage.setItem('puter_auth_token', puter.authToken);
    } catch(e) {}
}
<?php endif; ?>

let currentComplaintHistory = [];
let currentComplaintContext = {};

$(document).on('click', '.btn-view-respond', function() {
    const id = $(this).data('id');
    const dept = $(this).data('dept');
    const deptId = $(this).data('dept-id');
    const text = $(this).data('text');
    const status = $(this).data('status');
    const urgent = $(this).data('urgent');
    const date = $(this).data('date');
    const feedback = $(this).data('feedback');
    const attachment = $(this).data('attachment');
    const forwarded = $(this).data('forwarded');
    
    $('#modal_complaint_id').val(id);
    $('#modal_dept_name').text(dept);
    $('#modal_dept_id').text(deptId);
    $('#modal_text').text(text);
    $('#modal_status').val(status);
    $('#modal_urgent').prop('checked', urgent == 1);
    $('#modal_date').text(date);
    $('#modal_feedback').val(feedback);
    $('#modal_forwarded_to').val(forwarded || '');
    
    currentComplaintContext = {
        category: dept || 'Department Complaint',
        description: text || ''
    };
    
    currentComplaintHistory = [];
    // Load past feedback options
    $.getJSON('api/get_historical_dept_feedback.php', { complaint_id: id }, function(res) {
        if (res.success && res.history && res.history.length > 0) {
            currentComplaintHistory = res.history;
        }
    });
    
    // Handle attachments
    const attachGroup = $('#modal_attachments_group');
    const attachContent = $('#modal_attachments');
    attachContent.empty();
    
    if (attachment) {
        const images = attachment.split(',').filter(Boolean);
        if (images.length > 0) {
            images.forEach(img => {
                const imgPath = getImagePath(img);
                attachContent.append(`
                    <div class="mr-2 mb-2">
                        <img src="${imgPath}" class="img-thumbnail" style="max-height:80px; cursor:pointer;" onclick="showImageModal('${imgPath}')">
                    </div>
                `);
            });
            attachGroup.show();
        } else {
            attachGroup.hide();
        }
    } else {
        attachGroup.hide();
    }
    
    $('#respondModal').modal('show');
});

function cleanContinuation(typedText, aiResponse) {
    let cleaned = aiResponse.trim();
    if (!cleaned) return '';
    
    const cleanWord = w => w.replace(/[.,\/#!$%\^&\*;:{}=\-_`~()?]/g, "").toLowerCase();
    
    const typedWords = typedText.trim().split(/\s+/).filter(Boolean);
    const aiWords = cleaned.split(/\s+/).filter(Boolean);
    
    // Find suffix-prefix overlap
    let overlapCount = 0;
    const maxOverlap = Math.min(typedWords.length, aiWords.length);
    for (let i = 1; i <= maxOverlap; i++) {
        const typedSuffix = typedWords.slice(-i).map(cleanWord).join(' ');
        const aiPrefix = aiWords.slice(0, i).map(cleanWord).join(' ');
        if (typedSuffix === aiPrefix && typedSuffix.length > 0) {
            overlapCount = i;
        }
    }
    
    if (overlapCount > 0) {
        cleaned = aiWords.slice(overlapCount).join(' ');
    }
    
    // If the original AI response began with a repeat of the entire typedText (even with punctuation difference)
    const typedLowerClean = typedText.toLowerCase().replace(/[^a-z0-9]/g, '');
    const aiLowerClean = aiResponse.toLowerCase().replace(/[^a-z0-9]/g, '');
    if (aiLowerClean.startsWith(typedLowerClean) && typedLowerClean.length > 0) {
        let typedIdx = 0;
        let aiIdx = 0;
        while (typedIdx < typedText.length && aiIdx < aiResponse.length) {
            const tc = typedText[typedIdx].toLowerCase();
            const ac = aiResponse[aiIdx].toLowerCase();
            if (/[^a-z0-9]/.test(tc)) {
                typedIdx++;
                continue;
            }
            if (/[^a-z0-9]/.test(ac)) {
                aiIdx++;
                continue;
            }
            if (tc === ac) {
                typedIdx++;
                aiIdx++;
            } else {
                break;
            }
        }
        if (typedIdx >= typedText.replace(/[^a-z0-9]/gi, '').length) {
            const prospective = aiResponse.substring(aiIdx).trim();
            if (prospective.length < cleaned.length) {
                cleaned = prospective;
            }
        }
    }
    
    // Ensure space prefix if needed
    if (cleaned && !cleaned.startsWith(' ') && !typedText.endsWith(' ') && !/^[.,\/#!$%\^&\*;:{}=\-_`~()?]/.test(cleaned)) {
        cleaned = ' ' + cleaned;
    }
    return cleaned;
}

function extractAIText(result) {
    console.log('extractAIText received:', result);
    if (!result) return '';
    if (typeof result === 'string') {
        return result.trim();
    }
    if (typeof result === 'object') {
        if (result.message) {
            if (typeof result.message === 'string') {
                return result.message.trim();
            }
            if (result.message.content && typeof result.message.content === 'string') {
                return result.message.content.trim();
            }
            if (result.message.text && typeof result.message.text === 'string') {
                return result.message.text.trim();
            }
        }
        if (typeof result.content === 'string') {
            return result.content.trim();
        }
        if (typeof result.text === 'string') {
            return result.text.trim();
        }
        let longestStr = '';
        const excludeValues = ['assistant', 'user', 'system', 'role', 'text'];
        function search(obj) {
            if (!obj) return;
            if (typeof obj === 'string') {
                const trimmed = obj.trim();
                if (trimmed && !excludeValues.includes(trimmed.toLowerCase()) && trimmed.length > longestStr.length) {
                    longestStr = trimmed;
                }
                return;
            }
            if (typeof obj === 'object') {
                for (const key in obj) {
                    try {
                        if (Object.prototype.hasOwnProperty.call(obj, key)) {
                            search(obj[key]);
                        }
                    } catch (e) {}
                }
            }
        }
        search(result);
        if (longestStr) {
            return longestStr;
        }
        try {
            if (typeof result.toString === 'function') {
                const strVal = result.toString();
                if (typeof strVal === 'string' && strVal !== '[object Object]') {
                    return strVal.trim();
                }
            }
        } catch (e) {}
    }
    return '';
}

$(document).ready(function() {

    // Helper for AI inline ghost-text autocomplete completions
    function initResponseAutocomplete(textareaId, getHistoryFn, getContextFn) {
        const $textarea = $(textareaId);
        if ($textarea.length === 0) return;
        
        // Wrap textarea
        const $wrapper = $('<div class="textarea-autocomplete-wrapper" style="position: relative; width: 100%;"></div>');
        $textarea.wrap($wrapper);
        
        // Create backdrop
        const $backdrop = $('<div class="autocomplete-backdrop" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; pointer-events: none; color: transparent; white-space: pre-wrap; word-wrap: break-word; overflow: hidden; background: transparent; margin: 0; box-sizing: border-box;">' +
            '<span class="mirrored-text" style="color: transparent; white-space: pre-wrap; font-family: inherit; font-size: inherit; font-weight: inherit; line-height: inherit;"></span>' +
            '<span class="ghost-text" style="color: #868e96; opacity: 0.6; white-space: pre-wrap; font-family: inherit; font-size: inherit; font-weight: inherit; line-height: inherit;"></span>' +
            '</div>');
        $textarea.before($backdrop);
        
        $textarea.css({
            'background-color': 'transparent',
            'position': 'relative',
            'z-index': 1
        });
        
        function syncStyles() {
            const stylesToCopy = [
                'font-family', 'font-size', 'font-weight', 'line-height',
                'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
                'border-style', 'box-sizing', 'text-align', 'text-transform', 'letter-spacing', 'word-spacing',
                'width', 'height', 'min-height', 'max-height', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left'
            ];
            stylesToCopy.forEach(style => {
                $backdrop.css(style, $textarea.css(style));
            });
            $backdrop.find('.mirrored-text, .ghost-text').css({
                'font-family': $textarea.css('font-family'),
                'font-size': $textarea.css('font-size'),
                'font-weight': $textarea.css('font-weight'),
                'line-height': $textarea.css('line-height')
            });
        }
        
        syncStyles();
        $(window).on('resize', syncStyles);
        
        $textarea.on('scroll', function() {
            $backdrop.scrollTop($textarea.scrollTop());
            $backdrop.scrollLeft($textarea.scrollLeft());
        });
        
        let currentGhostText = '';
        let typingTimer;
        const typingDelay = 650;
        
        function setGhostText(text) {
            currentGhostText = text;
            $backdrop.find('.ghost-text').text(text);
        }
        
        function syncMirroredText() {
            const typedVal = $textarea.val();
            $backdrop.find('.mirrored-text').text(typedVal);
            $backdrop.scrollTop($textarea.scrollTop());
        }
        
        $textarea.on('input', function() {
            setGhostText('');
            syncMirroredText();
            
            clearTimeout(typingTimer);
            const typedVal = $(this).val();
            
            const words = typedVal.trim().split(/\s+/).filter(Boolean);
            if (words.length < 3 || typedVal.trim().length < 10) {
                return;
            }
            
            typingTimer = setTimeout(function() {
                triggerAICompletion(typedVal);
            }, typingDelay);
        });
        
        $textarea.on('focus', function() {
            syncStyles();
            syncMirroredText();
            const typedVal = $textarea.val();
            const words = typedVal.trim().split(/\s+/).filter(Boolean);
            if (words.length >= 3 && typedVal.trim().length >= 10) {
                triggerAICompletion(typedVal);
            }
        });
        
        $textarea.on('keydown', function(e) {
            if (e.key === 'Tab' && currentGhostText) {
                e.preventDefault();
                const currentVal = $textarea.val();
                $textarea.val(currentVal + currentGhostText);
                setGhostText('');
                syncMirroredText();
                $textarea.trigger('input');
            } else if (e.key === 'Escape') {
                setGhostText('');
                syncMirroredText();
            }
        });
        
        async function triggerAICompletion(typedText) {
            if (typeof puter === 'undefined') return;
            
            const context = getContextFn() || {};
            const history = getHistoryFn() || [];
            
            if (!context.category && !context.description) return;
            
            let historyStr = '';
            if (history && history.length > 0) {
                historyStr = history.map((item, index) => {
                    const text = item.feedback || item.admin_response || '';
                    const desc = item.complaint_text || item.description || '';
                    return `Past Match #${index + 1}:\nComplaint: "${desc}"\nResponse: "${text}"`;
                }).join('\n\n');
            }
            
            try {
                const prompt = `You are a helpful university Support Staff assistant responding to a student complaint.
Complaint Details:
Category/Issue: "${context.category || 'General'}"
Description: "${context.description || ''}"

Here are some past responses given to similar complaints:
${historyStr || 'None available.'}

The support agent has started typing their response:
"${typedText}"

Your task:
1. Continue the response from the exact point where the agent left off typing.
2. Return ONLY the continuation text (what comes immediately after the typed text).
3. Do NOT repeat any part of the typed text. The text you return will be appended directly to the end of the typed text to form a seamless sentence.
4. Keep the continuation natural, polite, and professional. The English should be polished and paraphrased, not exactly matching the past reference.
5. The continuation must be short (1 to 2 sentences max) and merge seamlessly.
6. Return ONLY the text to be appended. No explanations, no quotes, no markdown wrappers.`;

                const result = await puter.ai.chat(prompt);
                let rawText = extractAIText(result);
                
                rawText = cleanContinuation(typedText, rawText);
                
                if (rawText && $textarea.is(':focus') && $textarea.val() === typedText) {
                    setGhostText(rawText);
                }
            } catch (err) {
                console.error('Puter Autocomplete Error:', err);
            }
        }
    }

    // Initialize autocomplete on feedback response
    initResponseAutocomplete('#modal_feedback', function() { return currentComplaintHistory; }, function() { return currentComplaintContext; });

    // Initialize clipboard paste for feedback response
    if (typeof initializeClipboardPaste === 'function') {
        initializeClipboardPaste(document.getElementById('modal_feedback'), document.querySelector('input[name="admin_feedback_images[]"]'));
    }


});

function getImagePath(filename) {
    if (!filename) return '';
    if (filename.startsWith('http://') || filename.startsWith('https://')) return filename;
    return 'uploads/' + filename;
}

function showImageModal(src) {
    $('#modalImage').attr('src', src);
    $('#imageModal').modal('show');
}

function showGalleryModal(images) {
    const content = $('#galleryContent');
    content.empty();
    if (images && images.length > 0) {
        images.forEach(img => {
            content.append(`
                <div class="col-md-4 mb-3">
                    <img src="${img}" class="img-fluid img-thumbnail rounded shadow-sm" style="max-height: 250px; cursor: pointer;" onclick="showImageModal('${img}')">
                </div>
            `);
        });
        $('#galleryModal').modal('show');
    }
}
</script>
</body>
</html>
