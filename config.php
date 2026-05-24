<?php
// Force PHP errors to server error log before anything else loads
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Load environment variables from .env
require_once __DIR__ . '/includes/env.php';

// Load local overrides if present (local dev only — never deployed)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// ── Database credentials ──────────────────────────────────
// Read from .env; fall back to hardcoded values if .env is absent
define('DB_SERVER',   env('DB_HOST',     'localhost'));
define('DB_USERNAME', env('DB_USERNAME', 'tsuniver_tsu_ict_complaints'));
define('DB_PASSWORD', env('DB_PASSWORD', ''));
define('DB_NAME',     env('DB_DATABASE', 'tsuniver_tsu_ict_complaints'));

// Bootstrap error logging (creates logs/error.log automatically)
require_once __DIR__ . '/includes/logger.php';

// Attempt to connect to MySQL database
$conn = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn === false) {
    app_log('error', 'Database connection failed', ['error' => mysqli_connect_error()]);
    die("ERROR: Could not connect. " . mysqli_connect_error());
}

// Start session if not already started to support settings caching
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cache global application settings to minimize database query overhead
if (!isset($_SESSION['app_settings']) || !is_array($_SESSION['app_settings'])) {
    $_SESSION['app_settings'] = [];
    $settings_sql = "SELECT setting_key, setting_value FROM settings";
    $settings_result = mysqli_query($conn, $settings_sql);
    if ($settings_result) {
        while ($row = mysqli_fetch_assoc($settings_result)) {
            $_SESSION['app_settings'][$row['setting_key']] = $row['setting_value'];
        }
    }
}

// Globally expose core branding variables so pages load instantly
$app_name = $_SESSION['app_settings']['app_name'] ?? 'TSU ICT Help Desk';
$app_logo = $_SESSION['app_settings']['app_logo'] ?? '';
$app_favicon = $_SESSION['app_settings']['app_favicon'] ?? '';

// Automatically mark student ICT complaints under review for 1 week or more as Resolved
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'student_ict_complaints'");
if ($table_check && mysqli_num_rows($table_check) > 0) {
    // 1. Fetch complaints that are about to be auto-resolved to notify the students
    $select_sql = "SELECT complaint_id, student_id, node_label 
                   FROM student_ict_complaints 
                   WHERE status = 'Under Review' 
                     AND updated_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $select_res = mysqli_query($conn, $select_sql);
    if ($select_res && mysqli_num_rows($select_res) > 0) {
        $to_resolve_ids = [];
        while ($row = mysqli_fetch_assoc($select_res)) {
            $cid = (int)$row['complaint_id'];
            $sid = (int)$row['student_id'];
            $to_resolve_ids[] = $cid;
            
            // Insert notification for the student
            $notif_title = "Complaint Automatically Resolved";
            $notif_msg = "Your complaint regarding \"" . mysqli_real_escape_string($conn, $row['node_label']) . "\" has been automatically marked as resolved after 1 week of no activity under review.";
            $notif_sql = "INSERT INTO student_notifications (student_id, complaint_id, title, message, created_at)
                          VALUES ($sid, $cid, '" . mysqli_real_escape_string($conn, $notif_title) . "', '" . mysqli_real_escape_string($conn, $notif_msg) . "', NOW())";
            mysqli_query($conn, $notif_sql);
        }
        
        // 2. Perform the bulk update
        if (!empty($to_resolve_ids)) {
            $ids_str = implode(',', $to_resolve_ids);
            $update_sql = "UPDATE student_ict_complaints 
                           SET status = 'Resolved', updated_at = NOW() 
                           WHERE complaint_id IN ($ids_str)";
            mysqli_query($conn, $update_sql);
        }
    }
}


