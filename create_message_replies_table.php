<?php
// Create message_replies table if it doesn't exist
require_once "config.php";

$sql = "CREATE TABLE IF NOT EXISTS message_replies (
    reply_id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    sender_id INT NOT NULL,
    reply_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id) REFERENCES messages(message_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE
)";

if(mysqli_query($conn, $sql)){
    // Table created successfully or already exists
} else {
    // Log error but don't stop execution
    error_log("Error creating message_replies table: " . mysqli_error($conn));
}
?>