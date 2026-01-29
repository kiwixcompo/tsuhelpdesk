<?php
session_start();
require_once "../config.php";

// Check if user is logged in and has appropriate role
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if user has permission (Admin = 1, Director = 3, Deputy Director ICT = 8)
if(!in_array($_SESSION["role_id"], [1, 3, 8])){
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

switch($action) {
    case 'update_status':
        updateComplaintStatus($conn);
        break;
    case 'add_response':
        addAdminResponse($conn);
        break;
    case 'delete_complaint':
        deleteComplaint($conn);
        break;
    case 'bulk_update':
        bulkUpdateComplaints($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function updateComplaintStatus($conn) {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $response = trim($_POST['response'] ?? '');
    
    if($complaint_id <= 0 || empty($status)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }
    
    $valid_statuses = ['Pending', 'Under Review', 'Resolved', 'Rejected'];
    if(!in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
    // Update both status and admin response if provided
    if(!empty($response)) {
        $sql = "UPDATE student_complaints SET status = ?, admin_response = ?, handled_by = ?, updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "ssii", $status, $response, $_SESSION["user_id"], $complaint_id);
        }
    } else {
        $sql = "UPDATE student_complaints SET status = ?, handled_by = ?, updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "sii", $status, $_SESSION["user_id"], $complaint_id);
        }
    }
    
    if($stmt && mysqli_stmt_execute($stmt)) {
        // Create notification for the student
        createStudentNotification($conn, $complaint_id, $status);
        echo json_encode(['success' => true, 'message' => 'Complaint updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update complaint']);
    }
    
    if($stmt) {
        mysqli_stmt_close($stmt);
    }
}

function addAdminResponse($conn) {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $response = trim($_POST['response'] ?? '');
    
    if($complaint_id <= 0 || empty($response)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }
    
    $sql = "UPDATE student_complaints SET admin_response = ?, handled_by = ?, updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "sii", $response, $_SESSION["user_id"], $complaint_id);
        
        if(mysqli_stmt_execute($stmt)) {
            // Create notification for the student
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

function deleteComplaint($conn) {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    
    if($complaint_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid complaint ID']);
        return;
    }
    
    $sql = "DELETE FROM student_complaints WHERE complaint_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $complaint_id);
        
        if(mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Complaint deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete complaint']);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function bulkUpdateComplaints($conn) {
    $complaint_ids = $_POST['complaint_ids'] ?? [];
    $bulk_action = $_POST['bulk_action'] ?? '';
    
    if(empty($complaint_ids) || empty($bulk_action)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        return;
    }
    
    $complaint_ids = array_map('intval', $complaint_ids);
    $placeholders = str_repeat('?,', count($complaint_ids) - 1) . '?';
    $types = str_repeat('i', count($complaint_ids));
    
    switch($bulk_action) {
        case 'delete':
            $sql = "DELETE FROM student_complaints WHERE complaint_id IN ($placeholders)";
            break;
        case 'mark_resolved':
            $sql = "UPDATE student_complaints SET status = 'Resolved', handled_by = {$_SESSION["user_id"]}, updated_at = CURRENT_TIMESTAMP WHERE complaint_id IN ($placeholders)";
            break;
        case 'mark_under_review':
            $sql = "UPDATE student_complaints SET status = 'Under Review', handled_by = {$_SESSION["user_id"]}, updated_at = CURRENT_TIMESTAMP WHERE complaint_id IN ($placeholders)";
            break;
        case 'mark_rejected':
            $sql = "UPDATE student_complaints SET status = 'Rejected', handled_by = {$_SESSION["user_id"]}, updated_at = CURRENT_TIMESTAMP WHERE complaint_id IN ($placeholders)";
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid bulk action']);
            return;
    }
    
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, $types, ...$complaint_ids);
        
        if(mysqli_stmt_execute($stmt)) {
            $affected_rows = mysqli_stmt_affected_rows($stmt);
            
            // Create notifications for affected students if status was updated
            if(in_array($bulk_action, ['mark_resolved', 'mark_under_review', 'mark_rejected'])) {
                $status = str_replace('mark_', '', $bulk_action);
                $status = ucfirst(str_replace('_', ' ', $status));
                foreach($complaint_ids as $complaint_id) {
                    createStudentNotification($conn, $complaint_id, $status);
                }
            }
            
            echo json_encode(['success' => true, 'message' => "$affected_rows complaint(s) updated successfully"]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to perform bulk action']);
        }
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function createStudentNotification($conn, $complaint_id, $status) {
    // Get student information
    $sql = "SELECT sc.student_id, sc.course_code, s.first_name, s.last_name 
            FROM student_complaints sc 
            JOIN students s ON sc.student_id = s.student_id 
            WHERE sc.complaint_id = ?";
    
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $complaint_id);
        if(mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)) {
                $student_id = $row['student_id'];
                $course_code = $row['course_code'];
                $student_name = $row['first_name'] . ' ' . $row['last_name'];
                
                // Create notification message based on status
                if($status === 'response_added') {
                    $title = "Admin Response Added";
                    $message = "An admin has responded to your complaint for course $course_code";
                } else {
                    $title = "Complaint Status Updated";
                    $message = "Your complaint for course $course_code has been updated to: $status";
                }
                
                // Insert notification (assuming you have a student_notifications table)
                $notif_sql = "INSERT INTO student_notifications (student_id, complaint_id, title, message, created_at) VALUES (?, ?, ?, ?, NOW())";
                if($notif_stmt = mysqli_prepare($conn, $notif_sql)) {
                    mysqli_stmt_bind_param($notif_stmt, "iiss", $student_id, $complaint_id, $title, $message);
                    mysqli_stmt_execute($notif_stmt);
                    mysqli_stmt_close($notif_stmt);
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
}

mysqli_close($conn);
?>