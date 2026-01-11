<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Check if student is logged in
if(!isset($_SESSION["student_loggedin"]) || $_SESSION["student_loggedin"] !== true){
    header("location: student_login.php");
    exit;
}

require_once "config.php";

$current_password = $new_password = $confirm_password = "";
$current_password_err = $new_password_err = $confirm_password_err = "";
$success_msg = $error_msg = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validate current password
    if(empty(trim($_POST["current_password"]))){
        $current_password_err = "Please enter your current password.";
    } else{
        $current_password = trim($_POST["current_password"]);
        
        // Verify current password
        $sql = "SELECT password FROM students WHERE student_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $_SESSION["student_id"]);
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                if($row = mysqli_fetch_assoc($result)){
                    if(md5($current_password) !== $row['password']){
                        $current_password_err = "Current password is incorrect.";
                    }
                } else {
                    $current_password_err = "User not found.";
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Validate new password
    if(empty(trim($_POST["new_password"]))){
        $new_password_err = "Please enter a new password.";     
    } elseif(strlen(trim($_POST["new_password"])) < 6){
        $new_password_err = "Password must have at least 6 characters.";
    } else{
        $new_password = trim($_POST["new_password"]);
    }
    
    // Validate confirm password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm the new password.";     
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($new_password_err) && ($new_password != $confirm_password)){
            $confirm_password_err = "Passwords did not match.";
        }
    }
    
    // Check input errors before updating password
    if(empty($current_password_err) && empty($new_password_err) && empty($confirm_password_err)){
        $sql = "UPDATE students SET password = ? WHERE student_id = ?";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            $hashed_password = md5($new_password);
            mysqli_stmt_bind_param($stmt, "si", $hashed_password, $_SESSION["student_id"]);
            
            if(mysqli_stmt_execute($stmt)){
                $success_msg = "Password changed successfully!";
                
                // Send notification email
                $email_sql = "SELECT email, first_name, last_name FROM students WHERE student_id = ?";
                if($email_stmt = mysqli_prepare($conn, $email_sql)){
                    mysqli_stmt_bind_param($email_stmt, "i", $_SESSION["student_id"]);
                    if(mysqli_stmt_execute($email_stmt)){
                        $email_result = mysqli_stmt_get_result($email_stmt);
                        if($email_row = mysqli_fetch_assoc($email_result)){
                            $to = $email_row['email'];
                            $subject = "Password Changed - TSU ICT Help Desk";
                            $message = "Dear " . $email_row['first_name'] . " " . $email_row['last_name'] . ",\n\n";
                            $message .= "Your password has been successfully changed for your TSU ICT Help Desk student account.\n\n";
                            $message .= "If you did not make this change, please contact our support team immediately.\n\n";
                            $message .= "Date: " . date('F j, Y \a\t g:i A') . "\n\n";
                            $message .= "Best regards,\nTSU ICT Help Desk Team";
                            
                            $headers = "From: noreply@tsu.edu.ng\r\n";
                            $headers .= "Reply-To: support@tsu.edu.ng\r\n";
                            
                            @mail($to, $subject, $message, $headers);
                        }
                    }
                    mysqli_stmt_close($email_stmt);
                }
                
                // Clear form fields
                $current_password = $new_password = $confirm_password = "";
            } else{
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
    <title>Change Password - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar-brand, .navbar-nav .nav-link {
            color: white !important;
        }
        .navbar-nav .nav-link:hover {
            color: #f8f9fa !important;
        }
        .change-password-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-top: 2rem;
        }
        .card-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.5rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border: none;
            border-radius: 10px;
        }
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            border: none;
            border-radius: 10px;
        }
        .form-control {
            border-radius: 10px;
            border: 1px solid #dee2e6;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="student_dashboard.php">
                <i class="fas fa-graduation-cap mr-2"></i>
                TSU Student Portal
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="student_dashboard.php">
                            <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown">
                            <i class="fas fa-user mr-1"></i>
                            <?php echo htmlspecialchars($_SESSION["student_name"]); ?>
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="student_change_password.php">
                                <i class="fas fa-key mr-2"></i>Change Password
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="student_logout.php">
                                <i class="fas fa-sign-out-alt mr-2"></i>Logout
                            </a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card change-password-card">
                    <div class="card-header text-center">
                        <h4 class="mb-0"><i class="fas fa-key mr-2"></i>Change Password</h4>
                        <p class="mb-0 mt-2 opacity-75">Update your account password</p>
                    </div>
                    <div class="card-body p-4">
                        <!-- Alerts -->
                        <?php if(!empty($success_msg)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                                <button type="button" class="close" data-dismiss="alert">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if(!empty($error_msg)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                                <button type="button" class="close" data-dismiss="alert">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="changePasswordForm">
                            <div class="form-group">
                                <label for="current_password"><i class="fas fa-lock mr-2"></i>Current Password</label>
                                <input type="password" 
                                       id="current_password"
                                       name="current_password" 
                                       class="form-control <?php echo (!empty($current_password_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($current_password); ?>"
                                       required>
                                <div class="invalid-feedback"><?php echo $current_password_err; ?></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password"><i class="fas fa-key mr-2"></i>New Password</label>
                                <input type="password" 
                                       id="new_password"
                                       name="new_password" 
                                       class="form-control <?php echo (!empty($new_password_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($new_password); ?>"
                                       required>
                                <small class="form-text text-muted">Minimum 6 characters</small>
                                <div class="invalid-feedback"><?php echo $new_password_err; ?></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password"><i class="fas fa-check mr-2"></i>Confirm New Password</label>
                                <input type="password" 
                                       id="confirm_password"
                                       name="confirm_password" 
                                       class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($confirm_password); ?>"
                                       required>
                                <div class="invalid-feedback"><?php echo $confirm_password_err; ?></div>
                            </div>
                            
                            <div class="form-group mb-4">
                                <button type="submit" class="btn btn-primary btn-block" id="changePasswordBtn">
                                    <i class="fas fa-save mr-2"></i>Change Password
                                </button>
                            </div>
                            
                            <div class="text-center">
                                <a href="student_dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                                </a>
                            </div>
                        </form>
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
            $('#changePasswordForm').submit(function() {
                const changePasswordBtn = $('#changePasswordBtn');
                changePasswordBtn.prop('disabled', true);
                changePasswordBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Changing Password...');
            });
            
            // Auto-dismiss alerts
            $('.alert').delay(5000).fadeOut();
            
            // Focus on current password field
            $('#current_password').focus();
            
            // Password strength indicator
            $('#new_password').on('input', function() {
                const password = $(this).val();
                let strength = 0;
                
                if(password.length >= 6) strength++;
                if(password.match(/[a-z]/)) strength++;
                if(password.match(/[A-Z]/)) strength++;
                if(password.match(/[0-9]/)) strength++;
                if(password.match(/[^a-zA-Z0-9]/)) strength++;
                
                let strengthText = '';
                let strengthClass = '';
                
                switch(strength) {
                    case 0:
                    case 1:
                        strengthText = 'Weak';
                        strengthClass = 'text-danger';
                        break;
                    case 2:
                    case 3:
                        strengthText = 'Medium';
                        strengthClass = 'text-warning';
                        break;
                    case 4:
                    case 5:
                        strengthText = 'Strong';
                        strengthClass = 'text-success';
                        break;
                }
                
                // Remove existing strength indicator
                $('#new_password').next('.password-strength').remove();
                
                if(password.length > 0) {
                    $('#new_password').after(`<small class="password-strength ${strengthClass}">Password strength: ${strengthText}</small>`);
                }
            });
        });
    </script>
</body>
</html>