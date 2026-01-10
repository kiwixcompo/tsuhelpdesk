<?php
session_start();

// If user is already logged in, redirect to appropriate dashboard
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    if($_SESSION["role_id"] == 3) {
        header("location: director_dashboard.php");
    } else if($_SESSION["role_id"] == 4) {
        header("location: dvc_dashboard.php");
    } else {
        header("location: dashboard.php");
    }
    exit;
}

require_once "config.php";

// Function to create password_reset table if it doesn't exist
function createPasswordResetTable($conn) {
    $create_table_sql = "CREATE TABLE IF NOT EXISTS password_reset (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        reset_token VARCHAR(255) NOT NULL,
        token_expiry DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(reset_token),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )";
    
    if(mysqli_query($conn, $create_table_sql)) {
        return true;
    } else {
        error_log("Failed to create password_reset table: " . mysqli_error($conn));
        return false;
    }
}

$username = $email = $message = "";
$username_err = $email_err = "";
$success = false;

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // Validate username
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter your username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    // Validate email
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter your email.";
    } else{
        $email = trim($_POST["email"]);
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email_err = "Please enter a valid email address.";
        }
    }
    
    // Check input errors before proceeding
    if(empty($username_err) && empty($email_err)){
        
        // First, ensure the password_reset table exists
        createPasswordResetTable($conn);
        
        // Prepare a select statement
        $sql = "SELECT user_id, username, email FROM users WHERE username = ? AND email = ?";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "ss", $param_username, $param_email);
            
            // Set parameters
            $param_username = $username;
            $param_email = $email;
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Store result
                mysqli_stmt_store_result($stmt);
                
                // Check if username exists and email matches
                if(mysqli_stmt_num_rows($stmt) == 1){
                    mysqli_stmt_bind_result($stmt, $user_id, $db_username, $db_email);
                    if(mysqli_stmt_fetch($stmt)){
                        // Generate a unique token
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                        
                        // Close the first statement before proceeding
                        mysqli_stmt_close($stmt);
                        
                        // Delete any existing tokens for this user
                        $delete_sql = "DELETE FROM password_reset WHERE user_id = ?";
                        if($delete_stmt = mysqli_prepare($conn, $delete_sql)){
                            mysqli_stmt_bind_param($delete_stmt, "i", $user_id);
                            mysqli_stmt_execute($delete_stmt);
                            mysqli_stmt_close($delete_stmt);
                        } else {
                            error_log("Failed to prepare delete statement: " . mysqli_error($conn));
                        }
                        
                        // Insert the token into the database
                        $insert_sql = "INSERT INTO password_reset (user_id, reset_token, token_expiry) VALUES (?, ?, ?)";
                        if($insert_stmt = mysqli_prepare($conn, $insert_sql)){
                            mysqli_stmt_bind_param($insert_stmt, "iss", $user_id, $token, $expires);
                            
                            if(mysqli_stmt_execute($insert_stmt)){
                                // Send email with reset link
                                $reset_link = "https://helpdesk.tsuniversity.edu.ng/reset_password.php?token=$token";
                                $to = $db_email;
                                $subject = "Password Reset Request - TSU Helpdesk";
                                $message_body = "Hello $db_username,\n\n";
                                $message_body .= "You have requested to reset your password for TSU Helpdesk. Please click the link below to reset your password:\n\n";
                                $message_body .= "$reset_link\n\n";
                                $message_body .= "This link will expire in 1 hour.\n\n";
                                $message_body .= "If you did not request a password reset, please ignore this email.\n\n";
                                $message_body .= "Regards,\nTSU Helpdesk Team";
                                
                                // Better email headers
                                $headers = "From: TSU Helpdesk <noreply@tsuniversity.edu.ng>\r\n";
                                $headers .= "Reply-To: noreply@tsuniversity.edu.ng\r\n";
                                $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
                                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                                
                                // Send email
                                $mail_sent = @mail($to, $subject, $message_body, $headers);
                                
                                if($mail_sent){
                                    $success = true;
                                    $message = "A password reset link has been sent to your email address. Please check your inbox and spam folder.";
                                    // Clear form data on success
                                    $username = $email = "";
                                } else {
                                    $message = "Reset link generated but failed to send email. Please contact the system administrator.";
                                    error_log("Password reset email failed for user: $db_username, email: $db_email");
                                }
                            } else {
                                $message = "Database error occurred while saving reset token. Error: " . mysqli_stmt_error($insert_stmt);
                                error_log("Failed to insert password reset token for user_id: $user_id - " . mysqli_stmt_error($insert_stmt));
                            }
                            
                            mysqli_stmt_close($insert_stmt);
                        } else {
                            $message = "Database preparation error for password reset. Error: " . mysqli_error($conn);
                            error_log("Failed to prepare password reset insert statement: " . mysqli_error($conn));
                        }
                    }
                } else {
                    // Security: Don't reveal whether username or email exists
                    $message = "If an account with that username and email combination exists, a reset link has been sent.";
                }
            } else {
                $message = "Database query failed. Error: " . mysqli_stmt_error($stmt);
                error_log("Failed to execute user lookup query: " . mysqli_stmt_error($stmt));
            }
            
            // Close statement if it wasn't closed earlier
            if(isset($stmt) && $stmt !== false) {
                mysqli_stmt_close($stmt);
            }
        } else {
            $message = "Database connection error. Error: " . mysqli_error($conn);
            error_log("Failed to prepare user lookup statement: " . mysqli_error($conn));
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - TSU Helpdesk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #007bff;
            color: white;
            border-radius: 10px 10px 0 0 !important;
        }
        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
        }
        .btn-primary:hover {
            background-color: #0069d9;
            border-color: #0062cc;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header text-center">
                        <h3>Forgot Password</h3>
                    </div>
                    <div class="card-body">
                        <?php if($success): ?>
                            <div class="alert alert-success">
                                <strong>Success!</strong> <?php echo htmlspecialchars($message); ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="index.php" class="btn btn-primary">Back to Login</a>
                            </div>
                        <?php else: ?>
                            <?php if(!empty($message)): ?>
                                <div class="alert alert-danger">
                                    <?php echo htmlspecialchars($message); ?>
                                </div>
                            <?php endif; ?>
                            <p class="text-center">Please enter your username and registered email address to receive a password reset link.</p>
                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>" required>
                                    <span class="invalid-feedback"><?php echo $username_err; ?></span>
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" required>
                                    <span class="invalid-feedback"><?php echo $email_err; ?></span>
                                </div>
                                <div class="form-group text-center">
                                    <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
                                </div>
                                <div class="text-center">
                                    <a href="index.php">Back to Login</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>