<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Unset all student session variables
unset($_SESSION["student_loggedin"]);
unset($_SESSION["student_id"]);
unset($_SESSION["student_reg_number"]);
unset($_SESSION["student_name"]);
unset($_SESSION["student_email"]);
unset($_SESSION["student_faculty"]);
unset($_SESSION["student_department"]);
unset($_SESSION["student_programme"]);

// Destroy the session
session_destroy();

// Clear any output buffer before redirecting
ob_clean();

// Redirect to student portal
header("location: student_portal.php");
exit;
?>