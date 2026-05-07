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
 * Safe mail() wrapper — uses PHPMailer via SMTP if available, falls back to PHP mail().
 * Never outputs to browser. Returns true on success, false on failure.
 */
if (!function_exists('app_mail')) {
function app_mail($to, $subject, $message, $headers = '') {
    // Try PHPMailer first (SMTP — bypasses server mail() restrictions)
    $phpmailer_path = __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    if (file_exists($phpmailer_path)) {
        require_once __DIR__ . '/../PHPMailer/src/Exception.php';
        require_once $phpmailer_path;
        require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = env('MAIL_HOST',     'smtp.gmail.com');
            $mail->SMTPAuth   = true;
            $mail->Username   = env('MAIL_USERNAME', 'complaints@tsuniversity.edu.ng');
            $mail->Password   = env('MAIL_PASSWORD', '');
            $mail->SMTPSecure = (strtolower(env('MAIL_ENCRYPTION', 'tls')) === 'ssl')
                                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) env('MAIL_PORT', 587);

            $fromAddr = env('MAIL_FROM_ADDRESS', 'complaints@tsuniversity.edu.ng');
            $fromName = env('MAIL_FROM_NAME',    'TSU ICT Help Desk');
            $mail->setFrom($fromAddr, $fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($fromAddr, $fromName);
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body    = $message;
            $mail->send();
            return true;
        } catch (Exception $e) {
            @app_log('warning', 'PHPMailer failed, trying fallback', ['error' => $mail->ErrorInfo, 'to' => $to]);
            // Fall through to PHP mail() below
        }
    }

    // Fallback: PHP mail()
    if (!function_exists('mail')) {
        @app_log('warning', 'mail() is disabled on this server — email not sent', ['to' => $to, 'subject' => $subject]);
        return false;
    }
    $result = @mail($to, $subject, $message, $headers);
    if (!$result) {
        @app_log('warning', 'mail() returned false — email may not have been sent', ['to' => $to, 'subject' => $subject]);
    }
    return (bool) $result;
}
} // end function_exists('app_mail')

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
