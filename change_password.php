<?php
session_start();

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

// Initialize notification variables for navbar
$notification_count = 0;
$unread_count = 0;

$current_password = $new_password = $confirm_password = "";
$current_password_err = $new_password_err = $confirm_password_err = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validate current password
    if(empty(trim($_POST["current_password"]))){
        $current_password_err = "Please enter your current password.";
    } else{
        $current_password = trim($_POST["current_password"]);
        
        // Check if current password is correct
        $sql = "SELECT password FROM users WHERE user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
            
            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);
                if(mysqli_stmt_num_rows($stmt) == 1){
                    mysqli_stmt_bind_result($stmt, $hashed_password);
                    if(mysqli_stmt_fetch($stmt)){
                        if(md5($current_password) !== $hashed_password){
                            $current_password_err = "Current password is incorrect.";
                        }
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Validate new password
    if(empty(trim($_POST["new_password"]))){
        $new_password_err = "Please enter the new password.";     
    } elseif(strlen(trim($_POST["new_password"])) < 6){
        $new_password_err = "Password must have at least 6 characters.";
    } else{
        $new_password = trim($_POST["new_password"]);
    }
    
    // Validate confirm password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm the password.";
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($new_password_err) && ($new_password != $confirm_password)){
            $confirm_password_err = "Passwords did not match.";
        }
    }
    
    // Check input errors before updating the database
    if(empty($current_password_err) && empty($new_password_err) && empty($confirm_password_err)){
        $sql = "UPDATE users SET password = ? WHERE user_id = ?";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "si", $param_password, $param_id);
            
            $param_password = md5($new_password);
            $param_id = $_SESSION["user_id"];
            
            if(mysqli_stmt_execute($stmt)){
                // Password updated successfully
                $success_message = "Password changed successfully.";
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-6 offset-md-3">
                <div class="card">
                    <div class="card-header">
                        <h4>Change Password</h4>
                    </div>
                    <div class="card-body">
                        <?php if(isset($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        <?php if(isset($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" name="current_password" class="form-control <?php echo (!empty($current_password_err)) ? 'is-invalid' : ''; ?>">
                                <span class="invalid-feedback"><?php echo $current_password_err; ?></span>
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" class="form-control <?php echo (!empty($new_password_err)) ? 'is-invalid' : ''; ?>">
                                <span class="invalid-feedback"><?php echo $new_password_err; ?></span>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>">
                                <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                            </div>
                            <div class="form-group">
                                <?php 
                                // Define dashboard URL based on user role
                                $dashboard_url = 'dashboard.php';
                                switch($_SESSION["role_id"]) {
                                    case 3: $dashboard_url = 'director_dashboard.php'; break;
                                    case 4: $dashboard_url = 'dvc_dashboard.php'; break;
                                    case 5: $dashboard_url = 'i4cus_staff_dashboard.php'; break;
                                    case 6: $dashboard_url = 'payment_admin_dashboard.php'; break;
                                    case 7: $dashboard_url = 'department_dashboard.php'; break;
                                }
                                ?>
                                <button type="submit" class="btn btn-primary">Change Password</button>
                                <a class="btn btn-secondary" href="<?php echo $dashboard_url; ?>">Cancel</a>
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
</body>
</html>