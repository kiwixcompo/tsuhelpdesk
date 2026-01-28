<?php
// Start output buffering to prevent header issues
ob_start();

session_start();
require_once "config.php";

// Check if user is logged in and has appropriate role (Admin or Director)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: staff_login.php");
    exit;
}

// Check if user has permission (Admin = 1, Director = 3)
if(!in_array($_SESSION["role_id"], [1, 3])){
    header("location: dashboard.php");
    exit;
}

// Handle delete complaint action
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_complaint'])){
    $complaint_id = intval($_POST['complaint_id']);
    
    $delete_sql = "DELETE FROM student_complaints WHERE complaint_id = ?";
    if($stmt = mysqli_prepare($conn, $delete_sql)){
        mysqli_stmt_bind_param($stmt, "i", $complaint_id);
        if(mysqli_stmt_execute($stmt)){
            $success_msg = "Complaint deleted successfully!";
        } else {
            $error_msg = "Failed to delete complaint.";
        }
        mysqli_stmt_close($stmt);
    }
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$faculty_filter = $_GET['faculty_id'] ?? '';
$department_filter = $_GET['department_id'] ?? '';
$session_filter = $_GET['academic_session'] ?? '';
$status_filter = $_GET['status'] ?? '';
$complaint_type_filter = $_GET['complaint_type'] ?? '';

// Build query conditions
$where_conditions = ["1=1"];
$params = [];
$param_types = "";

if (!empty($date_from)) {
    $where_conditions[] = "sc.created_at >= ?";
    $params[] = $date_from . " 00:00:00";
    $param_types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "sc.created_at <= ?";
    $params[] = $date_to . " 23:59:59";
    $param_types .= "s";
}

if (!empty($faculty_filter)) {
    $where_conditions[] = "f.faculty_id = ?";
    $params[] = $faculty_filter;
    $param_types .= "i";
}

if (!empty($department_filter)) {
    $where_conditions[] = "sd.department_id = ?";
    $params[] = $department_filter;
    $param_types .= "i";
}

if (!empty($session_filter)) {
    $where_conditions[] = "sc.academic_session = ?";
    $params[] = $session_filter;
    $param_types .= "s";
}

if (!empty($status_filter)) {
    $where_conditions[] = "sc.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if (!empty($complaint_type_filter)) {
    $where_conditions[] = "sc.complaint_type = ?";
    $params[] = $complaint_type_filter;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get summary statistics
$stats_sql = "SELECT 
    COUNT(*) as total_complaints,
    COUNT(CASE WHEN sc.status = 'Pending' THEN 1 END) as pending_count,
    COUNT(CASE WHEN sc.status = 'Under Review' THEN 1 END) as under_review_count,
    COUNT(CASE WHEN sc.status = 'Resolved' THEN 1 END) as resolved_count,
    COUNT(CASE WHEN sc.status = 'Rejected' THEN 1 END) as rejected_count,
    COUNT(CASE WHEN sc.complaint_type = 'FA' THEN 1 END) as fa_count,
    COUNT(CASE WHEN sc.complaint_type = 'F' THEN 1 END) as f_count,
    COUNT(CASE WHEN sc.complaint_type = 'Incorrect Grade' THEN 1 END) as incorrect_grade_count
FROM student_complaints sc
JOIN students s ON sc.student_id = s.student_id
JOIN student_departments sd ON s.department_id = sd.department_id
JOIN faculties f ON sd.faculty_id = f.faculty_id
WHERE $where_clause";

$stats = [];
if ($stmt = mysqli_prepare($conn, $stats_sql)) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    }
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        $stats = mysqli_fetch_assoc($result);
    }
    mysqli_stmt_close($stmt);
}

// Get faculties for filter dropdown
$faculties = [];
$faculty_sql = "SELECT faculty_id, faculty_name FROM faculties ORDER BY faculty_name";
$faculty_result = mysqli_query($conn, $faculty_sql);
if ($faculty_result) {
    while ($row = mysqli_fetch_assoc($faculty_result)) {
        $faculties[] = $row;
    }
}

// Get departments for filter dropdown
$departments = [];
$department_sql = "SELECT sd.department_id, sd.department_name, f.faculty_name 
                   FROM student_departments sd 
                   JOIN faculties f ON sd.faculty_id = f.faculty_id 
                   ORDER BY f.faculty_name, sd.department_name";
$department_result = mysqli_query($conn, $department_sql);
if ($department_result) {
    while ($row = mysqli_fetch_assoc($department_result)) {
        $departments[] = $row;
    }
}

// Get unique academic sessions
$sessions = [];
$session_sql = "SELECT DISTINCT academic_session FROM student_complaints WHERE academic_session IS NOT NULL ORDER BY academic_session DESC";
$session_result = mysqli_query($conn, $session_sql);
if ($session_result) {
    while ($row = mysqli_fetch_assoc($session_result)) {
        $sessions[] = $row['academic_session'];
    }
}

// Get complaints data for display
$complaints_sql = "SELECT 
    sc.complaint_id,
    sc.course_code,
    sc.course_title,
    sc.complaint_type,
    sc.academic_session,
    sc.description,
    sc.status,
    sc.created_at,
    sc.updated_at,
    s.first_name,
    s.middle_name,
    s.last_name,
    s.registration_number,
    s.email,
    s.year_of_entry,
    sd.department_name,
    sd.department_code,
    f.faculty_name,
    f.faculty_code,
    p.programme_name,
    CONCAT(s.first_name, ' ', IFNULL(CONCAT(s.middle_name, ' '), ''), s.last_name) as full_name
FROM student_complaints sc
JOIN students s ON sc.student_id = s.student_id
JOIN student_departments sd ON s.department_id = sd.department_id
JOIN faculties f ON sd.faculty_id = f.faculty_id
JOIN programmes p ON s.programme_id = p.programme_id
WHERE $where_clause
ORDER BY sc.created_at DESC";

$complaints = [];
if ($stmt = mysqli_prepare($conn, $complaints_sql)) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    }
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $complaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// End output buffering and flush
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced Student Complaints Report - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .report-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(30, 60, 114, 0.1);
            margin-bottom: 1rem;
            border-left: 4px solid #1e3c72;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #1e3c72;
        }
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(30, 60, 114, 0.1);
            margin-bottom: 2rem;
        }
        .complaints-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(30, 60, 114, 0.1);
            overflow: hidden;
        }
        .status-badge {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
        }
        .status-pending { background: #2196f3; color: white; }
        .status-under-review { background: #17a2b8; color: white; }
        .status-resolved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
        .btn-action {
            margin: 2px;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="report-header">
            <div class="row">
                <div class="col-md-8">
                    <h2><i class="fas fa-chart-bar mr-2"></i>Enhanced Student Complaints Report</h2>
                    <p class="mb-0">Comprehensive management and analysis of student result verification complaints</p>
                </div>
                <div class="col-md-4 text-right">
                    <button class="btn btn-light" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if(isset($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number"><?php echo $stats['total_complaints'] ?? 0; ?></div>
                    <div class="text-muted">Total Complaints</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number text-primary"><?php echo $stats['pending_count'] ?? 0; ?></div>
                    <div class="text-muted">Pending</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number text-info"><?php echo $stats['under_review_count'] ?? 0; ?></div>
                    <div class="text-muted">Under Review</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-number text-success"><?php echo $stats['resolved_count'] ?? 0; ?></div>
                    <div class="text-muted">Resolved</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number text-danger"><?php echo $stats['rejected_count'] ?? 0; ?></div>
                    <div class="text-muted">Rejected</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <h5><i class="fas fa-filter mr-2"></i>Filter Complaints</h5>
            <form method="GET" class="row">
                <div class="col-md-2">
                    <label>Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label>Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-2">
                    <label>Faculty</label>
                    <select name="faculty_id" class="form-control">
                        <option value="">All Faculties</option>
                        <?php foreach($faculties as $faculty): ?>
                            <option value="<?php echo $faculty['faculty_id']; ?>" 
                                    <?php echo ($faculty_filter == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Department</label>
                    <select name="department_id" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $department): ?>
                            <option value="<?php echo $department['department_id']; ?>" 
                                    <?php echo ($department_filter == $department['department_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($department['faculty_name'] . ' - ' . $department['department_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Academic Session</label>
                    <select name="academic_session" class="form-control">
                        <option value="">All Sessions</option>
                        <?php foreach($sessions as $session): ?>
                            <option value="<?php echo $session; ?>" 
                                    <?php echo ($session_filter == $session) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($session); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo ($status_filter == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Under Review" <?php echo ($status_filter == 'Under Review') ? 'selected' : ''; ?>>Under Review</option>
                        <option value="Resolved" <?php echo ($status_filter == 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                        <option value="Rejected" <?php echo ($status_filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Complaint Type</label>
                    <select name="complaint_type" class="form-control">
                        <option value="">All Types</option>
                        <option value="FA" <?php echo ($complaint_type_filter == 'FA') ? 'selected' : ''; ?>>Fail Absent (FA)</option>
                        <option value="F" <?php echo ($complaint_type_filter == 'F') ? 'selected' : ''; ?>>Fail (F)</option>
                        <option value="Incorrect Grade" <?php echo ($complaint_type_filter == 'Incorrect Grade') ? 'selected' : ''; ?>>Incorrect Grade</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search mr-1"></i>Filter
                        </button>
                        <a href="enhanced_student_complaints_report.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-1"></i>Clear
                        </a>
                    </div>
                </div>
                <div class="col-md-2">
                    <label>&nbsp;</label>
                    <div>
                        <a href="api/export_student_complaints.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                            <i class="fas fa-download mr-1"></i>Export Excel
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Complaints Table -->
        <div class="complaints-table">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-dark">
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Type</th>
                            <th>Session</th>
                            <th>Faculty/Dept</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($complaints)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                    <p class="text-muted">No complaints found matching the selected criteria.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($complaints as $complaint): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($complaint['full_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($complaint['registration_number']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($complaint['course_code']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($complaint['course_title']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($complaint['complaint_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?php echo htmlspecialchars($complaint['academic_session']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($complaint['faculty_code']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($complaint['department_name']); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $complaint['status'])); ?>">
                                            <?php echo htmlspecialchars($complaint['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($complaint['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" 
                                                data-toggle="modal" data-target="#viewModal<?php echo $complaint['complaint_id']; ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-action" 
                                                onclick="confirmDelete(<?php echo $complaint['complaint_id']; ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- View Modal -->
                                <div class="modal fade" id="viewModal<?php echo $complaint['complaint_id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Complaint Details</h5>
                                                <button type="button" class="close" data-dismiss="modal">
                                                    <span>&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <h6>Student Information</h6>
                                                        <p><strong>Name:</strong> <?php echo htmlspecialchars($complaint['full_name']); ?></p>
                                                        <p><strong>Registration:</strong> <?php echo htmlspecialchars($complaint['registration_number']); ?></p>
                                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($complaint['email']); ?></p>
                                                        <p><strong>Programme:</strong> <?php echo htmlspecialchars($complaint['programme_name']); ?></p>
                                                        <p><strong>Department:</strong> <?php echo htmlspecialchars($complaint['department_name']); ?></p>
                                                        <p><strong>Faculty:</strong> <?php echo htmlspecialchars($complaint['faculty_name']); ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <h6>Complaint Information</h6>
                                                        <p><strong>Course:</strong> <?php echo htmlspecialchars($complaint['course_code'] . ' - ' . $complaint['course_title']); ?></p>
                                                        <p><strong>Type:</strong> <?php echo htmlspecialchars($complaint['complaint_type']); ?></p>
                                                        <p><strong>Academic Session:</strong> <?php echo htmlspecialchars($complaint['academic_session']); ?></p>
                                                        <p><strong>Status:</strong> 
                                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $complaint['status'])); ?>">
                                                                <?php echo htmlspecialchars($complaint['status']); ?>
                                                            </span>
                                                        </p>
                                                        <p><strong>Submitted:</strong> <?php echo date('M d, Y h:i A', strtotime($complaint['created_at'])); ?></p>
                                                        <?php if($complaint['updated_at'] != $complaint['created_at']): ?>
                                                            <p><strong>Last Updated:</strong> <?php echo date('M d, Y h:i A', strtotime($complaint['updated_at'])); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if(!empty($complaint['description'])): ?>
                                                    <hr>
                                                    <h6>Description</h6>
                                                    <p><?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this complaint? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="deleteForm">
                        <input type="hidden" name="complaint_id" id="deleteComplaintId">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_complaint" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        function confirmDelete(complaintId) {
            $('#deleteComplaintId').val(complaintId);
            $('#deleteModal').modal('show');
        }
        
        // Auto-dismiss alerts
        $('.alert').delay(5000).fadeOut();
    </script>
</body>
</html>