<?php
// Create suggestions table if it doesn't exist
require_once "config.php";

$sql = "CREATE TABLE IF NOT EXISTS suggestions (
    suggestion_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'General',
    status ENUM('Pending', 'Under Review', 'Approved', 'Rejected', 'Implemented') DEFAULT 'Pending',
    priority ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
    admin_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
)";

if(mysqli_query($conn, $sql)){
    // Table created successfully or already exists
} else {
    // Log error but don't stop execution
    error_log("Error creating suggestions table: " . mysqli_error($conn));
}
?>