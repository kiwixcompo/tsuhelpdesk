<?php
require_once "config.php";

$sql = "CREATE TABLE IF NOT EXISTS student_ict_complaints (
    complaint_id    INT AUTO_INCREMENT PRIMARY KEY,
    student_id      INT NOT NULL,
    node_id         VARCHAR(100) NOT NULL,
    node_label      VARCHAR(255) NOT NULL,
    category        VARCHAR(100) NOT NULL,
    path_summary    TEXT NOT NULL,
    description     TEXT,
    action_type     ENUM('auto_response','escalate','free_text') NOT NULL DEFAULT 'escalate',
    auto_response   TEXT,
    escalated       TINYINT(1) NOT NULL DEFAULT 0,
    extra_fields    JSON,
    status          ENUM('Pending','Auto-Resolved','Under Review','Resolved','Rejected') NOT NULL DEFAULT 'Pending',
    admin_response  TEXT,
    handled_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student  (student_id),
    INDEX idx_status   (status),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (mysqli_query($conn, $sql)) {
    echo "<p style='color:green'>✓ student_ict_complaints table created (or already exists)</p>";
} else {
    echo "<p style='color:red'>✗ Error: " . mysqli_error($conn) . "</p>";
}
echo "<p><a href='student_dashboard.php'>Go to Student Dashboard</a></p>";
