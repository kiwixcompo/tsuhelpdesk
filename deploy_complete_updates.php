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
    <title>Deploy Complete System Updates - TSU ICT Help Desk</title>
    <link rel='stylesheet' href='https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css'>
    <style>
        .step-success { color: #28a745; }
        .step-error { color: #dc3545; }
        .step-info { color: #17a2b8; }
        .step-warning { color: #ffc107; }
        .progress-step { margin: 10px 0; padding: 10px; border-left: 4px solid #007bff; background: #f8f9fa; }
        .section-header { background: #e9ecef; padding: 15px; margin: 20px 0 10px 0; border-radius: 5px; }
    </style>
</head>
<body>
<div class='container mt-4'>
    <h2>Deploy Complete System Updates</h2>
    <p class='text-muted'>This script applies all changes made to the TSU ICT Help Desk system including UI fixes, new faculties, programmes, and roles.</p>
    <div class='card'>
        <div class='card-body'>
";

$errors = [];
$success_count = 0;
$total_steps = 0;
$file_updates = [];

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

function updateFile($file_path, $old_content, $new_content, $description) {
    global $file_updates, $total_steps;
    $total_steps++;
    
    echo "<div class='progress-step'>";
    echo "<strong>Step $total_steps:</strong> $description<br>";
    
    if(file_exists($file_path)) {
        $current_content = file_get_contents($file_path);
        if(strpos($current_content, $old_content) !== false) {
            $updated_content = str_replace($old_content, $new_content, $current_content);
            if(file_put_contents($file_path, $updated_content)) {
                echo "<span class='step-success'>✓ File updated successfully</span>";
                $file_updates[] = $file_path;
            } else {
                echo "<span class='step-error'>✗ Failed to write file</span>";
            }
        } else {
            echo "<span class='step-warning'>⚠ Content already updated or not found</span>";
        }
    } else {
        echo "<span class='step-error'>✗ File not found: $file_path</span>";
    }
    echo "</div>";
    flush();
}

// SECTION 1: DATABASE UPDATES
echo "<div class='section-header'><h4>Section 1: Database Updates</h4></div>";

// Step 1: Create new faculties
echo "<h5>Creating New Faculties</h5>";

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
    echo "<h5>Creating FCA Departments</h5>";
    
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
    echo "<h5>Creating FRP Departments</h5>";
    
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
echo "<h5>Creating Programmes</h5>";

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
echo "<h5>Fixing Existing Programme Formats</h5>";

executeUpdate($conn,
    "UPDATE programmes SET reg_number_format = 'TSU/FSC/CS/YY/XXXX' WHERE programme_id = 72 AND reg_number_format = 'TSU/FCA/CS/YY/XXXX'",
    "Fixing Programme ID 72 registration format (FSC Computer Science)"
);

executeUpdate($conn,
    "UPDATE programmes SET reg_number_format = 'TSU/FSC/CS/YY/XXXX' WHERE programme_name LIKE '%Computer Science%' AND department_id IN (SELECT department_id FROM student_departments WHERE faculty_id = (SELECT faculty_id FROM faculties WHERE faculty_code = 'FSC'))",
    "Ensuring all FSC Computer Science programmes have correct format"
);

// Step 7: Add Deputy Director ICT role (if not exists)
echo "<h5>Adding Deputy Director ICT Role</h5>";

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

// Step 8: Add academic session field to student complaints
echo "<h5>Adding Academic Session Field to Student Complaints</h5>";

// Check if academic_session column exists
$session_check = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'academic_session'");
if(mysqli_num_rows($session_check) == 0) {
    executeUpdate($conn,
        "ALTER TABLE student_complaints ADD COLUMN academic_session VARCHAR(20) NOT NULL DEFAULT '2025/2026' AFTER complaint_type",
        "Adding academic_session column to student_complaints table"
    );
    
    executeUpdate($conn,
        "ALTER TABLE student_complaints ADD INDEX idx_academic_session (academic_session)",
        "Adding index for academic_session column"
    );
    
    executeUpdate($conn,
        "UPDATE student_complaints SET academic_session = '2025/2026' WHERE academic_session = ''",
        "Setting default academic session for existing complaints"
    );
}

// Step 9: Add admin management fields to student complaints
echo "<h5>Adding Admin Management Fields</h5>";

$handled_by_check = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'handled_by'");
if(mysqli_num_rows($handled_by_check) == 0) {
    executeUpdate($conn,
        "ALTER TABLE student_complaints ADD COLUMN handled_by INT NULL AFTER status",
        "Adding handled_by column for admin management"
    );
    
    executeUpdate($conn,
        "ALTER TABLE student_complaints ADD COLUMN admin_response TEXT NULL AFTER description",
        "Adding admin_response column for admin feedback"
    );
    
    executeUpdate($conn,
        "ALTER TABLE student_complaints ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        "Adding updated_at column for tracking changes"
    );
}

// Step 10: Create student notifications table
echo "<h5>Creating Student Notifications Table</h5>";

$notifications_check = mysqli_query($conn, "SHOW TABLES LIKE 'student_notifications'");
if(mysqli_num_rows($notifications_check) == 0) {
    executeUpdate($conn,
        "CREATE TABLE student_notifications (
            notification_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            complaint_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_id (student_id),
            INDEX idx_complaint_id (complaint_id)
        )",
        "Creating student_notifications table"
    );
}

// SECTION 2: FILE UPDATES
echo "<div class='section-header'><h4>Section 2: File Updates</h4></div>";

// Update student_portal.php - Title and heading changes
updateFile('student_portal.php',
    '<title>Student Portal - TSU ICT Help Desk</title>',
    '<title>Student Complaint Portal - TSU ICT Help Desk</title>',
    'Updating student_portal.php page title'
);

updateFile('student_portal.php',
    'Student Portal',
    'Student Complaint Portal',
    'Updating student_portal.php main heading'
);

// Update student_portal.php - Back link logic
updateFile('student_portal.php',
    '<a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                </a>',
    '<?php
                // Only show back link if user came from another page or if explicitly requested
                $show_back_link = false;
                
                // Check if there\'s a referrer and it\'s from the same domain
                if(isset($_SERVER[\'HTTP_REFERER\']) && !empty($_SERVER[\'HTTP_REFERER\'])) {
                    $referrer = parse_url($_SERVER[\'HTTP_REFERER\']);
                    $current_host = $_SERVER[\'HTTP_HOST\'];
                    
                    // Show back link if referrer is from same domain and not the student portal itself
                    if(isset($referrer[\'host\']) && $referrer[\'host\'] === $current_host) {
                        $referrer_path = isset($referrer[\'path\']) ? basename($referrer[\'path\']) : \'\';
                        if($referrer_path !== \'student_portal.php\' && $referrer_path !== \'\') {
                            $show_back_link = true;
                        }
                    }
                }
                
                // Also check for explicit back parameter
                if(isset($_GET[\'back\']) && $_GET[\'back\'] === \'1\') {
                    $show_back_link = true;
                }
                
                if($show_back_link): ?>
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                </a>
                <?php endif; ?>',
    'Adding conditional back link logic to student_portal.php'
);

// Update index.php - Student portal link
updateFile('index.php',
    '<a href="student_portal.php" class="portal-card student-card">
                            <i class="fas fa-graduation-cap portal-icon"></i>
                            <h2 class="portal-title">Student Portal</h2>',
    '<a href="student_portal.php?back=1" class="portal-card student-card">
                            <i class="fas fa-graduation-cap portal-icon"></i>
                            <h2 class="portal-title">Student Complaint Portal</h2>',
    'Updating index.php student portal link and title'
);

// Update student_login.php - Back link
updateFile('student_login.php',
    '<a href="student_portal.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Student Portal
                </a>',
    '<a href="student_portal.php?back=1" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Student Complaint Portal
                </a>',
    'Updating student_login.php back link'
);

// Update student_signup.php - Back link
updateFile('student_signup.php',
    '<a href="student_portal.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Student Portal
                </a>',
    '<a href="student_portal.php?back=1" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Student Complaint Portal
                </a>',
    'Updating student_signup.php back link'
);

// SECTION 3: SUMMARY
echo "<div class='section-header'><h4>Section 3: Deployment Summary</h4></div>";

echo "<div class='alert alert-info'>";
echo "<strong>Total Steps:</strong> $total_steps<br>";
echo "<strong>Successful:</strong> $success_count<br>";
echo "<strong>Database Errors:</strong> " . count($errors) . "<br>";
echo "<strong>Files Updated:</strong> " . count(array_unique($file_updates)) . "<br>";
echo "</div>";

if(count($errors) > 0) {
    echo "<div class='alert alert-warning'>";
    echo "<strong>Database Errors encountered:</strong><br>";
    foreach($errors as $error) {
        echo "• $error<br>";
    }
    echo "</div>";
}

if(count($file_updates) > 0) {
    echo "<div class='alert alert-success'>";
    echo "<strong>Files Updated:</strong><br>";
    foreach(array_unique($file_updates) as $file) {
        echo "• $file<br>";
    }
    echo "</div>";
}

if(count($errors) == 0) {
    echo "<div class='alert alert-success'>";
    echo "<strong>✓ All updates completed successfully!</strong><br>";
    echo "The system has been updated with:<br>";
    echo "• New faculties (FCA, FRP) and their departments<br>";
    echo "• New programmes with correct registration formats<br>";
    echo "• Deputy Director ICT role<br>";
    echo "• Academic session field for student complaints<br>";
    echo "• Enhanced admin complaint management system<br>";
    echo "• Student notifications system<br>";
    echo "• Updated UI titles and navigation<br>";
    echo "• Conditional back link functionality<br>";
    echo "</div>";
}

// Show final status
echo "<h5>Final Faculty Structure</h5>";
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
        <a href='index.php' class='btn btn-info'>View Main Portal</a>
    </div>
</div>
</body>
</html>";

mysqli_close($conn);
?>