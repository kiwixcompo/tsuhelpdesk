<?php
/**
 * security-config.php
 *
 * Core security configuration file for the TSU ICT Complaint system.
 * Implements defensive HTTP headers, strict session security configurations,
 * and secure error-handling boundaries to prevent information leakage.
 *
 * OWASP Reference:
 * - A04:2021-Insecure Design
 * - A05:2021-Security Misconfiguration
 */

// 1. Force Secure Session Configuration BEFORE any session starts
if (session_status() === PHP_SESSION_NONE) {
    // Only send cookies over HTTPS if connection is secure
    $isSecureConnection = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ($_SERVER['SERVER_PORT'] ?? 80) == 443 ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    ini_set('session.cookie_httponly', 1);     // Prevent JavaScript access to session cookie (mitigates XSS-based session hijacking)
    ini_set('session.use_only_cookies', 1);    // Force sessions to use cookies only, rejecting session IDs in URLs (mitigates session fixation)
    ini_set('session.use_strict_mode', 1);     // Reject uninitialized session IDs
    ini_set('session.cookie_samesite', 'Lax');  // Protect against Cross-Site Request Forgery (CSRF)

    if ($isSecureConnection) {
        ini_set('session.cookie_secure', 1);   // Only transmit cookie over secure TLS/SSL connections
    }
}

// 2. Strict Defensive Security Headers
// Prevent clickjacking by restricting framing to same origin
header("X-Frame-Options: SAMEORIGIN");

// Mitigate MIME-type sniffing vulnerabilities
header("X-Content-Type-Options: nosniff");

// Prevent referrer leakage across origins
header("Referrer-Policy: strict-origin-when-cross-origin");

// Cross-Site Scripting Protection for older browsers
header("X-XSS-Protection: 1; mode=block");

// HTTP Strict Transport Security (HSTS) - force HTTPS if connection is already HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

// Content Security Policy (CSP) - Default safe policy (whitelist self and secure CDNs)
// Customise these domains based on your static asset requirements (e.g. fonts, bootstrap)
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none';");

// 3. Centralized Secure Error Handling Boundary
// Ensure debugging output is disabled in production environments
$debugMode = defined('APP_DEBUG') ? APP_DEBUG : false;
if (!$debugMode && (isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'false')) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
} else {
    // Development configuration
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// 4. Secure Global Input Filtering Check
// Prevent basic pollution by sanitizing standard superglobals against null bytes
function sanitize_superglobal_keys(array $array): array {
    $sanitized = [];
    foreach ($array as $key => $value) {
        // Strip out null bytes from keys and values to prevent injection/file inclusion bypasses
        $cleanKey = str_replace(chr(0), '', $key);
        if (is_array($value)) {
            $sanitized[$cleanKey] = sanitize_superglobal_keys($value);
        } else {
            $sanitized[$cleanKey] = str_replace(chr(0), '', $value);
        }
    }
    return $sanitized;
}

$_GET = sanitize_superglobal_keys($_GET);
$_POST = sanitize_superglobal_keys($_POST);
$_COOKIE = sanitize_superglobal_keys($_COOKIE);
$_REQUEST = sanitize_superglobal_keys($_REQUEST);
