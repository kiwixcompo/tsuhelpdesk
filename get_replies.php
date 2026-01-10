<?php
session_start();

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    exit("Unauthorized");
}

require_once "config.php";

// Include the script to create message_replies table if it doesn't exist
require_once "create_message_replies_table.php";

if(!isset($_GET["message_id"]) || !is_numeric($_GET["message_id"])){
    exit("Invalid request");
}

$message_id = $_GET["message_id"];

// Fetch replies
$sql = "SELECT r.*, u.full_name 
        FROM message_replies r
        JOIN users u ON r.sender_id = u.user_id
        WHERE r.message_id = ?
        ORDER BY r.created_at ASC";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $message_id);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if(mysqli_num_rows($result) > 0){
            while($reply = mysqli_fetch_assoc($result)){
                ?>
                <div class="reply-item mb-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?php echo htmlspecialchars($reply['full_name']); ?></strong>
                            <p class="mb-1"><?php echo nl2br(htmlspecialchars($reply['reply_text'])); ?></p>
                        </div>
                        <small class="text-muted">
                            <?php echo date('M d, Y h:i A', strtotime($reply['created_at'])); ?>
                        </small>
                    </div>
                </div>
                <?php
            }
        } else {
            echo "<p class='text-muted'>No replies yet.</p>";
        }
    }
    mysqli_stmt_close($stmt);
} else {
    echo "Error loading replies.";
}
?>