<?php
/**
 * Notification Preferences Helper
 * Provides functions to check user email preferences and send conditional emails.
 */

/**
 * Ensure the user_notification_prefs table exists.
 */
function ensureNotifPrefsTable($conn): void {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_notification_prefs (
        pref_id       INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL UNIQUE,
        on_forwarded  TINYINT(1) NOT NULL DEFAULT 1,
        on_ict_response TINYINT(1) NOT NULL DEFAULT 1,
        on_status_change TINYINT(1) NOT NULL DEFAULT 1,
        on_new_student_complaint TINYINT(1) NOT NULL DEFAULT 0,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Get notification preferences for a user.
 * Returns an array with all pref keys. Defaults to enabled if no record exists.
 *
 * @param mysqli $conn
 * @param int    $user_id
 * @return array
 */
function getUserNotifPrefs($conn, int $user_id): array {
    ensureNotifPrefsTable($conn);

    $defaults = [
        'on_forwarded'             => 1,
        'on_ict_response'          => 1,
        'on_status_change'         => 1,
        'on_new_student_complaint' => 0,
    ];

    $stmt = mysqli_prepare($conn,
        "SELECT on_forwarded, on_ict_response, on_status_change, on_new_student_complaint
         FROM user_notification_prefs WHERE user_id = ?");
    if (!$stmt) return $defaults;

    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row ?: $defaults;
}

/**
 * Send an email to a department user only if their preferences allow it.
 *
 * @param mysqli $conn
 * @param int    $user_id   The department user's ID
 * @param string $pref_key  Which preference to check (e.g. 'on_forwarded')
 * @param string $to        Recipient email address
 * @param string $subject
 * @param string $body
 */
function sendDeptEmailIfAllowed($conn, int $user_id, string $pref_key,
                                 string $to, string $subject, string $body): void {
    $prefs = getUserNotifPrefs($conn, $user_id);
    if (empty($prefs[$pref_key])) return; // preference is off

    $headers  = "From: TSU ICT Help Desk <complaints@tsuniversity.edu.ng>\r\n";
    $headers .= "Reply-To: complaints@tsuniversity.edu.ng\r\n";
    @app_mail($to, $subject, $body, $headers);
}
