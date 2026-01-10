<?php
session_start();

// If user is already logged in, redirect to appropriate dashboard
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    if($_SESSION["role_id"] == 3) {
        header("location: director_dashboard.php");
    } else if($_SESSION["role_id"] == 4) {
        header("location: dvc_dashboard.php");
    } else if($_SESSION["role_id"] == 5) {
        header("location: i4cus_staff_dashboard.php");
    } else {
        header("location: dashboard.php");
    }
    exit;
}

require_once "config.php";

$token = $password = $confirm_password = "";
$token_err = $password_err = $confirm_password_err = $general_err = "";
$success = false;
$user_id = $username = "";
$token_valid = false;

// Check if token is provided in URL or POST
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["token"])){
    $token = trim($_POST["token"]);
} elseif(isset($_GET["token"]) && !empty(trim($_GET["token"]))){
    $token = trim($_GET["token"]);
} else {
    $token_err = "Invalid token.";
}

// Validate token and check if it's expired
if(empty($token_err) && !empty($token)){
    $sql = "SELECT pr.user_id, pr.token_expiry, u.username FROM password_reset pr JOIN users u ON pr.user_id = u.user_id WHERE pr.reset_token = ? AND pr.token_expiry > NOW()";
    
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "s", $token);
        
        if(mysqli_stmt_execute($stmt)){
            mysqli_stmt_store_result($stmt);
            
            if(mysqli_stmt_num_rows($stmt) == 1){
                mysqli_stmt_bind_result($stmt, $user_id, $expires_at, $username);
                mysqli_stmt_fetch($stmt);
                $token_valid = true;
            } else {
                $token_err = "Invalid or expired token.";
            }
        } else {
            $general_err = "Database error occurred. Please try again later.";
            error_log("Reset password token validation failed: " . mysqli_stmt_error($stmt));
        }
        
        mysqli_stmt_close($stmt);
    } else {
        $general_err = "Database preparation error. Please try again later.";
        error_log("Failed to prepare reset password token query: " . mysqli_error($conn));
    }
}

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST" && $token_valid && empty($token_err)){
    
    // Validate password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter a password.";
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
    
    // Check input errors before updating the database
    if(empty($password_err) && empty($confirm_password_err)){
        // Prepare an update statement
        $sql = "UPDATE users SET password = ? WHERE user_id = ?";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            // Bind variables to the prepared statement as parameters
            mysqli_stmt_bind_param($stmt, "si", $param_password, $param_user_id);
            
            // Set parameters
            $param_password = md5($password); // Using MD5 to match existing password hashing
            $param_user_id = $user_id;
            
            // Attempt to execute the prepared statement
            if(mysqli_stmt_execute($stmt)){
                // Delete the token from the database
                $delete_sql = "DELETE FROM password_reset WHERE user_id = ?";
                if($delete_stmt = mysqli_prepare($conn, $delete_sql)){
                    mysqli_stmt_bind_param($delete_stmt, "i", $user_id);
                    mysqli_stmt_execute($delete_stmt);
                    mysqli_stmt_close($delete_stmt);
                }
                
                // Password updated successfully
                $success = true;
            } else{
                $general_err = "Failed to update password. Please try again later.";
                error_log("Failed to update password for user_id: $user_id - " . mysqli_stmt_error($stmt));
            }
            
            // Close statement
            mysqli_stmt_close($stmt);
        } else {
            $general_err = "Database preparation error. Please try again later.";
            error_log("Failed to prepare password update statement: " . mysqli_error($conn));
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - TSU Helpdesk</title>
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
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 35px;
            cursor: pointer;
            z-index: 10;
        }
        .form-group {
            position: relative;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header text-center">
                        <h3>Reset Password</h3>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($token_err) || !$token_valid): ?>
                            <div class="alert alert-danger">
                                <?php echo !empty($token_err) ? htmlspecialchars($token_err) : "Invalid or expired token."; ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="forgot_password.php" class="btn btn-secondary mr-2">Request New Reset Link</a>
                                <a href="index.php" class="btn btn-primary">Back to Login</a>
                            </div>
                        <?php elseif(!empty($general_err)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($general_err); ?></div>
                            <div class="text-center mt-3">
                                <a href="index.php" class="btn btn-primary">Back to Login</a>
                            </div>
                        <?php elseif($success): ?>
                            <div class="alert alert-success">
                                <strong>Success!</strong> Your password has been reset successfully!
                            </div>
                            <div class="text-center mt-3">
                                <a href="index.php" class="btn btn-primary">Login with New Password</a>
                            </div>
                        <?php else: ?>
                            <p class="text-center">Hi <strong><?php echo htmlspecialchars($username); ?></strong>, please create a new password.</p>
                            
                            <?php if(!empty($password_err) || !empty($confirm_password_err)): ?>
                                <div class="alert alert-danger">
                                    <?php 
                                    if(!empty($password_err)) echo $password_err . "<br>";
                                    if(!empty($confirm_password_err)) echo $confirm_password_err;
                                    ?>
                                </div>
                            <?php endif; ?>
                            
                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" required>
                                    <span class="password-toggle" onclick="togglePassword('password')">
                                        <i class="fa fa-eye" id="password-icon"></i>
                                    </span>
                                    <span class="invalid-feedback"><?php echo $password_err; ?></span>
                                    <small class="form-text text-muted">Password must be at least 6 characters long.</small>
                                </div>
                                
                                <div class="form-group">
                                    <label>Confirm Password</label>
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" required>
                                    <span class="password-toggle" onclick="togglePassword('confirm_password')">
                                        <i class="fa fa-eye" id="confirm-password-icon"></i>
                                    </span>
                                    <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                                </div>
                                
                                <div class="form-group text-center">
                                    <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
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
    <script src="https://kit.fontawesome.com/a076d05399.js"></script>
    <script>
        function togglePassword(fieldId) {
            var field = document.getElementById(fieldId);
            var icon = document.getElementById(fieldId + '-icon');
            
            if (field.type === "password") {
                field.type = "text";
                icon.className = "fa fa-eye-slash";
            } else {
                field.type = "password";
                icon.className = "fa fa-eye";
            }
        }
    </script>
</body>
</html>