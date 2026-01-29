<?php
session_start();

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    die("Access denied - Admin only");
}

require_once "config.php";

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Add Academic Session Field - TSU ICT Help Desk</title>
    <link rel='stylesheet' href='https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css'>
    <style>
        .step-success { color: #28a745; }
        .step-error { color: #dc3545; }
        .step-info { color: #17a2b8; }
        .progress-step { margin: 10px 0; padding: 10px; border-left: 4px solid #007bff; background: #f8f9fa; }
    </style>
</head>
<body>
<div class='container mt-4'>
    <h2>Add Academic Session Field to Student Complaints</h2>
    <div class='card'>
        <div class='card-body'>
";

$errors = [];
$success_count = 0;
$total_steps = 0;

function executeUpdate($conn, $sql, $description) {
    global $errors, $success_count, $total_steps;
    $total_steps++;
    
    echo "<div class='progress-step'>";
    echo "<strong>Step $total_steps:</strong> $description<br>";
    
    if(mysqli_query($conn, $sql)) {
        echo "<span class='step-success'>✓ Success</span>";
        $success_count++;
    } else {
        $error = mysqli_error($conn);
        echo "<span class='step-error'>✗ Error: $error</span>";
        $errors[] = "$description: $error";
    }
    echo "</div>";
    flush();
}

// Check if academic_session column already exists
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'academic_session'");
if(mysqli_num_rows($check_column) > 0) {
    echo "<div class='alert alert-info'>Academic session column already exists!</div>";
} else {
    // Step 1: Add academic_session column to student_complaints table
    executeUpdate($conn,
        "ALTER TABLE student_complaints ADD COLUMN academic_session VARCHAR(20) NOT NULL DEFAULT '2025/2026' AFTER complaint_type",
        "Adding academic_session column to student_complaints table"
    );

    // Step 2: Add index for academic_session for better query performance
    executeUpdate($conn,
        "ALTER TABLE student_complaints ADD INDEX idx_academic_session (academic_session)",
        "Adding index for academic_session column"
    );

    // Step 3: Update existing records with default session
    executeUpdate($conn,
        "UPDATE student_complaints SET academic_session = '2025/2026' WHERE academic_session = '' OR academic_session IS NULL",
        "Setting default academic session for existing complaints"
    );
}

// Summary
echo "<h4>Update Summary</h4>";
echo "<div class='alert alert-info'>";
echo "<strong>Total Steps:</strong> $total_steps<br>";
echo "<strong>Successful:</strong> $success_count<br>";
echo "<strong>Errors:</strong> " . count($errors) . "<br>";
echo "</div>";

if(count($errors) > 0) {
    echo "<div class='alert alert-warning'>";
    echo "<strong>Errors encountered:</strong><br>";
    foreach($errors as $error) {
        echo "• $error<br>";
    }
    echo "</div>";
} else {
    echo "<div class='alert alert-success'>";
    echo "<strong>✓ Academic session field added successfully!</strong><br>";
    echo "The student_complaints table now includes the academic_session field.<br>";
    echo "You can now use the student complaint form with academic sessions.";
    echo "</div>";
}

// Show updated table structure
echo "<h4>Updated Table Structure</h4>";
$structure_sql = "DESCRIBE student_complaints";
$structure_result = mysqli_query($conn, $structure_sql);
if($structure_result) {
    echo "<table class='table table-striped table-sm'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while($row = mysqli_fetch_assoc($structure_result)) {
        $highlight = ($row['Field'] == 'academic_session') ? 'table-success' : '';
        echo "<tr class='$highlight'>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "<td>{$row['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<p><em>The academic_session field is highlighted in green.</em></p>";
}

echo "
        </div>
    </div>
    <div class='mt-3'>
        <a href='admin.php' class='btn btn-primary'>Back to Admin</a>
        <a href='student_dashboard.php' class='btn btn-success'>Test Student Form</a>
        <a href='enhanced_student_complaints_report.php' class='btn btn-info'>View Enhanced Report</a>
    </div>
</div>
</body>
</html>";

mysqli_close($conn);
?>