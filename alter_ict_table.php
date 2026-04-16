<?php
require 'config.php';
$sql = "ALTER TABLE student_ict_complaints ADD COLUMN forwarded_to INT DEFAULT NULL, ADD CONSTRAINT fk_ict_forwarded_to FOREIGN KEY (forwarded_to) REFERENCES users(user_id) ON DELETE SET NULL";
if(mysqli_query($conn, $sql)) {
    echo "Added forwarded_to to student_ict_complaints.";
} else {
    echo "Error or Already Exists: " . mysqli_error($conn);
}
?>
