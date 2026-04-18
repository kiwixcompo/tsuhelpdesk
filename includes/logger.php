<?php
// ── Application Error Logger ─────────────────────────────
// Included by config.php on every request.
// Creates logs/error.log automatically if missing or deleted.

define('APP_LOG', __DIR__ . '/../logs/error.log');

// Ensure logs/ directory exists
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Recreate log file if deleted
if (!file_exists(APP_LOG)) {
    file_put_contents(APP_LOG,
        "=== TSU ICT Help Desk Error Log ===\n" .
        "Created: " . date('Y-m-d H:i:s') . "\n\n"
    );
    @chmod(APP_LOG, 0644);
}

// Route all PHP errors into our log
ini_set('log_errors', 1);
ini_set('error_log', APP_LOG);
ini_set('display_errors', 0);
error_reporting(E_ALL);

/**
 * Write a custom entry to the application log.
 * Usage: app_log('error', 'Something failed', ['key' => 'value']);
 */
function app_log(string $level, string $message, array $context = []): void
{
    $uri  = $_SERVER['REQUEST_URI'] ?? 'CLI';
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] '
          . $message;
    if ($context) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $line .= ' | URI: ' . $uri . "\n";
    // Use @ and file_put_contents as fallback — never output to browser
    if (!@error_log($line, 3, APP_LOG)) {
        @file_put_contents(APP_LOG, $line, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Safe mail() wrapper — silently skips sending if mail() is disabled on the server.
 * Returns true on success, false if disabled or failed.
 */
function app_mail(string $to, string $subject, string $message, string $headers = ''): bool
{
    if (!function_exists('mail')) {
        app_log('warning', 'mail() is disabled on this server — email not sent', [
            'to'      => $to,
            'subject' => $subject,
        ]);
        return false;
    }
    $result = @mail($to, $subject, $message, $headers);
    if (!$result) {
        app_log('warning', 'mail() returned false — email may not have been sent', [
            'to'      => $to,
            'subject' => $subject,
        ]);
    }
    return (bool) $result;
}
// Catch fatal errors that the normal error handler misses
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log('FATAL', $e['message'], [
            'file' => $e['file'],
            'line' => $e['line'],
        ]);
    }
});
