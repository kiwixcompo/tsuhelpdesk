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

