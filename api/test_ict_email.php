<?php
/**
 * Diagnostic: Test ICT complaint email notification
 * Visit: https://helpdesk.tsuniversity.ng/api/test_ict_email.php
 * Admin only.
 */
session_start();
require_once '../config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['role_id'] != 1) {
    die('Admin access required.');
}

require_once '../includes/notification_prefs.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== ICT Email Notification Diagnostic ===\n\n";

// 1. Show all admins and their email + prefs
$res = mysqli_query($conn,
    "SELECT u.user_id, u.full_name, u.email, u.is_active,
            unp.on_new_student_complaint, unp.on_new_complaint
     FROM users u
     LEFT JOIN user_notification_prefs unp ON u.user_id = unp.user_id
     WHERE u.role_id = 1");

echo "ADMINS:\n";
$admins = [];
while ($row = mysqli_fetch_assoc($res)) {
    $admins[] = $row;
    $email_ok  = !empty($row['email']) ? "✓ {$row['email']}" : "✗ NO EMAIL SET";
    $pref_ict  = $row['on_new_student_complaint'] ?? 'no row';
    $pref_comp = $row['on_new_complaint']         ?? 'no row';
    echo "  [{$row['user_id']}] {$row['full_name']} | Email: $email_ok | on_new_student_complaint=$pref_ict | on_new_complaint=$pref_comp\n";
}

echo "\n";

// 2. Run ensureNotifPrefsTable to fix any missing rows
ensureNotifPrefsTable($conn);
echo "ensureNotifPrefsTable() ran — prefs rows created/updated for all admins.\n\n";

// 3. Re-check after fix
$res2 = mysqli_query($conn,
    "SELECT u.user_id, u.full_name, u.email,
            unp.on_new_student_complaint, unp.on_new_complaint
     FROM users u
     LEFT JOIN user_notification_prefs unp ON u.user_id = unp.user_id
     WHERE u.role_id = 1");

echo "ADMINS AFTER FIX:\n";
$will_receive = [];
while ($row = mysqli_fetch_assoc($res2)) {
    $email_ok = !empty($row['email']) ? "✓ {$row['email']}" : "✗ NO EMAIL SET";
    $pref     = $row['on_new_student_complaint'] ?? 0;
    echo "  [{$row['user_id']}] {$row['full_name']} | Email: $email_ok | on_new_student_complaint=$pref\n";
    if (!empty($row['email']) && $pref) {
        $will_receive[] = $row['email'];
    }
}

echo "\n";

if (empty($will_receive)) {
    echo "⚠️  NO ADMINS WILL RECEIVE EMAILS.\n";
    echo "   Reasons: either no email is set in account.php, or the pref is off.\n";
} else {
    echo "✓ Emails will be sent to: " . implode(', ', $will_receive) . "\n";
}

// 4. Send a test email if ?send=1
if (isset($_GET['send']) && $_GET['send'] == '1' && !empty($will_receive)) {
    echo "\nSending test email...\n";
    $subject = "[TEST] ICT Complaint Email — " . date('Y-m-d H:i:s');
    $body    = "This is a test email from the TSU ICT Help Desk diagnostic tool.\n\n"
             . "If you received this, email notifications for new ICT complaints are working correctly.\n\n"
             . "-- TSU ICT Help Desk";

    foreach ($will_receive as $email) {
        $result = app_mail($email, $subject, $body);
        echo "  → {$email}: " . ($result ? "✓ Sent" : "✗ Failed") . "\n";
    }
} elseif (!empty($will_receive)) {
    echo "\nTo send a test email, visit: api/test_ict_email.php?send=1\n";
}

echo "\nDone.\n";
