<?php
session_start();
require_once "config.php";

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    die("Access denied - Admin only");
}

// Set execution time limit
set_time_limit(300);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Structure Update</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; border-bottom: 3px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; border-left: 4px solid #007bff; padding-left: 10px; }
        h3 { color: #666; margin-top: 20px; }
        .success { 
            background: #d4edda; 
            border: 1px solid #c3e6cb; 
            color: #155724; 
            padding: 12px; 
            border-radius: 5px; 
            margin: 10px 0;
        }
        .error { 
            background: #f8d7da; 
            border: 1px solid #f5c6cb; 
            color: #721c24; 
            padding: 12px; 
            border-radius: 5px; 
            margin: 10px 0;
        }
        .info { 
            background: #d1ecf1; 
            border: 1px solid #bee5eb; 
            color: #0c5460; 
            padding: 12px; 
            border-radius: 5px; 
            margin: 10px 0;
        }
        .warning { 
            background: #fff3cd; 
            border: 1px solid #ffeaa7; 
            color: #856404; 
            padding: 12px; 
            border-radius: 5px; 
            margin: 10px 0;
        }
        .summary {
            background: #e7f3ff;
            border: 2px solid #007bff;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
        }
        .btn:hover { background: #0056b3; }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        ul { line-height: 1.8; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Database Structure Update</h1>
        <p>This script will update your database to match the current application structure.</p>
        
<?php

$updates_applied = 0;
$errors_encountered = 0;
$tables_created = 0;
$columns_added = 0;

// Helper function to check if table exists
function tableExists($conn, $table_name) {
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table_name'");
    return mysqli_num_rows($result) > 0;
}

// Helper function to check if column exists
function columnExists($conn, $table_name, $column_name) {
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table_name` LIKE '$column_name'");
    return mysqli_num_rows($result) > 0;
}

// Helper function to execute SQL with error handling
function executeSql($conn, $sql, $description) {
    global $updates_applied, $errors_encountered;
    
    if(mysqli_query($conn, $sql)) {
        echo "<div class='success'>✓ $description</div>";
        $updates_applied++;
        return true;
    } else {
        echo "<div class='error'>✗ $description failed: " . mysqli_error($conn) . "</div>";
        $errors_encountered++;
        return false;
    }
}

echo "<h2>1. Core Tables</h2>";

// 1. Departments Table
echo "<h3>Departments Table</h3>";
if(!tableExists($conn, 'departments')) {
    $sql = "CREATE TABLE departments (
        department_id INT PRIMARY KEY AUTO_INCREMENT,
        department_name VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created departments table")) {
        $tables_created++;
    }
} else {
    echo "<div class='info'>ℹ Departments table already exists</div>";
}

// 2. Notifications Table
echo "<h3>Notifications Table</h3>";
if(!tableExists($conn, 'notifications')) {
    $sql = "CREATE TABLE notifications (
        notification_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        complaint_id INT NOT NULL,
        type ENUM('feedback_given', 'feedback_reply') DEFAULT 'feedback_given',
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_id (user_id),
        KEY idx_complaint_id (complaint_id),
        KEY idx_is_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created notifications table")) {
        $tables_created++;
    }
} else {
    echo "<div class='info'>ℹ Notifications table already exists</div>";
}

// 3. Messages Table
echo "<h3>Messages Table</h3>";
if(!tableExists($conn, 'messages')) {
    $sql = "CREATE TABLE messages (
        message_id INT PRIMARY KEY AUTO_INCREMENT,
        sender_id INT NOT NULL,
        recipient_id INT DEFAULT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_broadcast TINYINT(1) DEFAULT 0,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sender_id (sender_id),
        KEY idx_recipient_id (recipient_id),
        KEY idx_is_broadcast (is_broadcast),
        KEY idx_is_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created messages table")) {
        $tables_created++;
    }
} else {
    echo "<div class='info'>ℹ Messages table already exists</div>";
}

// 4. Login Activities Table
echo "<h3>Login Activities Table</h3>";
if(!tableExists($conn, 'login_activities')) {
    $sql = "CREATE TABLE login_activities (
        activity_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        logout_time TIMESTAMP NULL DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        session_id VARCHAR(255) DEFAULT NULL,
        login_status ENUM('success','failed','logout') DEFAULT 'success',
        failure_reason VARCHAR(255) DEFAULT NULL,
        KEY idx_user_id (user_id),
        KEY idx_login_time (login_time),
        KEY idx_login_status (login_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created login_activities table")) {
        $tables_created++;
    }
} else {
    echo "<div class='info'>ℹ Login activities table already exists</div>";
}

// 5. Suggestions Table
echo "<h3>Suggestions Table</h3>";
if(!tableExists($conn, 'suggestions')) {
    $sql = "CREATE TABLE suggestions (
        suggestion_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        suggestion_text TEXT NOT NULL,
        status ENUM('Pending', 'Under Implementation', 'Implemented', 'Not Applicable') DEFAULT 'Pending',
        admin_response TEXT,
        is_deleted BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_user_id (user_id),
        KEY idx_status (status),
        KEY idx_is_deleted (is_deleted)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created suggestions table")) {
        $tables_created++;
    }
} else {
    echo "<div class='info'>ℹ Suggestions table already exists</div>";
}

echo "<h2>2. Column Updates</h2>";

// Add department_id to complaints table
echo "<h3>Complaints Table Updates</h3>";
if(tableExists($conn, 'complaints')) {
    if(!columnExists($conn, 'complaints', 'department_id')) {
        $sql = "ALTER TABLE complaints ADD COLUMN department_id INT AFTER department_name";
        if(executeSql($conn, $sql, "Added department_id column to complaints table")) {
            $columns_added++;
        }
    } else {
        echo "<div class='info'>ℹ department_id column already exists in complaints table</div>";
    }
    
    if(!columnExists($conn, 'complaints', 'feedback_images')) {
        $sql = "ALTER TABLE complaints ADD COLUMN feedback_images TEXT AFTER feedback_type";
        if(executeSql($conn, $sql, "Added feedback_images column to complaints table")) {
            $columns_added++;
        }
    } else {
        echo "<div class='info'>ℹ feedback_images column already exists in complaints table</div>";
    }
}

// Add reply_images to complaint_replies table
echo "<h3>Complaint Replies Table Updates</h3>";
if(tableExists($conn, 'complaint_replies')) {
    if(!columnExists($conn, 'complaint_replies', 'reply_images')) {
        $sql = "ALTER TABLE complaint_replies ADD COLUMN reply_images TEXT AFTER reply_text";
        if(executeSql($conn, $sql, "Added reply_images column to complaint_replies table")) {
            $columns_added++;
        }
    } else {
        echo "<div class='info'>ℹ reply_images column already exists in complaint_replies table</div>";
    }
}

// Update suggestions table if it exists
echo "<h3>Suggestions Table Updates</h3>";
if(tableExists($conn, 'suggestions')) {
    if(!columnExists($conn, 'suggestions', 'is_deleted')) {
        $sql = "ALTER TABLE suggestions ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE";
        if(executeSql($conn, $sql, "Added is_deleted column to suggestions table")) {
            $columns_added++;
        }
    } else {
        echo "<div class='info'>ℹ is_deleted column already exists in suggestions table</div>";
    }
}

echo "<h2>3. Data Migration</h2>";

// Migrate department names to departments table
echo "<h3>Department Data Migration</h3>";
if(tableExists($conn, 'departments') && tableExists($conn, 'complaints')) {
    $sql = "INSERT IGNORE INTO departments (department_name)
            SELECT DISTINCT department_name 
            FROM complaints 
            WHERE department_name IS NOT NULL 
            AND department_name != '' 
            AND department_name NOT IN (SELECT department_name FROM departments)";
    
    if(mysqli_query($conn, $sql)) {
        $migrated = mysqli_affected_rows($conn);
        if($migrated > 0) {
            echo "<div class='success'>✓ Migrated $migrated unique department names to departments table</div>";
            $updates_applied++;
        } else {
            echo "<div class='info'>ℹ No new departments to migrate</div>";
        }
    } else {
        echo "<div class='warning'>⚠ Department migration skipped: " . mysqli_error($conn) . "</div>";
    }
    
    // Update department_id in complaints
    if(columnExists($conn, 'complaints', 'department_id')) {
        $sql = "UPDATE complaints c
                JOIN departments d ON c.department_name = d.department_name
                SET c.department_id = d.department_id
                WHERE c.department_name IS NOT NULL 
                AND c.department_name != ''
                AND c.department_id IS NULL";
        
        if(mysqli_query($conn, $sql)) {
            $updated = mysqli_affected_rows($conn);
            if($updated > 0) {
                echo "<div class='success'>✓ Updated department_id for $updated complaints</div>";
                $updates_applied++;
            } else {
                echo "<div class='info'>ℹ All complaints already have department_id set</div>";
            }
        }
    }
}

echo "<h2>4. Role Updates</h2>";

// Check and add department role
echo "<h3>Department Role</h3>";
if(tableExists($conn, 'roles')) {
    $check_role = mysqli_query($conn, "SELECT role_id FROM roles WHERE role_name = 'department'");
    if(mysqli_num_rows($check_role) == 0) {
        $sql = "INSERT INTO roles (role_name) VALUES ('department')";
        executeSql($conn, $sql, "Added 'department' role");
    } else {
        echo "<div class='info'>ℹ Department role already exists</div>";
    }
}

echo "<h2>5. Index Optimization</h2>";

// Add indexes for better performance
$indexes = [
    ['table' => 'complaints', 'column' => 'department_id', 'name' => 'idx_department_id'],
    ['table' => 'complaints', 'column' => 'status', 'name' => 'idx_status'],
    ['table' => 'complaints', 'column' => 'is_payment_related', 'name' => 'idx_payment'],
    ['table' => 'complaints', 'column' => 'lodged_by', 'name' => 'idx_lodged_by'],
    ['table' => 'complaints', 'column' => 'handled_by', 'name' => 'idx_handled_by'],
];

foreach($indexes as $index) {
    if(tableExists($conn, $index['table'])) {
        $check_index = mysqli_query($conn, "SHOW INDEX FROM `{$index['table']}` WHERE Key_name = '{$index['name']}'");
        if(mysqli_num_rows($check_index) == 0) {
            $sql = "ALTER TABLE `{$index['table']}` ADD INDEX `{$index['name']}` (`{$index['column']}`)";
            mysqli_query($conn, $sql); // Silent execution for indexes
        }
    }
}
echo "<div class='success'>✓ Database indexes optimized</div>";

// Summary
echo "<div class='summary'>";
echo "<h2>📊 Update Summary</h2>";
echo "<ul>";
echo "<li><strong>Tables Created:</strong> $tables_created</li>";
echo "<li><strong>Columns Added:</strong> $columns_added</li>";
echo "<li><strong>Total Updates Applied:</strong> $updates_applied</li>";
echo "<li><strong>Errors Encountered:</strong> $errors_encountered</li>";
echo "</ul>";

if($errors_encountered == 0) {
    echo "<div class='success'>";
    echo "<h3>✅ Database Update Complete!</h3>";
    echo "<p>Your database structure has been successfully updated to match the current application requirements.</p>";
    echo "<p><strong>What's New:</strong></p>";
    echo "<ul>";
    echo "<li>✓ Departments management system</li>";
    echo "<li>✓ Notifications for feedback and replies</li>";
    echo "<li>✓ Direct messaging system</li>";
    echo "<li>✓ Login activity tracking</li>";
    echo "<li>✓ Suggestions system with soft delete</li>";
    echo "<li>✓ Image attachments for feedback and replies</li>";
    echo "<li>✓ Performance optimizations with indexes</li>";
    echo "</ul>";
    echo "</div>";
} else {
    echo "<div class='warning'>";
    echo "<h3>⚠ Update Completed with Warnings</h3>";
    echo "<p>Some updates could not be applied. Please review the errors above and fix them manually if needed.</p>";
    echo "</div>";
}

echo "</div>";

echo "<h2>6. Student System Tables</h2>";

// Student system tables
echo "<h3>Student System Setup</h3>";

// Faculties table
if(!tableExists($conn, 'faculties')) {
    $sql = "CREATE TABLE faculties (
        faculty_id INT AUTO_INCREMENT PRIMARY KEY,
        faculty_name VARCHAR(255) NOT NULL,
        faculty_code VARCHAR(10) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created faculties table")) {
        $tables_created++;
        
        // Insert sample faculties
        $faculties_data = [
            ['Faculty of Agriculture', 'AGR'],
            ['Faculty of Arts', 'ART'],
            ['Faculty of Education', 'EDU'],
            ['Faculty of Engineering', 'ENG'],
            ['Faculty of Health Sciences', 'HLT'],
            ['Faculty of Law', 'LAW'],
            ['Faculty of Management Sciences', 'MGT'],
            ['Faculty of Sciences', 'SCI'],
            ['Faculty of Social Sciences', 'SOC']
        ];
        
        foreach($faculties_data as $faculty) {
            $insert_sql = "INSERT IGNORE INTO faculties (faculty_name, faculty_code) VALUES ('{$faculty[0]}', '{$faculty[1]}')";
            mysqli_query($conn, $insert_sql);
        }
        echo "<div class='success'>✓ Populated faculties with sample data</div>";
    }
} else {
    echo "<div class='info'>ℹ Faculties table already exists</div>";
}

// Student departments table
if(!tableExists($conn, 'student_departments')) {
    $sql = "CREATE TABLE student_departments (
        department_id INT AUTO_INCREMENT PRIMARY KEY,
        department_name VARCHAR(255) NOT NULL,
        department_code VARCHAR(10) NOT NULL,
        faculty_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE CASCADE,
        UNIQUE KEY unique_dept_per_faculty (department_code, faculty_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created student_departments table")) {
        $tables_created++;
        
        // Insert sample departments
        $dept_sql = "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id) VALUES
            ('Computer Science', 'CSC', (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')),
            ('Mathematics & Statistics', 'MTS', (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')),
            ('Physics', 'PHY', (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')),
            ('Chemistry', 'CHM', (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')),
            ('Biology', 'BIO', (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')),
            ('Computer Engineering', 'CPE', (SELECT faculty_id FROM faculties WHERE faculty_code = 'ENG')),
            ('Electrical Engineering', 'EEE', (SELECT faculty_id FROM faculties WHERE faculty_code = 'ENG')),
            ('Mechanical Engineering', 'MEE', (SELECT faculty_id FROM faculties WHERE faculty_code = 'ENG')),
            ('Civil Engineering', 'CVE', (SELECT faculty_id FROM faculties WHERE faculty_code = 'ENG'))";
        
        if(mysqli_query($conn, $dept_sql)) {
            echo "<div class='success'>✓ Populated student departments with sample data</div>";
        }
    }
} else {
    echo "<div class='info'>ℹ Student departments table already exists</div>";
}

// Programmes table
if(!tableExists($conn, 'programmes')) {
    $sql = "CREATE TABLE programmes (
        programme_id INT AUTO_INCREMENT PRIMARY KEY,
        programme_name VARCHAR(255) NOT NULL,
        programme_code VARCHAR(10) NOT NULL,
        department_id INT NOT NULL,
        reg_number_format VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (department_id) REFERENCES student_departments(department_id) ON DELETE CASCADE,
        UNIQUE KEY unique_prog_per_dept (programme_code, department_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created programmes table")) {
        $tables_created++;
        
        // Insert sample programmes
        $prog_sql = "INSERT IGNORE INTO programmes (programme_name, programme_code, department_id, reg_number_format) VALUES
            ('B.Sc Computer Science', 'CSC', (SELECT department_id FROM student_departments WHERE department_code = 'CSC' AND faculty_id = (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')), 'TSU/SCI/CSC/YY/XXXX'),
            ('B.Sc Software Engineering', 'SWE', (SELECT department_id FROM student_departments WHERE department_code = 'CSC' AND faculty_id = (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')), 'TSU/SCI/SWE/YY/XXXX'),
            ('B.Sc Data Science', 'DSC', (SELECT department_id FROM student_departments WHERE department_code = 'CSC' AND faculty_id = (SELECT faculty_id FROM faculties WHERE faculty_code = 'SCI')), 'TSU/SCI/DSC/YY/XXXX'),
            ('B.Eng Computer Engineering', 'CPE', (SELECT department_id FROM student_departments WHERE department_code = 'CPE' AND faculty_id = (SELECT faculty_id FROM faculties WHERE faculty_code = 'ENG')), 'TSU/ENG/CPE/YY/XXXX')";
        
        if(mysqli_query($conn, $prog_sql)) {
            echo "<div class='success'>✓ Populated programmes with sample data</div>";
        }
    }
} else {
    echo "<div class='info'>ℹ Programmes table already exists</div>";
}

// Students table
if(!tableExists($conn, 'students')) {
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
        FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id),
        FOREIGN KEY (department_id) REFERENCES student_departments(department_id),
        FOREIGN KEY (programme_id) REFERENCES programmes(programme_id),
        KEY idx_registration_number (registration_number),
        KEY idx_email (email),
        KEY idx_is_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created students table")) {
        $tables_created++;
    }
} else {
    echo "<div class='info'>ℹ Students table already exists</div>";
}

// Student complaints table
if(!tableExists($conn, 'student_complaints')) {
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
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
        FOREIGN KEY (handled_by) REFERENCES users(user_id) ON DELETE SET NULL,
        KEY idx_student_id (student_id),
        KEY idx_status (status),
        KEY idx_complaint_type (complaint_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    if(executeSql($conn, $sql, "Created student_complaints table")) {
        $tables_created++;
    }
} else {
    echo "<div class='info'>ℹ Student complaints table already exists</div>";
}

echo "<h2>7. Department Accounts Creation</h2>";

// Department accounts data
$departments = [
    ['username' => 'agex_econ', 'full_name' => 'Agric Extension & Economics'],
    ['username' => 'agron', 'full_name' => 'Agronomy'],
    ['username' => 'anim_sci', 'full_name' => 'Animal Science'],
    ['username' => 'crop_prod', 'full_name' => 'Crop Production'],
    ['username' => 'forest_wild', 'full_name' => 'Forestry & Wildlife Conservation'],
    ['username' => 'home_econ', 'full_name' => 'Home Economics'],
    ['username' => 'soil_res', 'full_name' => 'Soil Science & Land Resources Mgmt'],
    ['username' => 'eng_lit', 'full_name' => 'English & Literary Studies'],
    ['username' => 'theatre_film', 'full_name' => 'Theatre & Film Studies'],
    ['username' => 'french', 'full_name' => 'French'],
    ['username' => 'history', 'full_name' => 'History'],
    ['username' => 'arabic_stu', 'full_name' => 'Arabic Studies'],
    ['username' => 'lang_ling', 'full_name' => 'Languages & Linguistic'],
    ['username' => 'mass_comm', 'full_name' => 'Mass Communication'],
    ['username' => 'arts_edu', 'full_name' => 'Arts Education'],
    ['username' => 'edu_found', 'full_name' => 'Educational Foundations'],
    ['username' => 'counsel_psych', 'full_name' => 'Counselling, Educational Psychology & Human Development'],
    ['username' => 'sci_edu', 'full_name' => 'Science Education'],
    ['username' => 'human_kin', 'full_name' => 'Human Kinetics & Physical Education'],
    ['username' => 'socsci_edu', 'full_name' => 'Social Science Education'],
    ['username' => 'voc_tech', 'full_name' => 'Vocational & Technology Education'],
    ['username' => 'lib_info', 'full_name' => 'Library & Info Science'],
    ['username' => 'abres_eng', 'full_name' => 'Agric & Bio-Resources Engineering'],
    ['username' => 'elec_eng', 'full_name' => 'Electrical/Electronics Engineering'],
    ['username' => 'civil_eng', 'full_name' => 'Civil Engineering'],
    ['username' => 'mech_eng', 'full_name' => 'Mechanical Engineering'],
    ['username' => 'env_health', 'full_name' => 'Environmental Health'],
    ['username' => 'pub_health', 'full_name' => 'Public Health'],
    ['username' => 'nursing', 'full_name' => 'Nursing'],
    ['username' => 'med_lab', 'full_name' => 'Medical Lab Science'],
    ['username' => 'pub_law', 'full_name' => 'Public Law'],
    ['username' => 'priv_prop_law', 'full_name' => 'Private & Property Law'],
    ['username' => 'acct', 'full_name' => 'Accounting'],
    ['username' => 'bus_admin', 'full_name' => 'Business Administration'],
    ['username' => 'pub_admin', 'full_name' => 'Public Administration'],
    ['username' => 'hosp_tour', 'full_name' => 'Hospitality & Tourism Management'],
    ['username' => 'bio_sci', 'full_name' => 'Biological Sciences'],
    ['username' => 'chem_sci', 'full_name' => 'Chemical Sciences'],
    ['username' => 'math_stats', 'full_name' => 'Mathematics & Statistics'],
    ['username' => 'physics', 'full_name' => 'Physics'],
    ['username' => 'comp_sci', 'full_name' => 'Computer Science'],
    ['username' => 'data_ai', 'full_name' => 'Data Science & Artificial Intelligence'],
    ['username' => 'ict', 'full_name' => 'Information & Communication Technology'],
    ['username' => 'soft_eng', 'full_name' => 'Software Engineering'],
    ['username' => 'econ', 'full_name' => 'Economics'],
    ['username' => 'geog', 'full_name' => 'Geography'],
    ['username' => 'pol_intrel', 'full_name' => 'Political & International Relations'],
    ['username' => 'peace_conf', 'full_name' => 'Peace & Conflict Studies'],
    ['username' => 'sociol', 'full_name' => 'Sociology'],
    ['username' => 'islam_stu', 'full_name' => 'Islamic Studies'],
    ['username' => 'crs', 'full_name' => 'CRS']
];

$password = 'user2026';
$hashed_password = md5($password);
$role_id = 7;

$dept_created = 0;
$dept_skipped = 0;

foreach($departments as $dept) {
    $check_sql = "SELECT user_id FROM users WHERE username = '{$dept['username']}'";
    $check_result = mysqli_query($conn, $check_sql);
    
    if(mysqli_num_rows($check_result) > 0) {
        $dept_skipped++;
    } else {
        $insert_sql = "INSERT INTO users (username, password, full_name, role_id) VALUES ('{$dept['username']}', '$hashed_password', '{$dept['full_name']}', $role_id)";
        if(mysqli_query($conn, $insert_sql)) {
            $dept_created++;
        }
    }
}

if($dept_created > 0) {
    echo "<div class='success'>✓ Created $dept_created department account(s) (Password: user2026)</div>";
    $updates_applied += $dept_created;
}
if($dept_skipped > 0) {
    echo "<div class='info'>ℹ Skipped $dept_skipped existing department account(s)</div>";
}

echo "<p><a href='admin.php' class='btn'>← Back to Admin Dashboard</a></p>";
echo "<p><a href='users.php' class='btn'>Go to Users Management</a></p>";

mysqli_close($conn);
?>

    </div>
</body>
</html>
