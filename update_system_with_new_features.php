<?php
// System Update Script - Add Deputy Director ICT Role and New Faculties/Programmes
session_start();

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    die("Access denied - Admin only");
}

require_once "config.php";

// Set execution time limit to prevent timeout
set_time_limit(300); // 5 minutes
ini_set('output_buffering', 0);
ini_set('implicit_flush', 1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>System Update - TSU ICT Help Desk</title>
    <link rel='stylesheet' href='https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css'>
    <style>
        .update-log { max-height: 400px; overflow-y: auto; background: #f8f9fa; padding: 15px; border-radius: 5px; }
        .progress-bar { transition: width 0.3s ease; }
    </style>
</head>
<body>
<div class='container mt-4'>
    <h2>System Update: Deputy Director ICT Role & New Faculties</h2>
    <div class='card'>
        <div class='card-body'>
            <div class='progress mb-3'>
                <div class='progress-bar' role='progressbar' style='width: 0%' id='progressBar'>0%</div>
            </div>
            <div class='update-log' id='updateLog'>
";

$updates_successful = 0;
$total_updates = 0;
$current_step = 0;

function executeUpdate($conn, $sql, $description) {
    global $updates_successful, $total_updates, $current_step;
    $total_updates++;
    $current_step++;
    
    echo "<div class='mb-2'>";
    echo "<strong>Step $current_step: $description:</strong> ";
    
    if(mysqli_query($conn, $sql)) {
        echo "<span class='text-success'>✓ Success</span>";
        $updates_successful++;
    } else {
        $error = mysqli_error($conn);
        echo "<span class='text-danger'>✗ Failed - $error</span>";
        
        // Log the error for debugging
        error_log("Update failed: $description - SQL: $sql - Error: $error");
    }
    echo "</div>";
    
    // Update progress bar
    $progress = ($current_step / 20) * 100; // Assuming about 20 total steps
    echo "<script>
        document.getElementById('progressBar').style.width = '{$progress}%';
        document.getElementById('progressBar').textContent = Math.round($progress) + '%';
        document.getElementById('updateLog').scrollTop = document.getElementById('updateLog').scrollHeight;
    </script>";
    
    flush();
    ob_flush();
    usleep(100000); // Small delay to make progress visible
}

echo "<h4>Step 1: Creating Required Tables...</h4>";

// Create faculties table if it doesn't exist
executeUpdate($conn, 
    "CREATE TABLE IF NOT EXISTS faculties (
        faculty_id INT PRIMARY KEY AUTO_INCREMENT,
        faculty_name VARCHAR(255) NOT NULL,
        faculty_code VARCHAR(10) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "Creating faculties table"
);

// Create student_departments table if it doesn't exist
executeUpdate($conn,
    "CREATE TABLE IF NOT EXISTS student_departments (
        department_id INT PRIMARY KEY AUTO_INCREMENT,
        department_name VARCHAR(255) NOT NULL,
        faculty_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id)
    )",
    "Creating student_departments table"
);

// Create programmes table if it doesn't exist
executeUpdate($conn,
    "CREATE TABLE IF NOT EXISTS programmes (
        programme_id INT PRIMARY KEY AUTO_INCREMENT,
        programme_name VARCHAR(255) NOT NULL,
        department_id INT NOT NULL,
        reg_number_format VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES student_departments(department_id)
    )",
    "Creating programmes table"
);

// Create notifications table if it doesn't exist
executeUpdate($conn,
    "CREATE TABLE IF NOT EXISTS notifications (
        notification_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        complaint_id INT,
        type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id),
        FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id)
    )",
    "Creating notifications table"
);

echo "<h4>Step 2: Updating Roles Table Structure...</h4>";

// Check if description column exists and add it if it doesn't
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM roles LIKE 'description'");
if(mysqli_num_rows($check_column) == 0) {
    executeUpdate($conn,
        "ALTER TABLE roles ADD COLUMN description TEXT",
        "Adding description column to roles table"
    );
} else {
    echo "<div class='mb-2'><strong>Step 2: Adding description column to roles table:</strong> <span class='text-info'>✓ Already exists</span></div>";
    $current_step++;
    echo "<script>
        var progress = ($current_step / 25) * 100;
        document.getElementById('progressBar').style.width = progress + '%';
        document.getElementById('progressBar').textContent = Math.round(progress) + '%';
    </script>";
    flush();
    ob_flush();
}

echo "<h4>Step 3: Adding Deputy Director ICT Role...</h4>";

// Add Deputy Director ICT role
executeUpdate($conn, 
    "INSERT IGNORE INTO roles (role_name, description) VALUES ('Deputy Director ICT', 'Deputy Director of ICT - Manages i4Cus communications and follow-ups')",
    "Adding Deputy Director ICT role"
);

echo "<h4>Step 4: Adding New Faculties...</h4>";

// Add new faculties
executeUpdate($conn,
    "INSERT IGNORE INTO faculties (faculty_name, faculty_code) VALUES ('Faculty of Computing & Artificial Intelligence', 'FCA')",
    "Adding Faculty of Computing & Artificial Intelligence"
);

executeUpdate($conn,
    "INSERT IGNORE INTO faculties (faculty_name, faculty_code) VALUES ('Faculty of Religion & Philosophy', 'FRP')",
    "Adding Faculty of Religion & Philosophy"
);

echo "<h4>Step 5: Adding New Departments...</h4>";

// Get faculty IDs
$fca_result = mysqli_query($conn, "SELECT faculty_id FROM faculties WHERE faculty_code = 'FCA'");
$frp_result = mysqli_query($conn, "SELECT faculty_id FROM faculties WHERE faculty_code = 'FRP'");

if($fca_result && $fca_row = mysqli_fetch_assoc($fca_result)) {
    $fca_id = $fca_row['faculty_id'];
    
    // Add FCA departments
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, faculty_id) VALUES ('Computer Science', $fca_id)",
        "Adding Computer Science department"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, faculty_id) VALUES ('Data Science and Artificial Intelligence', $fca_id)",
        "Adding Data Science and AI department"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, faculty_id) VALUES ('Information and Communication Technology', $fca_id)",
        "Adding ICT department"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, faculty_id) VALUES ('Software Engineering', $fca_id)",
        "Adding Software Engineering department"
    );
} else {
    echo "<div class='mb-2'><strong>Error:</strong> <span class='text-danger'>Could not find Faculty of Computing & AI</span></div>";
}

if($frp_result && $frp_row = mysqli_fetch_assoc($frp_result)) {
    $frp_id = $frp_row['faculty_id'];
    
    // Add FRP departments
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, faculty_id) VALUES ('Islamic Studies', $frp_id)",
        "Adding Islamic Studies department"
    );
    
    executeUpdate($conn,
        "INSERT IGNORE INTO student_departments (department_name, faculty_id) VALUES ('Christian Religious Studies', $frp_id)",
        "Adding Christian Religious Studies department"
    );
} else {
    echo "<div class='mb-2'><strong>Error:</strong> <span class='text-danger'>Could not find Faculty of Religion & Philosophy</span></div>";
}

echo "<h4>Step 6: Adding New Programmes...</h4>";

// Get department IDs and add programmes with correct registration formats
$departments_and_programmes = [
    // Faculty of Computing & Artificial Intelligence
    'Computer Science' => [
        'programme' => 'B. Sc. Computer Science',
        'reg_format' => 'TSU/FCA/CS/YY/XXXX'  // Only CS gets registration numbers
    ],
    'Data Science and Artificial Intelligence' => [
        'programme' => 'B. Sc. Data Science',
        'reg_format' => 'N/A'  // No registration numbers
    ],
    'Information and Communication Technology' => [
        'programme' => 'B. Sc. Information and Communication Technology',
        'reg_format' => 'N/A'  // No registration numbers
    ],
    'Software Engineering' => [
        'programme' => 'B. Sc. Software Engineering',
        'reg_format' => 'N/A'  // No registration numbers
    ],
    // Faculty of Religion & Philosophy
    'Islamic Studies' => [
        'programme' => 'B. A. ISL',
        'reg_format' => 'TSU/FRP/ISL/YY/XXXX'  // ISL gets registration numbers
    ],
    'Christian Religious Studies' => [
        'programme' => 'B. A. CRS',
        'reg_format' => 'TSU/FRP/CRS/YY/XXXX'  // CRS gets registration numbers
    ]
];

foreach($departments_and_programmes as $dept_name => $prog_info) {
    $dept_result = mysqli_query($conn, "SELECT department_id FROM student_departments WHERE department_name = '" . mysqli_real_escape_string($conn, $dept_name) . "'");
    if($dept_result && $dept_row = mysqli_fetch_assoc($dept_result)) {
        $dept_id = $dept_row['department_id'];
        $prog_name = mysqli_real_escape_string($conn, $prog_info['programme']);
        $reg_format = $prog_info['reg_format'];
        
        executeUpdate($conn,
            "INSERT IGNORE INTO programmes (programme_name, department_id, reg_number_format) VALUES ('$prog_name', $dept_id, '$reg_format')",
            "Adding programme: " . $prog_info['programme'] . " (Format: $reg_format)"
        );
    } else {
        echo "<div class='mb-2'><strong>Warning:</strong> <span class='text-warning'>Department '$dept_name' not found, skipping programme</span></div>";
    }
}

// Update progress to 100%
echo "<script>
    document.getElementById('progressBar').style.width = '100%';
    document.getElementById('progressBar').textContent = '100%';
    document.getElementById('progressBar').classList.add('bg-success');
</script>";

echo "</div>"; // Close update-log div

echo "<h4>Update Summary</h4>";
echo "<div class='alert alert-" . ($updates_successful >= ($total_updates * 0.8) ? 'success' : 'warning') . "'>";
echo "<strong>Updates completed: $updates_successful out of $total_updates</strong><br>";

if($updates_successful >= ($total_updates * 0.8)) {
    echo "Updates completed successfully! The system now includes:";
    echo "<ul>";
    echo "<li>Deputy Director ICT role (role_id = 8)</li>";
    echo "<li>Faculty of Computing & Artificial Intelligence with 4 departments</li>";
    echo "<li>Faculty of Religion & Philosophy with 2 departments</li>";
    echo "<li><strong>Registration Numbers:</strong></li>";
    echo "<ul>";
    echo "<li>Computer Science: TSU/FCA/CS/YY/XXXX (for 2025+ students)</li>";
    echo "<li>Islamic Studies: TSU/FRP/ISL/YY/XXXX (for 2025+ students)</li>";
    echo "<li>Christian Religious Studies: TSU/FRP/CRS/YY/XXXX (for 2025+ students)</li>";
    echo "<li>Other programmes: N/A (no registration numbers generated)</li>";
    echo "</ul>";
    echo "<li>Notifications table for system notifications</li>";
    echo "<li>Updated student signup form to accept 4-digit years</li>";
    echo "<li>New Deputy Director ICT dashboard</li>";
    echo "</ul>";
    echo "<p><strong>Next Steps:</strong></p>";
    echo "<ul>";
    echo "<li>Create a Deputy Director ICT user account via the Users page</li>";
    echo "<li>Test the new student signup process with 4-digit years</li>";
    echo "<li>Verify the new faculties and programmes appear in student registration</li>";
    echo "<li><strong>Important:</strong> Only students admitted from 2025 onwards will use the new registration formats</li>";
    echo "</ul>";
} else {
    echo "Some updates failed. Please check the errors above. You can run the script again to retry failed updates.";
}

echo "</div>";

echo "
        </div>
    </div>
    <div class='mt-3'>
        <a href='admin.php' class='btn btn-primary'>Back to Admin Dashboard</a>
        <a href='users.php' class='btn btn-success'>Manage Users</a>
        <a href='student_signup.php' class='btn btn-info'>Test Student Signup</a>
    </div>
</div>

<script>
// Auto-scroll to bottom of log
document.getElementById('updateLog').scrollTop = document.getElementById('updateLog').scrollHeight;
</script>

</body>
</html>";

mysqli_close($conn);
?>