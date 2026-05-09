<?php
/**
 * Notification Preferences Helper
 * ─────────────────────────────────────────────────────────────────────────────
 * Provides functions to:
 *   - Ensure the prefs table exists
 *   - Read a user's preferences (with sensible role-based defaults)
 *   - Send an email only when the relevant preference is enabled
 *   - Fire email notifications for every system event (new complaint, feedback,
 *     status change, forwarding) to all users whose prefs allow it
 *
 * Roles:
 *   1 = Admin (ICT)
 *   5 = I4CUS Staff
 *   6 = Payment Admin
 *   7 = Department
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Table bootstrap ──────────────────────────────────────────────────────────

function ensureNotifPrefsTable($conn): void {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_notification_prefs (
        pref_id                  INT AUTO_INCREMENT PRIMARY KEY,
        user_id                  INT NOT NULL UNIQUE,
        -- Fires when a complaint is forwarded TO this user / role
        on_forwarded             TINYINT(1) NOT NULL DEFAULT 1,
        -- Fires when ICT adds a response / feedback to a forwarded complaint
        on_ict_response          TINYINT(1) NOT NULL DEFAULT 1,
        -- Fires when the status of any complaint relevant to this user changes
        on_status_change         TINYINT(1) NOT NULL DEFAULT 1,
        -- Fires when ANY new student ICT complaint is submitted (high-volume)
        on_new_student_complaint TINYINT(1) NOT NULL DEFAULT 0,
        -- Fires when a new staff/department complaint is submitted (admin only)
        on_new_complaint         TINYINT(1) NOT NULL DEFAULT 1,
        -- Fires when a complaint this user lodged gets feedback
        on_feedback_received     TINYINT(1) NOT NULL DEFAULT 1,
        updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Read preferences ─────────────────────────────────────────────────────────

/**
 * Return the notification preferences for a user.
 * If no record exists, returns role-appropriate defaults.
 *
 * @param  mysqli $conn
 * @param  int    $user_id
 * @param  int    $role_id   Pass the user's role so defaults are sensible
 * @return array
 */
function getUserNotifPrefs($conn, int $user_id, int $role_id = 0): array {
    ensureNotifPrefsTable($conn);

    // Role-based defaults
    // Admins get everything on; departments get forwarded/response/status on;
    // i4cus/payment same as departments.
    $defaults = [
        'on_forwarded'             => 1,
        'on_ict_response'          => 1,
        'on_status_change'         => 1,
        'on_new_student_complaint' => ($role_id == 1) ? 1 : 0,
        'on_new_complaint'         => ($role_id == 1) ? 1 : 0,
        'on_feedback_received'     => 1,
    ];

    $stmt = mysqli_prepare($conn,
        "SELECT on_forwarded, on_ict_response, on_status_change,
                on_new_student_complaint, on_new_complaint, on_feedback_received
         FROM user_notification_prefs WHERE user_id = ?");
    if (!$stmt) return $defaults;

    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row ? array_merge($defaults, $row) : $defaults;
}

// ── Core send helper ─────────────────────────────────────────────────────────

/**
 * Send an email to a user only if their preference for $pref_key is enabled
 * AND they have a non-empty email address.
 *
 * @param  mysqli $conn
 * @param  int    $user_id
 * @param  int    $role_id
 * @param  string $pref_key   One of: on_forwarded | on_ict_response | on_status_change |
 *                             on_new_student_complaint | on_new_complaint | on_feedback_received
 * @param  string $to         Recipient email (pass '' to auto-look up from DB)
 * @param  string $subject
 * @param  string $body
 */
function sendEmailIfAllowed($conn, int $user_id, int $role_id,
                             string $pref_key, string $to,
                             string $subject, string $body): void {
    $prefs = getUserNotifPrefs($conn, $user_id, $role_id);
    if (empty($prefs[$pref_key])) return;

    // Auto-look up email if not supplied
    if (empty($to)) {
        $r = mysqli_query($conn, "SELECT email FROM users WHERE user_id = $user_id LIMIT 1");
        if ($r) {
            $row = mysqli_fetch_assoc($r);
            $to  = $row['email'] ?? '';
        }
    }
    if (empty($to)) return; // no email on file

    @app_mail($to, $subject, $body);
}

// Backwards-compatible alias used by department_dashboard.php
function sendDeptEmailIfAllowed($conn, int $user_id, string $pref_key,
                                 string $to, string $subject, string $body): void {
    sendEmailIfAllowed($conn, $user_id, 7, $pref_key, $to, $subject, $body);
}

// ── Event-level helpers ───────────────────────────────────────────────────────
// Call these from the places where events actually happen.

/**
 * Notify all admins (role_id=1) that a new staff/department complaint was submitted.
 */
function notifyAdminsNewComplaint($conn, int $complaint_id, string $lodger_name,
                                   string $complaint_preview): void {
    $subject = "New Complaint Submitted — #{$complaint_id}";
    $body    = "A new complaint has been submitted.\n\n"
             . "Complaint ID : #{$complaint_id}\n"
             . "Submitted by : {$lodger_name}\n"
             . "Preview      : " . mb_substr($complaint_preview, 0, 200) . "\n\n"
             . "Login to review: https://helpdesk.tsuniversity.ng/\n\n"
             . "-- TSU ICT Help Desk";

    $res = mysqli_query($conn, "SELECT user_id, email, role_id FROM users WHERE role_id = 1 AND is_active = 1");
    if (!$res) return;
    while ($u = mysqli_fetch_assoc($res)) {
        sendEmailIfAllowed($conn, (int)$u['user_id'], 1,
            'on_new_complaint', $u['email'] ?? '', $subject, $body);
    }
}

/**
 * Notify all admins that a new student ICT complaint was submitted.
 */
function notifyAdminsNewStudentIctComplaint($conn, int $complaint_id,
                                             string $student_name,
                                             string $category): void {
    $subject = "New Student ICT Complaint — #{$complaint_id}";
    $body    = "A new student ICT complaint has been submitted.\n\n"
             . "Complaint ID : #{$complaint_id}\n"
             . "Student      : {$student_name}\n"
             . "Category     : {$category}\n\n"
             . "Login to review: https://helpdesk.tsuniversity.ng/ict_complaints_admin.php\n\n"
             . "-- TSU ICT Help Desk";

    $res = mysqli_query($conn, "SELECT user_id, email FROM users WHERE role_id = 1 AND is_active = 1");
    if (!$res) return;
    while ($u = mysqli_fetch_assoc($res)) {
        sendEmailIfAllowed($conn, (int)$u['user_id'], 1,
            'on_new_student_complaint', $u['email'] ?? '', $subject, $body);
    }
}

/**
 * Notify a specific user that a complaint has been forwarded to them.
 *
 * @param  int    $recipient_user_id  0 = role-based (use $role_id to find all users with that role)
 * @param  int    $recipient_role_id  Used when $recipient_user_id == 0
 */
function notifyForwarded($conn, int $complaint_id, string $student_name,
                          string $category, string $node_label,
                          int $recipient_user_id, int $recipient_role_id = 0): void {
    $subject = "Complaint Forwarded to You — #{$complaint_id}";
    $body    = "A student ICT complaint has been forwarded to you.\n\n"
             . "Complaint ID : #{$complaint_id}\n"
             . "Student      : {$student_name}\n"
             . "Category     : {$category}\n"
             . "Issue        : {$node_label}\n\n"
             . "Login to review: https://helpdesk.tsuniversity.ng/\n\n"
             . "-- TSU ICT Help Desk";

    if ($recipient_user_id > 0) {
        // Single named recipient
        sendEmailIfAllowed($conn, $recipient_user_id, $recipient_role_id,
            'on_forwarded', '', $subject, $body);
    } else {
        // All users with the given role
        $res = mysqli_query($conn,
            "SELECT user_id, email FROM users WHERE role_id = $recipient_role_id AND is_active = 1");
        if (!$res) return;
        while ($u = mysqli_fetch_assoc($res)) {
            sendEmailIfAllowed($conn, (int)$u['user_id'], $recipient_role_id,
                'on_forwarded', $u['email'] ?? '', $subject, $body);
        }
    }
}

/**
 * Notify the forwarded-to party that ICT has added a response.
 */
function notifyIctResponse($conn, int $complaint_id, string $student_name,
                             string $response_preview,
                             int $recipient_user_id, int $recipient_role_id = 0): void {
    $subject = "ICT Response Added — Complaint #{$complaint_id}";
    $body    = "ICT has added a response to a complaint forwarded to you.\n\n"
             . "Complaint ID : #{$complaint_id}\n"
             . "Student      : {$student_name}\n"
             . "Response     : " . mb_substr($response_preview, 0, 300) . "\n\n"
             . "Login to view: https://helpdesk.tsuniversity.ng/\n\n"
             . "-- TSU ICT Help Desk";

    if ($recipient_user_id > 0) {
        sendEmailIfAllowed($conn, $recipient_user_id, $recipient_role_id,
            'on_ict_response', '', $subject, $body);
    } else {
        $res = mysqli_query($conn,
            "SELECT user_id, email FROM users WHERE role_id = $recipient_role_id AND is_active = 1");
        if (!$res) return;
        while ($u = mysqli_fetch_assoc($res)) {
            sendEmailIfAllowed($conn, (int)$u['user_id'], $recipient_role_id,
                'on_ict_response', $u['email'] ?? '', $subject, $body);
        }
    }
}

/**
 * Notify the forwarded-to party that a complaint's status changed.
 */
function notifyStatusChange($conn, int $complaint_id, string $student_name,
                              string $new_status,
                              int $recipient_user_id, int $recipient_role_id = 0): void {
    $subject = "Complaint Status Updated — #{$complaint_id}";
    $body    = "The status of a complaint has been updated.\n\n"
             . "Complaint ID : #{$complaint_id}\n"
             . "Student      : {$student_name}\n"
             . "New Status   : {$new_status}\n\n"
             . "Login to view: https://helpdesk.tsuniversity.ng/\n\n"
             . "-- TSU ICT Help Desk";

    if ($recipient_user_id > 0) {
        sendEmailIfAllowed($conn, $recipient_user_id, $recipient_role_id,
            'on_status_change', '', $subject, $body);
    } else {
        $res = mysqli_query($conn,
            "SELECT user_id, email FROM users WHERE role_id = $recipient_role_id AND is_active = 1");
        if (!$res) return;
        while ($u = mysqli_fetch_assoc($res)) {
            sendEmailIfAllowed($conn, (int)$u['user_id'], $recipient_role_id,
                'on_status_change', $u['email'] ?? '', $subject, $body);
        }
    }
}

/**
 * Notify the complaint lodger that feedback has been given on their complaint.
 */
function notifyFeedbackReceived($conn, int $complaint_id, int $lodger_user_id,
                                  string $status, string $feedback_preview): void {
    $subject = "Feedback on Your Complaint — #{$complaint_id}";
    $body    = "Your complaint has received a response.\n\n"
             . "Complaint ID : #{$complaint_id}\n"
             . "Status       : {$status}\n"
             . "Feedback     : " . mb_substr($feedback_preview, 0, 300) . "\n\n"
             . "Login to view: https://helpdesk.tsuniversity.ng/\n\n"
             . "-- TSU ICT Help Desk";

    // Look up lodger's role
    $r = mysqli_query($conn, "SELECT role_id, email FROM users WHERE user_id = $lodger_user_id LIMIT 1");
    if (!$r) return;
    $u = mysqli_fetch_assoc($r);
    if (!$u) return;

    sendEmailIfAllowed($conn, $lodger_user_id, (int)$u['role_id'],
        'on_feedback_received', $u['email'] ?? '', $subject, $body);
}

// ── Reusable UI snippet ───────────────────────────────────────────────────────

/**
 * Render the notification preferences card.
 * Call this inside any dashboard that needs it.
 *
 * @param  array  $prefs        Result of getUserNotifPrefs()
 * @param  int    $role_id      Current user's role
 * @param  string $collapse_id  Unique HTML id for the collapse target
 */
function renderNotifPrefsCard(array $prefs, int $role_id, string $collapse_id = 'notifPrefsBody'): void {
    $isAdmin   = ($role_id == 1);
    $isDept    = ($role_id == 7);
    $isI4cus   = ($role_id == 5);
    $isPayment = ($role_id == 6);
    ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center"
             style="background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff;cursor:pointer"
             data-toggle="collapse" data-target="#<?php echo $collapse_id; ?>">
            <h5 class="mb-0"><i class="fas fa-bell mr-2"></i>Email Notification Preferences</h5>
            <i class="fas fa-chevron-down"></i>
        </div>
        <div class="collapse" id="<?php echo $collapse_id; ?>">
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Choose which events send an email to your registered address.
                    In-app notifications are always shown regardless of these settings.
                </p>
                <form id="notifPrefsForm">
                    <div class="row">
                        <?php if ($isDept || $isI4cus || $isPayment || $isAdmin): ?>
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="pref_forwarded"
                                       name="on_forwarded"
                                       <?php echo !empty($prefs['on_forwarded']) ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="pref_forwarded">
                                    <strong>Complaint forwarded to me</strong><br>
                                    <small class="text-muted">Email when a complaint is forwarded to you</small>
                                </label>
                            </div>
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="pref_ict_response"
                                       name="on_ict_response"
                                       <?php echo !empty($prefs['on_ict_response']) ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="pref_ict_response">
                                    <strong>ICT adds a response</strong><br>
                                    <small class="text-muted">Email when ICT adds feedback to a forwarded complaint</small>
                                </label>
                            </div>
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="pref_status_change"
                                       name="on_status_change"
                                       <?php echo !empty($prefs['on_status_change']) ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="pref_status_change">
                                    <strong>Status change on a complaint</strong><br>
                                    <small class="text-muted">Email when the status of a relevant complaint is updated</small>
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="pref_feedback"
                                       name="on_feedback_received"
                                       <?php echo !empty($prefs['on_feedback_received']) ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="pref_feedback">
                                    <strong>Feedback on my complaint</strong><br>
                                    <small class="text-muted">Email when ICT responds to a complaint you lodged</small>
                                </label>
                            </div>
                            <?php if ($isAdmin): ?>
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="pref_new_complaint"
                                       name="on_new_complaint"
                                       <?php echo !empty($prefs['on_new_complaint']) ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="pref_new_complaint">
                                    <strong>New staff/department complaint</strong><br>
                                    <small class="text-muted">Email when any department or staff submits a new complaint</small>
                                </label>
                            </div>
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="pref_new_student"
                                       name="on_new_student_complaint"
                                       <?php echo !empty($prefs['on_new_student_complaint']) ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="pref_new_student">
                                    <strong>All new student ICT complaints</strong><br>
                                    <small class="text-muted">Email for every new student ICT complaint (high volume)</small>
                                </label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" id="savePrefsBtn">
                        <i class="fas fa-save mr-1"></i> Save Preferences
                    </button>
                    <span id="prefsSaveMsg" class="ml-2 small" style="display:none"></span>
                </form>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var form = document.getElementById('notifPrefsForm');
        if (!form) return;
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('savePrefsBtn');
            var msg = document.getElementById('prefsSaveMsg');
            btn.disabled = true;
            var data = new FormData(form);
            // Ensure unchecked boxes send 0
            ['on_forwarded','on_ict_response','on_status_change',
             'on_new_student_complaint','on_new_complaint','on_feedback_received'].forEach(function(k) {
                if (!data.has(k)) data.append(k, '0');
            });
            fetch('api/save_notification_prefs.php', {method:'POST', body: data})
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    msg.style.display = 'inline';
                    if (res.success) {
                        msg.style.color = 'green';
                        msg.textContent = 'Saved!';
                    } else {
                        msg.style.color = 'red';
                        msg.textContent = res.message || 'Error saving.';
                    }
                    btn.disabled = false;
                    setTimeout(function(){ msg.style.display='none'; }, 3000);
                })
                .catch(function() {
                    msg.style.display = 'inline';
                    msg.style.color = 'red';
                    msg.textContent = 'Network error.';
                    btn.disabled = false;
                });
        });
    })();
    </script>
    <?php
}
