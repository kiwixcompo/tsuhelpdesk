<?php
session_start();

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

// Fetch app settings for navbar
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

// Check if complaint ID is provided
if(!isset($_GET["id"])){
    header("location: dashboard.php");
    exit;
}

$complaint_id = $_GET["id"];

// Process complaint edit
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["edit_complaint"])){
    if($_SESSION["user_id"] == $_POST["lodged_by"]){ // Verify the editor is the original creator
        $student_id = trim($_POST["student_id"]);
        $complaint_text = trim($_POST["complaint_text"]);
        $is_urgent = isset($_POST["is_urgent"]) ? 1 : 0;
        $is_payment_related = isset($_POST["is_payment_related"]) ? 1 : 0;
        
        // Handle image upload for edit
        $image_paths = array();
        $existing_images = $complaint['image_path'] ?? '';
        if(isset($_FILES["edit_complaint_images"])){
            $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
            $target_dir = "uploads/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            foreach($_FILES["edit_complaint_images"]["tmp_name"] as $key => $tmp_name){
                if($_FILES["edit_complaint_images"]["error"][$key] == 0){
                    $filename = $_FILES["edit_complaint_images"]["name"][$key];
                    $filetype = $_FILES["edit_complaint_images"]["type"][$key];
                    $filesize = $_FILES["edit_complaint_images"]["size"][$key];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if(!array_key_exists($ext, $allowed)) continue;
                    $maxsize = 5 * 1024 * 1024;
                    if($filesize > $maxsize) continue;
                    if(in_array($filetype, $allowed)){
                        $new_filename = uniqid() . "." . $ext;
                        $target_file = $target_dir . $new_filename;
                        if(move_uploaded_file($tmp_name, $target_file)){
                            chmod($target_file, 0644);
                            // Store just the filename
                            $image_paths[] = $new_filename;
                        }
                    }
                }
            }
        }
        // Merge new images with existing
        $all_images = $existing_images;
        if(!empty($image_paths)){
            $all_images = $existing_images ? $existing_images . ',' . implode(",", $image_paths) : implode(",", $image_paths);
        }
        $sql = "UPDATE complaints SET student_id = ?, complaint_text = ?, is_urgent = ?, is_payment_related = ?, image_path = ? WHERE complaint_id = ? AND lodged_by = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "ssiiisi", $student_id, $complaint_text, $is_urgent, $is_payment_related, $all_images, $complaint_id, $_SESSION["user_id"]);
            if(mysqli_stmt_execute($stmt)){
                header("Location: view_complaint.php?id=$complaint_id&success=complaint_updated");
                exit();
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "You can only edit complaints that you have lodged.";
    }
}

// Handle threaded reply submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_reply']) && isset($_POST['reply_text'])) {
    $reply_text = trim($_POST['reply_text']);
    if ($reply_text !== '') {
        // Handle reply image uploads
        $reply_image_paths = array();
        if(isset($_FILES["reply_images"]) && !empty($_FILES["reply_images"]["name"][0])){
            $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
            $target_dir = "uploads/";
            
            // Create uploads directory if it doesn't exist
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            
            // Loop through each uploaded file
            foreach($_FILES["reply_images"]["tmp_name"] as $key => $tmp_name){
                if($_FILES["reply_images"]["error"][$key] == 0){
                    $filename = $_FILES["reply_images"]["name"][$key];
                    $filetype = $_FILES["reply_images"]["type"][$key];
                    $filesize = $_FILES["reply_images"]["size"][$key];
                    
                    // Verify file extension
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if(!array_key_exists($ext, $allowed)) continue;
                    
                    // Verify file size - 5MB maximum
                    $maxsize = 5 * 1024 * 1024;
                    if($filesize > $maxsize) continue;
                    
                    // Verify MIME type
                    if(in_array($filetype, $allowed)){
                        $new_filename = "reply_" . uniqid() . "." . $ext;
                        $target_file = $target_dir . $new_filename;
                        
                        if(move_uploaded_file($tmp_name, $target_file)){
                            chmod($target_file, 0644);
                            $reply_image_paths[] = $new_filename;
                        }
                    }
                }
            }
        }
        
        $reply_images_str = !empty($reply_image_paths) ? implode(",", $reply_image_paths) : null;
        
        $sql = "INSERT INTO complaint_replies (complaint_id, sender_id, reply_text, reply_images) VALUES (?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "iiss", $complaint_id, $_SESSION['user_id'], $reply_text, $reply_images_str);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            
            // Create notifications for relevant parties
            require_once "includes/notifications.php";
            
            // Get complaint details to determine who to notify
            $complaint_sql = "SELECT lodged_by, handled_by FROM complaints WHERE complaint_id = ?";
            if ($complaint_stmt = mysqli_prepare($conn, $complaint_sql)) {
                mysqli_stmt_bind_param($complaint_stmt, "i", $complaint_id);
                mysqli_stmt_execute($complaint_stmt);
                $complaint_result = mysqli_stmt_get_result($complaint_stmt);
                if ($complaint_row = mysqli_fetch_assoc($complaint_result)) {
                    $lodged_by = $complaint_row['lodged_by'];
                    $handled_by = $complaint_row['handled_by'];
                    
                    // If reply is from user, notify handler and admin
                    if ($_SESSION['user_id'] == $lodged_by) {
                        $notification_title = "New Reply from User";
                        $notification_message = "User replied to complaint #$complaint_id";
                        
                        // Notify handler if exists
                        if ($handled_by) {
                            createNotification($conn, $handled_by, $complaint_id, 'feedback_reply', $notification_title, $notification_message);
                        }
                        
                        // Notify admin (role_id = 1)
                        $admin_sql = "SELECT user_id FROM users WHERE role_id = 1 LIMIT 1";
                        $admin_result = mysqli_query($conn, $admin_sql);
                        if ($admin_row = mysqli_fetch_assoc($admin_result)) {
                            createNotification($conn, $admin_row['user_id'], $complaint_id, 'feedback_reply', $notification_title, $notification_message);
                        }
                    } else {
                        // If reply is from staff, notify the user who lodged the complaint
                        $notification_title = "New Reply from Staff";
                        $notification_message = "Staff replied to your complaint #$complaint_id";
                        createNotification($conn, $lodged_by, $complaint_id, 'feedback_reply', $notification_title, $notification_message);
                    }
                }
                mysqli_stmt_close($complaint_stmt);
            }
            
            $success_message = "Reply sent successfully.";
            header("Location: view_complaint.php?id=$complaint_id" . (isset($_GET['i4cus']) ? '&i4cus=1' : ''));
            exit;
        } else {
            $error_message = "Failed to send reply.";
        }
    }
}

// Fetch complaint details
$sql = "SELECT c.*, u1.full_name as handler_name, u2.full_name as lodger_name 
        FROM complaints c 
        LEFT JOIN users u1 ON c.handled_by = u1.user_id 
        LEFT JOIN users u2 ON c.lodged_by = u2.user_id 
        WHERE c.complaint_id = ?";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $complaint_id);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($complaint = mysqli_fetch_assoc($result)){
            // Continue with displaying the complaint
        } else {
            header("location: dashboard.php");
            exit;
        }
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['treat_complaint']) && 
    isset($_GET['i4cus']) && $_GET['i4cus'] == 1 && 
    $_SESSION['role_id'] == 5 && $complaint['status'] != 'Treated') {
    
    $status = $_POST['status'];
    $feedback = trim($_POST['feedback']);
    $handler_id = $_SESSION['user_id'];
    
    // Handle feedback image uploads
    $feedback_image_paths = array();
    if(isset($_FILES["feedback_images"]) && is_array($_FILES["feedback_images"]["name"]) && !empty($_FILES["feedback_images"]["name"][0])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        
        // Create uploads directory if it doesn't exist
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        // Loop through each uploaded file
        foreach($_FILES["feedback_images"]["tmp_name"] as $key => $tmp_name){
            if($_FILES["feedback_images"]["error"][$key] == 0){
                $filename = $_FILES["feedback_images"]["name"][$key];
                $filetype = $_FILES["feedback_images"]["type"][$key];
                $filesize = $_FILES["feedback_images"]["size"][$key];
                
                // Verify file extension
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if(!array_key_exists($ext, $allowed)) continue;
                
                // Verify file size - 5MB maximum
                $maxsize = 5 * 1024 * 1024;
                if($filesize > $maxsize) continue;
                
                // Verify MIME type
                if(in_array($filetype, $allowed)){
                    $new_filename = "feedback_" . uniqid() . "." . $ext;
                    $target_file = $target_dir . $new_filename;
                    
                    if(move_uploaded_file($tmp_name, $target_file)){
                        chmod($target_file, 0644);
                        $feedback_image_paths[] = $new_filename;
                    }
                }
            }
        }
    }
    
    $feedback_images_str = !empty($feedback_image_paths) ? implode(",", $feedback_image_paths) : '';
    
    $sql = "UPDATE complaints SET status=?, feedback=?, feedback_images=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssii", $status, $feedback, $feedback_images_str, $handler_id, $complaint_id);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            header("Location: view_complaint.php?id=$complaint_id&success=feedback_given&i4cus=1");
            exit;
        } else {
            $error_message = "Failed to update complaint. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}

// Handle treatment form submission for payment admin
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['treat_payment_complaint']) && 
    isset($_GET['payment']) && $_GET['payment'] == 1 && 
    $_SESSION['role_id'] == 6 && $complaint['status'] != 'Treated') {
    
    $status = $_POST['status'];
    $feedback = trim($_POST['feedback']);
    $handler_id = $_SESSION['user_id'];
    
    // Handle feedback image uploads
    $feedback_image_paths = array();
    if(isset($_FILES["feedback_images"]) && !empty($_FILES["feedback_images"]["name"][0])){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $target_dir = "uploads/";
        
        // Create uploads directory if it doesn't exist
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        
        // Loop through each uploaded file
        foreach($_FILES["feedback_images"]["tmp_name"] as $key => $tmp_name){
            if($_FILES["feedback_images"]["error"][$key] == 0){
                $filename = $_FILES["feedback_images"]["name"][$key];
                $filetype = $_FILES["feedback_images"]["type"][$key];
                $filesize = $_FILES["feedback_images"]["size"][$key];
                
                // Verify file extension
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if(!array_key_exists($ext, $allowed)) continue;
                
                // Verify file size - 5MB maximum
                $maxsize = 5 * 1024 * 1024;
                if($filesize > $maxsize) continue;
                
                // Verify MIME type
                if(in_array($filetype, $allowed)){
                    $new_filename = "feedback_" . uniqid() . "." . $ext;
                    $target_file = $target_dir . $new_filename;
                    
                    if(move_uploaded_file($tmp_name, $target_file)){
                        chmod($target_file, 0644);
                        $feedback_image_paths[] = $new_filename;
                    }
                }
            }
        }
    }
    
    $feedback_images_str = !empty($feedback_image_paths) ? implode(",", $feedback_image_paths) : null;
    
    $sql = "UPDATE complaints SET status=?, feedback=?, feedback_images=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssii", $status, $feedback, $feedback_images_str, $handler_id, $complaint_id);
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Payment complaint updated successfully.";
            
            // Create notification for the user who lodged the complaint
            require_once "includes/notifications.php";
            $notification_title = "Feedback Given on Your Payment Complaint";
            $notification_message = "Your payment complaint #$complaint_id has received feedback. Status: $status";
            createNotification($conn, $complaint['lodged_by'], $complaint_id, 'feedback_given', $notification_title, $notification_message);
            
            // Send email notification if complaint is treated
            if ($status == 'Treated') {
                // Get user email
                $user_email = '';
                $user_sql = "SELECT u.email FROM users u WHERE u.user_id = ?";
                if ($user_stmt = mysqli_prepare($conn, $user_sql)) {
                    mysqli_stmt_bind_param($user_stmt, "i", $complaint['lodged_by']);
                    mysqli_stmt_execute($user_stmt);
                    $user_result = mysqli_stmt_get_result($user_stmt);
                    if ($user_row = mysqli_fetch_assoc($user_result)) {
                        $user_email = $user_row['email'];
                    }
                    mysqli_stmt_close($user_stmt);
                }
                
                if (!empty($user_email)) {
                    $subject = "Your Payment-Related Complaint Has Been Treated";
                    $message = "Dear User,\n\n";
                    $message .= "Your payment-related complaint (ID: {$complaint_id}) has been treated.\n\n";
                    $message .= "Status: {$status}\n";
                    $message .= "Feedback: {$feedback}\n\n";
                    $message .= "Please login to the system to view the details.\n\n";
                    $message .= "Regards,\nTSU ICT Complaint Desk";
                    
                    $headers = "From: noreply@tsuictcomplaint.com\r\n";
                    
                    mail($user_email, $subject, $message, $headers);
                }
            }
            
            // Redirect to prevent form resubmission
            header("Location: view_complaint.php?id=$complaint_id&success=feedback_given" . (isset($_GET['payment']) ? '&payment=1' : ''));
            exit;
        } else {
            $error_message = "Failed to update payment complaint. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}

// Handle feedback reply submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback_reply'])) {
    $feedback_reply = trim($_POST['feedback_reply']);
    $sender_id = $_SESSION['user_id'];
    
    if (!empty($feedback_reply)) {
        $sql = "INSERT INTO complaint_replies (complaint_id, sender_id, reply_text) VALUES (?, ?, ?)";
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "iis", $complaint_id, $sender_id, $feedback_reply);
            if (mysqli_stmt_execute($stmt)) {
                // Create notification for admin/handler
                require_once "includes/notifications.php";
                $notification_title = "Reply to Feedback Received";
                $notification_message = "User replied to feedback on complaint #$complaint_id";
                
                // Get the handler of the complaint to notify them
                $handler_sql = "SELECT handled_by FROM complaints WHERE complaint_id = ?";
                if ($handler_stmt = mysqli_prepare($conn, $handler_sql)) {
                    mysqli_stmt_bind_param($handler_stmt, "i", $complaint_id);
                    mysqli_stmt_execute($handler_stmt);
                    $handler_result = mysqli_stmt_get_result($handler_stmt);
                    if ($handler_row = mysqli_fetch_assoc($handler_result)) {
                        $handler_id = $handler_row['handled_by'];
                        if ($handler_id) {
                            createNotification($conn, $handler_id, $complaint_id, 'feedback_reply', $notification_title, $notification_message);
                        }
                    }
                    mysqli_stmt_close($handler_stmt);
                }
                
                // Redirect to prevent form resubmission
                header("Location: view_complaint.php?id=$complaint_id&success=reply_sent");
                exit;
            } else {
                $error_message = "Failed to send reply. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please enter a reply message.";
    }
}

// Handle success messages from redirects
if(isset($_GET['success'])) {
    switch($_GET['success']) {
        case 'feedback_given':
            $success_message = "Feedback provided successfully.";
            break;
        case 'reply_sent':
            $success_message = "Reply sent successfully.";
            break;
        case 'complaint_updated':
            $success_message = "Complaint updated successfully.";
            break;
    }
}

// Fetch replies for this complaint
$replies = [];
$sql = "SELECT r.*, u.full_name FROM complaint_replies r JOIN users u ON r.sender_id = u.user_id WHERE r.complaint_id = ? ORDER BY r.created_at ASC";
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $complaint_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($reply = mysqli_fetch_assoc($result)) {
        $replies[] = $reply;
    }
    mysqli_stmt_close($stmt);
}
$reply_count = count($replies);

// Process attachment information
$images = array();
$img_count = 0;
$has_attachments = false;

if(isset($complaint['image_path']) && !empty($complaint['image_path']) && trim($complaint['image_path']) !== '' && trim($complaint['image_path']) !== '0') {
    $images = array_filter(explode(",", trim($complaint['image_path'])));
    $images = array_map('trim', $images); // Remove any whitespace
    $images = array_filter($images); // Remove empty values
    
    $img_count = count($images);
    $has_attachments = $img_count > 0;
}

// Helper function to get image URL with fallback
function getImagePath($image) {
    $image = trim($image);
    if (empty($image) || $image === '0') {
        return '';
    }
    
    // Clean the filename
    $filename = basename($image);
    
    // Use the public image serving script for better error handling
    return 'public_image.php?img=' . urlencode($filename);
}

// Alternative direct path function (for fallback)
function getDirectImagePath($image) {
    $image = trim($image);
    if (empty($image) || $image === '0') {
        return '';
    }
    
    // Clean the filename
    $filename = basename($image);
    
    // Always use the public image serving script
    return 'public_image.php?img=' . urlencode($filename);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Complaint - TSU ICT Complaint System</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }
        .card {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Navbar improvements */
        .navbar-nav .nav-link {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
        }
        
        .navbar-nav .nav-link i {
            margin-right: 0.5rem;
            font-size: 1rem;
        }
        
        /* Icon-only items */
        .navbar-nav .nav-link.icon-only {
            justify-content: center;
            padding: 0.5rem 0.75rem;
            min-width: 40px;
        }
        
        .navbar-nav .nav-link.icon-only i {
            margin-right: 0;
            font-size: 1.1rem;
        }
        
        .navbar-nav .badge {
            font-size: 0.65rem;
            min-width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .navbar-nav .nav-item.active .nav-link {
            background-color: rgba(255,255,255,0.1);
            border-radius: 4px;
        }
        
        /* Tooltip styling */
        .tooltip-inner {
            background-color: #333;
            color: #fff;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        
        /* Conversation styling */
        .reply-item {
            max-width: 80%;
        }
        
        .staff-reply {
            margin-left: auto;
            margin-right: 0;
        }
        
        .user-reply {
            margin-left: 0;
            margin-right: auto;
        }
        
        .staff-reply .reply-content {
            background-color: #007bff;
            color: white;
            border-bottom-right-radius: 5px !important;
        }
        
        .user-reply .reply-content {
            background-color: #e9ecef;
            color: #333;
            border-bottom-left-radius: 5px !important;
        }
        
        .reply-header {
            font-size: 0.9rem;
        }
        
        .staff-reply .reply-header {
            text-align: right;
        }
        
        .user-reply .reply-header {
            text-align: left;
        }
        .badge-success, .badge-warning, .badge-danger, .badge-secondary {
            font-size: 85%;
        }
        .image-count-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 0.7rem;
        }
        .image-modal img {
            max-width: 100%;
        }
        .modal-thumbnails {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 5px;
            width: 100%;
            margin-top: 10px;
        }
        .modal-thumbnail {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border: 2px solid #ddd;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .modal-thumbnail.active {
            border-color: #007bff;
            transform: scale(1.1);
        }
        /* Gallery Modal Styles */
        .gallery-image.zoomed {
            max-height: none;
            max-width: none;
            width: auto;
            height: auto;
            transform: scale(1.5);
            transition: transform 0.3s ease;
            cursor: zoom-out;
        }
        .attachment-notification {
            background-color: #f8f9fa;
            padding: 8px 12px;
            border-radius: 4px;
            border-left: 3px solid #17a2b8;
        }
        .no-attachments-info {
            color: #6c757d;
            font-style: italic;
        }
        /* Attachment preview styles */
        .attachment-preview {
            display: inline-block;
            margin: 5px;
            position: relative;
        }
        .attachment-preview img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .attachment-preview img:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        /* Image error handling */
        .image-error {
            background: #f8f9fa;
            border: 1px dashed #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
            width: 100px;
            height: 100px;
            border-radius: 4px;
        }
        
        /* Loading states */
        .image-loading {
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            width: 100px;
            height: 100px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Complaint Details</h4>
                        <?php
                        // Determine back dashboard URL based on user role
                        $back_dashboard = 'dashboard.php'; // Default for regular users
                        
                        // Always prioritize role-based redirection
                        if ($_SESSION['role_id'] == 5) { // i4Cus Staff
                            $back_dashboard = 'i4cus_staff_dashboard.php';
                        } elseif ($_SESSION['role_id'] == 6) { // Payment Admin
                            $back_dashboard = 'payment_admin_dashboard.php';
                        } elseif ($_SESSION['role_id'] == 3) { // Director
                            $back_dashboard = 'director_dashboard.php';
                        } elseif ($_SESSION['role_id'] == 4) { // DVC
                            $back_dashboard = 'dvc_dashboard.php';
                        } elseif ($_SESSION['role_id'] == 1) { // Admin
                            $back_dashboard = 'admin.php';
                        } elseif ($_SESSION['role_id'] == 7) { // Department
                            $back_dashboard = 'department_dashboard.php';
                        }
                        ?>
                        <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary btn-sm">Back</a>
                    </div>
                    <div class="card-body">
                        <?php if(isset($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        <?php if(isset($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <?php if($_SESSION["user_id"] == $complaint["lodged_by"]): ?>
                        <!-- Edit Form - Only visible to the person who lodged the complaint -->
                        <form method="post" id="editForm" style="display: none;" enctype="multipart/form-data">
                            <input type="hidden" name="lodged_by" value="<?php echo $complaint['lodged_by']; ?>">
                            <div class="form-group">
                                <label>Student ID</label>
                                <input type="text" name="student_id" class="form-control" value="<?php echo htmlspecialchars($complaint['student_id'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Complaint Details</label>
                                <textarea name="complaint_text" class="form-control" rows="5" required><?php echo htmlspecialchars($complaint['complaint_text'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="urgentCheck" name="is_urgent" <?php echo $complaint['is_urgent'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="urgentCheck">Mark as Urgent</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="paymentCheck" name="is_payment_related" <?php echo $complaint['is_payment_related'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="paymentCheck">This is a payment-related issue</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Attach Images (Optional)</label>
                                <input type="file" id="edit_complaint_images" name="edit_complaint_images[]" class="form-control-file" accept="image/*" multiple>
                                <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)<br>
                                <strong>💡 Tip:</strong> Paste screenshots with Ctrl+V or drag & drop images!</small>
                            </div>
                            <button type="submit" name="edit_complaint" class="btn btn-primary">Save Changes</button>
                            <button type="button" class="btn btn-secondary" onclick="toggleEdit()">Cancel</button>
                        </form>
                        <?php endif; ?>

                        <!-- Complaint Details View -->
                        <div id="viewDetails">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Student ID:</strong>
                                    <p><?php echo htmlspecialchars($complaint['student_id'] ?? ''); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <strong>Date Submitted:</strong>
                                    <p><?php echo date('M d, Y h:i A', strtotime($complaint['created_at'] ?? '')); ?></p>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <strong>Complaint Details:</strong>
                                    <p><?php echo nl2br(htmlspecialchars($complaint['complaint_text'] ?? '')); ?></p>
                                </div>
                            </div>

                            <!-- Enhanced attachment display -->
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <strong>Attachments:</strong>
                                    <div class="mt-2">
                                        <?php if($has_attachments): ?>
                                            <div class="attachment-notification mb-2">
                                                <i class="fas fa-paperclip mr-1"></i> 
                                                This complaint has <?php echo $img_count; ?> attachment<?php echo $img_count > 1 ? 's' : ''; ?>
                                            </div>
                                            
                                            <div class="attachment-preview-container">
                                                <?php foreach($images as $index => $image): 
                                                    $image_path = getDirectImagePath($image);
                                                ?>
                                                    <div class="attachment-preview">
                                                        <img src="<?php echo htmlspecialchars($image_path); ?>" 
                                                             alt="Complaint Image <?php echo ($index + 1); ?>"
                                                             loading="lazy"
                                                             onclick="showImageInModal('<?php echo htmlspecialchars($image_path); ?>', <?php echo $index; ?>)"
                                                             onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-error\'>Image not available</div>';">
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <button type="button" class="btn btn-info btn-sm mt-2" onclick="showGalleryModal(<?php echo htmlspecialchars(json_encode(array_map('getDirectImagePath', $images))); ?>)">
                                                <i class="fas fa-images"></i> View Gallery
                                            </button>
                                        <?php else: ?>
                                            <span class="no-attachments-info">
                                                <i class="fas fa-info-circle"></i> No attachments uploaded with this complaint
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Status:</strong>
                                    <p>
                                        <span class="badge badge-<?php echo $complaint['status'] == 'Treated' ? 'success' : 'warning'; ?>">
                                            <?php echo htmlspecialchars($complaint['status'] ?? ''); ?>
                                        </span>
                                    </p>
                                </div>
                                <div class="col-md-6">
                                    <strong>Priority:</strong>
                                    <p>
                                        <span class="badge badge-<?php echo $complaint['is_urgent'] ? 'danger' : 'secondary'; ?>">
                                            <?php echo $complaint['is_urgent'] ? 'Urgent' : 'Normal'; ?>
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Lodged By:</strong>
                                    <p><?php echo htmlspecialchars($complaint['lodger_name'] ?? ''); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <strong>Handled By:</strong>
                                    <p><?php echo htmlspecialchars($complaint['handler_name'] ?? 'Not assigned'); ?></p>
                                </div>
                            </div>

                            <?php if($complaint['feedback']): ?>
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <strong>Feedback:</strong>
                                    <p><?php echo nl2br(htmlspecialchars($complaint['feedback'] ?? '')); ?></p>
                                    
                                    <?php if(!empty($complaint['feedback_images'])): ?>
                                        <div class="mt-3">
                                            <strong>Feedback Images:</strong>
                                            <div class="gallery-container mt-2">
                                                <?php 
                                                $feedback_images = array_filter(explode(",", $complaint['feedback_images']));
                                                foreach($feedback_images as $index => $image): 
                                                    $image = trim($image);
                                                    if(!empty($image)):
                                                        $image_url = 'public_image.php?img=' . urlencode($image);
                                                ?>
                                                    <div class="gallery-item" onclick="toggleZoom(this)">
                                                        <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                                             alt="Feedback Image <?php echo $index+1; ?>"
                                                             loading="lazy"
                                                             onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-error\'>Image not available</div>';">
                                                    </div>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Feedback Reply Section -->
                            <?php if($complaint['feedback'] && $complaint['lodged_by'] == $_SESSION['user_id']): ?>
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <div class="card">
                                        <div class="card-header">
                                            <h6><i class="fas fa-reply"></i> Reply to Feedback</h6>
                                        </div>
                                        <div class="card-body">
                                            <form method="post" id="feedbackReplyForm">
                                                <div class="form-group">
                                                    <textarea name="feedback_reply" class="form-control" rows="3" placeholder="Write your reply to the feedback..." required></textarea>
                                                </div>
                                                <button type="submit" name="submit_feedback_reply" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-paper-plane"></i> Send Reply
                                                </button>
                                                <button type="button" class="btn btn-success btn-sm ml-2" onclick="markFeedbackAsRead()">
                                                    <i class="fas fa-check"></i> Mark as Read
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Complaint Replies Section -->
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <strong>Conversation:</strong>
                                    <div id="complaintReplies">
                                        <?php if (count($replies) > 0) : ?>
                                            <?php foreach ($replies as $reply): 
                                                $is_staff = ($reply['sender_id'] != $complaint['lodged_by']);
                                                $bubble_class = $is_staff ? 'staff-reply' : 'user-reply';
                                            ?>
                                                <div class="reply-item mb-3 <?php echo $bubble_class; ?>">
                                                    <div class="reply-header mb-1">
                                                        <strong><?php echo htmlspecialchars($reply['full_name']); ?></strong>
                                                        <small class="text-muted ml-2"><?php echo date('M d, Y h:i A', strtotime($reply['created_at'])); ?></small>
                                                        <?php if ($is_staff): ?>
                                                            <span class="badge badge-info ml-2">Staff</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="reply-content p-3 rounded">
                                                        <p class="mb-1"><?php echo nl2br(htmlspecialchars($reply['reply_text'])); ?></p>
                                                            
                                                            <?php if(!empty($reply['reply_images'])): ?>
                                                                <div class="mt-2">
                                                                    <strong>Attached Images:</strong>
                                                                    <div class="gallery-container mt-2">
                                                                        <?php 
                                                                        $reply_images = array_filter(explode(",", $reply['reply_images']));
                                                                        foreach($reply_images as $index => $image): 
                                                                            $image = trim($image);
                                                                            if(!empty($image)):
                                                                                $image_url = 'public_image.php?img=' . urlencode($image);
                                                                        ?>
                                                                            <div class="gallery-item" onclick="toggleZoom(this)">
                                                                                <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                                                                     alt="Reply Image <?php echo $index+1; ?>"
                                                                                     loading="lazy"
                                                                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-error\'>Image not available</div>';">
                                                                            </div>
                                                                        <?php 
                                                                            endif;
                                                                        endforeach; 
                                                                        ?>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <p class='text-muted'>No replies yet.</p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Reply form - available until complaint is treated -->
                                    <?php if ($complaint['status'] != 'Treated' && ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 2)): ?>
                                    <form method="post" class="mt-2" enctype="multipart/form-data">
                                        <div class="form-group">
                                            <textarea name="reply_text" class="form-control" rows="2" placeholder="Type your reply..." required></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="reply_images">Attach Images (Optional)</label>
                                            <input type="file" id="staff_reply_images" name="reply_images[]" class="form-control-file" accept="image/*" multiple>
                                            <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)<br>
                                            <strong>💡 Tip:</strong> Paste screenshots with Ctrl+V or drag & drop images!</small>
                                        </div>
                                        <button type="submit" name="submit_reply" class="btn btn-info btn-sm">Send Reply</button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <!-- Reply form for complaint owner -->
                                    <?php if ($complaint['status'] != 'Treated' && $_SESSION['user_id'] == $complaint['lodged_by']): ?>
                                    <form method="post" class="mt-2" enctype="multipart/form-data">
                                        <div class="form-group">
                                            <textarea name="reply_text" class="form-control" rows="2" placeholder="Type your reply..." required></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label for="user_reply_images">Attach Images (Optional)</label>
                                            <input type="file" id="user_reply_images" name="reply_images[]" class="form-control-file" accept="image/*" multiple>
                                            <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)<br>
                                            <strong>💡 Tip:</strong> Paste screenshots with Ctrl+V or drag & drop images!</small>
                                        </div>
                                        <button type="submit" name="submit_reply" class="btn btn-primary btn-sm">
                                            <i class="fas fa-paper-plane"></i> Send Reply
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if($_SESSION["user_id"] == $complaint["lodged_by"]): ?>
                            <button type="button" class="btn btn-primary" onclick="toggleEdit()">Edit Complaint</button>
                            <?php endif; ?>
                            
                            <?php if (isset($_GET['i4cus']) && $_GET['i4cus'] == 1 && $_SESSION['role_id'] == 5): ?>
                                <form method="post" class="mb-3" enctype="multipart/form-data">
                                    <div class="form-group">
                                        <label for="status">Update Status</label>
                                        <select name="status" class="form-control" required>
                                            <option value="Pending"<?php echo ($complaint['status']=='Pending'?' selected':''); ?>>Pending</option>
                                            <option value="Treated"<?php echo ($complaint['status']=='Treated'?' selected':''); ?>>Treated</option>
                                            <option value="Needs More Info"<?php echo ($complaint['status']=='Needs More Info'?' selected':''); ?>>Needs More Info</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="feedback">Feedback</label>
                                        <textarea name="feedback" class="form-control" rows="3" placeholder="Provide feedback... (Paste images with Ctrl+V)"><?php echo htmlspecialchars($complaint['feedback']??''); ?></textarea>
                                        <small class="form-text text-muted">
                                            <i class="fas fa-paperclip text-primary"></i> 
                                            Paste images directly while typing or click the attachment icon
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label for="feedback_images">Attach Images to Feedback (Optional)</label>
                                        <input type="file" id="i4cus_feedback_images" name="feedback_images[]" class="form-control-file" accept="image/*" multiple>
                                        <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)<br>
                                        <strong>💡 Tip:</strong> Paste screenshots with Ctrl+V or drag & drop images!</small>
                                    </div>
                                    <button type="submit" name="treat_complaint" class="btn btn-success">Update & Give Feedback</button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if (isset($_GET['payment']) && $_GET['payment'] == 1 && $_SESSION['role_id'] == 6 && $complaint['status'] != 'Treated'): ?>
                                <form method="post" class="mb-3" enctype="multipart/form-data">
                                    <div class="form-group">
                                        <label for="status">Update Payment Complaint Status</label>
                                        <select name="status" class="form-control" required>
                                            <option value="Pending"<?php echo ($complaint['status']=='Pending'?' selected':''); ?>>Pending</option>
                                            <option value="Treated"<?php echo ($complaint['status']=='Treated'?' selected':''); ?>>Treated</option>
                                            <option value="Needs More Info"<?php echo ($complaint['status']=='Needs More Info'?' selected':''); ?>>Needs More Info</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="feedback">Payment Resolution Feedback</label>
                                        <textarea id="payment_feedback" name="feedback" class="form-control manual-clipboard-init" rows="3"><?php echo htmlspecialchars($complaint['feedback']??''); ?></textarea>
                                        <small class="form-text text-muted">
                                            <strong>💡 Tip:</strong> Paste screenshots with Ctrl+V directly into this text area!
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label for="feedback_images">Attach Images to Feedback (Optional)</label>
                                        <input type="file" id="payment_feedback_images" name="feedback_images[]" class="form-control-file" accept="image/*" multiple>
                                        <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)<br>
                                        <strong>💡 Tip:</strong> Paste screenshots with Ctrl+V or drag & drop images!</small>
                                    </div>
                                    <button type="submit" name="treat_payment_complaint" class="btn btn-success">Update & Give Feedback</button>
                                </form>
                            <?php endif; ?>
                            
                            <?php
                            // Determine back dashboard URL based on user role
                            $back_dashboard = 'dashboard.php'; // Default for regular users
                            
                            // Always prioritize role-based redirection regardless of query parameters
                            if ($_SESSION['role_id'] == 5) { // i4Cus Staff
                                $back_dashboard = 'i4cus_staff_dashboard.php';
                            } elseif ($_SESSION['role_id'] == 6) { // Payment Admin
                                $back_dashboard = 'payment_admin_dashboard.php';
                            } elseif ($_SESSION['role_id'] == 3) { // Director
                                $back_dashboard = 'director_dashboard.php';
                            } elseif ($_SESSION['role_id'] == 4) { // DVC
                                $back_dashboard = 'dvc_dashboard.php';
                            } elseif ($_SESSION['role_id'] == 1) { // Admin
                                $back_dashboard = 'admin.php';
                            } elseif ($_SESSION['role_id'] == 7) { // Department
                                $back_dashboard = 'department_dashboard.php';
                            }
                            ?>
                            <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary">Back to Dashboard</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Gallery Modal -->    
    <div class="modal fade" id="galleryModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Complaint Attachments</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center p-0">
                    <img id="galleryMainImage" src="" class="img-fluid gallery-image" style="max-height: 70vh; cursor: zoom-in;" onclick="toggleZoom(this)">
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" onclick="navigateGallery(-1)">
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <span id="galleryImageCount"></span>
                    <button type="button" class="btn btn-secondary" onclick="navigateGallery(1)">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                <div class="modal-footer">
                    <div class="modal-thumbnails" id="galleryThumbnails"></div>
                    <button type="button" class="btn btn-info btn-sm" onclick="downloadAllImages()">
                        <i class="fas fa-download"></i> Download All
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="js/clipboard-paste.js"></script>
    <script>
    // Enhanced gallery functionality
    let currentImages = [];
    let currentIndex = 0;
    
    function showImageInModal(imageSrc, index) {
        // Set current images if not already set
        if (currentImages.length === 0) {
            currentImages = <?php echo json_encode(array_map('getDirectImagePath', $images)); ?>;
        }
        currentIndex = index;
        showGalleryModal(currentImages);
    }
    
    function showGalleryModal(images) {
        currentImages = images;
        if (currentImages.length === 0) return;
        
        const modal = $('#galleryModal');
        const modalImage = $('#galleryMainImage');
        const imageCount = $('#galleryImageCount');
        const thumbnailsContainer = $('#galleryThumbnails');
        
        // Set main image
        modalImage.attr('src', currentImages[currentIndex]);
        modalImage.removeClass('zoomed');
        modalImage.css('cursor', 'zoom-in');
        modalImage.attr('onerror', "this.onerror=null; this.src='data:image/svg+xml;charset=utf-8,' + encodeURIComponent('<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"300\" height=\"200\"><rect width=\"300\" height=\"200\" fill=\"#f8f9fa\" stroke=\"#dee2e6\"/><text x=\"150\" y=\"100\" text-anchor=\"middle\" dy=\".3em\" fill=\"#6c757d\" font-family=\"Arial\" font-size=\"16\">Image not found</text></svg>');");
        imageCount.text(`${currentIndex + 1} of ${currentImages.length}`);
        
        // Clear and create thumbnails
        thumbnailsContainer.empty();
        currentImages.forEach((img, idx) => {
            const thumb = $(`<img src="${img}" class="modal-thumbnail ${idx === currentIndex ? 'active' : ''}" data-index="${idx}" onerror="this.style.display='none';">`);
            thumb.on('click', function() {
                const index = $(this).data('index');
                currentIndex = index;
                modalImage.attr('src', currentImages[currentIndex]);
                modalImage.removeClass('zoomed');
                modalImage.css('cursor', 'zoom-in');
                imageCount.text(`${currentIndex + 1} of ${currentImages.length}`);
                
                // Update active thumbnail
                $('.modal-thumbnail').removeClass('active');
                $(this).addClass('active');
            });
            thumbnailsContainer.append(thumb);
        });
        
        modal.modal('show');
    }

    function navigateGallery(direction) {
        if (currentImages.length === 0) return;
        currentIndex = (currentIndex + direction + currentImages.length) % currentImages.length;
        $('#galleryMainImage').attr('src', currentImages[currentIndex]);
        $('#galleryMainImage').removeClass('zoomed');
        $('#galleryMainImage').css('cursor', 'zoom-in');
        $('#galleryImageCount').text(`${currentIndex + 1} of ${currentImages.length}`);
        
        // Update active thumbnail
        $('.modal-thumbnail').removeClass('active');
        $(`.modal-thumbnail[data-index="${currentIndex}"]`).addClass('active');
    }

    function downloadAllImages() {
        if (currentImages.length === 0) return;
        
        currentImages.forEach((img, index) => {
            const link = document.createElement('a');
            link.href = img;
            link.download = `attachment-${index+1}`;
            link.target = '_blank';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }

    function toggleEdit() {
        const editForm = document.getElementById('editForm');
        const viewDetails = document.getElementById('viewDetails');
        
        if (editForm.style.display === 'none') {
            editForm.style.display = 'block';
            viewDetails.style.display = 'none';
        } else {
            editForm.style.display = 'none';
            viewDetails.style.display = 'block';
        }
    }
    
    function toggleZoom(element) {
        if (element.classList.contains('zoomed')) {
            element.classList.remove('zoomed');
            element.style.cursor = 'zoom-in';
        } else {
            element.classList.add('zoomed');
            element.style.cursor = 'zoom-out';
        }
    }
    
    // Mark feedback as read function
    function markFeedbackAsRead() {
        // This could be enhanced to make an AJAX call to mark notifications as read
        // For now, just show a confirmation
        if (confirm('Mark this feedback as read?')) {
            // You could add AJAX call here to mark notifications as read
            alert('Feedback marked as read!');
        }
    }
    
    // Initialize clipboard paste functionality when document is ready
    $(document).ready(function() {
        // Initialize clipboard paste for payment feedback textarea
        if (window.clipboardPasteHandler) {
            const paymentFeedbackTextarea = document.getElementById('payment_feedback');
            const paymentFeedbackFileInput = document.getElementById('payment_feedback_images');
            
            if (paymentFeedbackTextarea && paymentFeedbackFileInput && !paymentFeedbackTextarea.classList.contains('textarea-with-paste')) {
                // Use the initializeClipboardPaste function for proper manual initialization
                if (typeof initializeClipboardPaste === 'function') {
                    initializeClipboardPaste(paymentFeedbackTextarea, paymentFeedbackFileInput);
                }
            }
        }
    });
    </script>
</body>
</html>
