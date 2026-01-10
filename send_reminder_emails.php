<?php
/**
 * Reminder Email Script for TSU ICT Complaint System
 * 
 * This script sends reminder emails for pending complaints that haven't been updated
 * in the last 48 hours. It should be scheduled to run daily using a cron job (Linux)
 * or Windows Task Scheduler.
 * 
 * Example cron job (run daily at 8 AM):
 * 0 8 * * * php /path/to/TSUICTComplaint/send_reminder_emails.php
 * 
 * Example Windows Task Scheduler:
 * Program/script: C:\path\to\php.exe
 * Arguments: C:\wamp64\www\TSUICTComplaint\send_reminder_emails.php
 * Start in: C:\wamp64\www\TSUICTComplaint\
 */

require_once "config.php";

// === CONFIGURE THESE ===
$site_url = "http://yourdomain.com/TSUICTComplaint/dashboard.php"; // Change to your actual dashboard URL
$from_email = "noreply@yourdomain.com"; // Change to your sender email
$from_name = "TSU ICT Complaint Desk";

// === Helper: Get users by role ===
function getUsersByRole($conn, $role_id) {
    $users = [];
    $sql = "SELECT email, full_name FROM users WHERE role_id = ? AND email IS NOT NULL AND email != ''";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $role_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    return $users;
}

// === Helper: Send email using PHP mail() ===
function sendReminderEmail($to_email, $to_name, $subject, $body, $from_email, $from_name) {
    $headers = "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    // Use mail() and return true/false
    return mail($to_email, $subject, $body, $headers);
}

// === 1. Find unhandled complaints older than 24 hours, not yet reminded ===
$sql = "SELECT * FROM complaints \
        WHERE status != 'Treated' \
          AND (handled_by IS NULL OR handled_by = 0) \
          AND created_at <= (NOW() - INTERVAL 24 HOUR)
          AND (reminder_sent_at IS NULL OR reminder_sent_at < (NOW() - INTERVAL 24 HOUR))";
$result = mysqli_query($conn, $sql);

if (!$result) {
    error_log("SQL Error: " . mysqli_error($conn));
    exit("Database error.");
}

while ($complaint = mysqli_fetch_assoc($result)) {
    $recipients = [];
    $role_id = 1; // Default to general admin

    if ($complaint['is_payment_related']) {
        $role_id = 6;
    } elseif ($complaint['is_i4cus']) {
        $role_id = 5;
    }

    $recipients = getUsersByRole($conn, $role_id);

    if (empty($recipients)) {
        error_log("No recipients found for complaint ID {$complaint['complaint_id']} (role_id: $role_id)");
        continue;
    }

    $subject = "Reminder: Unhandled Complaint (ID: {$complaint['complaint_id']})";
    $body = "Dear Staff,\n\n";
    $body .= "A complaint has been unhandled for over 24 hours:\n\n";
    $body .= "Complaint ID: {$complaint['complaint_id']}\n";
    $body .= "Complaint: {$complaint['complaint_text']}\n";
    $body .= "Date Lodged: {$complaint['created_at']}\n";
    $body .= "Student ID: {$complaint['student_id']}\n";
    $body .= "Department: {$complaint['department_name']}\n";
    $body .= "Staff Name: {$complaint['staff_name']}\n";
    $body .= "Type: " . 
        ($complaint['is_payment_related'] ? "Payment-related" : ($complaint['is_i4cus'] ? "i4cus-related" : "General")) . "\n\n";
    $body .= "Please attend to this complaint as soon as possible.\n";
    $body .= "View on dashboard: $site_url\n\n";
    $body .= "Thank you.";

    $all_sent = true;
    foreach ($recipients as $user) {
        $sent = sendReminderEmail($user['email'], $user['full_name'], $subject, $body, $from_email, $from_name);
        if (!$sent) {
            $all_sent = false;
            error_log("mail() failed for {$user['email']}");
        }
    }

    // === 4. Mark reminder as sent if all emails succeeded ===
    if ($all_sent) {
        $update_sql = "UPDATE complaints SET reminder_sent_at = NOW() WHERE complaint_id = ?";
        if ($stmt = mysqli_prepare($conn, $update_sql)) {
            mysqli_stmt_bind_param($stmt, "i", $complaint['complaint_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

echo "Reminder emails sent.\n";
?>