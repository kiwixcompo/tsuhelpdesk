<?php
session_start();

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once "config.php";

// Include the script to create message_replies table if it doesn't exist
require_once "create_message_replies_table.php";

// Fetch app settings for header use
$app_name = 'TSU ICT Complaint Desk'; // Default value
$app_logo = '';
$app_favicon = '';

$sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('app_name', 'app_logo', 'app_favicon')";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        switch($row['setting_key']) {
            case 'app_name':
                $app_name = $row['setting_value'] ?: 'TSU ICT Complaint Desk';
                break;
            case 'app_logo':
                $app_logo = $row['setting_value'];
                break;
            case 'app_favicon':
                $app_favicon = $row['setting_value'];
                break;
        }
    }
}

// Set timezone to West African Time
if(function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Africa/Lagos');
}

// Process new message submission
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["send_message"])){
    $subject = trim($_POST["subject"]);
    $message = trim($_POST["message"]);
    $recipient_id = isset($_POST["recipient_id"]) ? $_POST["recipient_id"] : null;
    $is_broadcast = isset($_POST["is_broadcast"]) ? 1 : 0;
    $is_staff_broadcast = isset($_POST["is_staff_broadcast"]) ? 1 : 0;
    
    if(!empty($subject) && !empty($message)){
        if($is_staff_broadcast) {
            // Send to all staff (role_id = 2) and departments (role_id = 7)
            $staff_sql = "SELECT user_id FROM users WHERE role_id IN (2, 7)";
            $staff_result = mysqli_query($conn, $staff_sql);
            if($staff_result){
                while($staff = mysqli_fetch_assoc($staff_result)){
                    $sql = "INSERT INTO messages (sender_id, recipient_id, subject, message_text, is_broadcast) VALUES (?, ?, ?, ?, 0)";
                    if($stmt = mysqli_prepare($conn, $sql)){
                        mysqli_stmt_bind_param($stmt, "iiss", $_SESSION["user_id"], $staff["user_id"], $subject, $message);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
                $success_message = "Message sent to all staff and departments.";
            }
        } else {
            $sql = "INSERT INTO messages (sender_id, recipient_id, subject, message_text, is_broadcast) VALUES (?, ?, ?, ?, ?)";
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "iissi", $_SESSION["user_id"], $recipient_id, $subject, $message, $is_broadcast);
                if(mysqli_stmt_execute($stmt)){
                    $success_message = "Message sent successfully.";
                } else{
                    $error_message = "Something went wrong. Please try again later.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Process reply submission
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["send_reply"])){
    $message_id = $_POST["message_id"];
    $reply = trim($_POST["reply"]);
    
    if(!empty($reply)){
        $sql = "INSERT INTO message_replies (message_id, sender_id, reply_text) VALUES (?, ?, ?)";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "iis", $message_id, $_SESSION["user_id"], $reply);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "Reply sent successfully.";
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Mark message as read
if(isset($_GET["read"]) && is_numeric($_GET["read"])){
    $sql = "UPDATE messages SET is_read = 1 WHERE message_id = ? AND (recipient_id = ? OR is_broadcast = 1)";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ii", $_GET["read"], $_SESSION["user_id"]);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

// Fetch users for recipient selection with categorization
$ict_staff = [];
$departments = [];
$other_users = [];

if($_SESSION["role_id"] == 7) {
    // Departments can only message ICT Director (role_id = 3)
    $sql = "SELECT user_id, full_name, role_id FROM users WHERE role_id = 3 ORDER BY full_name";
} else {
    $sql = "SELECT u.user_id, u.full_name, u.role_id, r.role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.user_id != ? 
            ORDER BY r.role_name, u.full_name";
}

if($stmt = mysqli_prepare($conn, $sql)){
    if($_SESSION["role_id"] != 7) {
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    }
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            // For staff, only show admins
            if($_SESSION["role_id"] == 2 && $row["role_id"] != 1) continue;
            
            // Categorize users
            if(in_array($row["role_id"], [1, 3, 4, 5, 6])) { // Admin, Director, DVC, i4Cus Staff, Payment Admin
                $ict_staff[] = $row;
            } elseif($row["role_id"] == 7) { // Departments
                $departments[] = $row;
            } else {
                $other_users[] = $row;
            }
        }
    }
    mysqli_stmt_close($stmt);
}

// Fetch messages
$messages = [];
$sql = "SELECT m.*, 
        u1.full_name as sender_name,
        u2.full_name as recipient_name,
        (SELECT COUNT(*) FROM message_replies WHERE message_id = m.message_id) as reply_count
        FROM messages m
        LEFT JOIN users u1 ON m.sender_id = u1.user_id
        LEFT JOIN users u2 ON m.recipient_id = u2.user_id
        WHERE m.recipient_id = ? OR m.sender_id = ? OR m.is_broadcast = 1
        ORDER BY m.created_at DESC";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "ii", $_SESSION["user_id"], $_SESSION["user_id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $messages[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Count unread messages
$unread_count = 0;
foreach($messages as $message){
    if(!$message['is_read'] && ($message['recipient_id'] == $_SESSION["user_id"] || $message['is_broadcast'])){
        $unread_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - <?php echo htmlspecialchars($app_name); ?></title>
    
    <!-- Dynamic Favicon -->
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
    .app-branding {
        display: flex;
        align-items: center;
    }
    .app-logo {
        height: 40px;
        margin-right: 10px;
        object-fit: contain;
    }
    .app-name {
        font-size: 1.25rem;
        font-weight: bold;
        color: white;
    }
    .message-list {
        max-height: 600px;
        overflow-y: auto;
    }
    .message-item {
        transition: all 0.3s ease;
    }
    .message-item:hover {
        background-color: #f8f9fa;
    }
    .message-item.unread {
        background-color: #e8f4f8;
    }
    .message-item.unread:hover {
        background-color: #d8edf7;
    }
    .reply-section {
        max-height: 300px;
        overflow-y: auto;
    }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <?php if(isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if(isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">New Message</h5>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <?php if($_SESSION["role_id"] == 1): ?>
                            <div class="form-group">
                                <div class="custom-control custom-checkbox mb-2">
                                    <input type="checkbox" class="custom-control-input" id="staffBroadcastCheck" name="is_staff_broadcast">
                                    <label class="custom-control-label" for="staffBroadcastCheck">Send to all staff and departments</label>
                                </div>
                                <div class="custom-control custom-checkbox mb-2">
                                    <input type="checkbox" class="custom-control-input" id="broadcastCheck" name="is_broadcast">
                                    <label class="custom-control-label" for="broadcastCheck">Send to all users</label>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="form-group" id="recipientGroup">
                                <label>Recipient</label>
                                <select name="recipient_id" class="form-control" required>
                                    <option value="">Select recipient</option>
                                    
                                    <?php if(!empty($ict_staff)): ?>
                                        <optgroup label="ICT Staff">
                                            <?php foreach($ict_staff as $user): ?>
                                                <option value="<?php echo $user['user_id']; ?>">
                                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                                    <?php if(isset($user['role_name'])): ?>
                                                        (<?php echo htmlspecialchars($user['role_name']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($departments)): ?>
                                        <optgroup label="Departments">
                                            <?php foreach($departments as $user): ?>
                                                <option value="<?php echo $user['user_id']; ?>">
                                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($other_users)): ?>
                                        <optgroup label="Other Users">
                                            <?php foreach($other_users as $user): ?>
                                                <option value="<?php echo $user['user_id']; ?>">
                                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                                    <?php if(isset($user['role_name'])): ?>
                                                        (<?php echo htmlspecialchars($user['role_name']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Subject</label>
                                <input type="text" name="subject" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="message" class="form-control" rows="4" required></textarea>
                            </div>
                            <button type="submit" name="send_message" class="btn btn-primary">Send Message</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Messages</h5>
                    </div>
                    <div class="card-body message-list">
                        <?php if(empty($messages)): ?>
                            <p class="text-muted text-center">No messages found.</p>
                        <?php else: ?>
                            <?php foreach($messages as $message): ?>
                                <div class="message-item p-3 border-bottom <?php echo (!$message['is_read'] && ($message['recipient_id'] == $_SESSION["user_id"] || $message['is_broadcast'])) ? 'unread' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="mb-1">
                                            <?php echo htmlspecialchars($message['subject']); ?>
                                            <?php if(!$message['is_read'] && ($message['recipient_id'] == $_SESSION["user_id"] || $message['is_broadcast'])): ?>
                                                <span class="badge badge-primary">New</span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?php echo date('M d, Y h:i A', strtotime($message['created_at'])); ?>
                                        </small>
                                    </div>
                                    <p class="mb-1"><?php echo nl2br(htmlspecialchars($message['message_text'])); ?></p>
                                    <small class="text-muted">
                                        From: <?php echo htmlspecialchars($message['sender_name']); ?>
                                        <?php if($message['is_broadcast']): ?>
                                            <span class="badge badge-info">Broadcast</span>
                                        <?php else: ?>
                                            To: <?php echo htmlspecialchars($message['recipient_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                    
                                    <button class="btn btn-sm btn-link" type="button" 
                                            data-toggle="collapse" 
                                            data-target="#replies<?php echo $message['message_id']; ?>"
                                            onclick="loadReplies(<?php echo $message['message_id']; ?>)">
                                        Replies (<?php echo $message['reply_count']; ?>)
                                    </button>
                                    
                                    <div class="collapse mt-2" id="replies<?php echo $message['message_id']; ?>">
                                        <div class="card card-body reply-section">
                                            <div id="replyList<?php echo $message['message_id']; ?>">
                                                Loading replies...
                                            </div>
                                            <form method="post" class="mt-3">
                                                <input type="hidden" name="message_id" value="<?php echo $message['message_id']; ?>">
                                                <div class="form-group">
                                                    <textarea name="reply" class="form-control" rows="2" placeholder="Write a reply..." required></textarea>
                                                </div>
                                                <button type="submit" name="send_reply" class="btn btn-sm btn-primary">Reply</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
    $(document).ready(function() {
        // Toggle recipient selection based on broadcast checkboxes
        $('#broadcastCheck, #staffBroadcastCheck').change(function() {
            if($('#broadcastCheck').is(':checked') || $('#staffBroadcastCheck').is(':checked')) {
                $('#recipientGroup').hide();
                $('select[name="recipient_id"]').prop('required', false);
            } else {
                $('#recipientGroup').show();
                $('select[name="recipient_id"]').prop('required', true);
            }
        });
        
        // Mark message as read when expanded
        $('.message-item').on('click', function() {
            var messageId = $(this).find('.collapse').attr('id').replace('replies', '');
            if($(this).hasClass('unread')) {
                $.get('messages.php?read=' + messageId);
                $(this).removeClass('unread');
            }
        });
    });

    function loadReplies(messageId) {
        $.get('get_replies.php?message_id=' + messageId, function(data) {
            $('#replyList' + messageId).html(data);
        });
    }
    </script>
</body>
</html>