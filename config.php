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
    // Self-healing check: ensure result_verification_enabled and lodging availability settings exist
    mysqli_query($conn, "INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, setting_label) VALUES ('result_verification_enabled', '1', 'boolean', 'Result Verification Enabled')");
    mysqli_query($conn, "INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, setting_label) VALUES ('work_days', '1,2,3,4,5', 'text', 'Complaint Submission Days')");
    mysqli_query($conn, "INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, setting_label) VALUES ('work_start_time', '08:00', 'text', 'Complaint Submission Start Time')");
    mysqli_query($conn, "INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, setting_label) VALUES ('work_end_time', '16:00', 'text', 'Complaint Submission End Time')");
    // Self-healing: Ensure Faculty of Computing & Artificial Intelligence (FCA) programmes use TSU/FCA/ format
    mysqli_query($conn, "UPDATE programmes SET reg_number_format = REPLACE(reg_number_format, 'TSU/FSC/', 'TSU/FCA/') WHERE department_id IN (SELECT department_id FROM student_departments WHERE faculty_id = (SELECT faculty_id FROM faculties WHERE faculty_code = 'FCA'))");
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

// Universal parser to display attached images inside complaint replies/responses
function parse_response_images($text) {
    if (empty($text)) return '';
    $text = htmlspecialchars($text);
    // Parse [Attached Image: uploads/filename.png]
    $pattern = '/\[Attached Image: (uploads\/[a-zA-Z0-9_.-]+)\]/';
    $replacement = '<div class="mt-2"><img src="$1" class="img-thumbnail" style="max-height: 150px; cursor: pointer; border: 1px solid #dee2e6;" onclick="if(window.showImageModal){window.showImageModal(\'$1\');}else{window.open(\'$1\',\'_blank\');}"></div>';
    $text = preg_replace($pattern, $replacement, $text);
    return nl2br($text);
}

/**
 * Check if the current time is within official work hours.
 * Uses dynamic admin settings for days and hours.
 * Timezone set to Africa/Lagos.
 */
function isWorkHours() {
    date_default_timezone_set('Africa/Lagos');
    $now = time();
    $dayOfWeek = (int) date('N', $now); // 1 (Mon) - 7 (Sun)
    $currentTimeStr = date('H:i', $now);
    
    $work_days_str = $_SESSION['app_settings']['work_days'] ?? '1,2,3,4,5';
    $work_start_time = $_SESSION['app_settings']['work_start_time'] ?? '08:00';
    $work_end_time = $_SESSION['app_settings']['work_end_time'] ?? '16:00';
    
    // Parse work days
    $work_days = array_filter(array_map('intval', explode(',', $work_days_str)));
    
    // Check day of week
    if (!in_array($dayOfWeek, $work_days)) {
        return false;
    }
    
    // Check time range (compare HH:MM strings)
    if ($currentTimeStr < $work_start_time || $currentTimeStr >= $work_end_time) {
        return false;
    }
    
    return true;
}

/**
 * Compiles dynamic work hours configuration into a friendly string description.
 */
function getWorkHoursDescription() {
    $work_days_str = $_SESSION['app_settings']['work_days'] ?? '1,2,3,4,5';
    $work_start_time = $_SESSION['app_settings']['work_start_time'] ?? '08:00';
    $work_end_time = $_SESSION['app_settings']['work_end_time'] ?? '16:00';
    
    // Parse time to friendly format, e.g. 08:00 -> 8:00 AM, 16:00 -> 4:00 PM
    $start_dt = DateTime::createFromFormat('H:i', $work_start_time);
    $end_dt = DateTime::createFromFormat('H:i', $work_end_time);
    $start_formatted = $start_dt ? $start_dt->format('g:i A') : $work_start_time;
    $end_formatted = $end_dt ? $end_dt->format('g:i A') : $work_end_time;
    
    $days_map = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
    ];
    
    $work_days = array_filter(array_map('intval', explode(',', $work_days_str)));
    sort($work_days);
    
    if (empty($work_days)) {
        return "Complaint lodging is currently disabled by the administrator";
    }
    
    // Check if it's Mon-Fri
    if ($work_days === [1, 2, 3, 4, 5]) {
        $days_desc = 'Mondays to Fridays';
    } elseif ($work_days === [1, 2, 3, 4, 5, 6, 7]) {
        $days_desc = 'Every day (Monday to Sunday)';
    } elseif ($work_days === [1, 2, 3, 4, 5, 6]) {
        $days_desc = 'Mondays to Saturdays';
    } else {
        $day_names = [];
        foreach ($work_days as $d) {
            if (isset($days_map[$d])) {
                $day_names[] = $days_map[$d] . 's';
            }
        }
        if (count($day_names) > 1) {
            $last_day = array_pop($day_names);
            $days_desc = implode(', ', $day_names) . ' and ' . $last_day;
        } else {
            $days_desc = implode('', $day_names);
        }
    }
    
    return "$days_desc, from $start_formatted to $end_formatted";
}
?>


