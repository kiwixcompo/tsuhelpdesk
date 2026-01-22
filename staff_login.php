<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Check if user is already logged in
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: dashboard.php");
    exit;
}

require_once "config.php";

$username = $password = "";
$username_err = $password_err = $login_err = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validate username
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter username.";
    } else{
        $username = trim($_POST["username"]);
    }
    
    // Validate password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if(empty($username_err) && empty($password_err)){
        $sql = "SELECT user_id, username, password, role_id, full_name, is_super_admin FROM users WHERE username = ?";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $username);
            
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                
                if(mysqli_num_rows($result) == 1){
                    $row = mysqli_fetch_assoc($result);
                    
                    // Check if password is in MD5 format
                    if(md5($password) === $row['password']){
                        // Clear any output buffer before setting session and redirecting
                        ob_clean();
                        
                        $_SESSION["loggedin"] = true;
                        $_SESSION["user_id"] = $row["user_id"];
                        $_SESSION["username"] = $row["username"];
                        $_SESSION["role_id"] = $row["role_id"];
                        $_SESSION["full_name"] = $row["full_name"];
                        $_SESSION["is_super_admin"] = $row["is_super_admin"];
                        
                        // Determine redirect location
                        if ($row["role_id"] == 3) {
                            $redirect_url = "director_dashboard.php";
                        } else if ($row["role_id"] == 4) {
                            $redirect_url = "dvc_dashboard.php";
                        } else if ($row["role_id"] == 5) {
                            $redirect_url = "i4cus_staff_dashboard.php";
                        } else if ($row["role_id"] == 6) {
                            $redirect_url = "payment_admin_dashboard.php";
                        } else if ($row["role_id"] == 7) {
                            $redirect_url = "department_dashboard.php";
                        } else if ($row["role_id"] == 8) {
                            $redirect_url = "deputy_director_dashboard.php";
                        } else {
                            $redirect_url = "dashboard.php";
                        }
                        
                        // Send header and exit immediately
                        header("Location: " . $redirect_url);
                        exit();
                    } else {
                        $login_err = "Invalid username or password.";
                    }
                } else {
                    $login_err = "Invalid username or password.";
                }
            } else {
                $login_err = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        } else {
            $login_err = "Database connection error. Please try again later.";
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
    <title>Staff Login - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
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
                <a href="index.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                </a>
                
                <div class="card login-card">
                    <div class="card-header">
                        <i class="fas fa-users-cog fa-2x mb-3"></i>
                        <h2 class="mb-0">Staff Portal</h2>
                        <p class="mb-0 mt-2 opacity-75">Please sign in to your account</p>
                    </div>
                    <div class="card-body p-4">
                        <?php 
                        if(!empty($login_err)){
                            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i>' . htmlspecialchars($login_err) . '
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                  </div>';
                        }        
                        ?>

                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="loginForm">
                            <div class="form-group">
                                <label for="username"><i class="fas fa-user mr-2"></i> Username</label>
                                <input type="text" 
                                       id="username"
                                       name="username" 
                                       class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($username); ?>"
                                       placeholder="Enter your username"
                                       required>
                                <div class="invalid-feedback"><?php echo $username_err; ?></div>
                            </div>    
                            
                            <div class="form-group">
                                <label for="password"><i class="fas fa-lock mr-2"></i> Password</label>
                                <div class="input-group">
                                    <input type="password" 
                                           id="password"
                                           name="password" 
                                           class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>"
                                           placeholder="Enter your password"
                                           required>
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback"><?php echo $password_err; ?></div>
                                </div>
                            </div>
                            <div class="form-group mb-4">
                                <button type="submit" class="btn btn-primary btn-block" id="loginBtn">
                                    <i class="fas fa-sign-in-alt mr-2"></i> Sign In
                                </button>
                            </div>
                            <div class="text-center mb-3">
                                <a href="forgot_password.php">Forgot Password?</a>
                            </div>
                        </form>
                        
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fas fa-shield-alt me-1"></i>
                                Secure login for authorized personnel only
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
            // Toggle password visibility
            $('#togglePassword').click(function() {
                const passwordField = $('#password');
                const passwordFieldType = passwordField.attr('type');
                const toggleIcon = $(this).find('i');
                
                if (passwordFieldType === 'password') {
                    passwordField.attr('type', 'text');
                    toggleIcon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordField.attr('type', 'password');
                    toggleIcon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Form submission with loading state
            $('#loginForm').submit(function() {
                const loginBtn = $('#loginBtn');
                loginBtn.prop('disabled', true);
                loginBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Signing In...');
            });
            
            // Auto-dismiss alerts
            $('.alert').delay(5000).fadeOut();
            
            // Focus on username field
            $('#username').focus();
        });
    </script>
</body>
</html>