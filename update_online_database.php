<?php
/**
 * TSU ICT Help Desk - Online Database Update Script
 * This script safely updates the online database with all new features
 * Compatible with all MySQL versions - checks existence before adding
 * 
 * Usage: Upload to server and run via browser
 * URL: https://helpdesk.tsuniversity.edu.ng/update_online_database.php
 */

// Start output buffering for clean display
ob_start();

// Include database configuration
require_once 'config.php';

// Set execution time limit for large operations
set_time_limit(300); // 5 minutes

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSU Help Desk - Database Update</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: #333;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(30, 60, 114, 0.3);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        .header h1 {
            color: #1e3c72;
            margin: 0;
            font-size: 2.5rem;
        }
        .log-container {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            max-height: 500px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .log-entry {
            margin: 5px 0;
            padding: 8px 12px;
            border-radius: 4px;
            line-height: 1.4;
        }
        .log-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .log-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        .log-info {
            background: #e8f4fd;
            color: #0d47a1;
            border-left: 4px solid #2196f3;
        }
        .log-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        .status {
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 0;
            font-weight: 600;
            font-size: 16px;
        }
        .status.success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 2px solid #28a745;
        }
        .status.error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 2px solid #dc3545;
        }
        .btn {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(30, 60, 114, 0.4);
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 TSU ICT Help Desk</h1>
            <p>Database Update System</p>
            <small style="color: #1e3c72; font-weight: 600;">helpdesk.tsuniversity.edu.ng</small>
        </div>

        <div class="log-container" id="logContainer">
            <h3>📝 Database Update Log</h3>

<?php

// Logging functions
function logMessage($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $class = 'log-' . $type;
    echo "<div class='log-entry $class'>[$timestamp] $message</div>\n";
    flush();
    ob_flush();
}

function logSuccess($message) {
    logMessage("✅ SUCCESS: $message", 'success');
}

function logError($message) {
    logMessage("❌ ERROR: $message", 'error');
}

function logInfo($message) {
    logMessage("ℹ️ INFO: $message", 'info');
}

function logWarning($message) {
    logMessage("⚠️ WARNING: $message", 'warning');
}

// Database helper functions
function columnExists($conn, $table, $column) {
    $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

function tableExists($conn, $table) {
    $sql = "SHOW TABLES LIKE '$table'";
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

function executeQuery($conn, $sql, $description) {
    if (mysqli_query($conn, $sql)) {
        logSuccess($description);
        return true;
    } else {
        logError("$description - " . mysqli_error($conn));
        return false;
    }
}

// Start database update process
logInfo("Starting TSU Help Desk Database Update Process");
logInfo("Connecting to database...");

// Test database connection
if (!$conn) {
    logError("Database connection failed: " . mysqli_connect_error());
    echo "</div><div class='status error'>Database connection failed. Please check your configuration.</div>";
    exit;
}

logSuccess("Database connection established");

$update_success = true;

// =====================================================
// UPDATE EXISTING COMPLAINTS TABLE
// =====================================================

logInfo("Updating complaints table structure...");

// Add missing columns to complaints table
$complaints_columns = [
    'is_payment_related' => "ALTER TABLE complaints ADD COLUMN is_payment_related TINYINT(1) DEFAULT 0",
    'is_i4cus' => "ALTER TABLE complaints ADD COLUMN is_i4cus TINYINT(1) DEFAULT 0",
    'feedback_type' => "ALTER TABLE complaints ADD COLUMN feedback_type VARCHAR(50) DEFAULT NULL"
];

foreach ($complaints_columns as $column => $sql) {
    if (!columnExists($conn, 'complaints', $column)) {
        if (!executeQuery($conn, $sql, "Added column '$column' to complaints table")) {
            $update_success = false;
        }
    } else {
        logWarning("Column '$column' already exists in complaints table");
    }
}

// =====================================================
// CREATE NEW SYSTEM TABLES
// =====================================================

logInfo("Creating new system tables...");

// Create login_activities table
if (!tableExists($conn, 'login_activities')) {
    $sql = "CREATE TABLE login_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        username VARCHAR(50) NOT NULL,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45),
        user_agent TEXT,
        status ENUM('success', 'failed') DEFAULT 'success',
        INDEX idx_user_id (user_id),
        INDEX idx_login_time (login_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created login_activities table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'login_activities' already exists");
}

// Create messages table
if (!tableExists($conn, 'messages')) {
    $sql = "CREATE TABLE messages (
        message_id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sender_id (sender_id),
        INDEX idx_receiver_id (receiver_id),
        INDEX idx_is_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created messages table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'messages' already exists");
}

// Create notifications table
if (!tableExists($conn, 'notifications')) {
    $sql = "CREATE TABLE notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_is_read (is_read),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created notifications table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'notifications' already exists");
}

// Create suggestions table
if (!tableExists($conn, 'suggestions')) {
    $sql = "CREATE TABLE suggestions (
        suggestion_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        status ENUM('pending', 'reviewed', 'implemented', 'rejected') DEFAULT 'pending',
        admin_response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created suggestions table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'suggestions' already exists");
}

// =====================================================
// CREATE STUDENT SYSTEM TABLES
// =====================================================

logInfo("Creating student system tables...");

// Create faculties table
if (!tableExists($conn, 'faculties')) {
    $sql = "CREATE TABLE faculties (
        faculty_id INT AUTO_INCREMENT PRIMARY KEY,
        faculty_name VARCHAR(255) NOT NULL,
        faculty_code VARCHAR(10) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created faculties table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'faculties' already exists");
}

// Create student_departments table
if (!tableExists($conn, 'student_departments')) {
    $sql = "CREATE TABLE student_departments (
        department_id INT AUTO_INCREMENT PRIMARY KEY,
        department_name VARCHAR(255) NOT NULL,
        department_code VARCHAR(10) NOT NULL,
        faculty_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_faculty_id (faculty_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created student_departments table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'student_departments' already exists");
}

// Create programmes table
if (!tableExists($conn, 'programmes')) {
    $sql = "CREATE TABLE programmes (
        programme_id INT AUTO_INCREMENT PRIMARY KEY,
        programme_name VARCHAR(255) NOT NULL,
        programme_code VARCHAR(10) NOT NULL,
        department_id INT NOT NULL,
        reg_number_format VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_department_id (department_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created programmes table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'programmes' already exists");
}

// Create students table
if (!tableExists($conn, 'students')) {
    $sql = "CREATE TABLE students (
        student_id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(100) NOT NULL,
        middle_name VARCHAR(100) DEFAULT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        registration_number VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        faculty_id INT NOT NULL,
        department_id INT NOT NULL,
        programme_id INT NOT NULL,
        year_of_entry YEAR NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_faculty_id (faculty_id),
        INDEX idx_department_id (department_id),
        INDEX idx_programme_id (programme_id),
        INDEX idx_registration_number (registration_number),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created students table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'students' already exists");
}

// Create student_complaints table
if (!tableExists($conn, 'student_complaints')) {
    $sql = "CREATE TABLE student_complaints (
        complaint_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        course_code VARCHAR(20) NOT NULL,
        course_title VARCHAR(255) NOT NULL,
        complaint_type ENUM('FA', 'F', 'Incorrect Grade') NOT NULL,
        description TEXT,
        status ENUM('Pending', 'Under Review', 'Resolved', 'Rejected') DEFAULT 'Pending',
        admin_response TEXT DEFAULT NULL,
        handled_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_student_id (student_id),
        INDEX idx_status (status),
        INDEX idx_complaint_type (complaint_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if (!executeQuery($conn, $sql, "Created student_complaints table")) {
        $update_success = false;
    }
} else {
    logWarning("Table 'student_complaints' already exists");
}

// =====================================================
// ADD DEPARTMENT ROLE AND ACCOUNTS
// =====================================================

logInfo("Setting up department roles and accounts...");

// Check if Department role exists
$role_check = mysqli_query($conn, "SELECT role_id FROM roles WHERE role_name = 'Department'");
if (mysqli_num_rows($role_check) == 0) {
    $sql = "INSERT INTO roles (role_name, description) VALUES ('Department', 'Department Staff')";
    if (!executeQuery($conn, $sql, "Added Department role")) {
        $update_success = false;
    }
} else {
    logWarning("Department role already exists");
}

// =====================================================
// POPULATE STUDENT SYSTEM DATA
// =====================================================

logInfo("Populating student system with academic data...");

// Check if faculties data exists
$faculty_check = mysqli_query($conn, "SELECT COUNT(*) as count FROM faculties");
if ($faculty_check) {
    $row = mysqli_fetch_assoc($faculty_check);
    if ($row['count'] == 0) {
        logInfo("Inserting faculty data...");
        
        $faculties = [
            ['Faculty of Agriculture', 'FAG'],
            ['Faculty of Health Sciences', 'FAH'],
            ['Faculty of Arts', 'FART'],
            ['Faculty of Communication and Media Studies', 'FCMS'],
            ['Faculty of Education', 'FED'],
            ['Faculty of Engineering', 'FEN'],
            ['Faculty of Management Sciences', 'FMS'],
            ['Faculty of Sciences', 'FSC'],
            ['Faculty of Social and Management Sciences', 'FSMS'],
            ['Faculty of Social Sciences', 'FSS'],
            ['Faculty of Law', 'LAW']
        ];
        
        foreach ($faculties as $faculty) {
            $sql = "INSERT INTO faculties (faculty_name, faculty_code) VALUES ('" . 
                   mysqli_real_escape_string($conn, $faculty[0]) . "', '" . 
                   mysqli_real_escape_string($conn, $faculty[1]) . "')";
            executeQuery($conn, $sql, "Inserted faculty: " . $faculty[0]);
        }
        
        logSuccess("Faculty data populated successfully");
    } else {
        logWarning("Faculty data already exists");
    }
}

// Sample departments and programmes (key ones for testing)
$dept_check = mysqli_query($conn, "SELECT COUNT(*) as count FROM student_departments");
if ($dept_check) {
    $row = mysqli_fetch_assoc($dept_check);
    if ($row['count'] == 0) {
        logInfo("Inserting sample department and programme data...");
        
        // Insert sample departments for Computer Science
        $sql = "INSERT INTO student_departments (department_name, department_code, faculty_id) 
                SELECT 'Computer Science', 'CS', faculty_id FROM faculties WHERE faculty_code = 'FSC'";
        executeQuery($conn, $sql, "Inserted Computer Science department");
        
        $sql = "INSERT INTO student_departments (department_name, department_code, faculty_id) 
                SELECT 'Mathematics', 'MT', faculty_id FROM faculties WHERE faculty_code = 'FSC'";
        executeQuery($conn, $sql, "Inserted Mathematics department");
        
        // Insert sample programmes
        $sql = "INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
                SELECT 'B.SC. Computer Science', 'CS', sd.department_id, 'TSU/FSC/CS/YY/XXXX'
                FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
                WHERE sd.department_code = 'CS' AND f.faculty_code = 'FSC'";
        executeQuery($conn, $sql, "Inserted Computer Science programme");
        
        $sql = "INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
                SELECT 'B.SC. Mathematics', 'MT', sd.department_id, 'TSU/FSC/MT/YY/XXXX'
                FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id 
                WHERE sd.department_code = 'MT' AND f.faculty_code = 'FSC'";
        executeQuery($conn, $sql, "Inserted Mathematics programme");
        
        logSuccess("Sample academic data populated");
    } else {
        logWarning("Department data already exists");
    }
}

// =====================================================
// FINAL STATUS
// =====================================================

logInfo("Database update process completed");

if ($update_success) {
    logSuccess("All database updates completed successfully!");
    echo "</div><div class='status success'>✅ Database update completed successfully!<br><small>Your TSU Help Desk system is now up to date with all latest features.</small></div>";
} else {
    logError("Some updates failed. Please check the log above.");
    echo "</div><div class='status error'>❌ Some updates failed. Please review the log above and contact support if needed.</div>";
}

// Close database connection
mysqli_close($conn);

?>

        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="/" class="btn">🏠 Go to Help Desk</a>
            <a href="admin.php" class="btn" style="margin-left: 15px;">👨‍💼 Admin Panel</a>
        </div>

        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #6c757d;">
            <p><strong>TSU ICT Help Desk Database Update System</strong></p>
            <p>Taraba State University - January 2026</p>
            <small>🔒 This file should be deleted after successful update for security</small>
        </div>
    </div>

    <script>
        // Auto-scroll log container to bottom
        const logContainer = document.getElementById('logContainer');
        if (logContainer) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }
    </script>
</body>
</html>