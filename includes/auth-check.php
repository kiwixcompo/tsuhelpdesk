<?php
/**
 * includes/auth-check.php
 *
 * Implements RBAC (Role-Based Access Control) authentication boundaries,
 * session lifecycle management, and session hijacking mitigation rules.
 *
 * OWASP Reference:
 * - A01:2021-Broken Access Control
 * - A07:2021-Identification and Authentication Failures
 */

require_once __DIR__ . '/security-helpers.php';

// Ensure secure configuration values are enforced
if (file_exists(__DIR__ . '/../security-config.php')) {
    require_once __DIR__ . '/../security-config.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 1. Validate Session Age and Enforce Max Session Idle Lifetime
 * Destroys sessions that have been idle past the config window.
 */
function enforce_session_lifetime(): void {
    $lifetime = (int)env('SESSION_LIFETIME', 120) * 60; // Convert minutes to seconds
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $lifetime)) {
        session_unset();
        session_destroy();
        
        // Redirect to appropriate landing page based on context
        $redirectUrl = (strpos($_SERVER['SCRIPT_NAME'], 'student') !== false) ? 'student_login.php' : 'staff_login.php';
        secure_redirect($redirectUrl . '?timeout=1');
    }
    
    $_SESSION['last_activity'] = time();
}

/**
 * 2. Mitigate Session Hijacking by regenerating the session identifier
 * Run immediately after status transitions (login, privilege escalation).
 */
function secure_session_regenerate(): void {
    // Regenerate ID and delete the old session file
    session_regenerate_id(true);
}

/**
 * 3. Enforce Authentication Check for Standard Users
 */
function require_login(string $userType = 'staff'): void {
    enforce_session_lifetime();
    
    if ($userType === 'student') {
        if (!isset($_SESSION['student_id']) || empty($_SESSION['student_id'])) {
            secure_redirect('student_login.php');
        }
    } else {
        if (!isset($_SESSION['id']) || empty($_SESSION['id'])) {
            secure_redirect('staff_login.php');
        }
    }
}

/**
 * 4. Role-Based Access Control Gatekeeper
 * Asserts the current user has the correct authorization roles before executing page tasks.
 */
function require_roles(array $allowedRoles): void {
    require_login('staff');
    
    $userRole = $_SESSION['role'] ?? '';
    
    if (!in_array($userRole, $allowedRoles, true)) {
        // Safe access denied routing
        http_response_code(403);
        
        // Custom secure access denied UI
        echo '<!DOCTYPE html>
        <html>
        <head>
            <title>Access Denied</title>
            <meta charset="utf-8">
            <style>
                body { font-family: sans-serif; text-align: center; padding: 100px; background-color: #f7f9fa; color: #333; }
                h1 { color: #d9534f; }
                p { font-size: 18px; }
                a { color: #337ab7; text-decoration: none; }
            </style>
        </head>
        <body>
            <h1>403 - Forbidden</h1>
            <p>You do not have the required permissions to access this page.</p>
            <hr style="width: 200px; border-color: #eee;">
            <p><a href="dashboard.php">Return to Dashboard</a></p>
        </body>
        </html>';
        exit;
    }
}
