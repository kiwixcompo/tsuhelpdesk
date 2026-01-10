<?php
// Start output buffering to prevent header issues
ob_start();

session_start();
require_once "config.php";

$token = $password = $confirm_password = "";
$token_err = $password_err = $confirm_password_err = $success_msg = $error_msg = "";

// Get token from URL
if(isset($_GET['token']) && !empty($_GET['token'])){
    $token = trim($_GET['token']);
} else {
    $token_err = "Invalid reset link.";
}

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $token = trim($_POST['token']);
    
    // Validate password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter a new password.";     
    } elseif(strlen(trim($_POST["password"])) < 6){
        $password_err = "Password must have at least 6 characters.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate confirm password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm password.";     
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($password_err) && ($password != $confirm_password)){
            $confirm_password_err = "Password did not match.";
        }
    }
    
    // Check if token is valid and not expired
    if(empty($password_err) && empty($confirm_password_err) && !empty($token)){
        $sql = "SELECT student_id, first_name, last_name FROM students WHERE password_reset_token = ? AND password_reset_expires > NOW() AND is_active = 1";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $token);
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                if(mysqli_num_rows($result) == 1){
                    $row = mysqli_fetch_assoc($result);
                    
                    // Update password and clear reset token
                    $hashed_password = md5($password);
                    $update_sql = "UPDATE students SET password = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE student_id = ?";
                    if($update_stmt = mysqli_prepare($conn, $update_sql)){
                        mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $row['student_id']);
                        if(mysqli_stmt_execute($update_stmt)){
                            $success_msg = "Your password has been successfully reset. You can now login with your new password.";
                        } else {
                            $error_msg = "Something went wrong. Please try again.";
                        }
                        mysqli_stmt_close($update_stmt);
                    }
                } else {
                    $error_msg = "Invalid or expired reset link.";
                }
            } else {
                $error_msg = "Something went wrong. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// End output buffering and flush
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
        }
        .reset-card {
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
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6 col-lg-5">
                <div class="card reset-card">
                    <div class="card-header">
                        <i class="fas fa-lock fa-2x mb-3"></i>
                        <h2 class="mb-0">Reset Password</h2>
                        <p class="mb-0 mt-2 opacity-75">Enter your new password</p>
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
                        
                        if(!empty($token_err)){
                            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>' . htmlspecialchars($token_err) . '
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                  </div>';
                        }        
                        ?>

                        <?php if(empty($success_msg) && empty($token_err)): ?>
                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="resetForm">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            
                            <div class="form-group">
                                <label for="password"><i class="fas fa-lock mr-2"></i> New Password</label>
                                <input type="password" 
                                       id="password"
                                       name="password" 
                                       class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>"
                                       placeholder="Enter your new password"
                                       required>
                                <small class="form-text text-muted">Minimum 6 characters</small>
                                <div class="invalid-feedback"><?php echo $password_err; ?></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password"><i class="fas fa-lock mr-2"></i> Confirm New Password</label>
                                <input type="password" 
                                       id="confirm_password"
                                       name="confirm_password" 
                                       class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>"
                                       placeholder="Confirm your new password"
                                       required>
                                <div class="invalid-feedback"><?php echo $confirm_password_err; ?></div>
                            </div>
                            
                            <div class="form-group mb-4">
                                <button type="submit" class="btn btn-primary btn-block" id="resetBtn">
                                    <i class="fas fa-save mr-2"></i> Reset Password
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="text-center">
                            <a href="student_login.php" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt mr-2"></i> Go to Login
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
            $('#resetForm').submit(function() {
                const resetBtn = $('#resetBtn');
                resetBtn.prop('disabled', true);
                resetBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Resetting...');
            });
            
            // Auto-dismiss alerts
            $('.alert').delay(5000).fadeOut();
            
            // Focus on password field
            $('#password').focus();
        });
    </script>
</body>
</html>