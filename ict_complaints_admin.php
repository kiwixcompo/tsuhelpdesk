<?php
ob_start();
session_start();
require_once "config.php";
require_once "includes/notifications.php";
require_once "includes/notification_prefs.php";

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

// Ensure forwarded_to column exists (MySQL 5.x compatible)
$_col = mysqli_query($conn, "SHOW COLUMNS FROM student_ict_complaints LIKE 'forwarded_to'");
if ($_col && mysqli_num_rows($_col) === 0) {
    mysqli_query($conn, "ALTER TABLE student_ict_complaints ADD COLUMN forwarded_to VARCHAR(255) NULL DEFAULT NULL");
}

$success_msg = $error_msg = '';

// Flash messages from PRG redirect
if (!empty($_GET['msg'])) {
    if (($_GET['type'] ?? '') === 'success') {
        $success_msg = htmlspecialchars($_GET['msg']);
    } else {
        $error_msg = htmlspecialchars($_GET['msg']);
    }
}

// ── Handle feedback submission ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $cid      = (int) $_POST['complaint_id'];
    $status   = $_POST['status'] ?? 'Pending';
    $response = trim($_POST['admin_response'] ?? '');

    $valid_statuses = ['Pending', 'Under Review', 'Resolved', 'Rejected', 'Auto-Resolved'];
    if (!in_array($status, $valid_statuses)) $status = 'Pending';

    $upd = mysqli_prepare($conn,
        "UPDATE student_ict_complaints
         SET status=?, admin_response=?, handled_by=?, forwarded_to=?, updated_at=NOW()
         WHERE complaint_id=?");
    if ($upd) {
        $forwarded_to = trim($_POST['forwarded_to'] ?? '');
        mysqli_stmt_bind_param($upd, 'ssiis', $status, $response, $_SESSION['user_id'], $forwarded_to, $cid);
        if (mysqli_stmt_execute($upd)) {
            $success_msg = "Complaint #$cid updated successfully.";
            // Notify student — hide staff identity
            $get = mysqli_prepare($conn,
                "SELECT student_id, node_label FROM student_ict_complaints WHERE complaint_id=?");
            if ($get) {
                mysqli_stmt_bind_param($get, 'i', $cid);
                mysqli_stmt_execute($get);
                $gr = mysqli_stmt_get_result($get);
                if ($grow = mysqli_fetch_assoc($gr)) {
                    $sid   = $grow['student_id'];
                    $topic = $grow['node_label'];
                    $title = "ICT Complaint Update";
                    $msg   = "Your ICT complaint regarding \"$topic\" has been updated. Status: $status.";
                    if ($response) $msg .= " Response from ICT: " . substr($response, 0, 150);

                    // Ensure notifications table exists (complaint_type column optional)
                    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_notifications (
                        notification_id INT AUTO_INCREMENT PRIMARY KEY,
                        student_id INT NOT NULL, complaint_id INT NULL,
                        title VARCHAR(255) NOT NULL, message TEXT NOT NULL,
                        is_read TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_s (student_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    // Add complaint_type column if it doesn't exist yet
                    $ct_check = mysqli_query($conn, "SHOW COLUMNS FROM student_notifications LIKE 'complaint_type'");
                    if ($ct_check && mysqli_num_rows($ct_check) === 0) {
                        mysqli_query($conn, "ALTER TABLE student_notifications ADD COLUMN complaint_type VARCHAR(20) NOT NULL DEFAULT 'ict' AFTER complaint_id");
                    }

                    $notif = mysqli_prepare($conn,
                        "INSERT INTO student_notifications
                         (student_id, complaint_id, title, message, created_at)
                         VALUES (?, ?, ?, ?, NOW())");
                    if ($notif) {
                        mysqli_stmt_bind_param($notif, 'iiss', $sid, $cid, $title, $msg);
                        mysqli_stmt_execute($notif);
                        mysqli_stmt_close($notif);
                    }

                    // Send email to student
                    $email_sql = mysqli_prepare($conn,
                        "SELECT s.email, s.first_name, s.last_name
                         FROM students s
                         JOIN student_ict_complaints c ON s.student_id = c.student_id
                         WHERE c.complaint_id = ?");
                    if ($email_sql) {
                        mysqli_stmt_bind_param($email_sql, 'i', $cid);
                        mysqli_stmt_execute($email_sql);
                        $er = mysqli_stmt_get_result($email_sql);
                        if ($erow = mysqli_fetch_assoc($er)) {
                            $to      = $erow['email'];
                            $subject = "Update on Your ICT Complaint — TSU ICT Help Desk";
                            $body    = "Dear " . $erow['first_name'] . " " . $erow['last_name'] . ",\n\n";
                            $body   .= "Your ICT complaint regarding \"$topic\" has been updated.\n\n";
                            $body   .= "Status: $status\n";
                            if ($response) {
                                $body .= "\nResponse from ICT:\n" . $response . "\n";
                            }
                            $body .= "\nYou can view the full details by logging into the student portal:\n";
                            $body .= "https://helpdesk.tsuniversity.ng/student_login.php\n\n";
                            $body .= "Best regards,\nTSU ICT Help Desk Team";

                            $headers  = "From: TSU ICT Help Desk <noreply@tsuniversity.edu.ng>\r\n";
                            $headers .= "Reply-To: support@tsuniversity.edu.ng\r\n";
                            @app_mail($to, $subject, $body, $headers);
                        }
                        mysqli_stmt_close($email_sql);
                    }

                    // Also notify the forwarded-to department if applicable
                    $fwd_stmt = mysqli_prepare($conn,
                        "SELECT c.forwarded_to, u.user_id, u.email
                         FROM student_ict_complaints c
                         JOIN users u ON u.full_name = c.forwarded_to AND u.role_id IN (5,6,7)
                         WHERE c.complaint_id = ? AND c.forwarded_to IS NOT NULL AND c.forwarded_to != ''
                         LIMIT 1");
                    if ($fwd_stmt) {
                        mysqli_stmt_bind_param($fwd_stmt, 'i', $cid);
                        mysqli_stmt_execute($fwd_stmt);
                        $fwd_row = mysqli_fetch_assoc(mysqli_stmt_get_result($fwd_stmt));
                        mysqli_stmt_close($fwd_stmt);

                        if ($fwd_row && !empty($fwd_row['email'])) {
                            $dept_name = $fwd_row['forwarded_to'];
                            $pref_key  = $response ? 'on_ict_response' : 'on_status_change';
                            $d_subject = "ICT Update on Forwarded Complaint #$cid — TSU ICT Help Desk";
                            $d_body    = "Dear $dept_name,\n\n";
                            $d_body   .= "ICT has updated a complaint forwarded to your department.\n\n";
                            $d_body   .= "Complaint #: $cid\n";
                            $d_body   .= "Issue      : $topic\n";
                            $d_body   .= "New Status : $status\n";
                            if ($response) {
                                $d_body .= "\nICT Response:\n" . $response . "\n";
                            }
                            $d_body .= "\nLog in to view the full details:\n";
                            $d_body .= "https://helpdesk.tsuniversity.ng/department_dashboard.php\n\n";
                            $d_body .= "Best regards,\nTSU ICT Help Desk";

                            sendDeptEmailIfAllowed($conn, (int)$fwd_row['user_id'],
                                $pref_key, $fwd_row['email'], $d_subject, $d_body);
                        }
                    }
                }
                mysqli_stmt_close($get);
            }
        } else {
            $error_msg = "Failed to update complaint.";
        }
        mysqli_stmt_close($upd);
    }
    // PRG redirect — prevents form resubmission and http/https mismatch
    $msg_text = $success_msg ?: $error_msg;
    $msg_type = $success_msg ? 'success' : 'error';
    header("Location: ict_complaints_admin.php?msg=" . urlencode($msg_text) . "&type=$msg_type");
    exit;
}

// ── Handle delete ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_complaint'])) {
    $cid = (int) $_POST['complaint_id'];
    $del = mysqli_prepare($conn, "DELETE FROM student_ict_complaints WHERE complaint_id=?");
    if ($del) {
        mysqli_stmt_bind_param($del, 'i', $cid);
        $del_ok = mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
        $msg_text = $del_ok ? 'Complaint deleted.' : 'Delete failed.';
        $msg_type = $del_ok ? 'success' : 'error';
    } else {
        $msg_text = 'Database error.'; $msg_type = 'error';
    }
    header("Location: ict_complaints_admin.php?msg=" . urlencode($msg_text) . "&type=$msg_type");
    exit;
}

// ── Handle Forwarding ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forward_complaint'])) {
    $cid        = (int) $_POST['complaint_id'];
    $forward_to = trim($_POST['forwarded_to'] ?? '');  // VARCHAR — department name

    if (empty($forward_to)) {
        header("Location: ict_complaints_admin.php?msg=" . urlencode('Please select a department.') . "&type=error");
        exit;
    }

    // Add forwarded_to column if it doesn't exist (compatible with MySQL 5.x)
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM student_ict_complaints LIKE 'forwarded_to'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE student_ict_complaints ADD COLUMN forwarded_to VARCHAR(255) NULL DEFAULT NULL");
    }

    $upd = mysqli_prepare($conn,
        "UPDATE student_ict_complaints
         SET forwarded_to=?, status='Under Review', updated_at=NOW()
         WHERE complaint_id=?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'si', $forward_to, $cid);
        if (mysqli_stmt_execute($upd)) {
            $success_msg = "Complaint #$cid forwarded to $forward_to.";

            // Email the department user if they have on_forwarded enabled
            // Use prepared statement for safety
            $du_stmt = mysqli_prepare($conn,
                "SELECT user_id, email FROM users WHERE full_name = ? AND role_id IN (5,6,7) LIMIT 1");
            if ($du_stmt) {
                mysqli_stmt_bind_param($du_stmt, 's', $forward_to);
                mysqli_stmt_execute($du_stmt);
                $du_row = mysqli_fetch_assoc(mysqli_stmt_get_result($du_stmt));
                mysqli_stmt_close($du_stmt);

                if ($du_row && !empty($du_row['email'])) {
                    // Get complaint details for the email
                    $comp_stmt = mysqli_prepare($conn,
                        "SELECT c.node_label, c.category, c.path_summary,
                                CONCAT(s.first_name,' ',s.last_name) as student_name,
                                s.registration_number
                         FROM student_ict_complaints c
                         JOIN students s ON c.student_id = s.student_id
                         WHERE c.complaint_id = ?");
                    if ($comp_stmt) {
                        mysqli_stmt_bind_param($comp_stmt, 'i', $cid);
                        mysqli_stmt_execute($comp_stmt);
                        $comp = mysqli_fetch_assoc(mysqli_stmt_get_result($comp_stmt));
                        mysqli_stmt_close($comp_stmt);
                    }

                    if (!empty($comp)) {
                        $subject = "ICT Complaint Forwarded to Your Department — TSU ICT Help Desk";
                        $body    = "Dear $forward_to,\n\n";
                        $body   .= "A student ICT complaint has been forwarded to your department for review.\n\n";
                        $body   .= "Complaint #: $cid\n";
                        $body   .= "Student    : {$comp['student_name']} ({$comp['registration_number']})\n";
                        $body   .= "Category   : {$comp['category']}\n";
                        $body   .= "Issue      : {$comp['node_label']}\n";
                        $body   .= "Path       : {$comp['path_summary']}\n\n";
                        $body   .= "Please log in to review and respond:\n";
                        $body   .= "https://helpdesk.tsuniversity.ng/department_dashboard.php\n\n";
                        $body   .= "Best regards,\nTSU ICT Help Desk";

                        sendDeptEmailIfAllowed($conn, (int)$du_row['user_id'],
                            'on_forwarded', $du_row['email'], $subject, $body);
                    }
                }
            }
        } else {
            $error_msg = "Failed to forward complaint: " . mysqli_error($conn);
        }
        mysqli_stmt_close($upd);
    }

    $msg_text = $success_msg ?: $error_msg;
    $msg_type = $success_msg ? 'success' : 'error';
    header("Location: ict_complaints_admin.php?msg=" . urlencode($msg_text) . "&type=$msg_type");
    exit;
}

// ── Filters ───────────────────────────────────────────────
$f_status   = $_GET['status']   ?? '';
$f_category = $_GET['category'] ?? '';
$f_from     = $_GET['date_from'] ?? '';
$f_to       = $_GET['date_to']   ?? '';
$show_archive = isset($_GET['archive']) && $_GET['archive'] === '1';

$where = ['1=1'];
$params = []; $types = '';

// Default: show only active (non-resolved) unless archive view or explicit status filter
if (empty($f_status) && !$show_archive) {
    $where[] = "c.status NOT IN ('Resolved', 'Rejected', 'Auto-Resolved')";
} elseif ($show_archive && empty($f_status)) {
    $where[] = "c.status IN ('Resolved', 'Rejected', 'Auto-Resolved')";
}

if ($f_status)   { $where[] = 'c.status=?';   $params[] = $f_status;   $types .= 's'; }
if ($f_category) { $where[] = 'c.category=?'; $params[] = $f_category; $types .= 's'; }
if ($f_from)     { $where[] = 'c.created_at>=?'; $params[] = $f_from.' 00:00:00'; $types .= 's'; }
if ($f_to)       { $where[] = 'c.created_at<=?'; $params[] = $f_to.' 23:59:59';   $types .= 's'; }

$wc = implode(' AND ', $where);

// Stats
$stats = ['total'=>0,'pending'=>0,'under_review'=>0,'resolved'=>0,'auto'=>0];
$sr = mysqli_query($conn, "SELECT
    COUNT(*) total,
    SUM(status='Pending') pending,
    SUM(status='Under Review') under_review,
    SUM(status='Resolved') resolved,
    SUM(status='Auto-Resolved') auto_resolved
    FROM student_ict_complaints");
if ($sr && $row = mysqli_fetch_assoc($sr)) {
    $stats = ['total'=>$row['total'],'pending'=>$row['pending'],
              'under_review'=>$row['under_review'],'resolved'=>$row['resolved'],
              'auto'=>$row['auto_resolved']];
}

// Complaints list
$sql = "SELECT c.*,
        CONCAT(s.first_name,' ',s.last_name) AS student_name,
        s.registration_number, s.email,
        sd.department_name, f.faculty_name
        FROM student_ict_complaints c
        JOIN students s ON c.student_id = s.student_id
        LEFT JOIN student_departments sd ON s.department_id = sd.department_id
        LEFT JOIN faculties f ON sd.faculty_id = f.faculty_id
        WHERE $wc
        ORDER BY c.created_at DESC";

$complaints = [];
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $complaints[] = $row;
    mysqli_stmt_close($stmt);
}

// Distinct categories for filter
$cats = [];
$cr = mysqli_query($conn, "SELECT DISTINCT category FROM student_ict_complaints WHERE category!='' ORDER BY category");
if ($cr) while ($row = mysqli_fetch_assoc($cr)) $cats[] = $row['category'];

// Get departments for forwarding
// Get departments + special roles for forwarding
$departments_for_forward = [];
// Departments (role 7), i4Cus staff (role 5), Payment Admin (role 6)
$dept_res = mysqli_query($conn,
    "SELECT user_id, full_name, role_id FROM users
     WHERE role_id IN (5, 6, 7)
     ORDER BY role_id, full_name");
if ($dept_res) {
    while ($r = mysqli_fetch_assoc($dept_res)) {
        $departments_for_forward[] = $r;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ICT Complaints — <?php echo htmlspecialchars($app_name); ?></title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="css/style.css">
<style>
.stat-card { background:#fff; border-radius:10px; padding:1.25rem 1.5rem;
             box-shadow:0 2px 10px rgba(30,60,114,.08); border-left:4px solid #1e3c72; }
.stat-num  { font-size:1.8rem; font-weight:700; color:#1e3c72; }
.stat-lbl  { font-size:.8rem; color:#6c757d; text-transform:uppercase; letter-spacing:.04em; }
.badge-auto { background:#6f42c1; color:#fff; }
.path-text { font-size:.8rem; color:#6c757d; max-width:260px; }

/* ── Proper modal fix — no flicker ── */
/* Let Bootstrap handle the backdrop normally but keep body scrollable */
body.modal-open { overflow: auto !important; padding-right: 0 !important; }
.modal-dialog { margin: 5vh auto; }
</style>
</head>
<body>
<?php
$page_title    = 'ICT Complaints';
$page_subtitle = 'Student ICT & portal complaints';
$page_icon     = 'fas fa-headset';
$show_breadcrumb = true;
$breadcrumb_items = [
    ['title'=>'Admin','url'=>'admin.php'],
    ['title'=>'ICT Complaints','url'=>'']
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

<!-- Stats -->
<div class="row mb-4">
    <?php foreach ([
        ['Total',        $stats['total'],        '#1e3c72'],
        ['Pending',      $stats['pending'],       '#e67e22'],
        ['Under Review', $stats['under_review'],  '#2980b9'],
        ['Resolved',     $stats['resolved'],      '#27ae60'],
        ['Auto-Resolved',$stats['auto'],          '#6f42c1'],
    ] as [$lbl,$num,$col]): ?>
    <div class="col-6 col-md-2 mb-3">
        <div class="stat-card" style="border-left-color:<?php echo $col; ?>">
            <div class="stat-num" style="color:<?php echo $col; ?>"><?php echo (int)$num; ?></div>
            <div class="stat-lbl"><?php echo $lbl; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">Status</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="">All</option>
                    <?php foreach (['Pending','Under Review','Resolved','Rejected','Auto-Resolved'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $f_status===$s?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-2">
                <label class="small font-weight-bold">Category</label>
                <select name="category" class="form-control form-control-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($cats as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $f_category===$cat?'selected':''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_from); ?>">
            </div>
            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_to); ?>">
            </div>
            <div class="col-md-3 mb-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm mr-2">
                    <i class="fas fa-search mr-1"></i>Filter
                </button>
                <a href="ict_complaints_admin.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times mr-1"></i>Clear
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-headset mr-2"></i>
            <?php echo $show_archive ? 'Archived ICT Complaints' : 'Active ICT Complaints Queue'; ?>
            (<?php echo count($complaints); ?>)
        </h5>
        <div>
            <?php if (!$show_archive): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['archive'=>'1'])); ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-archive mr-1"></i>View Archive
                </a>
            <?php else: ?>
                <a href="ict_complaints_admin.php"
                   class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-list mr-1"></i>Back to Active Queue
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Category / Issue</th>
                    <th>Path</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($complaints)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No complaints found.</td></tr>
            <?php else: ?>
            <?php foreach ($complaints as $c): ?>
                <tr>
                    <td><?php echo $c['complaint_id']; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($c['student_name']); ?></strong><br>
                        <small class="text-muted"><?php echo htmlspecialchars($c['registration_number']); ?></small><br>
                        <small class="text-muted"><?php echo htmlspecialchars($c['department_name'] ?? ''); ?></small>
                    </td>
                    <td>
                        <div><?php echo htmlspecialchars($c['category']); ?></div>
                        <small class="text-muted"><?php echo htmlspecialchars($c['node_label']); ?></small>
                    </td>
                    <td>
                        <div class="path-text"><?php echo htmlspecialchars($c['path_summary']); ?></div>
                    </td>
                    <td>
                        <?php
                        $sc = ['Pending'=>'warning','Under Review'=>'info','Resolved'=>'success',
                               'Rejected'=>'danger','Auto-Resolved'=>'secondary'];
                        $bc = $sc[$c['status']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?php echo $bc; ?>"><?php echo htmlspecialchars($c['status']); ?></span>
                        <?php if (!empty($c['forwarded_to'])): ?>
                            <br>
                            <span class="badge badge-light border mt-1" style="font-size:.7rem;color:#0c5460;background:#d1ecf1;border-color:#bee5eb!important">
                                <i class="fas fa-share-square mr-1"></i><?php echo htmlspecialchars($c['forwarded_to']); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary mr-1 btn-view"
                                data-id="<?php echo $c['complaint_id']; ?>"
                                data-name="<?php echo htmlspecialchars($c['student_name'], ENT_QUOTES); ?>"
                                data-reg="<?php echo htmlspecialchars($c['registration_number'], ENT_QUOTES); ?>"
                                data-email="<?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?>"
                                data-dept="<?php echo htmlspecialchars($c['department_name'] ?? '', ENT_QUOTES); ?>"
                                data-faculty="<?php echo htmlspecialchars($c['faculty_name'] ?? '', ENT_QUOTES); ?>"
                                data-category="<?php echo htmlspecialchars($c['category'], ENT_QUOTES); ?>"
                                data-label="<?php echo htmlspecialchars($c['node_label'], ENT_QUOTES); ?>"
                                data-status="<?php echo htmlspecialchars($c['status'], ENT_QUOTES); ?>"
                                data-escalated="<?php echo $c['escalated'] ? 'Yes' : 'No'; ?>"
                                data-date="<?php echo date('M d, Y H:i', strtotime($c['created_at'])); ?>"
                                data-path="<?php echo htmlspecialchars($c['path_summary'], ENT_QUOTES); ?>"
                                data-desc="<?php echo htmlspecialchars($c['description'] ?? '', ENT_QUOTES); ?>"
                                data-auto="<?php echo htmlspecialchars($c['auto_response'] ?? '', ENT_QUOTES); ?>"
                                data-response="<?php echo htmlspecialchars($c['admin_response'] ?? '', ENT_QUOTES); ?>"
                                data-attachment="<?php echo htmlspecialchars($c['attachment_path'] ?? '', ENT_QUOTES); ?>"
                                data-extra="<?php echo htmlspecialchars($c['extra_fields'] ?? '{}', ENT_QUOTES); ?>">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-success mr-1 btn-reply"
                                data-id="<?php echo $c['complaint_id']; ?>"
                                data-name="<?php echo htmlspecialchars($c['student_name'], ENT_QUOTES); ?>"
                                data-label="<?php echo htmlspecialchars($c['node_label'], ENT_QUOTES); ?>"
                                data-status="<?php echo htmlspecialchars($c['status'], ENT_QUOTES); ?>"
                                data-response="<?php echo htmlspecialchars($c['admin_response'] ?? '', ENT_QUOTES); ?>"
                                data-forwarded="<?php echo htmlspecialchars($c['forwarded_to'] ?? '', ENT_QUOTES); ?>"
                                title="Respond to Student">
                            <i class="fas fa-reply"></i>
                        </button>
                        <?php if (in_array($c['status'], ['Pending', 'Under Review'])): ?>
                        <button class="btn btn-sm btn-outline-info mr-1 btn-forward"
                                data-id="<?php echo $c['complaint_id']; ?>"
                                data-name="<?php echo htmlspecialchars($c['student_name'], ENT_QUOTES); ?>"
                                data-label="<?php echo htmlspecialchars($c['node_label'], ENT_QUOTES); ?>"
                                title="Forward to Department">
                            <i class="fas fa-share-square"></i>
                        </button>
                        <?php endif; ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Delete this complaint?')">
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
</div>

</div><!-- /container -->

<!-- ── Shared View Modal (outside table, no flicker) ── -->
<div class="modal fade" id="sharedViewModal" tabindex="-1" role="dialog" aria-labelledby="viewModalTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewModalTitle">Complaint Details</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="sharedViewBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Shared Feedback Modal (outside table, no flicker) ── -->
<div class="modal fade" id="sharedFeedbackModal" tabindex="-1" role="dialog" aria-labelledby="feedbackModalTitle">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="feedbackModalTitle">Respond to Complaint</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" id="feedbackForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="complaint_id" id="feedbackComplaintId">
                    <p class="text-muted small" id="feedbackMeta"></p>
                    <div class="form-group">
                        <label class="font-weight-bold">Update Status</label>
                        <select name="status" id="feedbackStatus" class="form-control" required>
                            <option value="Pending">Pending</option>
                            <option value="Under Review">Under Review</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Response to Student</label>
                        <textarea name="admin_response" id="feedbackResponse" class="form-control manual-clipboard-init" rows="5"
                                  placeholder="Your response will be shown to the student. Your identity will not be revealed."></textarea>
                        <small class="text-muted">
                            <i class="fas fa-shield-alt mr-1"></i>
                            Your name will not be shown to the student.
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Attach Images <span class="text-muted font-weight-normal">(optional)</span></label>
                        <input type="file" id="feedbackImages" name="response_images[]"
                               class="form-control-file" accept="image/*" multiple>
                        <small class="text-muted"><i class="fas fa-info-circle mr-1"></i>Tip: You can also paste screenshots with Ctrl+V in the response box above</small>
                        <div id="feedbackImgPreview" class="d-flex flex-wrap mt-2"></div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">
                            <i class="fas fa-share mr-1"></i>Forward to Department / Unit
                            <span class="text-muted font-weight-normal">(optional)</span>
                        </label>
                        <input type="text" id="forwardSearch" class="form-control mb-1"
                               placeholder="Type to search department or unit…" autocomplete="off">
                        <select name="forwarded_to" id="feedbackForwardTo" class="form-control">
                            <option value="">— Do not forward —</option>
                            <?php
                            $rl = [5=>'i4Cus Staff', 6=>'Payment Admin', 7=>'Department'];
                            $role_tags_fb = [5 => 'i4cus', 6 => 'payment'];
                            $cur_grp = null;
                            foreach ($departments_for_forward as $dept):
                                $grp = $rl[$dept['role_id']] ?? 'Other';
                                if ($grp !== $cur_grp):
                                    if ($cur_grp !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($grp) . '">';
                                    $cur_grp = $grp;
                                endif;
                                $fb_value = isset($role_tags_fb[$dept['role_id']])
                                    ? $role_tags_fb[$dept['role_id']]
                                    : $dept['full_name'];
                            ?>
                                <option value="<?php echo htmlspecialchars($fb_value, ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($dept['full_name']); ?>
                                </option>
                            <?php endforeach; if ($cur_grp !== null) echo '</optgroup>'; ?>
                        </select>
                        <small class="text-muted">If forwarded, the department will be noted in the complaint record.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_feedback" class="btn btn-success">
                        <i class="fas fa-paper-plane mr-1"></i>Send Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Shared Forward Modal (outside table, no flicker) ── -->
<div class="modal fade" id="sharedForwardModal" tabindex="-1" role="dialog" aria-labelledby="forwardModalTitle">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="forwardModalTitle">Forward Complaint</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="POST" id="forwardForm">
                <div class="modal-body">
                    <input type="hidden" name="complaint_id" id="forwardComplaintId">
                    <div class="alert alert-light border">
                        <strong>Student:</strong> <span id="fwStudentName"></span><br>
                        <strong>Issue:</strong> <span id="fwIssueLabel"></span>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Select Department / Unit</label>
                        <input type="text" id="fwDeptSearch" class="form-control mb-2"
                               placeholder="Type to search…" autocomplete="off">
                        <select name="forwarded_to" id="fwDeptSelect" class="form-control" required
                                style="height:auto; max-height:200px; overflow-y:auto;">
                            <option value="">— Select recipient —</option>
                            <?php
                            $rl2 = [5=>'i4Cus Staff', 6=>'Payment Admin', 7=>'Department'];
                            // Role tags used as forwarded_to values for role-based routing
                            $role_tags = [5 => 'i4cus', 6 => 'payment'];
                            $cur_grp2 = null;
                            foreach ($departments_for_forward as $dept):
                                $grp2 = $rl2[$dept['role_id']] ?? 'Other';
                                if ($grp2 !== $cur_grp2):
                                    if ($cur_grp2 !== null) echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($grp2) . '">';
                                    $cur_grp2 = $grp2;
                                endif;
                                // Roles 5 & 6: use role tag so ALL users with that role see it
                                // Role 7 (departments): use full_name for specific routing
                                $opt_value = isset($role_tags[$dept['role_id']])
                                    ? $role_tags[$dept['role_id']]
                                    : $dept['full_name'];
                            ?>
                                <option value="<?php echo htmlspecialchars($opt_value, ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($dept['full_name']); ?>
                                </option>
                            <?php endforeach; if ($cur_grp2 !== null) echo '</optgroup>'; ?>
                        </select>
                        <small class="text-muted">The department name will be recorded on the complaint.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="forward_complaint" class="btn btn-info">
                        <i class="fas fa-share-square mr-1"></i>Forward
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="js/clipboard-paste.js"></script>
<script>
$(function() {
    // Auto-dismiss alerts
    $('.alert').delay(4000).fadeOut();

    // ── View button ──────────────────────────────────────
    $(document).on('click', '.btn-view', function() {
        const d = $(this).data();
        const statusColors = {
            'Pending':'warning','Under Review':'info','Resolved':'success',
            'Rejected':'danger','Auto-Resolved':'secondary'
        };
        const bc = statusColors[d.status] || 'secondary';

        let extraHtml = '';
        try {
            const ef = JSON.parse(d.extra || '{}');
            const filtered = Object.entries(ef).filter(([k,v]) => v !== '' && v !== null && k !== 'ai_category' && k !== 'jamb_login_password');
            if (filtered.length) {
                extraHtml = '<h6 class="mt-3">Extra Information Provided</h6><table class="table table-sm table-bordered">';
                filtered.forEach(([k,v]) => {
                    const label = k.replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase());
                    extraHtml += `<tr><td class="font-weight-bold" style="width:40%">${esc(label)}</td><td>${esc(String(v))}</td></tr>`;
                });
                extraHtml += '</table>';
            }
        } catch(e) {}

        const autoHtml = d.auto
            ? `<div class="alert alert-info mt-3"><strong>Auto-Response Shown to Student:</strong><br>${esc(d.auto).replace(/\n/g,'<br>')}</div>`
            : '';
        const respHtml = d.response
            ? `<div class="alert alert-success mt-3"><strong>ICT Response:</strong><br>${esc(d.response).replace(/\n/g,'<br>')}</div>`
            : '';
        const descHtml = d.desc
            ? `<h6 class="mt-3">Additional Details</h6><p>${esc(d.desc).replace(/\n/g,'<br>')}</p>`
            : '';

        const attachmentHtml = d.attachment
            ? `<h6 class="mt-3">Attachment</h6><a href="${esc(d.attachment)}" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-file-download mr-1"></i> View Attached File</a>`
            : '';

        const body = `
            <div class="row">
                <div class="col-md-6">
                    <h6>Student</h6>
                    <p><strong>Name:</strong> ${esc(d.name)}</p>
                    <p><strong>Reg No:</strong> ${esc(d.reg)}</p>
                    <p><strong>Email:</strong> ${esc(d.email)}</p>
                    <p><strong>Department:</strong> ${esc(d.dept || 'N/A')}</p>
                    <p><strong>Faculty:</strong> ${esc(d.faculty || 'N/A')}</p>
                </div>
                <div class="col-md-6">
                    <h6>Complaint</h6>
                    <p><strong>Category:</strong> ${esc(d.category)}</p>
                    <p><strong>Issue:</strong> ${esc(d.label)}</p>
                    <p><strong>Status:</strong> <span class="badge badge-${bc}">${esc(d.status)}</span></p>
                    <p><strong>Escalated:</strong> ${esc(d.escalated)}</p>
                    <p><strong>Submitted:</strong> ${esc(d.date)}</p>
                </div>
            </div>
            <hr>
            <h6>Decision Path</h6>
            <p class="text-muted">${esc(d.path)}</p>
            ${descHtml}${attachmentHtml}${autoHtml}${respHtml}${extraHtml}`;

        $('#viewModalTitle').text('Complaint #' + d.id + ' — Details');
        $('#sharedViewBody').html(body);
        $('#sharedViewModal').modal('show');
    });

    // ── Reply button ─────────────────────────────────────
    $(document).on('click', '.btn-reply', function() {
        const d = $(this).data();
        $('#feedbackModalTitle').text('Respond to Complaint #' + d.id);
        $('#feedbackComplaintId').val(d.id);
        $('#feedbackMeta').html('<strong>Student:</strong> ' + esc(d.name) + '<br><strong>Issue:</strong> ' + esc(d.label));
        $('#feedbackStatus').val(d.status);
        $('#feedbackResponse').val(d.response || '');
        $('#feedbackForwardTo').val(d.forwarded || '');
        $('#forwardSearch').val('');
        // Reset forward dropdown to show all options
        $('#feedbackForwardTo option').show();
        $('#feedbackImages').val('');
        $('#feedbackImgPreview').empty();
        $('#sharedFeedbackModal').modal('show');

        // Init clipboard paste after modal opens
        $('#sharedFeedbackModal').one('shown.bs.modal', function() {
            const ta = document.getElementById('feedbackResponse');
            const fi = document.getElementById('feedbackImages');
            if (ta && fi && typeof initializeClipboardPaste === 'function') {
                initializeClipboardPaste(ta, fi);
            }
        });
    });

    // Image preview for feedback form
    $('#feedbackImages').on('change', function() {
        const preview = $('#feedbackImgPreview');
        preview.empty();
        Array.from(this.files).forEach(file => {
            const reader = new FileReader();
            reader.onload = e => {
                preview.append(`<div class="mr-2 mb-2"><img src="${e.target.result}" style="max-height:80px;max-width:100px;border-radius:4px;border:1px solid #dee2e6"></div>`);
            };
            reader.readAsDataURL(file);
        });
    });

    // Live search for forward dropdown
    $('#forwardSearch').on('input', function() {
        const q = $(this).val().toLowerCase();
        $('#feedbackForwardTo option').each(function() {
            const txt = $(this).text().toLowerCase();
            $(this).toggle(txt.includes(q) || $(this).val() === '');
        });
        // Reset selection if current doesn't match
        const cur = $('#feedbackForwardTo').val();
        if (cur && !$('#feedbackForwardTo option:selected').is(':visible')) {
            $('#feedbackForwardTo').val('');
        }
    });

    // ── Forward button ─────────────────────────────────────
    $(document).on('click', '.btn-forward', function() {
        const d = $(this).data();
        $('#forwardComplaintId').val(d.id);
        $('#fwStudentName').text(d.name);
        $('#fwIssueLabel').text(d.label);
        $('#fwDeptSearch').val('');
        $('#fwDeptSelect option').show();
        $('#fwDeptSelect').val([]);
        $('#sharedForwardModal').modal('show');
    });

    // Handle department search filtering inside the modal
    $('#fwDeptSearch').on('input', function() {
        const val = $(this).val().toLowerCase();
        $('#fwDeptSelect option').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(val) > -1);
        });
    });

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str || '');
        return d.innerHTML;
    }
});
</script>
</body>
</html>

