<?php
session_start();
require_once "../config.php";
require_once "../includes/notification_prefs.php";

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Admin=1, Director=3, Deputy Director ICT=8
if (!in_array($_SESSION["role_id"], [1, 3, 8])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'update_status':    updateComplaintStatus($conn);   break;
    case 'add_response':     addAdminResponse($conn);        break;
    case 'delete_complaint': deleteComplaint($conn);         break;
    case 'bulk_update':      bulkUpdateComplaints($conn);    break;
    case 'forward_complaint':forwardComplaint($conn);        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ── Update status ─────────────────────────────────────────
function updateComplaintStatus($conn) {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $status       = $_POST['status'] ?? '';
    $response     = trim($_POST['response'] ?? '');

    if ($complaint_id <= 0 || empty($status)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }

    $valid = ['Pending', 'Under Review', 'Resolved', 'Rejected'];
    if (!in_array($status, $valid)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }

    if (!empty($response)) {
        $sql  = "UPDATE student_complaints SET status=?, admin_response=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) mysqli_stmt_bind_param($stmt, 'ssii', $status, $response, $_SESSION['user_id'], $complaint_id);
    } else {
        $sql  = "UPDATE student_complaints SET status=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) mysqli_stmt_bind_param($stmt, 'sii', $status, $_SESSION['user_id'], $complaint_id);
    }

    if ($stmt && mysqli_stmt_execute($stmt)) {
        createStudentNotification($conn, $complaint_id, $status);
        echo json_encode(['success' => true, 'message' => 'Complaint updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update complaint']);
    }
    if ($stmt) mysqli_stmt_close($stmt);
}

// ── Add admin response ────────────────────────────────────
function addAdminResponse($conn) {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $response     = trim($_POST['response'] ?? '');

    if ($complaint_id <= 0 || empty($response)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }

    $sql  = "UPDATE student_complaints SET admin_response=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sii', $response, $_SESSION['user_id'], $complaint_id);
        if (mysqli_stmt_execute($stmt)) {
            createStudentNotification($conn, $complaint_id, 'response_added');
            echo json_encode(['success' => true, 'message' => 'Response added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add response']);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

// ── Delete complaint ──────────────────────────────────────
function deleteComplaint($conn) {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    if ($complaint_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid complaint ID']);
        return;
    }
    $stmt = mysqli_prepare($conn, "DELETE FROM student_complaints WHERE complaint_id=?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $complaint_id);
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Complaint deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete complaint']);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

// ── Bulk update ───────────────────────────────────────────
function bulkUpdateComplaints($conn) {
    $complaint_ids = $_POST['complaint_ids'] ?? [];
    $bulk_action   = $_POST['bulk_action']   ?? '';

    if (empty($complaint_ids) || empty($bulk_action)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }

    $complaint_ids  = array_map('intval', $complaint_ids);
    $placeholders   = implode(',', array_fill(0, count($complaint_ids), '?'));
    $types          = str_repeat('i', count($complaint_ids));
    $uid            = (int) $_SESSION['user_id'];

    switch ($bulk_action) {
        case 'delete':
            $sql = "DELETE FROM student_complaints WHERE complaint_id IN ($placeholders)";
            break;
        case 'mark_resolved':
            $sql = "UPDATE student_complaints SET status='Resolved', handled_by=$uid, updated_at=NOW() WHERE complaint_id IN ($placeholders)";
            break;
        case 'mark_under_review':
            $sql = "UPDATE student_complaints SET status='Under Review', handled_by=$uid, updated_at=NOW() WHERE complaint_id IN ($placeholders)";
            break;
        case 'mark_rejected':
            $sql = "UPDATE student_complaints SET status='Rejected', handled_by=$uid, updated_at=NOW() WHERE complaint_id IN ($placeholders)";
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid bulk action']);
            return;
    }

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$complaint_ids);
        if (mysqli_stmt_execute($stmt)) {
            $affected = mysqli_stmt_affected_rows($stmt);
            if (in_array($bulk_action, ['mark_resolved', 'mark_under_review', 'mark_rejected'])) {
                $status = ucfirst(str_replace(['mark_', '_'], ['', ' '], $bulk_action));
                foreach ($complaint_ids as $cid) createStudentNotification($conn, $cid, $status);
            }
            echo json_encode(['success' => true, 'message' => "$affected complaint(s) updated successfully"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to perform bulk action']);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

// ── Forward complaint to Payment Admin / i4Cus / Department ──
function forwardComplaint($conn) {
    $complaint_id  = intval($_POST['complaint_id']  ?? 0);
    $forward_to_id = intval($_POST['forward_to_id'] ?? 0);

    if ($complaint_id <= 0 || $forward_to_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }

    // Verify recipient exists with an allowed role
    $u_stmt = mysqli_prepare($conn,
        "SELECT user_id, full_name, email, role_id FROM users WHERE user_id=? AND role_id IN (5,6,7)");
    if (!$u_stmt) { echo json_encode(['success' => false, 'message' => 'DB error']); return; }
    mysqli_stmt_bind_param($u_stmt, 'i', $forward_to_id);
    mysqli_stmt_execute($u_stmt);
    $target = mysqli_fetch_assoc(mysqli_stmt_get_result($u_stmt));
    mysqli_stmt_close($u_stmt);

    if (!$target) {
        echo json_encode(['success' => false, 'message' => 'Invalid recipient']);
        return;
    }

    // Ensure forwarded_to column exists
    $col = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'forwarded_to'");
    if ($col && mysqli_num_rows($col) === 0) {
        mysqli_query($conn, "ALTER TABLE student_complaints ADD COLUMN forwarded_to INT NULL DEFAULT NULL");
    }

    // Update complaint
    $upd = mysqli_prepare($conn,
        "UPDATE student_complaints SET forwarded_to=?, status='Under Review', handled_by=?, updated_at=NOW() WHERE complaint_id=?");
    if (!$upd) { echo json_encode(['success' => false, 'message' => 'DB error']); return; }
    mysqli_stmt_bind_param($upd, 'iii', $forward_to_id, $_SESSION['user_id'], $complaint_id);
    if (!mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        echo json_encode(['success' => false, 'message' => 'Failed to forward complaint']);
        return;
    }
    mysqli_stmt_close($upd);

    // Notify student
    createStudentNotification($conn, $complaint_id, 'Under Review');

    // Email the recipient
    if (!empty($target['email'])) {
        $comp_stmt = mysqli_prepare($conn,
            "SELECT sc.course_code, sc.course_title, sc.complaint_type,
                    CONCAT(s.first_name,' ',s.last_name) AS student_name,
                    s.registration_number
             FROM student_complaints sc
             JOIN students s ON sc.student_id = s.student_id
             WHERE sc.complaint_id=?");
        $comp = null;
        if ($comp_stmt) {
            mysqli_stmt_bind_param($comp_stmt, 'i', $complaint_id);
            mysqli_stmt_execute($comp_stmt);
            $comp = mysqli_fetch_assoc(mysqli_stmt_get_result($comp_stmt));
            mysqli_stmt_close($comp_stmt);
        }

        if ($comp) {
            $subject = "Student Complaint Forwarded to You — TSU ICT Help Desk";
            $body    = "Dear {$target['full_name']},\n\n"
                     . "A student result verification complaint has been forwarded to you.\n\n"
                     . "Complaint # : $complaint_id\n"
                     . "Student     : {$comp['student_name']} ({$comp['registration_number']})\n"
                     . "Course      : {$comp['course_code']} — {$comp['course_title']}\n"
                     . "Type        : {$comp['complaint_type']}\n\n"
                     . "Log in to review:\nhttps://helpdesk.tsuniversity.ng/\n\n"
                     . "Best regards,\nTSU ICT Help Desk";
            $headers = "From: TSU ICT Help Desk <noreply@tsuniversity.edu.ng>\r\n"
                     . "Reply-To: support@tsuniversity.edu.ng\r\n";

            if (function_exists('sendDeptEmailIfAllowed')) {
                sendDeptEmailIfAllowed($conn, (int)$target['user_id'], 'on_forwarded',
                    $target['email'], $subject, $body);
            } else {
                @app_mail($target['email'], $subject, $body, $headers);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => "Forwarded to {$target['full_name']} successfully."]);
}

// ── Student notification helper ───────────────────────────
function createStudentNotification($conn, $complaint_id, $status) {
    $sql = "SELECT sc.student_id, sc.course_code, s.first_name, s.last_name
            FROM student_complaints sc
            JOIN students s ON sc.student_id = s.student_id
            WHERE sc.complaint_id=?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'i', $complaint_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row) return;

    $title   = $status === 'response_added' ? 'Admin Response Added' : 'Complaint Status Updated';
    $message = $status === 'response_added'
        ? "An admin has responded to your complaint for course {$row['course_code']}"
        : "Your complaint for course {$row['course_code']} has been updated to: $status";

    $ns = mysqli_prepare($conn,
        "INSERT INTO student_notifications (student_id, complaint_id, title, message, created_at)
         VALUES (?,?,?,?,NOW())");
    if ($ns) {
        mysqli_stmt_bind_param($ns, 'iiss', $row['student_id'], $complaint_id, $title, $message);
        mysqli_stmt_execute($ns);
        mysqli_stmt_close($ns);
    }
}

mysqli_close($conn);
