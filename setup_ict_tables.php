<?php
/**
 * TSU ICT Help Desk — ICT Complaints Table Setup
 * Run once: https://helpdesk.tsuniversity.ng/setup_ict_tables.php
 * Delete this file after running.
 */
require_once "config.php";

$steps = [];

function run(string $label, string $sql): void {
    global $conn, $steps;
    if (mysqli_query($conn, $sql)) {
        $steps[] = ['ok', $label];
    } else {
        $err = mysqli_error($conn);
        // Ignore "already exists" errors
        if (strpos($err, 'already exists') !== false || strpos($err, 'Duplicate') !== false) {
            $steps[] = ['skip', $label . ' (already exists)'];
        } else {
            $steps[] = ['err', $label . ': ' . $err];
        }
    }
}

// ── 1. student_ict_complaints ─────────────────────────────
run('Create student_ict_complaints table',
"CREATE TABLE IF NOT EXISTS student_ict_complaints (
    complaint_id   INT AUTO_INCREMENT PRIMARY KEY,
    student_id     INT NOT NULL,
    node_id        VARCHAR(100) NOT NULL DEFAULT '',
    node_label     VARCHAR(255) NOT NULL DEFAULT '',
    category       VARCHAR(100) NOT NULL DEFAULT '',
    path_summary   TEXT NOT NULL,
    description    TEXT,
    action_type    VARCHAR(20) NOT NULL DEFAULT 'escalate',
    auto_response  TEXT,
    escalated      TINYINT(1) NOT NULL DEFAULT 0,
    extra_fields   TEXT,
    status         VARCHAR(30) NOT NULL DEFAULT 'Pending',
    admin_response TEXT,
    handled_by     INT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student  (student_id),
    INDEX idx_status   (status),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── 2. student_notifications ─────────────────────────────
run('Create student_notifications table',
"CREATE TABLE IF NOT EXISTS student_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id      INT NOT NULL,
    complaint_id    INT NULL,
    complaint_type  VARCHAR(20) NOT NULL DEFAULT 'result',
    title           VARCHAR(255) NOT NULL,
    message         TEXT NOT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student    (student_id),
    INDEX idx_complaint  (complaint_id),
    INDEX idx_read       (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add complaint_type column to existing notifications table if missing
run('Add complaint_type column to student_notifications (if missing)',
"ALTER TABLE student_notifications ADD COLUMN complaint_type VARCHAR(20) NOT NULL DEFAULT 'result' AFTER complaint_id");

// ── Output ────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ICT Tables Setup</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4" style="max-width:700px">
    <h3>ICT Complaints — Table Setup</h3>
    <table class="table table-sm table-bordered mt-3">
        <thead class="thead-light"><tr><th>Status</th><th>Step</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$status, $label]): ?>
            <tr class="<?php echo $status === 'err' ? 'table-danger' : ($status === 'skip' ? 'table-warning' : 'table-success'); ?>">
                <td><?php echo $status === 'ok' ? '✓' : ($status === 'skip' ? '–' : '✗'); ?></td>
                <td><?php echo htmlspecialchars($label); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php $errors = array_filter($steps, fn($s) => $s[0] === 'err'); ?>
    <?php if (empty($errors)): ?>
        <div class="alert alert-success">
            <strong>✓ Setup complete.</strong> Delete this file from the server for security.
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <strong>Some steps failed.</strong> Check the errors above.
        </div>
    <?php endif; ?>

    <a href="admin.php" class="btn btn-primary">Go to Admin Panel</a>
    <a href="student_dashboard.php" class="btn btn-secondary ml-2">Student Dashboard</a>
</div>
</body>
</html>
