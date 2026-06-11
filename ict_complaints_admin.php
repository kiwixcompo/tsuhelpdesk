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

// Ensure student_ict_replies table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_ict_replies (
    reply_id     INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    sender_type  ENUM('student','staff') NOT NULL DEFAULT 'student',
    sender_id    INT NOT NULL,
    reply_text   TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_complaint (complaint_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

    $forwarded_to = trim($_POST['forwarded_to'] ?? '');
    // If a forwarded department/unit is specified, force status to 'Under Review'
    if (!empty($forwarded_to)) {
        $status = 'Under Review';
    }

    // Process response image uploads
    $response_image_paths = array();
    if(isset($_FILES["response_images"]) && !empty($_FILES["response_images"]["name"][0])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        foreach($_FILES["response_images"]["tmp_name"] as $key => $tmp_name){
            if($_FILES["response_images"]["error"][$key] == 0){
                $filename = $_FILES["response_images"]["name"][$key];
                $filetype = $_FILES["response_images"]["type"][$key];
                $filesize = $_FILES["response_images"]["size"][$key];
                
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if(!array_key_exists($ext, $allowed)) continue;
                
                $maxsize = 5 * 1024 * 1024;
                if($filesize > $maxsize) continue;
                
                if(in_array($filetype, $allowed)){
                    $new_filename = "response_" . uniqid() . "." . $ext;
                    $target_file = $target_dir . $new_filename;
                    
                    if(move_uploaded_file($tmp_name, $target_file)){
                        chmod($target_file, 0644);
                        $response_image_paths[] = $target_file;
                    }
                }
            }
        }
    }
    
    // Append attached images to the response
    if (!empty($response_image_paths)) {
        foreach ($response_image_paths as $path) {
            $response .= "\n[Attached Image: " . $path . "]";
        }
    }

    $upd = mysqli_prepare($conn,
        "UPDATE student_ict_complaints
         SET status=?, admin_response=?, handled_by=?, forwarded_to=?, updated_at=NOW()
         WHERE complaint_id=?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'ssisi', $status, $response, $_SESSION['user_id'], $forwarded_to, $cid);
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

                            $headers  = "From: TSU ICT Help Desk <complaints@tsuniversity.edu.ng>\r\n";
                            $headers .= "Reply-To: complaints@tsuniversity.edu.ng\r\n";
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
                        "SELECT c.node_label, c.category, c.path_summary, c.attachment_path,
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
                        $body   .= "Path       : {$comp['path_summary']}\n";
                        if (!empty($comp['attachment_path'])) {
                            $body .= "Attachment : https://helpdesk.tsuniversity.ng/{$comp['attachment_path']}\n";
                        }
                        $body   .= "\nPlease log in to review and respond:\n";
                        $body   .= "https://helpdesk.tsuniversity.ng/department_dashboard.php\n\n";
                        $body   .= "Best regards,\nTSU ICT Help Desk";

                        sendDeptEmailIfAllowed($conn, (int)$du_row['user_id'],
                            'on_forwarded', $du_row['email'], $subject, $body, $comp['attachment_path'] ?? '');
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

// ── Filters & Pagination ──────────────────────────────────
$f_search   = trim($_GET['search'] ?? '');
$f_status   = $_GET['status']   ?? '';
$f_category = $_GET['category'] ?? '';
$f_from     = $_GET['date_from'] ?? '';
$f_to       = $_GET['date_to']   ?? '';
$show_archive = isset($_GET['archive']) && $_GET['archive'] === '1';
$f_sort_order = $_GET['sort_order'] ?? 'oldest';
$order_by_sql = ($f_sort_order === 'newest') ? 'DESC' : 'ASC';

$where = ['1=1'];
$params = []; $types = '';

// If a search is done, ignore status/archive view filters to search entire records globally
if ($f_search !== '') {
    $where[] = "(s.registration_number LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR c.description LIKE ? OR c.node_label LIKE ?)";
    $search_param = '%' . $f_search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
    
    // Maintain optional category and date filters if set
    if ($f_category) { $where[] = 'c.category=?'; $params[] = $f_category; $types .= 's'; }
    if ($f_from)     { $where[] = 'c.created_at>=?'; $params[] = $f_from.' 00:00:00'; $types .= 's'; }
    if ($f_to)       { $where[] = 'c.created_at<=?'; $params[] = $f_to.' 23:59:59';   $types .= 's'; }
} else {
    // Standard view filters
    if ($f_status === 'all') {
        // Show all complaints
    } elseif ($f_status === 'Pending') {
        $where[] = "(c.status = 'Pending' OR (c.status NOT IN ('Resolved', 'Rejected', 'Auto-Resolved') AND (SELECT COUNT(*) FROM student_ict_replies r WHERE r.complaint_id = c.complaint_id AND r.sender_type = 'student' AND r.created_at > c.updated_at) > 0))";
    } elseif ($f_status === 'Under Review') {
        $where[] = "(c.status = 'Under Review' AND (SELECT COUNT(*) FROM student_ict_replies r WHERE r.complaint_id = c.complaint_id AND r.sender_type = 'student' AND r.created_at > c.updated_at) = 0)";
    } elseif ($f_status && $f_status !== 'all') {
        $where[] = 'c.status=?';
        $params[] = $f_status;
        $types .= 's';
    } elseif (empty($f_status) && !$show_archive) {
        // Default view: Active queue (Pending or student-replied active complaints)
        $where[] = "(c.status = 'Pending' OR (c.status NOT IN ('Resolved', 'Rejected', 'Auto-Resolved') AND (SELECT COUNT(*) FROM student_ict_replies r WHERE r.complaint_id = c.complaint_id AND r.sender_type = 'student' AND r.created_at > c.updated_at) > 0))";
    } elseif ($show_archive && empty($f_status)) {
        $where[] = "c.status IN ('Resolved', 'Rejected', 'Auto-Resolved')";
    }

    if ($f_category) { $where[] = 'c.category=?'; $params[] = $f_category; $types .= 's'; }
    if ($f_from)     { $where[] = 'c.created_at>=?'; $params[] = $f_from.' 00:00:00'; $types .= 's'; }
    if ($f_to)       { $where[] = 'c.created_at<=?'; $params[] = $f_to.' 23:59:59';   $types .= 's'; }
}

$wc = implode(' AND ', $where);

// --- Dynamic Pagination Logic Setup ---
$default_limit = 10;
$allowed_limits = [10, 20, 50, 100, 'all'];
$per_page = isset($_GET['limit']) ? $_GET['limit'] : $default_limit;

if (!in_array($per_page, $allowed_limits)) {
    $per_page = $default_limit;
}

// Count total matching complaints for current filters
$count_sql = "SELECT COUNT(*) as total
              FROM student_ict_complaints c
              JOIN students s ON c.student_id = s.student_id
              LEFT JOIN student_departments sd ON s.department_id = sd.department_id
              LEFT JOIN faculties f ON sd.faculty_id = f.faculty_id
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
$stats = ['total'=>0,'pending'=>0,'under_review'=>0,'resolved'=>0,'auto'=>0];
$sr = mysqli_query($conn, "SELECT
    COUNT(*) total,
    SUM(status='Pending' OR (status NOT IN ('Resolved', 'Rejected', 'Auto-Resolved') AND (SELECT COUNT(*) FROM student_ict_replies r WHERE r.complaint_id = student_ict_complaints.complaint_id AND r.sender_type = 'student' AND r.created_at > student_ict_complaints.updated_at) > 0)) pending,
    SUM(status='Under Review' AND (SELECT COUNT(*) FROM student_ict_replies r WHERE r.complaint_id = student_ict_complaints.complaint_id AND r.sender_type = 'student' AND r.created_at > student_ict_complaints.updated_at) = 0) under_review,
    SUM(status='Resolved') resolved,
    SUM(status='Auto-Resolved') auto_resolved
    FROM student_ict_complaints");
if ($sr && $row = mysqli_fetch_assoc($sr)) {
    $stats = ['total'=>$row['total'],'pending'=>$row['pending'],
              'under_review'=>$row['under_review'],'resolved'=>$row['resolved'],
              'auto'=>$row['auto_resolved']];
}

// Complaints list with dynamic limit and offset
$sql = "SELECT c.*,
        CONCAT(s.first_name,' ',s.last_name) AS student_name,
        s.registration_number, s.email,
        sd.department_name, f.faculty_name,
        (SELECT COUNT(*) FROM student_ict_replies r WHERE r.complaint_id = c.complaint_id AND r.sender_type = 'student' AND r.created_at > c.updated_at) AS student_replied
        FROM student_ict_complaints c
        JOIN students s ON c.student_id = s.student_id
        LEFT JOIN student_departments sd ON s.department_id = sd.department_id
        LEFT JOIN faculties f ON sd.faculty_id = f.faculty_id
        WHERE $wc
        ORDER BY c.created_at $order_by_sql
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
.badge-auto { background:#6f42c1; color:#fff; }
.path-text { font-size:.8rem; color:#6c757d; max-width:260px; }

/* ── Proper modal fix — no flicker ── */
/* Let Bootstrap handle the backdrop normally but keep body scrollable */
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
<?php
$val_map = [
    'Total' => 'all',
    'Pending' => 'Pending',
    'Under Review' => 'Under Review',
    'Resolved' => 'Resolved',
    'Auto-Resolved' => 'Auto-Resolved'
];
?>
<div class="row mb-4">
    <?php foreach ([
        ['Total',        $stats['total'],        '#1e3c72'],
        ['Pending',      $stats['pending'],       '#e67e22'],
        ['Under Review', $stats['under_review'],  '#2980b9'],
        ['Resolved',     $stats['resolved'],      '#27ae60'],
        ['Auto-Resolved',$stats['auto'],          '#6f42c1'],
    ] as [$lbl,$num,$col]): 
        $val = $val_map[$lbl] ?? '';
        $card_params = $_GET;
        $card_params['status'] = $val;
        $card_params['page'] = 1; // Reset page to 1 on tab click
        // Adjust archive param based on selection
        if ($val === 'Resolved' || $val === 'Auto-Resolved') {
            $card_params['archive'] = '1';
        } elseif ($val === 'all') {
            unset($card_params['archive']);
        } else {
            unset($card_params['archive']);
        }
        $card_url = 'ict_complaints_admin.php?' . http_build_query($card_params);
    ?>
    <div class="col-6 col-md-2 mb-3">
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
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold"><i class="fas fa-search mr-1"></i>Search Keyword</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Name, matric, or details…" value="<?php echo htmlspecialchars($f_search); ?>">
            </div>
            <!-- Status Filter -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold">Status</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="" <?php echo $f_status===''?'selected':''; ?>>Active (Queue)</option>
                    <option value="all" <?php echo $f_status==='all'?'selected':''; ?>>All (incl. Resolved)</option>
                    <?php foreach (['Pending','Under Review','Resolved','Rejected','Auto-Resolved'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $f_status===$s?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Category Filter -->
            <div class="col-md-2 col-sm-6 mb-2">
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
            <!-- Sort Order Filter -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold"><i class="fas fa-sort-amount-down-alt mr-1"></i>Sort Order</label>
                <select name="sort_order" class="form-control form-control-sm">
                    <option value="oldest" <?php echo $f_sort_order==='oldest'?'selected':''; ?>>Oldest First</option>
                    <option value="newest" <?php echo $f_sort_order==='newest'?'selected':''; ?>>Newest First</option>
                </select>
            </div>
            <!-- Date range: From -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_from); ?>">
            </div>
            <!-- Date range: To -->
            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($f_to); ?>">
            </div>
            <!-- Submit/Reset Buttons -->
            <div class="col-12 mt-2 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary btn-sm px-4 mr-2">
                    <i class="fas fa-filter mr-1"></i>Apply Filters
                </button>
                <a href="ict_complaints_admin.php" class="btn btn-secondary btn-sm px-4">
                    <i class="fas fa-sync-alt mr-1"></i>Reset
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
            (<span id="complaintsCount"><?php echo $total_filtered_complaints; ?></span>)
        </h5>
        <div>
            <?php if (!$show_archive): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['archive'=>'1'])); ?>"
                   class="btn btn-sm btn-outline-primary">
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
                <tr class="complaint-row">
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
                        <?php if ($c['student_replied'] > 0): ?>
                            <span class="badge badge-success mt-1 d-inline-block" style="background-color: #2ec4b6; border-color: #2ec4b6; color: white;">
                                <i class="fas fa-reply mr-1"></i>Student Responded
                            </span>
                        <?php elseif ($c['status'] === 'Under Review' && !empty($c['admin_response'])): ?>
                            <span class="badge badge-success mt-1 d-inline-block" style="background-color: #2ec4b6; border-color: #2ec4b6; color: white;">
                                <i class="fas fa-comment-dots mr-1"></i>Feedback Gotten
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($c['forwarded_to'])): ?>
                            <br>
                            <span class="badge badge-light border mt-1" style="font-size:.7rem;color:#0c5460;background:#d1ecf1;border-color:#bee5eb!important">
                                <i class="fas fa-share-square mr-1"></i><?php echo htmlspecialchars($c['forwarded_to']); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                    <td>
                        <button class="btn btn-sm btn-primary btn-view-respond"
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
                                data-extra="<?php echo htmlspecialchars($c['extra_fields'] ?? '{}', ENT_QUOTES); ?>"
                                data-forwarded="<?php echo htmlspecialchars($c['forwarded_to'] ?? '', ENT_QUOTES); ?>"
                                data-student-replied="<?php echo $c['student_replied'] > 0 ? '1' : '0'; ?>"
                                title="View & Respond">
                            <i class="fas fa-eye mr-1"></i>View & Respond
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
                // Clean $_GET to construct base query parameters without limit and page
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
                        <!-- Previous Page -->
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($page_params, ['page' => $page - 1])); ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <!-- Page Numbers -->
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
                        
                        <!-- Next Page -->
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($page_params, ['page' => $page + 1])); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</div><!-- /container -->

<!-- ── Combined View & Respond Modal ── -->
<div class="modal fade" id="sharedViewModal" tabindex="-1" role="dialog" aria-labelledby="viewModalTitle">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff">
                <h5 class="modal-title" id="viewModalTitle"><i class="fas fa-headset mr-2"></i>Complaint Details</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <!-- Details section -->
                <div id="sharedViewBody" class="mb-4"></div>
                <!-- Respond section -->
                <div id="respondSection">
                    <hr>
                    <h6 class="text-primary font-weight-bold mb-3"><i class="fas fa-reply mr-2"></i>Respond to Student</h6>
                    <form method="POST" id="feedbackForm" enctype="multipart/form-data">
                        <input type="hidden" name="complaint_id" id="feedbackComplaintId">
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
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="font-weight-bold mb-0">Response to Student</label>
                                <div class="d-flex align-items-center" style="gap: 8px;">
                                    <span id="puterStatusPill" class="badge badge-light border py-1 px-2" style="font-size: 0.72rem; cursor: pointer; display: none; border-radius: 20px; font-weight: 600;" title="Click to switch Puter accounts">
                                        <i class="fas fa-robot text-purple mr-1" style="color: #7F00FF;"></i> Puter: <span id="puterActiveUser" class="text-primary">Loading...</span>
                                    </span>
                                    <button type="button" id="btnDraftWithAI" class="btn btn-sm" style="display: none; background: linear-gradient(135deg, #7F00FF, #E100FF); color: white; border: none; border-radius: 20px; padding: 0.25rem 0.85rem; font-size: 0.75rem; font-weight: 600; box-shadow: 0 2px 8px rgba(225, 0, 255, 0.3); transition: all 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(225, 0, 255, 0.5)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(225, 0, 255, 0.3)';">
                                        <i class="fas fa-robot mr-1"></i> Draft with AI
                                    </button>
                                    <button type="button" id="btnRephraseWithAI" class="btn btn-sm" style="background: linear-gradient(135deg, #00b4db, #0083b0); color: white; border: none; border-radius: 20px; padding: 0.25rem 0.85rem; font-size: 0.75rem; font-weight: 600; box-shadow: 0 2px 8px rgba(0, 180, 219, 0.3); transition: all 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(0, 180, 219, 0.5)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(0, 180, 219, 0.3)';">
                                        <i class="fas fa-magic mr-1"></i> Rephrase
                                    </button>
                                </div>
                            </div>
                            <textarea name="admin_response" id="feedbackResponse" class="form-control manual-clipboard-init" rows="4"
                                      placeholder="Your response will be shown to the student. Your identity will not be revealed."></textarea>
                            <small class="text-muted"><i class="fas fa-shield-alt mr-1"></i>Your name will not be shown to the student. Paste screenshots with Ctrl+V.</small>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Attach Images <span class="text-muted font-weight-normal">(optional)</span></label>
                            <input type="file" id="feedbackImages" name="response_images[]" class="form-control-file" accept="image/*" multiple>
                            <div id="feedbackImgPreview" class="d-flex flex-wrap mt-2"></div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold"><i class="fas fa-share mr-1"></i>Forward to Department / Unit <span class="text-muted font-weight-normal">(optional)</span></label>
                            <input type="text" id="forwardSearch" class="form-control mb-1" placeholder="Type to search department or unit…" autocomplete="off">
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
                        </div>
                        <div class="modal-footer px-0 pb-0">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button type="submit" name="submit_feedback" class="btn btn-success">
                                <i class="fas fa-paper-plane mr-1"></i>Send Response
                            </button>
                        </div>
                    </form>
                </div>
            </div>
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
    
    // 1. If it's already a string, return it
    if (typeof result === 'string') {
        return result.trim();
    }
    
    // 2. If it's an object, check standard paths
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
        
        // 3. Recursive deep search for the longest non-metadata string
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
                    } catch (e) {
                        // ignore key access errors
                    }
                }
            }
        }
        
        search(result);
        if (longestStr) {
            console.log('extractAIText successfully extracted text via deep search:', longestStr);
            return longestStr;
        }
        
        // 4. Try toString() safely as a last resort
        try {
            if (typeof result.toString === 'function') {
                const strVal = result.toString();
                if (typeof strVal === 'string' && strVal !== '[object Object]') {
                    return strVal.trim();
                }
            }
        } catch (e) {
            // ignore toString errors
        }
    }
    return '';
}

$(function() {
    let currentComplaintHistory = [];
    let currentComplaintContext = {};

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
    }            const rawText = extractAIText(result);
                
                if (rawText && $textarea.is(':focus') && $textarea.val() === typedText) {
                    setGhostText(rawText);
                }
            } catch (err) {
                console.error('Puter Autocomplete Error:', err);
            }
        }
    }

    // Initialize autocomplete on feedback response
    initResponseAutocomplete('#feedbackResponse', function() { return currentComplaintHistory; }, function() { return currentComplaintContext; });

    // Initialize clipboard paste for complaint response feedback
    try {
        if (typeof initializeClipboardPaste === 'function') {
            initializeClipboardPaste(document.getElementById('feedbackResponse'), document.getElementById('feedbackImages'));
        }
    } catch (e) {
        console.error("Clipboard paste handler ready initialization failed:", e);
    }

    // Auto-dismiss alerts
    $('.alert').delay(4000).fadeOut();

    // ── View & Respond button (combined) ─────────────────
    $(document).on('click', '.btn-view-respond', function() {
        const d = $(this).data();
        currentComplaintContext = {
            category: d.category || '',
            description: d.desc || ''
        };
        const statusColors = {
            'Pending':'warning','Under Review':'info','Resolved':'success',
            'Rejected':'danger','Auto-Resolved':'secondary'
        };
        const bc = statusColors[d.status] || 'secondary';

        let badgeHtml = `<span class="badge badge-${bc}">${esc(d.status)}</span>`;
        if (d.studentReplied == 1 || d.studentReplied == '1') {
            badgeHtml += ` <span class="badge badge-success ml-1 d-inline-block" style="background-color: #2ec4b6; border-color: #2ec4b6; color: white;"><i class="fas fa-reply mr-1"></i>Student Responded</span>`;
        } else if (d.status === 'Under Review' && d.response) {
            badgeHtml += ` <span class="badge badge-success ml-1 d-inline-block" style="background-color: #2ec4b6; border-color: #2ec4b6; color: white;"><i class="fas fa-comment-dots mr-1"></i>Feedback Gotten</span>`;
        }

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
            ? `<div class="alert alert-success mt-3"><strong>Current ICT Response:</strong><br>${esc(d.response).replace(/\n/g,'<br>')}</div>`
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
                    <h6 class="text-muted text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Student</h6>
                    <p class="mb-1"><strong>${esc(d.name)}</strong></p>
                    <p class="mb-1 text-muted small">${esc(d.reg)}</p>
                    <p class="mb-1 text-muted small">${esc(d.email)}</p>
                    <p class="mb-1 text-muted small">${esc(d.dept || 'N/A')} &bull; ${esc(d.faculty || 'N/A')}</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Complaint</h6>
                    <p class="mb-1"><strong>${esc(d.category)}</strong></p>
                    <p class="mb-1">${esc(d.label)}</p>
                    <p class="mb-1">${badgeHtml}</p>
                    <p class="mb-1 text-muted small">${esc(d.date)}</p>
                </div>
            </div>
            <hr>
            <h6 class="text-muted text-uppercase" style="font-size:.72rem;letter-spacing:.05em">Decision Path</h6>
            <p class="text-muted small">${esc(d.path)}</p>
            ${descHtml}${attachmentHtml}${autoHtml}${respHtml}${extraHtml}
            <div id="ictRepliesContainer" style="display: none;" class="mt-4"></div>`;

        $('#viewModalTitle').html('<i class="fas fa-headset mr-2"></i>Complaint #' + d.id + ' — ' + esc(d.label));
        $('#sharedViewBody').html(body);

        // Fetch and display replies
        const repliesContainer = $('#ictRepliesContainer');
        repliesContainer.hide().empty();
        $.getJSON('api/get_ict_replies.php', { complaint_id: d.id }, function(res) {
            if (res.success && res.replies && res.replies.length > 0) {
                let repliesHtml = `
                    <hr>
                    <h6 class="text-primary font-weight-bold mb-3"><i class="fas fa-comments mr-2"></i>Conversation History</h6>
                    <div style="max-height: 350px; overflow-y: auto; padding-right: 5px;">
                `;
                res.replies.forEach(reply => {
                    const isStudent = reply.sender_type === 'student';
                    const icon = isStudent ? 'fa-user-graduate' : 'fa-user-shield';
                    const color = isStudent ? 'success' : 'primary';
                    const senderTitle = isStudent ? 'Student' : 'Staff';
                    
                    repliesHtml += `
                        <div class="mb-3 p-3 bg-light rounded shadow-sm border-left border-${color}" style="border-left-width:3px!important">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="font-weight-bold text-${color}" style="font-size: 0.9rem;">
                                    <i class="fas ${icon} mr-1"></i> ${esc(reply.sender_name)} (${senderTitle})
                                </span>
                                <small class="text-muted" style="font-size: 0.75rem;">${esc(reply.created_at)}</small>
                            </div>
                            <p class="mb-0 text-dark small" style="white-space: pre-line; line-height: 1.45;">${esc(reply.reply_text)}</p>
                        </div>
                    `;
                });
                repliesHtml += '</div>';
                repliesContainer.html(repliesHtml).show();
            }
        });

        // Pre-fill the response form
        $('#feedbackComplaintId').val(d.id);
        $('#feedbackStatus').val(d.status);
        $('#feedbackResponse').val(d.response || '');
        $('#feedbackForwardTo').val(d.forwarded || '');
        $('#forwardSearch').val('');
        $('#feedbackForwardTo option').show();
        $('#feedbackImages').val('');
        $('#feedbackImgPreview').empty();

        // Check if there is historical feedback to enable AI responses
        $('#btnDraftWithAI').hide().removeData('history');
        $('#puterStatusPill').hide();
        currentComplaintHistory = [];
        if (d.category) {
            $.getJSON('api/get_historical_feedback.php', { category: d.category, complaint_id: d.id }, function(res) {
                if (res.success && res.history && res.history.length > 0) {
                    $('#btnDraftWithAI').data('history', res.history).show();
                    if (window.updatePuterPill) window.updatePuterPill();
                    currentComplaintHistory = res.history;
                }
            });
        }

        // Show/hide respond section based on status
        const resolved = ['Resolved','Rejected','Auto-Resolved'];
        $('#respondSection').toggle(!resolved.includes(d.status));

        $('#sharedViewModal').modal('show');

        // Init clipboard paste after modal opens
        $('#sharedViewModal').one('shown.bs.modal', function() {
            const ta = document.getElementById('feedbackResponse');
            const fi = document.getElementById('feedbackImages');
            try {
                if (ta && fi && typeof initializeClipboardPaste === 'function') {
                    initializeClipboardPaste(ta, fi);
                }
            } catch (e) {
                console.error("Clipboard paste handler modal initialization failed:", e);
            }
        });
    });

    // ── Puter AI response generator click handler ────────────────────────
    $(document).on('click', '#btnDraftWithAI', async function() {
        const btn = $(this);
        const originalHtml = btn.html();
        const historyData = btn.data('history') || [];
        
        if (!historyData.length) return;
        
        // Find current complaint description from modal body
        let currentDesc = '';
        $('#sharedViewBody p').each(function() {
            if ($(this).prev('h6').text().includes('Additional Details')) {
                currentDesc = $(this).text().trim();
            }
        });
        
        // If not found in additional details, fallback to the label/title
        if (!currentDesc) {
            currentDesc = $('#viewModalTitle').text().replace(/Complaint #\d+\s*—\s*/, '').trim();
        }
        
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Coining...');
        
        try {
            let examplesPrompt = "";
            historyData.forEach((h, index) => {
                examplesPrompt += `Example #${index + 1}:\nStudent Complaint: "${h.description}"\nAdmin Response: "${h.admin_response}"\n\n`;
            });
            
            const prompt = `You are a helpful university ICT Support assistant. 
Below are historical examples of similar student complaints and the correct professional feedback responses that were given:

${examplesPrompt}
Now, draft a highly professional, polite, and helpful response for this new student complaint. Tweak, refine, and polish the English phrasing to make it clear, grammatically flawless, and exceptionally professional, while retaining the correct technical instructions from similar historical responses:
New Complaint Description: "${currentDesc}"

Return ONLY the response text that the admin should send to the student. Do not write any intro or outro (e.g. do not say "Here is a response" or "Dear student"). Just output the exact text to be pasted into the response box.`;


            const result = await puter.ai.chat(prompt);
            const generatedResponse = extractAIText(result);
            
            if (generatedResponse) {
                $('#feedbackResponse').val(generatedResponse);
                
                // Glimmering green glow effect to show AI success
                const ta = $('#feedbackResponse');
                ta.css('transition', 'all 0.4s');
                ta.css('box-shadow', '0 0 15px rgba(46, 196, 182, 0.8)');
                ta.css('border-color', '#2ec4b6');
                
                setTimeout(() => {
                    ta.css('box-shadow', '');
                    ta.css('border-color', '');
                }, 2000);
            } else {
                alert('AI generated an empty response. Please try again.');
            }
        } catch (e) {
            console.error('Puter AI error:', e);
            alert('Could not generate response with AI: ' + e.message);
        } finally {
            btn.prop('disabled', false).html(originalHtml);
        }
    });

    // Handle past response selection
    $(document).on('change', '#selPastResponse', function() {
        const val = $(this).val();
        if (val) {
            $('#feedbackResponse').val(val);
            
            // Glow effect
            const ta = $('#feedbackResponse');
            ta.css('transition', 'all 0.4s');
            ta.css('box-shadow', '0 0 15px rgba(40, 167, 69, 0.8)');
            ta.css('border-color', '#28a745');
            setTimeout(() => {
                ta.css('box-shadow', '');
                ta.css('border-color', '');
            }, 1500);
        }
    });

    // ── Puter AI rephrase click handler ────────────────────────
    $(document).on('click', '#btnRephraseWithAI', async function() {
        const btn = $(this);
        const originalHtml = btn.html();
        const textarea = $('#feedbackResponse');
        const currentText = textarea.val().trim();
        
        if (!currentText) {
            alert('Please type some text in the response box first before rephrasing.');
            return;
        }
        
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Rephrasing...');
        
        try {
            const prompt = `You are a helpful university ICT Support assistant.
An ICT admin has drafted a response to a student's complaint. Help rephrase, polish, and refine this draft to make it sound highly professional, exceptionally polite, clear, and grammatically flawless, while keeping all original meaning, key technical details, instructions, and facts exactly the same:

Draft response: "${currentText}"

Return ONLY the professionally rephrased response text that the admin should send to the student. Do not write any intro or outro (e.g. do not say "Here is the rephrased version:" or "Dear student"). Just output the exact text to be pasted into the response box.`;

            const result = await puter.ai.chat(prompt);
            const generatedResponse = extractAIText(result);
            
            if (generatedResponse) {
                textarea.val(generatedResponse);
                
                // Glimmering teal glow effect to show AI success
                textarea.css('transition', 'all 0.4s');
                textarea.css('box-shadow', '0 0 15px rgba(0, 180, 219, 0.8)');
                textarea.css('border-color', '#00b4db');
                
                setTimeout(() => {
                    textarea.css('box-shadow', '');
                    textarea.css('border-color', '');
                }, 2000);
            } else {
                alert('AI generated an empty response. Please try again.');
            }
        } catch (e) {
            console.error('Puter AI error:', e);
            alert('Could not rephrase response with AI: ' + e.message);
        } finally {
            btn.prop('disabled', false).html(originalHtml);
        }
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

    // Live Search on input
    $('input[name="search"]').on('input', function() {
        let query = $(this).val().toLowerCase().trim();
        let matchCount = 0;
        
        $('.complaint-row').each(function() {
            let row = $(this);
            let name = row.find('td:nth-child(2) strong').text().toLowerCase();
            let matric = row.find('td:nth-child(2) small:nth-of-type(1)').text().toLowerCase();
            let dept = row.find('td:nth-child(2) small:nth-of-type(2)').text().toLowerCase();
            let category = row.find('td:nth-child(3) div').text().toLowerCase();
            let label = row.find('td:nth-child(3) small').text().toLowerCase();
            let path = row.find('td:nth-child(4) .path-text').text().toLowerCase();
            
            let btn = row.find('.btn-view-respond');
            let desc = (btn.attr('data-desc') || btn.data('desc') || '').toString().toLowerCase();
            let extra = '';
            try {
                let extraData = btn.attr('data-extra') || btn.data('extra') || '';
                extra = (typeof extraData === 'object') ? JSON.stringify(extraData) : extraData.toString();
            } catch(e){}
            extra = extra.toLowerCase();
            
            if (name.includes(query) || 
                matric.includes(query) || 
                dept.includes(query) ||
                category.includes(query) || 
                label.includes(query) || 
                path.includes(query) ||
                desc.includes(query) || 
                extra.includes(query)) {
                row.show();
                matchCount++;
            } else {
                row.hide();
            }
        });
        
        $('#complaintsCount').text(matchCount);
        
        if (matchCount === 0) {
            if ($('#noMatchesRow').length === 0) {
                $('.table tbody').append('<tr id="noMatchesRow"><td colspan="7" class="text-center py-4 text-muted">No matching complaints found.</td></tr>');
            } else {
                $('#noMatchesRow').show();
            }
        } else {
            $('#noMatchesRow').hide();
        }
    });

    // Automatically open complaint if id is passed in URL query param
    const urlParams = new URLSearchParams(window.location.search);
    const openId = urlParams.get('id');
    if (openId) {
        const btn = $(`.btn-view-respond[data-id="${openId}"]`);
        if (btn.length) {
            btn.trigger('click');
        } else {
            // If the button is not found, try reloading with status=all so the complaint is visible
            const curStatus = urlParams.get('status');
            if (curStatus !== 'all') {
                urlParams.set('status', 'all');
                window.location.search = urlParams.toString();
            }
        }
    }

    // Puter Active Session Status Pill logic
    if (typeof puter !== 'undefined') {
        function updatePuterPill() {
            getPuterUserWithTimeout(1500).then(function(user) {
                $('#puterActiveUser').text(esc(user.username));
                $('#puterStatusPill').removeClass('badge-warning').addClass('badge-light').show();
            }).catch(function() {
                const hasCachedToken = localStorage.getItem('puter-auth-token') || localStorage.getItem('puter_auth_token');
                if (hasCachedToken) {
                    $('#puterActiveUser').text('Session Active');
                    $('#puterStatusPill').removeClass('badge-warning').addClass('badge-light').show();
                } else {
                    $('#puterActiveUser').text('Not Connected');
                    $('#puterStatusPill').removeClass('badge-light').addClass('badge-warning').show();
                }
            });
        }

        function getPuterUserWithTimeout(timeoutMs) {
            return new Promise(function(resolve, reject) {
                const timer = setTimeout(function() {
                    reject(new Error("Timeout"));
                }, timeoutMs);
                puter.auth.getUser().then(function(user) {
                    clearTimeout(timer);
                    resolve(user);
                }).catch(function(err) {
                    clearTimeout(timer);
                    reject(err);
                });
            });
        }

        // Expose function globally so we can trigger it
        window.updatePuterPill = updatePuterPill;

        // Switch account button handler
        $('#puterStatusPill').on('click', function() {
            if (confirm("Would you like to sign out of Puter AI? This will clear your session and let you connect a new account.")) {
                puter.auth.signOut().then(function() {
                    try {
                        localStorage.removeItem('puter-auth-token');
                        localStorage.removeItem('puter_auth_token');
                    } catch(e) {}
                    window.location.reload();
                });
            }
        });
    }

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str || '');
        return d.innerHTML;
    }
});

function showImageModal(src) {
    $('#modalImage').attr('src', src);
    $('#imageModal').modal('show');
}
</script>

<!-- Image Attachment Lightbox Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title font-weight-bold">Response Image Viewer</h5>
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
</body>
</html>

