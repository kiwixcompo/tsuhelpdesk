<?php
// API endpoint for managing student complaints by admin
session_start();
require_once "../config.php";

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $input = json_decode(file_get_contents('php://input'), true);
    
    if(!isset($input['action']) || !isset($input['complaint_id'])){
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }
    
    $action = $input['action'];
    $complaint_id = intval($input['complaint_id']);
    
    switch($action){
        case 'update_status':
            if(!isset($input['status'])){
                http_response_code(400);
                echo json_encode(['error' => 'Status is required']);
                exit;
            }
            
            $status = $input['status'];
            $admin_response = isset($input['admin_response']) ? trim($input['admin_response']) : null;
            
            $sql = "UPDATE student_complaints SET status = ?, admin_response = ?, updated_at = CURRENT_TIMESTAMP WHERE complaint_id = ?";
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "ssi", $status, $admin_response, $complaint_id);
                if(mysqli_stmt_execute($stmt)){
                    // Send notification email to student
                    $student_sql = "SELECT s.email, s.first_name, s.last_name, sc.course_code, sc.course_title 
                                   FROM students s 
                                   JOIN student_complaints sc ON s.student_id = sc.student_id 
                                   WHERE sc.complaint_id = ?";
                    if($student_stmt = mysqli_prepare($conn, $student_sql)){
                        mysqli_stmt_bind_param($student_stmt, "i", $complaint_id);
                        if(mysqli_stmt_execute($student_stmt)){
                            $student_result = mysqli_stmt_get_result($student_stmt);
                            if($student_row = mysqli_fetch_assoc($student_result)){
                                $to = $student_row['email'];
                                $subject = "Complaint Status Update - TSU ICT Help Desk";
                                $message = "Dear " . $student_row['first_name'] . " " . $student_row['last_name'] . ",\n\n";
                                $message .= "Your complaint for " . $student_row['course_code'] . " - " . $student_row['course_title'] . " has been updated.\n\n";
                                $message .= "New Status: " . $status . "\n\n";
                                if($admin_response){
                                    $message .= "Admin Response:\n" . $admin_response . "\n\n";
                                }
                                $message .= "You can view your complaint details by logging into the student portal.\n\n";
                                $message .= "Login URL: https://helpdesk.tsuniversity.edu.ng/student_login.php\n\n";
                                $message .= "Best regards,\nTSU ICT Help Desk Team";
                                
                                $headers = "From: TSU ICT Help Desk <noreply@tsuniversity.edu.ng>\r\n";
                                $headers .= "Reply-To: support@tsuniversity.edu.ng\r\n";
                                
                                @mail($to, $subject, $message, $headers);
                            }
                        }
                        mysqli_stmt_close($student_stmt);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Complaint updated successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to update complaint']);
                }
                mysqli_stmt_close($stmt);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Database error']);
            }
            break;
            
        case 'delete_complaint':
            $sql = "DELETE FROM student_complaints WHERE complaint_id = ?";
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "i", $complaint_id);
                if(mysqli_stmt_execute($stmt)){
                    echo json_encode(['success' => true, 'message' => 'Complaint deleted successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to delete complaint']);
                }
                mysqli_stmt_close($stmt);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Database error']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>