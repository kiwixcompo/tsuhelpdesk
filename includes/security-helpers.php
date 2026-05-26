<?php
/**
 * includes/security-helpers.php
 *
 * Core cryptographic, input validation, and output sanitation utilities.
 * Implements defenses against standard web attack vectors.
 *
 * OWASP Reference:
 * - A03:2021-Injection (Cross-Site Scripting, SQLi)
 * - A01:2021-Broken Access Control (CSRF, Path Traversal)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 1. Safe Output encoding to prevent Cross-Site Scripting (XSS)
 * Wraps htmlspecialchars with strict options and explicit UTF-8 encoding.
 */
function secure_output(?string $data): string {
    if ($data === null) {
        return '';
    }
    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * 2. Strict Input validation against common patterns
 * Validates variables before processing. Returns validated value or false.
 */
function validate_input(string $data, string $type) {
    $data = trim($data);
    switch ($type) {
        case 'email':
            $cleaned = filter_var($data, FILTER_SANITIZE_EMAIL);
            return filter_var($cleaned, FILTER_VALIDATE_EMAIL) ? $cleaned : false;

        case 'int':
            return filter_var($data, FILTER_VALIDATE_INT) !== false ? (int)$data : false;

        case 'float':
            return filter_var($data, FILTER_VALIDATE_FLOAT) !== false ? (float)$data : false;

        case 'alphanumeric':
            return preg_match('/^[a-zA-Z0-9]+$/', $data) ? $data : false;

        case 'slug':
            return preg_match('/^[a-z0-9\-]+$/', $data) ? $data : false;

        case 'filename':
            return sanitize_filename($data);

        default:
            return false;
    }
}

/**
 * 3. Sanitize file name to prevent Path Traversal attacks (CWE-22)
 * Removes directory separators, double dots, and invalid characters.
 */
function sanitize_filename(string $filename): string {
    // Strip path traversal attempts and invalid control chars
    $filename = basename($filename);
    $filename = preg_replace('/[^\w\.\-\_]/', '_', $filename);
    // Eliminate double dots
    $filename = preg_replace('/\.\.+/', '.', $filename);
    return $filename;
}

/**
 * 4. Secure Open Redirect validation
 * Asserts the target redirect URL is internal to prevent external phishing links.
 */
function secure_redirect(string $url): void {
    $parsed = parse_url($url);
    
    // Check if the host belongs to external domain (allow empty host for relative paths)
    if (isset($parsed['host'])) {
        $allowedHost = env('APP_URL', '');
        $allowedHostName = parse_url($allowedHost, PHP_URL_HOST);
        
        if ($parsed['host'] !== $allowedHostName) {
            // Default safe redirect location
            $url = 'index.php';
        }
    }
    
    header("Location: " . $url);
    exit;
}

/**
 * 5. Cryptographic Anti-CSRF Token Management
 * Generates and validates cryptographically secure pseudo-random tokens.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    // Mitigate timing attack variables via constant-time comparison
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Renders anti-CSRF token input field for forms
 */
function csrf_token_input(): string {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . secure_output($token) . '">';
}
