<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Check if user is logged in and is admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 1){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";

// Initialize notification count
$notification_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
}

// Fetch app settings
$app_name = 'TSU ICT Help Desk'; // Default value
$app_logo = '';
$app_favicon = '';

$sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('app_name', 'app_logo', 'app_favicon')";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        switch($row['setting_key']) {
            case 'app_name':
                $app_name = $row['setting_value'] ?: 'TSU ICT Help Desk';
                break;
            case 'app_logo':
                $app_logo = $row['setting_value'];
                break;
            case 'app_favicon':
                $app_favicon = $row['setting_value'];
                break;
        }
    }
}

// Process user creation (super admin only)
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["create_user"]) && $_SESSION["is_super_admin"]){
    $username = trim($_POST["username"]);
    $password = md5(trim($_POST["password"]));
    $full_name = trim($_POST["full_name"]);
    $role_id = $_POST["role_id"];
    
    $sql = "INSERT INTO users (username, password, full_name, role_id) VALUES (?, ?, ?, ?)";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "sssi", $username, $password, $full_name, $role_id);
        
        if(mysqli_stmt_execute($stmt)){
            $success_message = "User created successfully.";
        } else{
            $error_message = "Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Process user deletion (super admin only)
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_user"]) && $_SESSION["is_super_admin"]){
    $user_id = $_POST["user_id"];
    
    // Prevent super admin from deleting themselves
    if($user_id != $_SESSION["user_id"]){
        $sql = "DELETE FROM users WHERE user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "User deleted successfully.";
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "You cannot delete your own account.";
    }
}

// Process user password reset (super admin only)
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["reset_password"]) && $_SESSION["is_super_admin"]){
    $user_id = $_POST["user_id"];
    $new_password = trim($_POST["new_password"]);
    
    if(!empty($new_password)){
        $sql = "UPDATE users SET password = ? WHERE user_id = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            $hashed_password = md5($new_password);
            mysqli_stmt_bind_param($stmt, "si", $hashed_password, $user_id);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "Password reset successfully.";
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please enter a new password.";
    }
}

// Fetch all users
$users = [];
$sql = "SELECT u.*, r.role_name FROM users u 
        JOIN roles r ON u.role_id = r.role_id 
        ORDER BY u.created_at DESC";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        $users[] = $row;
    }
}

// Fetch roles for dropdown
$roles = [];
$sql = "SELECT * FROM roles";
$result = mysqli_query($conn, $sql);
while($row = mysqli_fetch_assoc($result)){
    $roles[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?php echo htmlspecialchars($app_name); ?></title>
    
    <!-- Dynamic Favicon -->
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .app-branding {
            display: flex;
            align-items: center;
        }
        .app-logo {
            height: 40px;
            margin-right: 10px;
            object-fit: contain;
        }
        .app-name {
            font-size: 1.25rem;
            font-weight: bold;
        }
        
        /* Autocomplete Search Styles */
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .search-result-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .search-result-item:hover {
            background-color: #f8f9fa;
        }
        
        .search-result-item:last-child {
            border-bottom: none;
        }
        
        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-details h6 {
            margin: 0;
            font-weight: 600;
            color: #333;
        }
        
        .user-details small {
            color: #666;
        }
        
        .user-actions {
            display: flex;
            gap: 5px;
        }
        
        .user-actions .btn {
            padding: 4px 8px;
            font-size: 0.8rem;
        }
        
        /* User Detail Modal Styles */
        .user-detail-card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .user-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: bold;
            margin: 0 auto 20px;
        }
        
        .action-buttons .btn {
            margin: 5px;
            min-width: 120px;
        }
        
        .no-results {
            padding: 15px;
            text-align: center;
            color: #666;
            font-style: italic;
        }
        
        /* Performance optimizations for Chrome */
        .table {
            table-layout: fixed;
            width: 100%;
        }
        
        .table td, .table th {
            word-wrap: break-word;
            vertical-align: middle;
        }
        
        /* Prevent layout shifts */
        .search-results {
            will-change: transform;
            transform: translateZ(0);
        }
        
        .modal {
            will-change: transform;
            transform: translateZ(0);
        }
        
        /* Smooth transitions */
        .user-checkbox {
            transition: none;
        }
        
        .btn {
            transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out;
        }
        
        /* Bulk actions styling */
        .bulk-actions {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
        }
        
        #selectedCount {
            font-weight: bold;
            color: #007bff;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <?php
    // Set up users header variables
    $page_title = 'User Management';
    $page_subtitle = 'Manage staff accounts and permissions';
    $page_icon = 'fas fa-users';
    $show_breadcrumb = true;
    $breadcrumb_items = [
        ['title' => 'Admin', 'url' => 'admin.php'],
        ['title' => 'User Management', 'url' => '']
    ];
    
    include 'includes/dashboard_header.php';
    ?>

    <div class="container-fluid">
        <?php if(isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if(isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row">
            <?php if(isset($_SESSION["is_super_admin"]) && $_SESSION["is_super_admin"]): ?>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h4>Create New User</h4>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role_id" class="form-control" required>
                                <?php foreach($roles as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>"><?php echo $role['role_name']; ?></option>
                                <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="create_user" class="btn btn-primary">Create User</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="col-md-<?php echo $_SESSION["is_super_admin"] ? '8' : '12'; ?>">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4>User Management</h4>
                            <?php if($_SESSION["is_super_admin"]): ?>
                            <div class="search-container" style="position: relative; width: 300px;">
                                <div class="input-group">
                                    <input type="text" id="userSearch" class="form-control" placeholder="Search users..." autocomplete="off">
                                    <div class="input-group-append">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    </div>
                                </div>
                                <div id="searchResults" class="search-results" style="display: none;"></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Bulk Actions -->
                        <?php if($_SESSION["is_super_admin"]): ?>
                        <div class="mb-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllUsers()">
                                            <i class="fas fa-check-square"></i> Select All
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllUsers()">
                                            <i class="fas fa-square"></i> Deselect All
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6 text-right">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-warning" onclick="bulkResetPasswords()" disabled id="bulkResetBtn">
                                            <i class="fas fa-key"></i> Reset Selected Passwords
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="bulkDeleteUsers()" disabled id="bulkDeleteBtn">
                                            <i class="fas fa-trash"></i> Delete Selected
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted">
                                    <span id="selectedCount">0</span> user(s) selected
                                </small>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <?php if($_SESSION["is_super_admin"]): ?>
                                        <th width="40">
                                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleAllUsers(this)">
                                        </th>
                                        <?php endif; ?>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Created</th>
                                        <?php if($_SESSION["is_super_admin"]): ?>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($users as $user): ?>
                                        <tr>
                                            <?php if($_SESSION["is_super_admin"]): ?>
                                            <td>
                                                <?php if($user['user_id'] != $_SESSION["user_id"]): ?>
                                                <input type="checkbox" class="user-checkbox" value="<?php echo $user['user_id']; ?>" onchange="updateBulkActions()">
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <?php if($_SESSION["is_super_admin"] && $user['user_id'] != $_SESSION["user_id"]): ?>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-warning" data-toggle="modal" 
                                                        data-target="#resetPasswordModal<?php echo $user['user_id']; ?>">
                                                    Reset Password
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" 
                                                        data-target="#deleteUserModal<?php echo $user['user_id']; ?>">
                                                    Delete
                                                </button>
                                            </td>
                                            <?php endif; ?>
                                        </tr>

                                        <?php if($_SESSION["is_super_admin"] && $user['user_id'] != $_SESSION["user_id"]): ?>
                                        <!-- Reset Password Modal -->
                                        <div class="modal fade" id="resetPasswordModal<?php echo $user['user_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Reset Password for <?php echo htmlspecialchars($user['full_name']); ?></h5>
                                                        <button type="button" class="close" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <form method="post">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                            <div class="form-group">
                                                                <label>New Password</label>
                                                                <input type="password" name="new_password" class="form-control" required>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="reset_password" class="btn btn-warning">Reset Password</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Delete User Modal -->
                                        <div class="modal fade" id="deleteUserModal<?php echo $user['user_id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Delete User</h5>
                                                        <button type="button" class="close" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Are you sure you want to delete user <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>?</p>
                                                        <p class="text-danger">This action cannot be undone.</p>
                                                    </div>
                                                    <form method="post">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="delete_user" class="btn btn-danger">Delete User</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Detail Modal -->
    <div class="modal fade" id="userDetailModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">User Details & Actions</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="userDetailContent">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Direct Message Modal -->
    <div class="modal fade" id="directMessageModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Send Direct Message</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form id="directMessageForm">
                    <div class="modal-body">
                        <input type="hidden" id="messageRecipientId" name="recipient_id">
                        <div class="form-group">
                            <label>To:</label>
                            <input type="text" id="messageRecipientName" class="form-control" readonly>
                        </div>
                        <div class="form-group">
                            <label>Subject:</label>
                            <input type="text" id="messageSubject" name="subject" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Message:</label>
                            <textarea id="messageContent" name="message" class="form-control" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Reset Password Modal -->
    <div class="modal fade" id="bulkResetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Reset Passwords</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form id="bulkResetForm">
                    <div class="modal-body">
                        <p>Are you sure you want to reset passwords for <span id="resetUserCount">0</span> selected user(s)?</p>
                        <div class="form-group">
                            <label>New Password (will be applied to all selected users)</label>
                            <input type="password" id="bulkNewPassword" class="form-control" required>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Warning:</strong> This will reset passwords for all selected users. Make sure to inform them of their new password.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Reset Passwords</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Delete Modal -->
    <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Delete Users</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <span id="deleteUserCount">0</span> selected user(s)?</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> This action cannot be undone. All selected users and their data will be permanently deleted.
                    </div>
                    <div id="selectedUsersList"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmBulkDelete()">Delete Users</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
    $(document).ready(function() {
        let searchTimeout;
        
        // Autocomplete search functionality
        $('#userSearch').on('input', function() {
            const query = $(this).val().trim();
            
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                $('#searchResults').hide();
                return;
            }
            
            searchTimeout = setTimeout(function() {
                searchUsers(query);
            }, 300);
        });
        
        // Hide search results when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.search-container').length) {
                $('#searchResults').hide();
            }
        });
        
        // Direct message form submission
        $('#directMessageForm').on('submit', function(e) {
            e.preventDefault();
            sendDirectMessage();
        });
        
        // Bulk reset password form submission
        $('#bulkResetForm').on('submit', function(e) {
            e.preventDefault();
            processBulkPasswordReset();
        });
    });
    
    // Bulk selection functions
    function toggleAllUsers(checkbox) {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
        });
        updateBulkActions();
    }
    
    function selectAllUsers() {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = true;
        });
        document.getElementById('selectAllCheckbox').checked = true;
        updateBulkActions();
    }
    
    function deselectAllUsers() {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = false;
        });
        document.getElementById('selectAllCheckbox').checked = false;
        updateBulkActions();
    }
    
    function updateBulkActions() {
        const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
        const count = selectedCheckboxes.length;
        
        document.getElementById('selectedCount').textContent = count;
        document.getElementById('bulkResetBtn').disabled = count === 0;
        document.getElementById('bulkDeleteBtn').disabled = count === 0;
        
        // Update select all checkbox state
        const allCheckboxes = document.querySelectorAll('.user-checkbox');
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        
        if (count === 0) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = false;
        } else if (count === allCheckboxes.length) {
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.indeterminate = true;
        }
    }
    
    function bulkResetPasswords() {
        const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
        if (selectedCheckboxes.length === 0) {
            alert('Please select at least one user.');
            return;
        }
        
        document.getElementById('resetUserCount').textContent = selectedCheckboxes.length;
        document.getElementById('bulkNewPassword').value = '';
        $('#bulkResetPasswordModal').modal('show');
    }
    
    function bulkDeleteUsers() {
        const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
        if (selectedCheckboxes.length === 0) {
            alert('Please select at least one user.');
            return;
        }
        
        document.getElementById('deleteUserCount').textContent = selectedCheckboxes.length;
        
        // Show list of selected users
        let usersList = '<ul class="list-group">';
        selectedCheckboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const fullName = row.cells[1].textContent; // Adjust index based on checkbox column
            const username = row.cells[2].textContent;
            usersList += `<li class="list-group-item">${fullName} (@${username})</li>`;
        });
        usersList += '</ul>';
        
        document.getElementById('selectedUsersList').innerHTML = usersList;
        $('#bulkDeleteModal').modal('show');
    }
    
    function processBulkPasswordReset() {
        const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
        const newPassword = document.getElementById('bulkNewPassword').value;
        
        if (!newPassword) {
            alert('Please enter a new password.');
            return;
        }
        
        const userIds = Array.from(selectedCheckboxes).map(cb => cb.value);
        
        // Show loading state
        const submitBtn = document.querySelector('#bulkResetForm button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
        submitBtn.disabled = true;
        
        // Process each user
        let completed = 0;
        let errors = [];
        
        userIds.forEach(userId => {
            const formData = new FormData();
            formData.append('reset_password', '1');
            formData.append('user_id', userId);
            formData.append('new_password', newPassword);
            
            fetch('users.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                completed++;
                if (completed === userIds.length) {
                    finalizeBulkReset(errors, originalText, submitBtn);
                }
            })
            .catch(error => {
                errors.push(`User ID ${userId}: ${error.message}`);
                completed++;
                if (completed === userIds.length) {
                    finalizeBulkReset(errors, originalText, submitBtn);
                }
            });
        });
    }
    
    function finalizeBulkReset(errors, originalText, submitBtn) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        if (errors.length === 0) {
            alert('Passwords reset successfully for all selected users!');
            $('#bulkResetPasswordModal').modal('hide');
            location.reload();
        } else {
            alert('Some errors occurred:\n' + errors.join('\n'));
        }
    }
    
    function confirmBulkDelete() {
        const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
        const userIds = Array.from(selectedCheckboxes).map(cb => cb.value);
        
        // Show loading state
        const deleteBtn = document.querySelector('#bulkDeleteModal .btn-danger');
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        deleteBtn.disabled = true;
        
        // Process each user
        let completed = 0;
        let errors = [];
        
        userIds.forEach(userId => {
            const formData = new FormData();
            formData.append('delete_user', '1');
            formData.append('user_id', userId);
            
            fetch('users.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                completed++;
                if (completed === userIds.length) {
                    finalizeBulkDelete(errors, originalText, deleteBtn);
                }
            })
            .catch(error => {
                errors.push(`User ID ${userId}: ${error.message}`);
                completed++;
                if (completed === userIds.length) {
                    finalizeBulkDelete(errors, originalText, deleteBtn);
                }
            });
        });
    }
    
    function finalizeBulkDelete(errors, originalText, deleteBtn) {
        deleteBtn.innerHTML = originalText;
        deleteBtn.disabled = false;
        
        if (errors.length === 0) {
            alert('Users deleted successfully!');
            $('#bulkDeleteModal').modal('hide');
            location.reload();
        } else {
            alert('Some errors occurred:\n' + errors.join('\n'));
        }
    }
    
    function searchUsers(query) {
        $.ajax({
            url: 'api/search_users.php',
            type: 'GET',
            data: { q: query },
            dataType: 'json',
            success: function(response) {
                displaySearchResults(response.users);
            },
            error: function() {
                $('#searchResults').html('<div class="no-results">Error searching users</div>').show();
            }
        });
    }
    
    function displaySearchResults(users) {
        const resultsContainer = $('#searchResults');
        
        if (users.length === 0) {
            resultsContainer.html('<div class="no-results">No users found</div>').show();
            return;
        }
        
        let html = '';
        users.forEach(function(user) {
            const initials = user.full_name.split(' ').map(n => n[0]).join('').toUpperCase();
            const joinDate = new Date(user.created_at).toLocaleDateString();
            
            html += `
                <div class="search-result-item" onclick="showUserDetails(${user.user_id})">
                    <div class="user-info">
                        <div class="user-details">
                            <h6>${escapeHtml(user.full_name)}</h6>
                            <small class="text-muted">@${escapeHtml(user.username)} • ${escapeHtml(user.role_name)}</small>
                        </div>
                        <div class="user-actions">
                            <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); showUserDetails(${user.user_id})">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        
        resultsContainer.html(html).show();
    }
    
    function showUserDetails(userId) {
        $('#searchResults').hide();
        $('#userSearch').val('');
        
        // Show loading state
        $('#userDetailContent').html(`
            <div class="text-center">
                <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                <p>Loading user details...</p>
            </div>
        `);
        $('#userDetailModal').modal('show');
        
        // Fetch user details
        $.ajax({
            url: 'api/get_user_details_simple.php',
            type: 'GET',
            data: { id: userId },
            dataType: 'json',
            timeout: 10000,
            success: function(response) {
                console.log('User details response:', response);
                if (response && response.success) {
                    loadUserDetails(response.user);
                } else {
                    let errorMsg = response.error || 'Failed to load user details';
                    let debugInfo = '';
                    
                    if (response.debug) {
                        debugInfo = '<br><small>Debug: ' + JSON.stringify(response.debug) + '</small>';
                    }
                    
                    $('#userDetailContent').html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> ${errorMsg}${debugInfo}
                            <br><br>
                            <button class="btn btn-sm btn-secondary" onclick="location.reload()">Refresh Page</button>
                        </div>
                    `);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error, xhr.responseText);
                
                let errorMsg = 'Error loading user details';
                let debugInfo = '';
                
                if (status === 'timeout') {
                    errorMsg = 'Request timed out';
                } else if (xhr.status === 401) {
                    errorMsg = 'Session expired - please login again';
                } else if (xhr.status === 403) {
                    errorMsg = 'Access denied - insufficient permissions';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error';
                }
                
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.error) {
                        errorMsg = response.error;
                    }
                    if (response.debug) {
                        debugInfo = '<br><small>Debug: ' + JSON.stringify(response.debug) + '</small>';
                    }
                } catch (e) {
                    debugInfo = '<br><small>Status: ' + status + ', Error: ' + error + '</small>';
                }
                
                $('#userDetailContent').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> ${errorMsg}${debugInfo}
                        <br><br>
                        <button class="btn btn-sm btn-secondary" onclick="location.reload()">Refresh Page</button>
                        <button class="btn btn-sm btn-info" onclick="window.open('diagnose_database.php', '_blank')">Diagnose DB</button>
                        <button class="btn btn-sm btn-success" onclick="window.open('create_messages_simple.php', '_blank')">Fix Messages</button>
                        <button class="btn btn-sm btn-primary" onclick="window.open('setup_database_tables.php', '_blank')">Setup All</button>
                    </div>
                `);
            }
        });
    }
    
    function loadUserDetails(user) {
        const initials = user.full_name.split(' ').map(n => n[0]).join('').toUpperCase();
        const joinDate = new Date(user.created_at).toLocaleDateString();
        const lastLogin = 'Not tracked'; // last_login column doesn't exist in database
        
        let activitiesHtml = '';
        if (user.recent_activities && user.recent_activities.length > 0) {
            activitiesHtml = user.recent_activities.map(activity => {
                const activityIcon = getActivityIcon(activity.type);
                const activityColor = getActivityColor(activity.type);
                return `
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="fas ${activityIcon} ${activityColor} mr-2"></i>
                                    <strong>${activity.action}</strong>
                                </div>
                                ${activity.details ? `<small class="text-muted">${escapeHtml(activity.details)}</small>` : ''}
                            </div>
                            <small class="text-muted">${new Date(activity.created_at).toLocaleDateString()}</small>
                        </div>
                    </li>
                `;
            }).join('');
        } else {
            activitiesHtml = '<li class="list-group-item text-muted text-center">No recent activity</li>';
        }
        
        // Activity statistics
        let statsHtml = '';
        if (user.activity_stats) {
            const stats = user.activity_stats;
            statsHtml = `
                <div class="row text-center mb-3">
                    <div class="col">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${stats.messages_sent || 0}</h6>
                                <small class="text-muted">Messages Sent</small>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${stats.messages_received || 0}</h6>
                                <small class="text-muted">Messages Received</small>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${stats.replies_sent || 0}</h6>
                                <small class="text-muted">Replies Sent</small>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${stats.unread_notifications || 0}</h6>
                                <small class="text-muted">Unread Notifications</small>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        const userDetailContent = `
            <div class="row">
                <div class="col-md-4 text-center">
                    <div class="user-avatar">${initials}</div>
                    <h5>${escapeHtml(user.full_name)}</h5>
                    <p class="text-muted">@${escapeHtml(user.username)}</p>
                    <span class="badge badge-primary">${escapeHtml(user.role_name)}</span>
                </div>
                <div class="col-md-8">
                    <h6>User Information</h6>
                    <table class="table table-sm">
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td>${user.email ? escapeHtml(user.email) : 'Not provided'}</td>
                        </tr>
                        <tr>
                            <td><strong>Joined:</strong></td>
                            <td>${joinDate}</td>
                        </tr>
                        <tr>
                            <td><strong>Last Login:</strong></td>
                            <td>${lastLogin}</td>
                        </tr>
                        <tr>
                            <td><strong>Complaints Lodged:</strong></td>
                            <td>${user.complaint_count}</td>
                        </tr>
                        <tr>
                            <td><strong>Complaints Handled:</strong></td>
                            <td>${user.handled_count}</td>
                        </tr>
                    </table>
                    
                    <h6 class="mt-3">Activity Statistics</h6>
                    ${statsHtml}
                    
                    <h6 class="mt-3">Recent Activity</h6>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <ul class="list-group list-group-flush">
                            ${activitiesHtml}
                        </ul>
                    </div>
                    
                    <h6 class="mt-3">Login Activities</h6>
                    <div id="loginActivities-${user.user_id}" style="max-height: 250px; overflow-y: auto;">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Loading login activities...
                        </div>
                    </div>
                </div>
            </div>
            
            <hr>
            
            <div class="text-center action-buttons">
                <button class="btn btn-primary" onclick="openDirectMessage(${user.user_id}, '${escapeHtml(user.full_name)}')">
                    <i class="fas fa-envelope"></i> Send Message
                </button>
                <button class="btn btn-info" onclick="viewUserComplaints(${user.user_id})">
                    <i class="fas fa-list"></i> View Complaints
                </button>
                <button class="btn btn-warning" onclick="showResetPasswordModal(${user.user_id})">
                    <i class="fas fa-key"></i> Reset Password
                </button>
                <button class="btn btn-danger" onclick="showDeleteUserModal(${user.user_id})">
                    <i class="fas fa-trash"></i> Delete User
                </button>
            </div>
        `;
        
        $('#userDetailContent').html(userDetailContent);
        
        // Load login activities after the modal content is set
        loadLoginActivities(user.user_id);
    }
    
    function openDirectMessage(userId, userName) {
        $('#userDetailModal').modal('hide');
        
        $('#messageRecipientId').val(userId);
        $('#messageRecipientName').val(userName);
        $('#messageSubject').val('');
        $('#messageContent').val('');
        $('#directMessageModal').modal('show');
    }
    
    function sendDirectMessage() {
        const formData = {
            recipient_id: $('#messageRecipientId').val(),
            subject: $('#messageSubject').val(),
            message: $('#messageContent').val()
        };
        
        // Validate form data
        if (!formData.recipient_id || !formData.subject || !formData.message) {
            alert('Please fill in all required fields.');
            return;
        }
        
        // Show loading state
        const originalText = $('#directMessageModal .btn-primary').html();
        $('#directMessageModal .btn-primary').html('<i class="fas fa-spinner fa-spin"></i> Sending...').prop('disabled', true);
        
        $.ajax({
            url: 'api/send_direct_message.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            dataType: 'json',
            timeout: 10000,
            success: function(response) {
                console.log('Message response:', response);
                if (response && response.success) {
                    alert('Message sent successfully!');
                    $('#directMessageModal').modal('hide');
                    // Clear form
                    $('#messageSubject').val('');
                    $('#messageContent').val('');
                } else {
                    let errorMsg = 'Failed to send message';
                    if (response && response.error) {
                        errorMsg = response.error;
                    }
                    if (response && response.debug) {
                        errorMsg += '\n\nDebug info: ' + JSON.stringify(response.debug);
                    }
                    alert('Error: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                console.error('Message sending error:', status, error, xhr.responseText);
                
                let errorMsg = 'Error sending message';
                if (status === 'timeout') {
                    errorMsg = 'Request timed out. Please try again.';
                } else if (xhr.status === 403) {
                    errorMsg = 'Access denied. Please check your permissions.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error. The messages table might not exist.';
                }
                
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.error) {
                        errorMsg = response.error;
                        if (response.debug) {
                            errorMsg += '\n\nDebug: ' + JSON.stringify(response.debug);
                        }
                    }
                } catch (e) {
                    errorMsg += '\n\nStatus: ' + status + ', Error: ' + error;
                }
                
                alert(errorMsg + '\n\nTip: Try clicking "Test Messages" to check if the messages table exists.');
            },
            complete: function() {
                // Reset button state
                $('#directMessageModal .btn-primary').html(originalText).prop('disabled', false);
            }
        });
    }
    
    function viewUserComplaints(userId) {
        // Redirect to a filtered view of complaints for this user
        window.open(`admin.php?filter_user=${userId}`, '_blank');
    }
    
    function showResetPasswordModal(userId) {
        $('#userDetailModal').modal('hide');
        // Find and trigger the existing reset password modal
        $(`#resetPasswordModal${userId}`).modal('show');
    }
    
    function showDeleteUserModal(userId) {
        $('#userDetailModal').modal('hide');
        // Find and trigger the existing delete user modal
        $(`#deleteUserModal${userId}`).modal('show');
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function checkSession() {
        $.ajax({
            url: 'api/check_session.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                alert('Session Status:\n' + JSON.stringify(response, null, 2));
            },
            error: function(xhr, status, error) {
                alert('Session check failed:\nStatus: ' + status + '\nError: ' + error);
            }
        });
    }
    
    function testBasicAPI() {
        $.ajax({
            url: 'api/test_basic.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                alert('Basic API Test:\n' + JSON.stringify(response, null, 2));
            },
            error: function(xhr, status, error) {
                alert('Basic API test failed:\nStatus: ' + status + '\nError: ' + error + '\nResponse: ' + xhr.responseText);
            }
        });
    }
    
    function testConfigAPI() {
        $.ajax({
            url: 'api/test_config.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                alert('Config API Test:\n' + JSON.stringify(response, null, 2));
            },
            error: function(xhr, status, error) {
                alert('Config API test failed:\nStatus: ' + status + '\nError: ' + error + '\nResponse: ' + xhr.responseText);
            }
        });
    }
    
    function testMessagesTable() {
        $.ajax({
            url: 'api/test_messages_table.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                alert('Messages Table Test:\n' + JSON.stringify(response, null, 2));
            },
            error: function(xhr, status, error) {
                alert('Messages table test failed:\nStatus: ' + status + '\nError: ' + error + '\nResponse: ' + xhr.responseText);
            }
        });
    }
    
    function loadLoginActivities(userId) {
        $.ajax({
            url: 'api/get_login_activities.php',
            type: 'GET',
            data: { user_id: userId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    displayLoginActivities(userId, response.activities, response.statistics);
                } else if (response.setup_required) {
                    $(`#loginActivities-${userId}`).html(`
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            Login activities table not found.
                            <br>
                            <button class="btn btn-sm btn-primary mt-2" onclick="window.open('setup_database_tables.php', '_blank')">
                                Setup Database Tables
                            </button>
                        </div>
                    `);
                } else {
                    $(`#loginActivities-${userId}`).html(`
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> ${response.error}
                        </div>
                    `);
                }
            },
            error: function(xhr, status, error) {
                $(`#loginActivities-${userId}`).html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> Error loading login activities
                    </div>
                `);
            }
        });
    }
    
    function displayLoginActivities(userId, activities, stats) {
        let html = '';
        
        // Statistics summary
        if (stats && stats.total_logins > 0) {
            html += `
                <div class="row text-center mb-3">
                    <div class="col-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${stats.total_logins || 0}</h6>
                                <small class="text-muted">Total Logins</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${stats.successful_logins || 0}</h6>
                                <small class="text-muted">Successful</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${stats.failed_logins || 0}</h6>
                                <small class="text-muted">Failed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="card bg-light">
                            <div class="card-body py-2">
                                <h6 class="card-title mb-1">${Math.round(stats.avg_session_duration || 0)}m</h6>
                                <small class="text-muted">Avg Session</small>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Activities list
        if (activities && activities.length > 0) {
            html += '<ul class="list-group list-group-flush">';
            activities.forEach(function(activity) {
                const loginTime = new Date(activity.login_time).toLocaleString();
                const logoutTime = activity.logout_time ? new Date(activity.logout_time).toLocaleString() : 'Still active';
                const statusIcon = activity.login_status === 'success' ? 'fa-check-circle text-success' : 'fa-times-circle text-danger';
                const duration = activity.session_duration ? `${activity.session_duration}m` : 'Active';
                
                html += `
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center mb-1">
                                    <i class="fas ${statusIcon} mr-2"></i>
                                    <strong>${loginTime}</strong>
                                </div>
                                <small class="text-muted">
                                    ${activity.browser} on ${activity.operating_system} • 
                                    IP: ${activity.ip_address || 'Unknown'} • 
                                    Duration: ${duration}
                                </small>
                                ${activity.logout_time ? `<br><small class="text-muted">Logged out: ${logoutTime}</small>` : ''}
                                ${activity.failure_reason ? `<br><small class="text-danger">Reason: ${activity.failure_reason}</small>` : ''}
                            </div>
                        </div>
                    </li>
                `;
            });
            html += '</ul>';
        } else {
            html += '<div class="text-center text-muted py-3">No login activities recorded</div>';
        }
        
        $(`#loginActivities-${userId}`).html(html);
    }
    
    function getActivityIcon(type) {
        const icons = {
            'complaint_lodged': 'fa-exclamation-circle',
            'feedback_given': 'fa-comment-dots',
            'reply_sent': 'fa-reply',
            'message_sent': 'fa-paper-plane',
            'message_received': 'fa-envelope'
        };
        return icons[type] || 'fa-circle';
    }
    
    function getActivityColor(type) {
        const colors = {
            'complaint_lodged': 'text-warning',
            'feedback_given': 'text-success',
            'reply_sent': 'text-info',
            'message_sent': 'text-primary',
            'message_received': 'text-secondary'
        };
        return colors[type] || 'text-muted';
    }
    </script>
</body>
</html>

<?php
// End output buffering and flush
ob_end_flush();
?>