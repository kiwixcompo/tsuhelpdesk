<?php
ob_start();
session_start();
require_once "config.php";
require_once "includes/notifications.php";

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
         SET status=?, admin_response=?, handled_by=?, updated_at=NOW()
         WHERE complaint_id=?");
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'ssii', $status, $response, $_SESSION['user_id'], $cid);
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

                    // Ensure notifications table exists
                    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS student_notifications (
                        notification_id INT AUTO_INCREMENT PRIMARY KEY,
                        student_id INT NOT NULL, complaint_id INT NULL,
                        complaint_type VARCHAR(20) NOT NULL DEFAULT 'ict',
                        title VARCHAR(255) NOT NULL, message TEXT NOT NULL,
                        is_read TINYINT(1) DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_s (student_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $notif = mysqli_prepare($conn,
                        "INSERT INTO student_notifications
                         (student_id, complaint_id, complaint_type, title, message, created_at)
                         VALUES (?, ?, 'ict', ?, ?, NOW())");
                    if ($notif) {
                        mysqli_stmt_bind_param($notif, 'iiss', $sid, $cid, $title, $msg);
                        mysqli_stmt_execute($notif);
                        mysqli_stmt_close($notif);
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

// ── Filters ───────────────────────────────────────────────
$f_status   = $_GET['status']   ?? '';
$f_category = $_GET['category'] ?? '';
$f_from     = $_GET['date_from'] ?? '';
$f_to       = $_GET['date_to']   ?? '';

$where = ['1=1'];
$params = []; $types = '';

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

ob_end_flush();
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
        <h5 class="mb-0"><i class="fas fa-headset mr-2"></i>ICT Complaints (<?php echo count($complaints); ?>)</h5>
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
                                data-response="<?php echo htmlspecialchars($c['admin_response'] ?? '', ENT_QUOTES); ?>">
                            <i class="fas fa-reply"></i>
                        </button>
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
            <form method="POST" id="feedbackForm">
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
                        <textarea name="admin_response" id="feedbackResponse" class="form-control" rows="5"
                                  placeholder="Your response will be shown to the student. Your identity will not be revealed."></textarea>
                        <small class="text-muted">
                            <i class="fas fa-shield-alt mr-1"></i>
                            Your name will not be shown to the student.
                        </small>
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

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
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
            const filtered = Object.entries(ef).filter(([k,v]) => v !== '' && v !== null && k !== 'ai_category');
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
        $('#sharedFeedbackModal').modal('show');
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

<?php ob_end_flush(); ?>
