<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Check if student is already logged in
if(isset($_SESSION["student_loggedin"]) && $_SESSION["student_loggedin"] === true){
    header("location: student_dashboard.php");
    exit;
}

require_once "config.php";

$registration_number = $password = "";
$registration_number_err = $password_err = $login_err = "";
$success_msg = "";

// Check for registration success message
if(isset($_GET['registered']) && $_GET['registered'] == '1'){
    $success_msg = "Registration successful! Please login with your credentials.";
}

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validate registration number
    if(empty(trim($_POST["registration_number"]))){
        $registration_number_err = "Please enter your registration number.";
    } else{
        $registration_number = trim($_POST["registration_number"]);
    }
    
    // Validate password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password = trim($_POST["password"]);
    }
    
    // Validate credentials
    if(empty($registration_number_err) && empty($password_err)){
        $sql = "SELECT s.student_id, s.registration_number, s.password, s.first_name, s.last_name, s.email, s.is_active,
                       f.faculty_name, d.department_name, p.programme_name
                FROM students s
                JOIN faculties f ON s.faculty_id = f.faculty_id
                JOIN student_departments d ON s.department_id = d.department_id
                JOIN programmes p ON s.programme_id = p.programme_id
                WHERE s.registration_number = ?";
        
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $registration_number);
            
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                
                if(mysqli_num_rows($result) == 1){
                    $row = mysqli_fetch_assoc($result);
                    
                    // Check if account is active
                    if($row['is_active'] != 1){
                        $login_err = "Your account has been deactivated. Please contact administration.";
                    } else {
                        // Check password
                        if(md5($password) === $row['password']){
                            // Clear any output buffer before setting session and redirecting
                            ob_clean();
                            
                            $_SESSION["student_loggedin"] = true;
                            $_SESSION["student_id"] = $row["student_id"];
                            $_SESSION["student_reg_number"] = $row["registration_number"];
                            $_SESSION["student_name"] = $row["first_name"] . " " . $row["last_name"];
                            $_SESSION["student_email"] = $row["email"];
                            $_SESSION["student_faculty"] = $row["faculty_name"];
                            $_SESSION["student_department"] = $row["department_name"];
                            $_SESSION["student_programme"] = $row["programme_name"];
                            
                            // Redirect to student dashboard
                            header("Location: student_dashboard.php");
                            exit();
                        } else {
                            $login_err = "Invalid registration number or password.";
                        }
                    }
                } else {
                    $login_err = "Invalid registration number or password.";
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
    <title>Student Login - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
        }
        .login-card {
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
                <a href="student_portal.php" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Student Portal
                </a>
                
                <div class="card login-card">
                    <div class="card-header">
                        <i class="fas fa-graduation-cap fa-2x mb-3"></i>
                        <h2 class="mb-0">Student Login</h2>
                        <p class="mb-0 mt-2 opacity-75">Sign in with your registration number</p>
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
                        
                        if(!empty($login_err)){
                            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>' . htmlspecialchars($login_err) . '
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                  </div>';
                        }        
                        ?>

                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="loginForm">
                            <div class="form-group">
                                <label for="registration_number"><i class="fas fa-id-card mr-2"></i> Registration Number</label>
                                <input type="text" 
                                       id="registration_number"
                                       name="registration_number" 
                                       class="form-control <?php echo (!empty($registration_number_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($registration_number); ?>"
                                       placeholder="e.g., TSU/SCI/CSC/24/0001"
                                       required>
                                <div class="invalid-feedback"><?php echo $registration_number_err; ?></div>
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
                                <a href="student_forgot_password.php">Forgot Password?</a>
                            </div>
                            
                            <div class="text-center mb-3">
                                <p>Don't have an account? <a href="student_signup.php">Sign up here</a></p>
                            </div>
                        </form>
                        
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fas fa-shield-alt mr-1"></i>
                                Secure login for registered students only
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
                loginBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Signing In...');
            });
            
            // Auto-dismiss alerts
            $('.alert').delay(5000).fadeOut();
            
            // Focus on registration number field
            $('#registration_number').focus();
        });
    </script>
</body>
</html>