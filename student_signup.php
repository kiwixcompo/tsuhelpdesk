<?php
// Start output buffering to prevent header issues
ob_start();

session_start();
require_once "config.php";

// Check if student is already logged in
if(isset($_SESSION["student_loggedin"]) && $_SESSION["student_loggedin"] === true){
    header("location: student_dashboard.php");
    exit;
}

$first_name = $middle_name = $last_name = $email = $password = $confirm_password = "";
$faculty_id = $department_id = $programme_id = $year = $last_digits = "";
$first_name_err = $last_name_err = $email_err = $password_err = $confirm_password_err = "";
$faculty_err = $department_err = $programme_err = $year_err = $last_digits_err = $signup_err = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validate first name
    if(empty(trim($_POST["first_name"]))){
        $first_name_err = "Please enter your first name.";
    } else{
        $first_name = trim($_POST["first_name"]);
    }
    
    // Middle name is optional
    $middle_name = trim($_POST["middle_name"]);
    
    // Validate last name
    if(empty(trim($_POST["last_name"]))){
        $last_name_err = "Please enter your last name.";
    } else{
        $last_name = trim($_POST["last_name"]);
    }
    
    // Validate email
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter your email.";
    } elseif(!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)){
        $email_err = "Please enter a valid email address.";
    } else{
        // Check if email already exists
        $sql = "SELECT student_id FROM students WHERE email = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "s", trim($_POST["email"]));
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                if(mysqli_num_rows($result) == 1){
                    $email_err = "This email is already registered.";
                } else{
                    $email = trim($_POST["email"]);
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    // Validate faculty
    if(empty($_POST["faculty_id"])){
        $faculty_err = "Please select your faculty.";
    } else{
        $faculty_id = $_POST["faculty_id"];
    }
    
    // Validate department
    if(empty($_POST["department_id"])){
        $department_err = "Please select your department.";
    } else{
        $department_id = $_POST["department_id"];
    }
    
    // Validate programme
    if(empty($_POST["programme_id"])){
        $programme_err = "Please select your programme.";
    } else{
        $programme_id = $_POST["programme_id"];
    }
    
    // Validate year
    if(empty(trim($_POST["year"]))){
        $year_err = "Please enter your year of admission.";
    } elseif(!preg_match("/^\d{4}$/", trim($_POST["year"]))){
        $year_err = "Year must be exactly 4 digits.";
    } else{
        $year = trim($_POST["year"]);
    }
    
    // Validate last digits (only required for programmes that generate registration numbers)
    if(!empty($_POST["programme_id"])) {
        // Check if this programme needs registration numbers
        $check_sql = "SELECT reg_number_format FROM programmes WHERE programme_id = ?";
        if($check_stmt = mysqli_prepare($conn, $check_sql)) {
            mysqli_stmt_bind_param($check_stmt, "i", $_POST["programme_id"]);
            if(mysqli_stmt_execute($check_stmt)) {
                $check_result = mysqli_stmt_get_result($check_stmt);
                if($check_row = mysqli_fetch_assoc($check_result)) {
                    $reg_format_check = $check_row['reg_number_format'];
                    
                    if($reg_format_check !== 'N/A' && !empty($reg_format_check)) {
                        // Registration numbers are required for this programme
                        if(empty(trim($_POST["last_digits"]))){
                            $last_digits_err = "Please enter the last 4 digits of your student registration number.";
                        } elseif(!preg_match("/^\d{4}$/", trim($_POST["last_digits"]))){
                            $last_digits_err = "Last digits must be exactly 4 numbers.";
                        } else{
                            $last_digits = trim($_POST["last_digits"]);
                        }
                    } else {
                        // Registration numbers not needed for this programme
                        $last_digits = "0000"; // Default value for programmes without reg numbers
                    }
                }
            }
            mysqli_stmt_close($check_stmt);
        }
    } else {
        // No programme selected, validate normally
        if(empty(trim($_POST["last_digits"]))){
            $last_digits_err = "Please enter the last 4 digits of your student registration number.";
        } elseif(!preg_match("/^\d{4}$/", trim($_POST["last_digits"]))){
            $last_digits_err = "Last digits must be exactly 4 numbers.";
        } else{
            $last_digits = trim($_POST["last_digits"]);
        }
    }
    
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
    
    // Check input errors before inserting in database
    if(empty($first_name_err) && empty($last_name_err) && empty($email_err) && 
       empty($faculty_err) && empty($department_err) && empty($programme_err) && 
       empty($year_err) && empty($last_digits_err) && empty($password_err) && empty($confirm_password_err)){
        
        // Get programme details to generate registration number
        $sql = "SELECT reg_number_format FROM programmes WHERE programme_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $programme_id);
            if(mysqli_stmt_execute($stmt)){
                $result = mysqli_stmt_get_result($stmt);
                if($row = mysqli_fetch_assoc($result)){
                    $reg_format = $row['reg_number_format'];
                    
                    // Check if this programme generates registration numbers
                    if($reg_format === 'N/A' || empty($reg_format)) {
                        // For programmes that don't generate registration numbers
                        $registration_number = 'N/A';
                        
                        // Insert new student without registration number validation
                        $insert_sql = "INSERT INTO students (first_name, middle_name, last_name, email, registration_number, password, faculty_id, department_id, programme_id, year_of_entry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        if($insert_stmt = mysqli_prepare($conn, $insert_sql)){
                            $hashed_password = md5($password);
                            $full_year = $year; // Store full 4-digit year
                            
                            mysqli_stmt_bind_param($insert_stmt, "ssssssiiis", 
                                $first_name, $middle_name, $last_name, $email, 
                                $registration_number, $hashed_password, 
                                $faculty_id, $department_id, $programme_id, $full_year);
                            
                            if(mysqli_stmt_execute($insert_stmt)){
                                // Registration successful, send welcome email
                                $welcome_subject = "Welcome to TSU ICT Help Desk - Student Portal";
                                $welcome_message = "Dear " . $first_name . " " . $last_name . ",\n\n";
                                $welcome_message .= "Welcome to the TSU ICT Help Desk Student Portal!\n\n";
                                $welcome_message .= "Your account has been successfully created.\n";
                                $welcome_message .= "Programme: Registration numbers not applicable for this programme\n";
                                $welcome_message .= "Email: " . $email . "\n\n";
                                $welcome_message .= "You can now login to the student portal to:\n";
                                $welcome_message .= "• Lodge result verification complaints\n";
                                $welcome_message .= "• Track your complaint status\n";
                                $welcome_message .= "• View admin responses\n";
                                $welcome_message .= "• Change your password\n\n";
                                $welcome_message .= "Login URL: https://helpdesk.tsuniversity.edu.ng/student_login.php\n\n";
                                $welcome_message .= "Best regards,\nTSU ICT Help Desk Team";
                                
                                $welcome_headers = "From: TSU ICT Help Desk <noreply@tsuniversity.edu.ng>\r\n";
                                $welcome_headers .= "Reply-To: support@tsuniversity.edu.ng\r\n";
                                $welcome_headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
                                $welcome_headers .= "MIME-Version: 1.0\r\n";
                                $welcome_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                                
                                // Send welcome email (suppress errors to avoid blocking registration)
                                @mail($email, $welcome_subject, $welcome_message, $welcome_headers);
                                
                                // Registration successful, redirect to login
                                header("location: student_login.php?registered=1");
                                exit();
                            } else{
                                $signup_err = "Something went wrong. Please try again.";
                            }
                            mysqli_stmt_close($insert_stmt);
                        }
                    } else {
                        // For programmes that generate registration numbers (CS, ISL, CRS)
                        // Use last 2 digits of the 4-digit year
                        $year_2_digits = substr($year, -2);
                        $registration_number = str_replace('YY', $year_2_digits, $reg_format);
                        $registration_number = str_replace('XXXX', $last_digits, $registration_number);
                        
                        // Check if registration number already exists
                        $check_sql = "SELECT student_id FROM students WHERE registration_number = ?";
                        if($check_stmt = mysqli_prepare($conn, $check_sql)){
                            mysqli_stmt_bind_param($check_stmt, "s", $registration_number);
                            if(mysqli_stmt_execute($check_stmt)){
                                $check_result = mysqli_stmt_get_result($check_stmt);
                                if(mysqli_num_rows($check_result) > 0){
                                    $signup_err = "This registration number already exists. Please check your details.";
                                } else{
                                    // Insert new student
                                    $insert_sql = "INSERT INTO students (first_name, middle_name, last_name, email, registration_number, password, faculty_id, department_id, programme_id, year_of_entry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                    
                                    if($insert_stmt = mysqli_prepare($conn, $insert_sql)){
                                        $hashed_password = md5($password);
                                        $full_year = $year; // Store full 4-digit year
                                        
                                        mysqli_stmt_bind_param($insert_stmt, "ssssssiiis", 
                                            $first_name, $middle_name, $last_name, $email, 
                                            $registration_number, $hashed_password, 
                                            $faculty_id, $department_id, $programme_id, $full_year);
                                        
                                        if(mysqli_stmt_execute($insert_stmt)){
                                            // Registration successful, send welcome email
                                            $welcome_subject = "Welcome to TSU ICT Help Desk - Student Portal";
                                            $welcome_message = "Dear " . $first_name . " " . $last_name . ",\n\n";
                                            $welcome_message .= "Welcome to the TSU ICT Help Desk Student Portal!\n\n";
                                            $welcome_message .= "Your account has been successfully created with the following details:\n";
                                            $welcome_message .= "Registration Number: " . $registration_number . "\n";
                                            $welcome_message .= "Email: " . $email . "\n\n";
                                            $welcome_message .= "You can now login to the student portal to:\n";
                                            $welcome_message .= "• Lodge result verification complaints\n";
                                            $welcome_message .= "• Track your complaint status\n";
                                            $welcome_message .= "• View admin responses\n";
                                            $welcome_message .= "• Change your password\n\n";
                                            $welcome_message .= "Login URL: https://helpdesk.tsuniversity.edu.ng/student_login.php\n\n";
                                            $welcome_message .= "IMPORTANT SECURITY TIPS:\n";
                                            $welcome_message .= "• Keep your login credentials secure\n";
                                            $welcome_message .= "• Change your password regularly\n";
                                            $welcome_message .= "• Never share your account details with others\n\n";
                                            $welcome_message .= "If you have any questions or need assistance, please don't hesitate to contact our support team.\n\n";
                                            $welcome_message .= "Best regards,\nTSU ICT Help Desk Team\n";
                                            $welcome_message .= "Taraba State University\n";
                                            $welcome_message .= "Email: support@tsuniversity.edu.ng";
                                            
                                            $welcome_headers = "From: TSU ICT Help Desk <noreply@tsuniversity.edu.ng>\r\n";
                                            $welcome_headers .= "Reply-To: support@tsuniversity.edu.ng\r\n";
                                            $welcome_headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
                                            $welcome_headers .= "MIME-Version: 1.0\r\n";
                                            $welcome_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                                            
                                            // Send welcome email (suppress errors to avoid blocking registration)
                                            @mail($email, $welcome_subject, $welcome_message, $welcome_headers);
                                            
                                            // Registration successful, redirect to login
                                            header("location: student_login.php?registered=1");
                                            exit();
                                        } else{
                                            $signup_err = "Something went wrong. Please try again.";
                                        }
                                        mysqli_stmt_close($insert_stmt);
                                    }
                                }
                            }
                            mysqli_stmt_close($check_stmt);
                        }
                    }
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Fetch faculties for dropdown
$faculties = [];
$sql = "SELECT * FROM faculties ORDER BY faculty_name";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        $faculties[] = $row;
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
    <title>Student Sign Up - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .signup-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(30, 60, 114, 0.3);
            margin: 2rem 0;
            background: white;
        }
        .card-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            text-align: center;
            padding: 2rem;
        }
        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            border: 2px solid rgba(30, 60, 114, 0.1);
            transition: all 0.3s ease;
            background-color: white;
            color: #495057;
            font-size: 14px;
            height: auto;
            min-height: 45px;
        }
        .form-control:focus {
            border-color: #1e3c72;
            box-shadow: 0 0 0 0.2rem rgba(30, 60, 114, 0.25);
            background-color: white;
        }
        .form-control option {
            color: #495057;
            background-color: white;
            padding: 8px 12px;
        }
        .form-label, label {
            color: #1e3c72;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .btn-primary {
            border-radius: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 60, 114, 0.4);
            background: linear-gradient(135deg, #2a5298 0%, #4a90e2 100%);
        }
        .back-link {
            color: white;
            text-decoration: none;
            margin-bottom: 2rem;
            display: inline-block;
            font-weight: 500;
        }
        .back-link:hover {
            color: #e8f4fd;
            text-decoration: none;
        }
        .reg-number-preview {
            background: linear-gradient(135deg, #e8f4fd 0%, #f8fbff 100%);
            border: 2px solid rgba(30, 60, 114, 0.1);
            padding: 12px 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #1e3c72;
            font-size: 14px;
        }
        .alert {
            border-radius: 10px;
            border: none;
            padding: 16px 20px;
            font-weight: 500;
        }
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        .card-body {
            padding: 2rem;
        }
        h5 {
            color: #1e3c72;
            font-weight: 700;
            border-bottom: 2px solid rgba(30, 60, 114, 0.1);
            padding-bottom: 8px;
            margin-bottom: 1.5rem;
        }
        .form-text {
            color: #6c757d;
            font-size: 12px;
        }
        .invalid-feedback {
            font-size: 13px;
        }
        /* Ensure dropdown text is fully visible */
        select.form-control {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%231e3c72' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
            padding-right: 40px;
            line-height: 1.5;
        }
        select.form-control option {
            padding: 10px 15px;
            line-height: 1.5;
        }
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            .card-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <a href="student_portal.php?back=1" class="back-link">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Student Complaint Portal
                </a>
                
                <div class="card signup-card">
                    <div class="card-header">
                        <i class="fas fa-user-plus fa-2x mb-3"></i>
                        <h2 class="mb-0">Student Registration</h2>
                        <p class="mb-0 mt-2 opacity-75">Create your account to access the system</p>
                    </div>
                    <div class="card-body p-4">
                        <?php 
                        if(!empty($signup_err)){
                            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>' . htmlspecialchars($signup_err) . '
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                  </div>';
                        }        
                        ?>

                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="signupForm">
                            <!-- Personal Information -->
                            <h5 class="mb-3"><i class="fas fa-user mr-2"></i>Personal Information</h5>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="first_name">First Name *</label>
                                        <input type="text" id="first_name" name="first_name" 
                                               class="form-control <?php echo (!empty($first_name_err)) ? 'is-invalid' : ''; ?>" 
                                               value="<?php echo htmlspecialchars($first_name); ?>" required>
                                        <div class="invalid-feedback"><?php echo $first_name_err; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="middle_name">Middle Name</label>
                                        <input type="text" id="middle_name" name="middle_name" 
                                               class="form-control" value="<?php echo htmlspecialchars($middle_name); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="last_name">Last Name *</label>
                                <input type="text" id="last_name" name="last_name" 
                                       class="form-control <?php echo (!empty($last_name_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($last_name); ?>" required>
                                <div class="invalid-feedback"><?php echo $last_name_err; ?></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address *</label>
                                <input type="email" id="email" name="email" 
                                       class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($email); ?>" required>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle mr-1"></i>Please use an email address you have access to
                                </small>
                                <div class="invalid-feedback"><?php echo $email_err; ?></div>
                            </div>
                            
                            <!-- Academic Information -->
                            <div class="mt-5 mb-4">
                                <h5 class="mb-4"><i class="fas fa-graduation-cap mr-2"></i>Academic Information</h5>
                            </div>
                            
                            <div class="form-group mb-4">
                                <label for="faculty_id">Faculty *</label>
                                <select id="faculty_id" name="faculty_id" 
                                        class="form-control <?php echo (!empty($faculty_err)) ? 'is-invalid' : ''; ?>" required>
                                    <option value="">Select Faculty</option>
                                    <?php foreach($faculties as $faculty): ?>
                                        <option value="<?php echo $faculty['faculty_id']; ?>" 
                                                <?php echo ($faculty_id == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback"><?php echo $faculty_err; ?></div>
                            </div>
                            
                            <div class="form-group mb-4">
                                <label for="department_id">Department *</label>
                                <select id="department_id" name="department_id" 
                                        class="form-control <?php echo (!empty($department_err)) ? 'is-invalid' : ''; ?>" required disabled>
                                    <option value="">Select Department</option>
                                </select>
                                <div class="invalid-feedback"><?php echo $department_err; ?></div>
                            </div>
                            
                            <div class="form-group mb-5">
                                <label for="programme_id">Programme *</label>
                                <select id="programme_id" name="programme_id" 
                                        class="form-control <?php echo (!empty($programme_err)) ? 'is-invalid' : ''; ?>" required disabled>
                                    <option value="">Select Programme</option>
                                </select>
                                <div class="invalid-feedback"><?php echo $programme_err; ?></div>
                            </div>
                            
                            <!-- Registration Number -->
                            <h5 class="mb-3 mt-4"><i class="fas fa-id-card mr-2"></i>Registration Number</h5>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="year">What Year Were You Admitted? *</label>
                                        <input type="text" id="year" name="year" 
                                               class="form-control <?php echo (!empty($year_err)) ? 'is-invalid' : ''; ?>" 
                                               value="<?php echo htmlspecialchars($year); ?>" 
                                               placeholder="e.g., 2026" maxlength="4" required>
                                        <div class="invalid-feedback"><?php echo $year_err; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6" id="lastDigitsGroup">
                                    <div class="form-group">
                                        <label for="last_digits">Last 4 Digits of Your Registration Number *</label>
                                        <input type="text" id="last_digits" name="last_digits" 
                                               class="form-control <?php echo (!empty($last_digits_err)) ? 'is-invalid' : ''; ?>" 
                                               value="<?php echo htmlspecialchars($last_digits); ?>" 
                                               placeholder="e.g., 0001" maxlength="4" required>
                                        <div class="invalid-feedback"><?php echo $last_digits_err; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Registration Number Preview:</label>
                                <div class="reg-number-preview" id="regNumberPreview">
                                    Select programme and enter details to see preview
                                </div>
                            </div>
                            
                            <!-- Password -->
                            <h5 class="mb-3 mt-4"><i class="fas fa-lock mr-2"></i>Account Security</h5>
                            
                            <div class="form-group">
                                <label for="password">Password *</label>
                                <input type="password" id="password" name="password" 
                                       class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" required>
                                <small class="form-text text-muted">Minimum 6 characters</small>
                                <div class="invalid-feedback"><?php echo $password_err; ?></div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password" 
                                       class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" required>
                                <div class="invalid-feedback"><?php echo $confirm_password_err; ?></div>
                            </div>
                            
                            <div class="form-group mb-4 mt-4">
                                <button type="submit" class="btn btn-primary btn-block" id="signupBtn">
                                    <i class="fas fa-user-plus mr-2"></i> Create Account
                                </button>
                            </div>
                            
                            <div class="text-center">
                                <p>Already have an account? <a href="student_login.php">Login here</a></p>
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
            let currentRegFormat = '';
            
            // Faculty change handler
            $('#faculty_id').change(function() {
                const facultyId = $(this).val();
                $('#department_id').prop('disabled', true).html('<option value="">Loading...</option>');
                $('#programme_id').prop('disabled', true).html('<option value="">Select Programme</option>');
                updateRegNumberPreview();
                
                if(facultyId) {
                    $.ajax({
                        url: 'api/get_departments.php',
                        type: 'POST',
                        data: {faculty_id: facultyId},
                        dataType: 'json',
                        success: function(response) {
                            console.log('Departments response:', response);
                            
                            if(response.error) {
                                console.error('API Error:', response.error);
                                $('#department_id').html('<option value="">Error: ' + response.error + '</option>');
                                return;
                            }
                            
                            let options = '<option value="">Select Department</option>';
                            if(Array.isArray(response) && response.length > 0) {
                                response.forEach(function(dept) {
                                    options += `<option value="${dept.department_id}">${dept.department_name}</option>`;
                                });
                            } else {
                                options = '<option value="">No departments found</option>';
                            }
                            $('#department_id').html(options).prop('disabled', false);
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', status, error);
                            console.error('Response:', xhr.responseText);
                            $('#department_id').html('<option value="">Error loading departments</option>');
                        }
                    });
                } else {
                    $('#department_id').prop('disabled', true).html('<option value="">Select Department</option>');
                }
            });
            
            // Department change handler
            $('#department_id').change(function() {
                const departmentId = $(this).val();
                $('#programme_id').prop('disabled', true).html('<option value="">Loading...</option>');
                updateRegNumberPreview();
                
                if(departmentId) {
                    $.ajax({
                        url: 'api/get_programmes.php',
                        type: 'POST',
                        data: {department_id: departmentId},
                        dataType: 'json',
                        success: function(response) {
                            let options = '<option value="">Select Programme</option>';
                            response.forEach(function(prog) {
                                options += `<option value="${prog.programme_id}" data-format="${prog.reg_number_format}">${prog.programme_name}</option>`;
                            });
                            $('#programme_id').html(options).prop('disabled', false);
                        },
                        error: function() {
                            $('#programme_id').html('<option value="">Error loading programmes</option>');
                        }
                    });
                } else {
                    $('#programme_id').prop('disabled', true).html('<option value="">Select Programme</option>');
                }
            });
            
            // Programme change handler
            $('#programme_id').change(function() {
                const selectedOption = $(this).find('option:selected');
                currentRegFormat = selectedOption.data('format') || '';
                
                // Show/hide last digits field based on programme
                if(currentRegFormat === 'N/A') {
                    $('#lastDigitsGroup').hide();
                    $('#last_digits').prop('required', false);
                } else {
                    $('#lastDigitsGroup').show();
                    $('#last_digits').prop('required', true);
                }
                
                updateRegNumberPreview();
            });
            
            // Year and last digits change handlers
            $('#year, #last_digits').on('input', function() {
                updateRegNumberPreview();
            });
            
            // Update registration number preview
            function updateRegNumberPreview() {
                if(currentRegFormat && $('#year').val() && $('#last_digits').val()) {
                    if(currentRegFormat === 'N/A') {
                        $('#regNumberPreview').text('Registration numbers not applicable for this programme');
                        $('#regNumberPreview').addClass('text-info');
                    } else {
                        let preview = currentRegFormat;
                        preview = preview.replace('YY', $('#year').val().slice(-2)); // Use last 2 digits of 4-digit year
                        preview = preview.replace('XXXX', $('#last_digits').val());
                        $('#regNumberPreview').text(preview);
                        $('#regNumberPreview').removeClass('text-info');
                    }
                } else {
                    $('#regNumberPreview').text('Select programme and enter details to see preview');
                    $('#regNumberPreview').removeClass('text-info');
                }
            }
            
            // Form validation
            $('#year').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '').substring(0, 4);
            });
            
            $('#last_digits').on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '').substring(0, 4);
            });
            
            // Form submission
            $('#signupForm').submit(function() {
                const signupBtn = $('#signupBtn');
                signupBtn.prop('disabled', true);
                signupBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating Account...');
            });
            
            // Auto-dismiss alerts
            $('.alert').delay(5000).fadeOut();
        });
    </script>
</body>
</html>