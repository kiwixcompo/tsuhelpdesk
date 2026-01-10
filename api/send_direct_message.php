<?php
// Prevent any HTML output before JSON
ob_start();
ob_clean();

session_start();

// Set JSON headers immediately
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Disable HTML error output
ini_set('display_errors', 0);

try {
    require_once "../config.php";
} catch (Exception $e) {
    echo json_encode(['error' => 'Configuration error: ' . $e->getMessage()]);
    exit;
}

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    http_response_code(403);
    echo json_encode([
        'error' => 'Access denied',
        'debug' => [
            'logged_in' => isset($_SESSION["loggedin"]) ? $_SESSION["loggedin"] : 'not set',
            'role_id' => isset($_SESSION["role_id"]) ? $_SESSION["role_id"] : 'not set'
        ]
    ]);
    exit;
}

if($_SERVER['REQUEST_METHOD'] != 'POST'){
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$recipient_id = isset($input['recipient_id']) ? intval($input['recipient_id']) : 0;
$subject = isset($input['subject']) ? trim($input['subject']) : '';
$message = isset($input['message']) ? trim($input['message']) : '';

if($recipient_id <= 0 || empty($subject) || empty($message)){
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// Verify recipient exists
$check_sql = "SELECT user_id, full_name FROM users WHERE user_id = ?";
if($check_stmt = mysqli_prepare($conn, $check_sql)){
    mysqli_stmt_bind_param($check_stmt, "i", $recipient_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if(!mysqli_fetch_assoc($check_result)){
        mysqli_stmt_close($check_stmt);
        http_response_code(404);
        echo json_encode(['error' => 'Recipient not found']);
        exit;
    }
    mysqli_stmt_close($check_stmt);
}

// Insert message
$sql = "INSERT INTO messages (sender_id, recipient_id, subject, message, is_broadcast) VALUES (?, ?, ?, ?, 0)";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "iiss", $_SESSION["user_id"], $recipient_id, $subject, $message);
    
    if(mysqli_stmt_execute($stmt)){
        echo json_encode(['success' => true, 'message' => 'Message sent successfully']);
    } else {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to send message',
            'debug' => [
                'mysql_error' => mysqli_error($conn),
                'sender_id' => $_SESSION["user_id"],
                'recipient_id' => $recipient_id,
                'subject' => $subject,
                'message_length' => strlen($message)
            ]
        ]);
    }
    mysqli_stmt_close($stmt);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'debug' => [
            'mysql_error' => mysqli_error($conn)
        ]
    ]);
}
?>