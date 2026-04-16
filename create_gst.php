<?php
require 'config.php';
$password = md5('user2026');
$sql = "INSERT INTO users (username, password, full_name, role_id) VALUES ('gst_unit', '$password', 'General Studies (GST) Unit', 7)";
if(mysqli_query($conn, $sql)) {
    echo "GST Unit Created.";
} else {
    echo "Error or Already Exists: " . mysqli_error($conn);
}
?>
