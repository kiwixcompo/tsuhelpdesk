<?php
/**
 * Student ICT Complaint Management API
 * Allows students to edit (description only) or delete their own ICT complaints.
 * Students can only modify complaints that are still Pending (not yet handled).
 */
ob_start();
session_start();
require_once "../config.php";

header('Content-Type: application/json');
ob_clean();

if (!isset($_SESSION["student_loggedin"]) || $_SESSION["student_loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$student_id = (int) $_SESSION['student_id'];
$action     = $_POST['action'] ?? '';

switch ($action) {
    case 'delete':
        deleteComplaint($conn, $student_id);
        break;
    case 'edit':
        editComplaint($conn, $student_id);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function deleteComplaint($conn, $student_id) {
    $complaint_id = (int) ($_POST['complaint_id'] ?? 0);
    if ($complaint_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid complaint ID']);
        return;
    }

    // Verify ownership and that complaint is still Pending
    $check = mysqli_prepare($conn,
        "SELECT complaint_id, status FROM student_ict_complaints
         WHERE complaint_id = ? AND student_id = ?");
    if (!$check) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        return;
    }
    mysqli_stmt_bind_param($check, 'ii', $complaint_id, $student_id);
    mysqli_stmt_execute($check);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
    mysqli_stmt_close($check);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Complaint not found']);
        return;
    }
    if (!in_array($row['status'], ['Pending', 'Auto-Resolved'])) {
        echo json_encode(['success' => false,
            'message' => 'This complaint is already being processed and cannot be deleted.']);
        return;
    }

    $del = mysqli_prepare($conn,
        "DELETE FROM student_ict_complaints WHERE complaint_id = ? AND student_id = ?");
    mysqli_stmt_bind_param($del, 'ii', $complaint_id, $student_id);
    if (mysqli_stmt_execute($del)) {
        mysqli_stmt_close($del);
        echo json_encode(['success' => true, 'message' => 'Complaint deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete complaint']);
    }
}

function editComplaint($conn, $student_id) {
    $complaint_id = (int) ($_POST['complaint_id'] ?? 0);
    $description  = trim($_POST['description'] ?? '');

    if ($complaint_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid complaint ID']);
        return;
    }

    // Verify ownership and pending status
    $check = mysqli_prepare($conn,
        "SELECT complaint_id, status, path_summary FROM student_ict_complaints
         WHERE complaint_id = ? AND student_id = ?");
    if (!$check) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
        return;
    }
    mysqli_stmt_bind_param($check, 'ii', $complaint_id, $student_id);
    mysqli_stmt_execute($check);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
    mysqli_stmt_close($check);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Complaint not found']);
        return;
    }
    if (!in_array($row['status'], ['Pending', 'Auto-Resolved'])) {
        echo json_encode(['success' => false,
            'message' => 'This complaint is already being processed and cannot be edited.']);
        return;
    }

    // Rebuild full_description from path_summary + new description
    $full_description = $row['path_summary'];
    if ($description) {
        $full_description .= "\n\nAdditional details: " . $description;
    }

    $upd = mysqli_prepare($conn,
        "UPDATE student_ict_complaints
         SET description = ?, updated_at = NOW()
         WHERE complaint_id = ? AND student_id = ?");
    mysqli_stmt_bind_param($upd, 'sii', $full_description, $complaint_id, $student_id);
    if (mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        echo json_encode(['success' => true, 'message' => 'Complaint updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update complaint']);
    }
}
