<?php
// Start output buffering to prevent header issues
ob_start();

session_start();
require_once "config.php";

$email = "";
$email_err = $success_msg = $error_msg = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validate email
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter your email address.";
    } elseif(!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)){
        $email_err = "Please enter a valid email address.";
    } else{
        $email = trim($_POST["email"]);
        
        // Check if email exists in students table
        $sql = "SELECT student_id, first_name, last_name, registration_number FROM students WHERE email = ? AND is_active = 1";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $email);
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                if(mysqli_num_rows($result) == 1){
                    $row = mysqli_fetch_assoc($result);
                    
                    // Generate reset token
                    $reset_token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Store reset token in database (you might want to create a password_resets table)
                    // For now, we'll use a simple approach
                    $update_sql = "UPDATE students SET password_reset_token = ?, password_reset_expires = ? WHERE student_id = ?";
                    if($update_stmt = mysqli_prepare($conn, $update_sql)){
                        mysqli_stmt_bind_param($update_stmt, "ssi", $reset_token, $expires_at, $row['student_id']);
                        if(mysqli_stmt_execute($update_stmt)){
                            // Send email (simplified version)
                            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/student_reset_password.php?token=" . $reset_token;
                            
                            $to = $email;
                            $subject = "Password Reset - TSU ICT Help Desk";
                            $message = "Dear " . $row['first_name'] . " " . $row['last_name'] . ",\n\n";
                            $message .= "You have requested a password reset for your student account.\n\n";
                            $message .= "Registration Number: " . $row['registration_number'] . "\n\n";
                            $message .= "Click the link below to reset your password:\n";
                            $message .= $reset_link . "\n\n";
                            $message .= "This link will expire in 1 hour.\n\n";
                            $message .= "If you did not request this reset, please ignore this email.\n\n";
                            $message .= "Best regards,\nTSU ICT Help Desk Team";
                            
                            $headers = "From: noreply@tsu.edu.ng\r\n";
                            $headers .= "Reply-To: support@tsu.edu.ng\r\n";
                            
                            if(mail($to, $subject, $message, $headers)){
                                $success_msg = "Password reset instructions have been sent to your email address.";
                            } else {
                                $error_msg = "Failed to send email. Please try again later.";
                            }
                        } else {
                            $error_msg = "Something went wrong. Please try again.";
                        }
                        mysqli_stmt_close($update_stmt);
                    }
                } else {
                    $error_msg = "No account found with that email address.";
                }
            } else {
                $error_msg = "Something went wrong. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Add password reset columns to students table if they don't exist
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'password_reset_token'");
if(mysqli_num_rows($check_columns) == 0) {
    mysqli_query($conn, "ALTER TABLE students ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE students ADD COLUMN password_reset_expires DATETIME DEFAULT NULL");
}

// End output buffering and flush
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
        }
        .forgot-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            text-align: center;
            padding: 2rem;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
        }
        .btn-primary {
            border-radius: 10px;
            padding: 12px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border: none;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            background: linear-gradient(135deg, #2a5298 0%, #1e3c72 100%);
        }
        .back-link {
            color: white;
            text-decoration: none;
            margin-bottom: 2rem;
            display: inline-block;
        }
        .back-link:hover {
            color: #f8f9fa;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5">
                <a href="student_login.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Login
                </a>
                
                <div class="card forgot-card">
                    <div class="card-header">
                        <i class="fas fa-key fa-2x mb-3"></i>
                        <h2 class="mb-0">Forgot Password</h2>
                        <p class="mb-0 mt-2 opacity-75">Enter your email to reset your password</p>
                    </div>
                    <div class="card-body p-4">
                        <?php 
                        if(!empty($success_msg)){
                            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="fas fa-check-circle mr-2"></i>' . htmlspecialchars($success_msg) . '
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                  </div>';
                        }
                        
                        if(!empty($error_msg)){
                            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>' . htmlspecialchars($error_msg) . '
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                  </div>';
                        }        
                        ?>

                        <?php if(empty($success_msg)): ?>
                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="forgotForm">
                            <div class="form-group">
                                <label for="email"><i class="fas fa-envelope mr-2"></i> Email Address</label>
                                <input type="email" 
                                       id="email"
                                       name="email" 
                                       class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($email); ?>"
                                       placeholder="Enter your registered email address"
                                       required>
                                <div class="invalid-feedback"><?php echo $email_err; ?></div>
                            </div>
                            
                            <div class="form-group mb-4">
                                <button type="submit" class="btn btn-primary btn-block" id="resetBtn">
                                    <i class="fas fa-paper-plane mr-2"></i> Send Reset Link
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="text-center">
                            <p>Check your email for password reset instructions.</p>
                            <a href="student_login.php" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt mr-2"></i> Back to Login
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fas fa-info-circle mr-1"></i>
                                Remember your password? <a href="student_login.php">Sign in here</a>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Form submission with loading state
            $('#forgotForm').submit(function() {
                const resetBtn = $('#resetBtn');
                resetBtn.prop('disabled', true);
                resetBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Sending...');
            });
            
            // Auto-dismiss alerts
            $('.alert').delay(5000).fadeOut();
            
            // Focus on email field
            $('#email').focus();
        });
    </script>
</body>
</html>