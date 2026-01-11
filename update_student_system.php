<?php
// Database update script for student system enhancements
require_once "config.php";

echo "<h2>Student System Database Update</h2>";

// Add password reset columns to students table if they don't exist
echo "<h3>1. Adding password reset columns to students table...</h3>";
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'password_reset_token'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE students ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added password_reset_token column<br>";
    } else {
        echo "✗ Error adding password_reset_token column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ password_reset_token column already exists<br>";
}

$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'password_reset_expires'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE students ADD COLUMN password_reset_expires DATETIME DEFAULT NULL";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added password_reset_expires column<br>";
    } else {
        echo "✗ Error adding password_reset_expires column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ password_reset_expires column already exists<br>";
}

// Add is_active column to students table if it doesn't exist
echo "<h3>2. Adding is_active column to students table...</h3>";
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'is_active'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE students ADD COLUMN is_active TINYINT(1) DEFAULT 1";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added is_active column<br>";
    } else {
        echo "✗ Error adding is_active column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ is_active column already exists<br>";
}

// Add created_at column to students table if it doesn't exist
echo "<h3>3. Adding created_at column to students table...</h3>";
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'created_at'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE students ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added created_at column<br>";
    } else {
        echo "✗ Error adding created_at column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ created_at column already exists<br>";
}

// Add updated_at column to students table if it doesn't exist
echo "<h3>4. Adding updated_at column to students table...</h3>";
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'updated_at'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE students ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added updated_at column<br>";
    } else {
        echo "✗ Error adding updated_at column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ updated_at column already exists<br>";
}

// Add admin_response column to student_complaints table if it doesn't exist
echo "<h3>5. Adding admin_response column to student_complaints table...</h3>";
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'admin_response'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE student_complaints ADD COLUMN admin_response TEXT DEFAULT NULL";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added admin_response column<br>";
    } else {
        echo "✗ Error adding admin_response column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ admin_response column already exists<br>";
}

// Add updated_at column to student_complaints table if it doesn't exist
echo "<h3>6. Adding updated_at column to student_complaints table...</h3>";
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM student_complaints LIKE 'updated_at'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE student_complaints ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added updated_at column<br>";
    } else {
        echo "✗ Error adding updated_at column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ updated_at column already exists<br>";
}

// Update existing students to have is_active = 1 if NULL
echo "<h3>7. Updating existing students to be active...</h3>";
$sql = "UPDATE students SET is_active = 1 WHERE is_active IS NULL";
if(mysqli_query($conn, $sql)) {
    $affected = mysqli_affected_rows($conn);
    echo "✓ Updated $affected students to active status<br>";
} else {
    echo "✗ Error updating students: " . mysqli_error($conn) . "<br>";
}

// Check if is_super_admin column exists in users table
echo "<h3>8. Checking is_super_admin column in users table...</h3>";
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'is_super_admin'");
if(mysqli_num_rows($check_columns) == 0) {
    $sql = "ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0";
    if(mysqli_query($conn, $sql)) {
        echo "✓ Added is_super_admin column<br>";
        
        // Set the first admin user as super admin
        $sql = "UPDATE users SET is_super_admin = 1 WHERE role_id = 1 ORDER BY user_id LIMIT 1";
        if(mysqli_query($conn, $sql)) {
            echo "✓ Set first admin user as super admin<br>";
        }
    } else {
        echo "✗ Error adding is_super_admin column: " . mysqli_error($conn) . "<br>";
    }
} else {
    echo "✓ is_super_admin column already exists<br>";
}

echo "<h3>Database Update Complete!</h3>";
echo "<p><a href='admin.php'>← Back to Admin Panel</a></p>";
?>