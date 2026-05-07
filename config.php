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
