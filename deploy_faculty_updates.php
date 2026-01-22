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
    <title>Deploy Faculty Updates - TSU ICT Help Desk</title>
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
    <h2>Deploy Faculty & Programme Updates</h2>
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

// Step 1: Create new faculties
echo "<h4>Creating New Faculties</h4>";

executeUpdate($conn, 
    "INSERT IGNORE INTO faculties (faculty_name, faculty_code) VALUES ('Faculty of Computing & Artificial Intelligence', 'FCA')",
    "Adding Faculty of Computing & Artificial Intelligence"
);

executeUpdate($conn, 
    "INSERT IGNORE INTO faculties (faculty_name, faculty_code) VALUES ('Faculty of Religion & Philosophy', 'FRP')",
    "Adding Faculty of Religion & Philosophy"
);

// Step 2: Get faculty IDs
$fca_id = null;
$frp_id = null;

$fca_result = mysqli_query($conn, "SELECT faculty_id FROM faculties WHERE faculty_code = 'FCA'");
if($fca_result && $row = mysqli_fetch_assoc($fca_result)) {
    $fca_id = $row['faculty_id'];
    echo "<div class='progress-step'><span class='step-info'>Faculty of Computing & Artificial Intelligence ID: $fca_id</span></div>";
}

$frp_result = mysqli_query($conn, "SELECT faculty_id FROM faculties WHERE faculty_code = 'FRP'");
if($frp_result && $row = mysqli_fetch_assoc($frp_result)) {
    $frp_id = $row['faculty_id'];
    echo "<div class='progress-step'><span class='step-info'>Faculty of Religion & Philosophy ID: $frp_id</span></div>";
}

// Step 3: Create departments for FCA
if($fca_id) {
    echo "<h4>Creating FCA Departments</h4>";
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id) VALUES ('Computer Science', 'CS', $fca_id)",
        "Adding Computer Science department to FCA"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id) VALUES ('Data Science and Artificial Intelligence', 'DSAI', $fca_id)",
        "Adding Data Science and Artificial Intelligence department to FCA"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id) VALUES ('Information and Communication Technology', 'ICT', $fca_id)",
        "Adding Information and Communication Technology department to FCA"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id) VALUES ('Software Engineering', 'SE', $fca_id)",
        "Adding Software Engineering department to FCA"
    );
}

// Step 4: Create departments for FRP
if($frp_id) {
    echo "<h4>Creating FRP Departments</h4>";
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id) VALUES ('Islamic Studies', 'ISL', $frp_id)",
        "Adding Islamic Studies department to FRP"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id) VALUES ('Christian Religious Studies', 'CRS', $frp_id)",
        "Adding Christian Religious Studies department to FRP"
    );
}

// Step 5: Get department IDs and create programmes
echo "<h4>Creating Programmes</h4>";

// FCA Computer Science Programme
$fca_cs_result = mysqli_query($conn, "SELECT department_id FROM student_departments WHERE department_name = 'Computer Science' AND faculty_id = $fca_id");
if($fca_cs_result && $row = mysqli_fetch_assoc($fca_cs_result)) {
    $fca_cs_dept_id = $row['department_id'];
    
    executeUpdate($conn,
        "INSERT IGNORE INTO programmes (programme_name, programme_code, department_id, reg_number_format) VALUES ('B.Sc Computer Science', 'BSCCS', $fca_cs_dept_id, 'TSU/FCA/CS/YY/XXXX')",
        "Adding B.Sc Computer Science programme to FCA"
    );
}

// FCA Other Programmes (N/A format)
$departments = [
    'Data Science and Artificial Intelligence' => ['B.Sc Data Science', 'BSCDS'],
    'Information and Communication Technology' => ['B.Sc Information and Communication Technology', 'BSCICT'],
    'Software Engineering' => ['B.Sc Software Engineering', 'BSCSE']
];

foreach($departments as $dept_name => $prog_info) {
    $dept_result = mysqli_query($conn, "SELECT department_id FROM student_departments WHERE department_name = '$dept_name' AND faculty_id = $fca_id");
    if($dept_result && $row = mysqli_fetch_assoc($dept_result)) {
        $dept_id = $row['department_id'];
        
        executeUpdate($conn,
            "INSERT IGNORE INTO programmes (programme_name, programme_code, department_id, reg_number_format) VALUES ('{$prog_info[0]}', '{$prog_info[1]}', $dept_id, 'N/A')",
            "Adding {$prog_info[0]} programme"
        );
    }
}

// FRP Programmes
$frp_isl_result = mysqli_query($conn, "SELECT department_id FROM student_departments WHERE department_name = 'Islamic Studies' AND faculty_id = $frp_id");
if($frp_isl_result && $row = mysqli_fetch_assoc($frp_isl_result)) {
    $frp_isl_dept_id = $row['department_id'];
    
    executeUpdate($conn,
        "INSERT IGNORE INTO programmes (programme_name, programme_code, department_id, reg_number_format) VALUES ('B.A. ISL', 'BAISL', $frp_isl_dept_id, 'TSU/FRP/ISL/YY/XXXX')",
        "Adding B.A. ISL programme to FRP"
    );
}

$frp_crs_result = mysqli_query($conn, "SELECT department_id FROM student_departments WHERE department_name = 'Christian Religious Studies' AND faculty_id = $frp_id");
if($frp_crs_result && $row = mysqli_fetch_assoc($frp_crs_result)) {
    $frp_crs_dept_id = $row['department_id'];
    
    executeUpdate($conn,
        "INSERT IGNORE INTO programmes (programme_name, programme_code, department_id, reg_number_format) VALUES ('B.A. CRS', 'BACRS', $frp_crs_dept_id, 'TSU/FRP/CRS/YY/XXXX')",
        "Adding B.A. CRS programme to FRP"
    );
}

// Step 6: Fix existing programme registration formats
echo "<h4>Fixing Existing Programme Formats</h4>";

executeUpdate($conn,
    "UPDATE programmes SET reg_number_format = 'TSU/FSC/CS/YY/XXXX' WHERE programme_id = 72 AND reg_number_format = 'TSU/FCA/CS/YY/XXXX'",
    "Fixing Programme ID 72 registration format (FSC Computer Science)"
);

executeUpdate($conn,
    "UPDATE programmes SET reg_number_format = 'TSU/FSC/CS/YY/XXXX' WHERE programme_name LIKE '%Computer Science%' AND department_id IN (SELECT department_id FROM student_departments WHERE faculty_id = (SELECT faculty_id FROM faculties WHERE faculty_code = 'FSC'))",
    "Ensuring all FSC Computer Science programmes have correct format"
);

// Step 7: Add Deputy Director ICT role (if not exists)
echo "<h4>Adding Deputy Director ICT Role</h4>";

// Check if roles table has description column
$roles_check = mysqli_query($conn, "SHOW COLUMNS FROM roles LIKE 'description'");
if(mysqli_num_rows($roles_check) == 0) {
    executeUpdate($conn,
        "ALTER TABLE roles ADD COLUMN description TEXT",
        "Adding description column to roles table"
    );
}

executeUpdate($conn,
    "INSERT IGNORE INTO roles (role_name, description) VALUES ('Deputy Director ICT', 'Deputy Director of ICT - Manages i4Cus communications and follow-ups')",
    "Adding Deputy Director ICT role"
);

// Step 8: Summary
echo "<h4>Deployment Summary</h4>";
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
    echo "<strong>✓ All updates completed successfully!</strong><br>";
    echo "The new faculties, departments, and programmes have been added to the system.";
    echo "</div>";
}

// Step 9: Show final status
echo "<h4>Final Faculty Structure</h4>";
$final_sql = "SELECT f.faculty_name, f.faculty_code, d.department_name, p.programme_name, p.reg_number_format 
              FROM faculties f 
              LEFT JOIN student_departments d ON f.faculty_id = d.faculty_id 
              LEFT JOIN programmes p ON d.department_id = p.department_id 
              WHERE f.faculty_code IN ('FCA', 'FRP') 
              ORDER BY f.faculty_name, d.department_name, p.programme_name";

$final_result = mysqli_query($conn, $final_sql);
if($final_result && mysqli_num_rows($final_result) > 0) {
    echo "<table class='table table-striped table-sm'>";
    echo "<tr><th>Faculty</th><th>Department</th><th>Programme</th><th>Registration Format</th></tr>";
    while($row = mysqli_fetch_assoc($final_result)) {
        $prog_name = $row['programme_name'] ?: '<em class="text-muted">No programme</em>';
        $reg_format = $row['reg_number_format'] ?: '<em class="text-muted">N/A</em>';
        echo "<tr>";
        echo "<td>{$row['faculty_name']} ({$row['faculty_code']})</td>";
        echo "<td>{$row['department_name']}</td>";
        echo "<td>$prog_name</td>";
        echo "<td>$reg_format</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "
        </div>
    </div>
    <div class='mt-3'>
        <a href='admin.php' class='btn btn-primary'>Back to Admin</a>
        <a href='student_signup.php' class='btn btn-success'>Test Signup Form</a>
        <a href='debug_faculties.php' class='btn btn-info'>Debug Database</a>
    </div>
</div>
</body>
</html>";

mysqli_close($conn);
?>