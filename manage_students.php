<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Check if user is logged in and has appropriate role (Admin or Super Admin)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role_id"], [1])){
    header("location: staff_login.php");
    exit;
}

require_once "config.php";

$success_msg = $error_msg = "";

// Handle student actions
if($_SERVER["REQUEST_METHOD"] == "POST"){
    if(isset($_POST["action"])){
        switch($_POST["action"]){
            case "update_student":
                $student_id = $_POST["student_id"];
                $first_name = trim($_POST["first_name"]);
                $middle_name = trim($_POST["middle_name"]);
                $last_name = trim($_POST["last_name"]);
                $email = trim($_POST["email"]);
                $registration_number = trim($_POST["registration_number"]);
                $is_active = isset($_POST["is_active"]) ? 1 : 0;
                
                // Validate email uniqueness
                $check_email_sql = "SELECT student_id FROM students WHERE email = ? AND student_id != ?";
                if($check_stmt = mysqli_prepare($conn, $check_email_sql)){
                    mysqli_stmt_bind_param($check_stmt, "si", $email, $student_id);
                    if(mysqli_stmt_execute($check_stmt)){
                        $check_result = mysqli_stmt_get_result($check_stmt);
                        if(mysqli_num_rows($check_result) > 0){
                            $error_msg = "Email address is already in use by another student.";
                        } else {
                            // Update student
                            $update_sql = "UPDATE students SET first_name = ?, middle_name = ?, last_name = ?, email = ?, registration_number = ?, is_active = ? WHERE student_id = ?";
                            if($update_stmt = mysqli_prepare($conn, $update_sql)){
                                mysqli_stmt_bind_param($update_stmt, "sssssii", $first_name, $middle_name, $last_name, $email, $registration_number, $is_active, $student_id);
                                if(mysqli_stmt_execute($update_stmt)){
                                    $success_msg = "Student information updated successfully.";
                                } else {
                                    $error_msg = "Failed to update student information.";
                                }
                                mysqli_stmt_close($update_stmt);
                            }
                        }
                    }
                    mysqli_stmt_close($check_stmt);
                }
                break;
                
            case "reset_password":
                $student_id = $_POST["student_id"];
                $new_password = trim($_POST["new_password"]);
                
                if(!empty($new_password) && strlen($new_password) >= 6){
                    $hashed_password = md5($new_password);
                    $reset_sql = "UPDATE students SET password = ? WHERE student_id = ?";
                    if($reset_stmt = mysqli_prepare($conn, $reset_sql)){
                        mysqli_stmt_bind_param($reset_stmt, "si", $hashed_password, $student_id);
                        if(mysqli_stmt_execute($reset_stmt)){
                            $success_msg = "Student password reset successfully.";
                            
                            // Send notification email to student
                            $email_sql = "SELECT email, first_name, last_name FROM students WHERE student_id = ?";
                            if($email_stmt = mysqli_prepare($conn, $email_sql)){
                                mysqli_stmt_bind_param($email_stmt, "i", $student_id);
                                if(mysqli_stmt_execute($email_stmt)){
                                    $email_result = mysqli_stmt_get_result($email_stmt);
                                    if($email_row = mysqli_fetch_assoc($email_result)){
                                        $to = $email_row['email'];
                                        $subject = "Password Reset by Administrator - TSU ICT Help Desk";
                                        $message = "Dear " . $email_row['first_name'] . " " . $email_row['last_name'] . ",\n\n";
                                        $message .= "Your password has been reset by an administrator.\n\n";
                                        $message .= "Your new password is: " . $new_password . "\n\n";
                                        $message .= "For security reasons, please login and change your password immediately.\n\n";
                                        $message .= "Login URL: https://helpdesk.tsuniversity.ng/student_login.php\n\n";
                                        $message .= "Best regards,\nTSU ICT Help Desk Team";
                                        
                                        $headers = "From: TSU ICT Help Desk <complaints@tsuniversity.edu.ng>\r\n";
                                        $headers .= "Reply-To: complaints@tsuniversity.edu.ng\r\n";
                                        
                                        if(function_exists('app_mail')) {
                                            app_mail($to, $subject, $message, $headers);
                                        } else {
                                            mail($to, $subject, $message, $headers);
                                        }
                                    }
                                }
                                mysqli_stmt_close($email_stmt);
                            }
                        } else {
                            $error_msg = "Failed to reset password.";
                        }
                        mysqli_stmt_close($reset_stmt);
                    }
                } else {
                    $error_msg = "Password must be at least 6 characters long.";
                }
                break;
                
            case "delete_student":
                if(!empty($_SESSION["is_super_admin"])){
                    $student_id = $_POST["student_id"];
                    
                    // First delete related complaints
                    $delete_complaints_sql = "DELETE FROM student_complaints WHERE student_id = ?";
                    if($delete_complaints_stmt = mysqli_prepare($conn, $delete_complaints_sql)){
                        mysqli_stmt_bind_param($delete_complaints_stmt, "i", $student_id);
                        mysqli_stmt_execute($delete_complaints_stmt);
                        mysqli_stmt_close($delete_complaints_stmt);
                    }
                    
                    // Then delete student
                    $delete_sql = "DELETE FROM students WHERE student_id = ?";
                    if($delete_stmt = mysqli_prepare($conn, $delete_sql)){
                        mysqli_stmt_bind_param($delete_stmt, "i", $student_id);
                        if(mysqli_stmt_execute($delete_stmt)){
                            $success_msg = "Student deleted successfully.";
                        } else {
                            $error_msg = "Failed to delete student.";
                        }
                        mysqli_stmt_close($delete_stmt);
                    }
                } else {
                    $error_msg = "Only Super Admin can delete students.";
                }
                break;
                
            case "activate_student":
                $student_id = $_POST["student_id"];
                $activate_sql = "UPDATE students SET is_active = 1 WHERE student_id = ?";
                if($activate_stmt = mysqli_prepare($conn, $activate_sql)){
                    mysqli_stmt_bind_param($activate_stmt, "i", $student_id);
                    if(mysqli_stmt_execute($activate_stmt)){
                        $success_msg = "Student activated successfully.";
                    } else {
                        $error_msg = "Failed to activate student.";
                    }
                    mysqli_stmt_close($activate_stmt);
                }
                break;
                
            case "deactivate_student":
                $student_id = $_POST["student_id"];
                $deactivate_sql = "UPDATE students SET is_active = 0 WHERE student_id = ?";
                if($deactivate_stmt = mysqli_prepare($conn, $deactivate_sql)){
                    mysqli_stmt_bind_param($deactivate_stmt, "i", $student_id);
                    if(mysqli_stmt_execute($deactivate_stmt)){
                        $success_msg = "Student deactivated successfully.";
                    } else {
                        $error_msg = "Failed to deactivate student.";
                    }
                    mysqli_stmt_close($deactivate_stmt);
                }
                break;
        }
    }
}

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$faculty_filter = isset($_GET['faculty_id']) ? $_GET['faculty_id'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = "";

if(!empty($search)){
    $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.email LIKE ? OR s.registration_number LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    $param_types .= "ssss";
}

if(!empty($faculty_filter)){
    $where_conditions[] = "s.faculty_id = ?";
    $params[] = $faculty_filter;
    $param_types .= "i";
}

if($status_filter !== ''){
    if($status_filter == '1'){
        $where_conditions[] = "s.is_active = 1";
    } else {
        $where_conditions[] = "s.is_active = 0";
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM students s 
              LEFT JOIN faculties f ON s.faculty_id = f.faculty_id 
              LEFT JOIN student_departments sd ON s.department_id = sd.department_id 
              LEFT JOIN programmes p ON s.programme_id = p.programme_id 
              $where_clause";

$total_students = 0;
if($count_stmt = mysqli_prepare($conn, $count_sql)){
    if(!empty($params)){
        $bind_params = array($param_types);
        foreach($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array(array($count_stmt, 'bind_param'), $bind_params);
    }
    if(mysqli_stmt_execute($count_stmt)){
        $count_result = mysqli_stmt_get_result($count_stmt);
        if($count_row = mysqli_fetch_assoc($count_result)){
            $total_students = $count_row['total'];
        }
    }
    mysqli_stmt_close($count_stmt);
}

// Dynamic Pagination Logic
$default_limit = 20;
$allowed_limits = [10, 20, 50, 100, 'all'];

$per_page = isset($_GET['limit']) ? $_GET['limit'] : $default_limit;

// Validate limit parameter
if (!in_array($per_page, $allowed_limits)) {
    $per_page = $default_limit;
}

// Calculate offset and limits
if ($per_page === 'all') {
    $total_pages = 1;
    $page = 1;
    $limit_clause = "";
} else {
    $per_page = (int)$per_page;
    $total_pages = ceil($total_students / $per_page);
    
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($page < 1) $page = 1;
    if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
    
    $offset = ($page - 1) * $per_page;
    if ($offset < 0) $offset = 0;
    
    $limit_clause = "LIMIT $per_page OFFSET $offset";
}

// Get students with pagination
$students_sql = "SELECT s.*, f.faculty_name, sd.department_name,
                 COALESCE(p.programme_name,
                     (SELECT programme_name FROM programmes WHERE programme_id = s.programme_id LIMIT 1),
                     'Unknown Programme') AS programme_name,
                 (SELECT COUNT(*) FROM student_complaints sc WHERE sc.student_id = s.student_id) as complaint_count
                 FROM students s 
                 LEFT JOIN faculties f ON s.faculty_id = f.faculty_id 
                 LEFT JOIN student_departments sd ON s.department_id = sd.department_id 
                 LEFT JOIN programmes p ON s.programme_id = p.programme_id 
                 $where_clause
                 ORDER BY s.created_at DESC 
                 $limit_clause";

$students = [];
if($students_stmt = mysqli_prepare($conn, $students_sql)){
    if(!empty($params)){
        $bind_params = array($param_types);
        foreach($params as $key => $value) {
            $bind_params[] = &$params[$key];
        }
        call_user_func_array(array($students_stmt, 'bind_param'), $bind_params);
    }
    if(mysqli_stmt_execute($students_stmt)){
        $students_result = mysqli_stmt_get_result($students_stmt);
        while($row = mysqli_fetch_assoc($students_result)){
            $students[] = $row;
        }
    }
    mysqli_stmt_close($students_stmt);
}

// Get faculties for filter dropdown
$faculties = [];
$faculties_sql = "SELECT * FROM faculties ORDER BY faculty_name";
$faculties_result = mysqli_query($conn, $faculties_sql);
if($faculties_result){
    while($row = mysqli_fetch_assoc($faculties_result)){
        $faculties[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/responsive-fix.css">
    <style>
        .student-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(30, 60, 114, 0.1);
            margin-bottom: 2rem;
        }
        .card-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.5rem;
        }
        .status-active { color: #28a745; font-weight: bold; }
        .status-inactive { color: #dc3545; font-weight: bold; }
        
        .search-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* Anti-flicker modal fix */
        body.modal-open { overflow: auto !important; padding-right: 0 !important; }
        .modal-dialog { margin: 5vh auto; }
        
        /* Pagination Styling */
        .pagination { margin-bottom: 0; }
        .page-link { color: var(--primary-blue); padding: 0.5rem 0.75rem; }
        .page-item.active .page-link { background-color: var(--primary-blue); border-color: var(--primary-blue); }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <?php
    // Set up student management header variables
    $page_title = 'Student Management';
    $page_subtitle = 'View, edit, and manage student accounts';
    $page_icon = 'fas fa-user-graduate';
    $show_breadcrumb = true;
    $breadcrumb_items = [
        ['title' => 'Admin', 'url' => 'admin.php'],
        ['title' => 'Student Management', 'url' => '']
    ];
    
    // Set up quick stats
    $active_students = 0;
    $inactive_students = 0;
    $total_complaints = 0;
    
    // Approximate active vs inactive from the fetched subset (or re-query if exact global is needed)
    foreach($students as $student) {
        if($student['is_active']) {
            $active_students++;
        } else {
            $inactive_students++;
        }
        $total_complaints += $student['complaint_count'];
    }
    
    $quick_stats = [
        ['number' => $total_students, 'label' => 'Total Students'],
        ['number' => $active_students . '+', 'label' => 'Active (Showing)'],
        ['number' => $total_complaints, 'label' => 'Complaints (Showing)']
    ];
    
    include 'includes/dashboard_header.php';
    ?>

    <div class="container-fluid px-3 px-md-4 px-xl-5">
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

        <div class="search-section">
            <form method="GET" class="row align-items-end">
                <?php if (isset($_GET['limit'])): ?>
                    <input type="hidden" name="limit" value="<?php echo htmlspecialchars($_GET['limit']); ?>">
                <?php endif; ?>
                
                <div class="col-12 col-md-4 mb-3 mb-md-0">
                    <div class="form-group mb-0">
                        <label for="search" class="font-weight-bold">Search Students</label>
                        <input type="text" id="search" name="search" class="form-control" 
                               placeholder="Name, email, or reg number" 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-12 col-md-3 mb-3 mb-md-0">
                    <div class="form-group mb-0">
                        <label for="faculty_id" class="font-weight-bold">Faculty</label>
                        <select id="faculty_id" name="faculty_id" class="form-control">
                            <option value="">All Faculties</option>
                            <?php foreach($faculties as $faculty): ?>
                                <option value="<?php echo $faculty['faculty_id']; ?>" 
                                        <?php echo ($faculty_filter == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-12 col-md-3 mb-3 mb-md-0">
                    <div class="form-group mb-0">
                        <label for="status" class="font-weight-bold">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">All Status</option>
                            <option value="1" <?php echo ($status_filter === '1') ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo ($status_filter === '0') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-primary btn-block h-100">
                        <i class="fas fa-search mr-1"></i>Search
                    </button>
                </div>
            </form>
            
            <?php if(!empty($search) || !empty($faculty_filter) || $status_filter !== ''): ?>
                <div class="mt-3">
                    <a href="manage_students.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-times mr-1"></i>Clear Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card student-card">
            <div class="card-header">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                    <h4 class="mb-2 mb-md-0">
                        <i class="fas fa-list mr-2"></i>Students List 
                        <span class="badge badge-light text-primary ml-2"><?php echo $total_students; ?> total</span>
                    </h4>
                </div>
            </div>
            <div class="card-body">
                <?php if(empty($students)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No students found</h5>
                        <p class="text-muted">Try adjusting your search criteria</p>
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <div class="row align-items-center">
                            <div class="col-12 col-md-3 mb-2 mb-md-0">
                                <div class="btn-group w-100 w-md-auto" role="group">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllStudents()">
                                        <i class="fas fa-check-square"></i> Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllStudents()">
                                        <i class="fas fa-square"></i> Deselect
                                    </button>
                                </div>
                            </div>
                            <div class="col-12 col-md-3 mb-2 mb-md-0 d-flex justify-content-center justify-content-md-start">
                                <div class="d-flex align-items-center">
                                    <label class="mr-2 mb-0 font-weight-bold text-muted" style="white-space: nowrap;">Show:</label>
                                    <select class="form-control form-control-sm" style="width: auto; cursor: pointer;" 
                                            onchange="window.location.href='?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>&limit='+this.value">
                                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10 entries</option>
                                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20 entries</option>
                                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50 entries</option>
                                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100 entries</option>
                                        <option value="all" <?php echo $per_page === 'all' ? 'selected' : ''; ?>>All entries</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12 col-md-6 text-md-right">
                                <div class="btn-group w-100 w-md-auto flex-wrap" role="group">
                                    <button type="button" class="btn btn-sm btn-warning" onclick="bulkResetStudentPasswords()" disabled id="bulkResetStudentsBtn">
                                        <i class="fas fa-key"></i> <span class="d-none d-lg-inline">Reset</span>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-success" onclick="bulkActivateStudents()" disabled id="bulkActivateBtn">
                                        <i class="fas fa-check"></i> <span class="d-none d-lg-inline">Activate</span>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="bulkDeactivateStudents()" disabled id="bulkDeactivateBtn">
                                        <i class="fas fa-ban"></i> <span class="d-none d-lg-inline">Deactivate</span>
                                    </button>
                                    <?php if(!empty($_SESSION["is_super_admin"])): ?>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="bulkDeleteStudents()" disabled id="bulkDeleteStudentsBtn">
                                        <i class="fas fa-trash"></i> <span class="d-none d-lg-inline">Delete</span>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2 text-center text-md-left">
                            <small class="text-muted">
                                <span id="selectedStudentCount">0</span> student(s) selected
                            </small>
                        </div>
                    </div>
                    
                    <div class="table-responsive mb-3">
                        <table class="table table-hover table-mobile-cards">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAllStudentsCheckbox" onchange="toggleAllStudents(this)">
                                    </th>
                                    <th>Student Info</th>
                                    <th>Registration</th>
                                    <th>Programme</th>
                                    <th>Status</th>
                                    <th>Complaints</th>
                                    <th>Joined</th>
                                    <th style="width: 140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $student): ?>
                                    <tr class="student-row">
                                        <td data-label="Select">
                                            <input type="checkbox" class="student-checkbox" value="<?php echo $student['student_id']; ?>" onchange="updateStudentBulkActions()">
                                        </td>
                                        <td data-label="Student Info">
                                            <div>
                                                <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>
                                                <?php if(!empty($student['middle_name'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($student['middle_name']); ?></small>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($student['email']); ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td data-label="Registration">
                                            <code><?php echo htmlspecialchars($student['registration_number']); ?></code>
                                            <br>
                                            <small class="text-muted">Year: <?php echo $student['year_of_entry']; ?></small>
                                        </td>
                                        <td data-label="Programme">
                                            <div>
                                                <strong><?php echo htmlspecialchars($student['programme_name']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($student['department_name'] ?? 'N/A'); ?></small>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($student['faculty_name'] ?? 'N/A'); ?></small>
                                            </div>
                                        </td>
                                        <td data-label="Status">
                                            <span class="<?php echo $student['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                <i class="fas fa-circle mr-1" style="font-size: 0.6rem; vertical-align: middle;"></i>
                                                <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td data-label="Complaints">
                                            <span class="badge badge-info">
                                                <?php echo $student['complaint_count']; ?>
                                            </span>
                                        </td>
                                        <td data-label="Joined">
                                            <?php echo date('M d, y', strtotime($student['created_at'])); ?>
                                        </td>
                                        <td data-label="Actions" class="action-col">
                                            <button type="button" class="btn btn-primary btn-sm btn-edit-student" title="Edit"
                                                    data-id="<?php echo $student['student_id']; ?>"
                                                    data-firstname="<?php echo htmlspecialchars($student['first_name'], ENT_QUOTES); ?>"
                                                    data-middlename="<?php echo htmlspecialchars($student['middle_name'], ENT_QUOTES); ?>"
                                                    data-lastname="<?php echo htmlspecialchars($student['last_name'], ENT_QUOTES); ?>"
                                                    data-email="<?php echo htmlspecialchars($student['email'], ENT_QUOTES); ?>"
                                                    data-reg="<?php echo htmlspecialchars($student['registration_number'], ENT_QUOTES); ?>"
                                                    data-active="<?php echo $student['is_active']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-warning btn-sm btn-reset-student" title="Reset Password"
                                                    data-id="<?php echo $student['student_id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'], ENT_QUOTES); ?>"
                                                    data-email="<?php echo htmlspecialchars($student['email'], ENT_QUOTES); ?>">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <?php if(!empty($_SESSION["is_super_admin"])): ?>
                                                <button type="button" class="btn btn-danger btn-sm" title="Delete"
                                                        onclick="confirmDelete(<?php echo $student['student_id']; ?>, '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'], ENT_QUOTES); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if($total_pages > 1): ?>
                        <nav aria-label="Students pagination" class="mt-4">
                            <ul class="pagination justify-content-center flex-wrap">
                                <?php 
                                    // Preserve limits and search parameters for links
                                    $page_params = $_GET;
                                    if(isset($page_params['page'])) unset($page_params['page']);
                                    $qs = http_build_query($page_params);
                                    $qs = $qs ? '&' . $qs : '';
                                ?>
                                
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1 . $qs; ?>" tabindex="-1">Previous</a>
                                </li>

                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if($start_page > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="?page=1'.$qs.'">1</a></li>';
                                    if($start_page > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                }
                                
                                for($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                                    <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i . $qs; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php 
                                if($end_page < $total_pages) {
                                    if($end_page < $total_pages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    echo '<li class="page-item"><a class="page-link" href="?page='.$total_pages.$qs.'">'.$total_pages.'</a></li>';
                                }
                                ?>
                                
                                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1 . $qs; ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="sharedEditModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Student</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_student">
                        <input type="hidden" name="student_id" id="editStudentId">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold text-muted">First Name</label>
                                    <input type="text" name="first_name" id="editFirstName" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold text-muted">Middle Name</label>
                                    <input type="text" name="middle_name" id="editMiddleName" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="font-weight-bold text-muted">Last Name</label>
                                    <input type="text" name="last_name" id="editLastName" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold text-muted">Email</label>
                                    <input type="email" name="email" id="editEmail" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="font-weight-bold text-muted">Registration Number</label>
                                    <input type="text" name="registration_number" id="editRegNumber" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <div class="custom-control custom-checkbox mt-2">
                                <input type="checkbox" class="custom-control-input" id="editIsActive" name="is_active">
                                <label class="custom-control-label font-weight-bold" for="editIsActive">Active Account</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Update Student</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="sharedResetModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Student Password</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="student_id" id="resetStudentId">
                        
                        <div class="alert alert-info py-2">
                            <p class="mb-1"><i class="fas fa-user-circle mr-2"></i><strong>Student:</strong> <span id="resetStudentName"></span></p>
                            <p class="mb-0"><i class="fas fa-envelope mr-2"></i><strong>Email:</strong> <span id="resetStudentEmail"></span></p>
                        </div>
                        
                        <div class="form-group mt-3">
                            <label class="font-weight-bold text-muted">New Password</label>
                            <input type="password" name="new_password" class="form-control"
                                   placeholder="Enter new password" required minlength="6">
                            <small class="form-text text-muted">Minimum 6 characters. The student will be notified via email.</small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-key mr-1"></i> Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_student">
        <input type="hidden" name="student_id" id="deleteStudentId">
    </form>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        function confirmDelete(studentId, studentName) {
            if(confirm('Are you sure you want to delete ' + studentName + '?\n\nThis will also delete all their complaints and CANNOT be undone.')) {
                document.getElementById('deleteStudentId').value = studentId;
                document.getElementById('deleteForm').submit();
            }
        }

        // Shared Edit modal
        $(document).on('click', '.btn-edit-student', function() {
            const d = $(this).data();
            $('#editStudentId').val(d.id);
            $('#editFirstName').val(d.firstname);
            $('#editMiddleName').val(d.middlename);
            $('#editLastName').val(d.lastname);
            $('#editEmail').val(d.email);
            $('#editRegNumber').val(d.reg);
            $('#editIsActive').prop('checked', d.active == 1);
            $('#sharedEditModal').modal('show');
        });

        // Shared Reset Password modal
        $(document).on('click', '.btn-reset-student', function() {
            const d = $(this).data();
            $('#resetStudentId').val(d.id);
            $('#resetStudentName').text(d.name);
            $('#resetStudentEmail').text(d.email);
            $('#sharedResetModal').modal('show');
        });
        
        // Student bulk selection functions
        function toggleAllStudents(checkbox) {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateStudentBulkActions();
        }
        
        function selectAllStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = true;
            });
            document.getElementById('selectAllStudentsCheckbox').checked = true;
            updateStudentBulkActions();
        }
        
        function deselectAllStudents() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('selectAllStudentsCheckbox').checked = false;
            updateStudentBulkActions();
        }
        
        function updateStudentBulkActions() {
            const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            const count = selectedCheckboxes.length;
            
            document.getElementById('selectedStudentCount').textContent = count;
            document.getElementById('bulkResetStudentsBtn').disabled = count === 0;
            document.getElementById('bulkActivateBtn').disabled = count === 0;
            document.getElementById('bulkDeactivateBtn').disabled = count === 0;
            
            const deleteBtn = document.getElementById('bulkDeleteStudentsBtn');
            if(deleteBtn) {
                deleteBtn.disabled = count === 0;
            }
            
            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.student-checkbox');
            const selectAllCheckbox = document.getElementById('selectAllStudentsCheckbox');
            
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
        
        function bulkResetStudentPasswords() {
            const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one student.');
                return;
            }
            
            const newPassword = prompt('Enter new password for all selected students (minimum 6 characters):');
            if (!newPassword || newPassword.length < 6) {
                alert('Password must be at least 6 characters long.');
                return;
            }
            
            if (!confirm(`Are you sure you want to reset passwords for ${selectedCheckboxes.length} selected student(s)?`)) {
                return;
            }
            
            processBulkStudentAction('reset_password', selectedCheckboxes, { new_password: newPassword });
        }
        
        function bulkActivateStudents() {
            const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one student.');
                return;
            }
            
            if (!confirm(`Are you sure you want to activate ${selectedCheckboxes.length} selected student(s)?`)) {
                return;
            }
            
            processBulkStudentAction('activate_student', selectedCheckboxes);
        }
        
        function bulkDeactivateStudents() {
            const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one student.');
                return;
            }
            
            if (!confirm(`Are you sure you want to deactivate ${selectedCheckboxes.length} selected student(s)?`)) {
                return;
            }
            
            processBulkStudentAction('deactivate_student', selectedCheckboxes);
        }
        
        function bulkDeleteStudents() {
            const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Please select at least one student.');
                return;
            }
            
            if (!confirm(`Are you sure you want to DELETE ${selectedCheckboxes.length} selected student(s)?\n\nThis will also delete all their complaints and CANNOT be undone!`)) {
                return;
            }
            
            processBulkStudentAction('delete_student', selectedCheckboxes);
        }
        
        function processBulkStudentAction(action, checkboxes, extraData = {}) {
            const studentIds = Array.from(checkboxes).map(cb => cb.value);
            let completed = 0;
            let errors = [];
            
            // Show progress
            const progressMsg = document.createElement('div');
            progressMsg.className = 'alert alert-info mx-3 mt-3';
            progressMsg.innerHTML = `<i class="fas fa-spinner fa-spin mr-2"></i> Processing ${studentIds.length} student(s)...`;
            document.querySelector('.container-fluid').insertBefore(progressMsg, document.querySelector('.container-fluid').firstChild);
            
            studentIds.forEach(studentId => {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('student_id', studentId);
                
                // Add extra data if provided
                Object.keys(extraData).forEach(key => {
                    formData.append(key, extraData[key]);
                });
                
                fetch('manage_students.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    completed++;
                    if (completed === studentIds.length) {
                        finalizeBulkStudentAction(errors, progressMsg);
                    }
                })
                .catch(error => {
                    errors.push(`Student ID ${studentId}: ${error.message}`);
                    completed++;
                    if (completed === studentIds.length) {
                        finalizeBulkStudentAction(errors, progressMsg);
                    }
                });
            });
        }
        
        function finalizeBulkStudentAction(errors, progressMsg) {
            progressMsg.remove();
            
            if (errors.length === 0) {
                alert('Bulk action completed successfully!');
                location.reload();
            } else {
                alert('Some errors occurred:\n' + errors.join('\n'));
                location.reload();
            }
        }
        
        // Auto-dismiss alerts
        $('.alert').delay(5000).fadeOut();
        
        // Auto-focus search input and place cursor at the end on load if search is present
        const searchInput = $('#search');
        if (searchInput.length) {
            const val = searchInput.val();
            if (val) {
                searchInput.focus().val('').val(val);
            }
        }

        // Live Search on input (filtering visible rows instantly)
        let searchTimeout;
        $('#search').on('input', function() {
            let query = $(this).val().toLowerCase().trim();
            let matchCount = 0;
            
            $('.student-row').each(function() {
                let row = $(this);
                let studentInfo = row.find('td[data-label="Student Info"]').text().toLowerCase();
                let regInfo = row.find('td[data-label="Registration"]').text().toLowerCase();
                let progInfo = row.find('td[data-label="Programme"]').text().toLowerCase();
                
                if (studentInfo.includes(query) || regInfo.includes(query) || progInfo.includes(query)) {
                    row.show();
                    matchCount++;
                } else {
                    row.hide();
                }
            });
            
            // Update visible badge count
            $('.card-header span.badge').text(matchCount + ' visible');
            
            if (matchCount === 0) {
                if ($('#noStudentsRow').length === 0) {
                    $('.table tbody').append('<tr id="noStudentsRow"><td colspan="8" class="text-center py-4 text-muted">No matching students found on this page.</td></tr>');
                } else {
                    $('#noStudentsRow').show();
                }
            } else {
                $('#noStudentsRow').hide();
            }

            // Debounced global search form submission (1000ms delay of inactivity)
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                const form = $('#search').closest('form');
                form.submit();
            }, 1000);
        });

        // Auto-submit dropdowns on change
        $('#faculty_id, #status').on('change', function() {
            $(this).closest('form').submit();
        });
        
        // Form validation / Loading indicator
        $('form').submit(function() {
            const submitBtn = $(this).find('button[type="submit"]');
            if (submitBtn.length) {
                submitBtn.prop('disabled', true);
                const originalText = submitBtn.html();
                submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...');
                
                // Re-enable after 3 seconds to prevent permanent disable on validation errors
                setTimeout(function() {
                    submitBtn.prop('disabled', false);
                    submitBtn.html(originalText);
                }, 3000);
            }
        });
    </script>
</body>
</html>