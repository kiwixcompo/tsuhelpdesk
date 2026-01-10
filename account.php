<?php
// Include the header with dynamic branding
require_once "includes/header.php";

// Initialize notification variables for navbar
$notification_count = 0;
$unread_count = 0;

$success_message = $error_message = "";

// Fetch current user data
$user_data = [];
$sql = "SELECT * FROM users WHERE user_id = ?";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            $user_data = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Always fetch the latest email and phone for the user before rendering the reminder
$sql = "SELECT email, phone FROM users WHERE user_id = ?";
if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)){
            $user_data['email'] = $row['email'];
            $user_data['phone'] = $row['phone'];
        }
    }
    mysqli_stmt_close($stmt);
}

// Process profile update
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_profile"])){
    $full_name = trim($_POST["full_name"]);
    $email = trim($_POST["email"]);
    $phone = trim($_POST["phone"]);
    
    // Prevent department users from changing their full name (department name)
    if($_SESSION["role_id"] == 7) {
        $full_name = $user_data['full_name']; // Keep original department name
    }
    
    // Handle profile photo upload
    $profile_photo = $user_data['profile_photo'];
    if(isset($_FILES["profile_photo"]) && $_FILES["profile_photo"]["error"] == 0){
        $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
        $filename = $_FILES["profile_photo"]["name"];
        $filetype = $_FILES["profile_photo"]["type"];
        $filesize = $_FILES["profile_photo"]["size"];
    
        // Verify file extension
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if(!array_key_exists($ext, $allowed)) {
            $error_message = "Error: Please select a valid image file format.";
        }
    
        // Verify file size - 5MB maximum
        $maxsize = 5 * 1024 * 1024;
        if($filesize > $maxsize) {
            $error_message = "Error: File size is larger than the allowed limit (5MB).";
        }
    
        if(empty($error_message)){
            $target_dir = "uploads/profiles/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $new_filename = uniqid() . "." . $ext;
            $target_file = $target_dir . $new_filename;
            
            if(move_uploaded_file($_FILES["profile_photo"]["tmp_name"], $target_file)){
                // Delete old profile photo if exists
                if($profile_photo && file_exists($profile_photo)){
                    unlink($profile_photo);
                }
                $profile_photo = $target_file;
            }
        }
    }
    
    if(empty($error_message)){
        $sql = "UPDATE users SET full_name = ?, email = ?, phone = ?, profile_photo = ? WHERE user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "ssssi", $full_name, $email, $phone, $profile_photo, $_SESSION["user_id"]);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "Profile updated successfully.";
                // Refresh user data from database
                $sql_refresh = "SELECT * FROM users WHERE user_id = ?";
                if($stmt_refresh = mysqli_prepare($conn, $sql_refresh)){
                    mysqli_stmt_bind_param($stmt_refresh, "i", $_SESSION["user_id"]);
                    if(mysqli_stmt_execute($stmt_refresh)){
                        $result_refresh = mysqli_stmt_get_result($stmt_refresh);
                        if($row_refresh = mysqli_fetch_assoc($result_refresh)){
                            $user_data = $row_refresh;
                            // Optionally update session variables if used elsewhere
                            $_SESSION['full_name'] = $user_data['full_name'];
                            $_SESSION['email'] = $user_data['email'];
                            $_SESSION['phone'] = $user_data['phone'];
                            $_SESSION['profile_photo'] = $user_data['profile_photo'];
                        }
                    }
                    mysqli_stmt_close($stmt_refresh);
                }
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Process password change
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["change_password"])){
    $current_password = trim($_POST["current_password"]);
    $new_password = trim($_POST["new_password"]);
    $confirm_password = trim($_POST["confirm_password"]);
    
    // Validate current password
    $sql = "SELECT password FROM users WHERE user_id = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)){
                if(md5($current_password) !== $row['password']){
                    $error_message = "Current password is incorrect.";
                }
            }
        }
        mysqli_stmt_close($stmt);
    }
    
    // Validate new password
    if(empty($error_message)){
        if(strlen($new_password) < 6){
            $error_message = "New password must have at least 6 characters.";
        } elseif($new_password !== $confirm_password){
            $error_message = "New passwords do not match.";
        } else {
            $md5_password = md5($new_password);
            $sql = "UPDATE users SET password = ? WHERE user_id = ?";
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "si", $md5_password, $_SESSION["user_id"]);
                
                if(mysqli_stmt_execute($stmt)){
                    $success_message = "Password changed successfully.";
                } else{
                    $error_message = "Something went wrong. Please try again later.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Helper function to get dashboard URL by role
function getDashboardUrlByRole($role_id) {
    switch ($role_id) {
        case 1: return 'dashboard.php'; // Admin
        case 3: return 'director_dashboard.php';
        case 4: return 'dvc_dashboard.php';
        case 5: return 'i4cus_staff_dashboard.php';
        case 6: return 'payment_admin_dashboard.php';
        case 7: return 'department_dashboard.php'; // Department
        default: return 'dashboard.php';
    }
}
?>

<script>
    // Update page title to be more specific
    document.title = "Account Settings - <?php echo htmlspecialchars($app_name); ?>";
</script>

<?php include 'includes/navbar.php'; ?>

<div class="container mt-4">
    <?php if(empty($user_data['email']) || empty($user_data['phone'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <strong>Reminder:</strong> Please update your profile with a valid email address and phone number. These are required for future password recovery.
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
    <?php endif; ?>
    <!-- Page Header with App Branding -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex align-items-center">
                <i class="fas fa-user-cog fa-2x text-primary mr-3"></i>
                <div>
                    <h2 class="mb-0">Account Settings</h2>
                    <small class="text-muted">Manage your profile and security settings</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-body text-center">
                    <?php if($user_data['profile_photo']): ?>
                        <?php 
                        // Get just the filename from the path
                        $profile_filename = basename($user_data['profile_photo']);
                        $profile_image_url = 'public_image.php?img=' . urlencode($profile_filename);
                        ?>
                        <img src="<?php echo htmlspecialchars($profile_image_url); ?>" 
                             class="rounded-circle mb-3" style="width: 150px; height: 150px; object-fit: cover;"
                             alt="Profile Photo">
                    <?php else: ?>
                        <div class="rounded-circle mb-3 bg-secondary d-flex align-items-center justify-content-center mx-auto" 
                             style="width: 150px; height: 150px;">
                            <i class="fas fa-user fa-4x text-white"></i>
                        </div>
                    <?php endif; ?>
                    <h4><?php echo htmlspecialchars($user_data['full_name']); ?></h4>
                    <p class="text-muted">
                        <i class="fas fa-user-tag"></i>
                        <?php echo $_SESSION["role_id"] == 1 ? "Administrator" : "Staff"; ?>
                        <?php echo $_SESSION["is_super_admin"] ? " (Super Admin)" : ""; ?>
                    </p>
                    <p class="text-muted">
                        <i class="fas fa-envelope"></i> 
                        <?php echo htmlspecialchars($user_data['email'] ?? 'No email set'); ?>
                    </p>
                    <?php if($user_data['phone']): ?>
                    <p class="text-muted">
                        <i class="fas fa-phone"></i> 
                        <?php echo htmlspecialchars($user_data['phone']); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <?php if($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            <?php if($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <h4><i class="fas fa-user-edit"></i> Profile Information</h4>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name</label>
                            <?php if($_SESSION["role_id"] == 7): ?>
                                <input type="text" name="full_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($user_data['full_name']); ?>" 
                                       readonly style="background-color: #f8f9fa; cursor: not-allowed;" required>
                                <small class="form-text text-muted">
                                    <i class="fas fa-info-circle"></i> Department name cannot be changed
                                </small>
                            <?php else: ?>
                                <input type="text" name="full_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone Number</label>
                            <input type="tel" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-camera"></i> Profile Photo</label>
                            <input type="file" name="profile_photo" class="form-control-file" accept="image/*">
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle"></i> 
                                Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB)
                            </small>
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-lock"></i> Change Password</h4>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="form-group">
                            <label><i class="fas fa-unlock"></i> Current Password</label>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> New Password</label>
                            <input type="password" name="new_password" class="form-control" required>
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle"></i> 
                                Password must be at least 6 characters long
                            </small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-check-double"></i> Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-primary">
                            <i class="fas fa-save"></i> Change Password
                        </button>
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
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
    
    // Preview profile photo before upload
    $('input[name="profile_photo"]').change(function() {
        var file = this.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                // You can add image preview functionality here if needed
                console.log('New image selected: ' + file.name);
            }
            reader.readAsDataURL(file);
        }
    });
</script>

</body>
</html>