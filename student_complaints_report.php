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

// Get filter parameters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$faculty_filter = $_GET['faculty_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

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

if (!empty($status_filter)) {
    $where_conditions[] = "sc.status = ?";
    $params[] = $status_filter;
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

// Get complaints data for display
$complaints_sql = "SELECT 
    sc.complaint_id,
    sc.course_code,
    sc.course_title,
    sc.complaint_type,
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
ORDER BY f.faculty_name, sd.department_name, sc.created_at DESC";

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

// Group complaints by department for display
$complaints_by_dept = [];
foreach ($complaints as $complaint) {
    $dept_key = $complaint['faculty_code'] . '_' . $complaint['department_code'];
    if (!isset($complaints_by_dept[$dept_key])) {
        $complaints_by_dept[$dept_key] = [
            'faculty_name' => $complaint['faculty_name'],
            'department_name' => $complaint['department_name'],
            'complaints' => []
        ];
    }
    $complaints_by_dept[$dept_key]['complaints'][] = $complaint;
}

// End output buffering and flush
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Complaints Report - TSU ICT Help Desk</title>
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
        .department-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(30, 60, 114, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .department-header {
            background: linear-gradient(135deg, #4a90e2 0%, #6bb6ff 100%);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        .complaint-type-fa { color: #dc3545; font-weight: 600; }
        .complaint-type-f { color: #fd7e14; font-weight: 600; }
        .complaint-type-incorrect { color: #6f42c1; font-weight: 600; }
        .status-pending { color: #007bff; font-weight: 600; }
        .status-under-review { color: #17a2b8; font-weight: 600; }
        .status-resolved { color: #28a745; font-weight: 600; }
        .status-rejected { color: #dc3545; font-weight: 600; }
        .export-buttons {
            position: sticky;
            top: 20px;
            z-index: 100;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="report-header text-center">
            <h1><i class="fas fa-chart-bar mr-3"></i>Student Complaints Report</h1>
            <p class="mb-0">Result Verification Complaints Analysis</p>
            <small>Generated on <?php echo date('F j, Y \a\t g:i A'); ?></small>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <h4><i class="fas fa-filter mr-2"></i>Report Filters</h4>
            <form method="GET" class="row">
                <div class="col-md-3">
                    <label for="date_from">From Date:</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to">To Date:</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-3">
                    <label for="faculty_id">Faculty:</label>
                    <select id="faculty_id" name="faculty_id" class="form-control">
                        <option value="">All Faculties</option>
                        <?php foreach ($faculties as $faculty): ?>
                            <option value="<?php echo $faculty['faculty_id']; ?>" 
                                    <?php echo ($faculty_filter == $faculty['faculty_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($faculty['faculty_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status">Status:</label>
                    <select id="status" name="status" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?php echo ($status_filter == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Under Review" <?php echo ($status_filter == 'Under Review') ? 'selected' : ''; ?>>Under Review</option>
                        <option value="Resolved" <?php echo ($status_filter == 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                        <option value="Rejected" <?php echo ($status_filter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search mr-2"></i>Apply Filters
                    </button>
                    <a href="student_complaints_report.php" class="btn btn-secondary ml-2">
                        <i class="fas fa-times mr-2"></i>Clear Filters
                    </a>
                </div>
            </form>
        </div>

        <!-- Statistics Summary -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <div class="stats-number"><?php echo $stats['total_complaints'] ?? 0; ?></div>
                    <div>Total Complaints</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <div class="stats-number text-primary"><?php echo $stats['pending_count'] ?? 0; ?></div>
                    <div>Pending</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <div class="stats-number text-info"><?php echo $stats['under_review_count'] ?? 0; ?></div>
                    <div>Under Review</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <div class="stats-number text-success"><?php echo $stats['resolved_count'] ?? 0; ?></div>
                    <div>Resolved</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <div class="stats-number text-danger"><?php echo $stats['rejected_count'] ?? 0; ?></div>
                    <div>Rejected</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card text-center">
                    <div class="stats-number text-warning"><?php echo count($complaints_by_dept); ?></div>
                    <div>Departments</div>
                </div>
            </div>
        </div>

        <!-- Complaint Type Breakdown -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stats-card text-center">
                    <div class="stats-number complaint-type-fa"><?php echo $stats['fa_count'] ?? 0; ?></div>
                    <div>Fail Absent (FA)</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card text-center">
                    <div class="stats-number complaint-type-f"><?php echo $stats['f_count'] ?? 0; ?></div>
                    <div>Fail (F)</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card text-center">
                    <div class="stats-number complaint-type-incorrect"><?php echo $stats['incorrect_grade_count'] ?? 0; ?></div>
                    <div>Incorrect Grade</div>
                </div>
            </div>
        </div>

        <!-- Export Buttons -->
        <div class="export-buttons">
            <div class="card">
                <div class="card-body text-center">
                    <h5><i class="fas fa-download mr-2"></i>Export Options</h5>
                    <a href="api/export_student_complaints.php?<?php echo http_build_query($_GET); ?>" 
                       class="btn btn-success mr-2" target="_blank">
                        <i class="fas fa-file-excel mr-2"></i>Export to Excel
                    </a>
                    <button onclick="window.print()" class="btn btn-info">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </button>
                </div>
            </div>
        </div>

        <!-- Complaints by Department -->
        <?php if (empty($complaints_by_dept)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle mr-2"></i>
                No student complaints found matching the selected criteria.
            </div>
        <?php else: ?>
            <?php foreach ($complaints_by_dept as $dept_key => $dept_data): ?>
                <div class="department-section">
                    <div class="department-header">
                        <h5 class="mb-0">
                            <i class="fas fa-building mr-2"></i>
                            <?php echo htmlspecialchars($dept_data['faculty_name']); ?> - 
                            <?php echo htmlspecialchars($dept_data['department_name']); ?>
                            <span class="badge badge-light ml-2"><?php echo count($dept_data['complaints']); ?> complaints</span>
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Reg. Number</th>
                                    <th>Programme</th>
                                    <th>Course</th>
                                    <th>Complaint Type</th>
                                    <th>Status</th>
                                    <th>Date Submitted</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dept_data['complaints'] as $complaint): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($complaint['full_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($complaint['email']); ?></small>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($complaint['registration_number']); ?></code><br>
                                            <small class="text-muted">Year: <?php echo $complaint['year_of_entry']; ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($complaint['programme_name']); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($complaint['course_code']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($complaint['course_title']); ?></small>
                                        </td>
                                        <td>
                                            <span class="complaint-type-<?php echo strtolower(str_replace(' ', '-', $complaint['complaint_type'])); ?>">
                                                <?php echo htmlspecialchars($complaint['complaint_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-<?php echo strtolower(str_replace(' ', '-', $complaint['status'])); ?>">
                                                <?php echo htmlspecialchars($complaint['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($complaint['created_at'])); ?><br>
                                            <small class="text-muted"><?php echo date('g:i A', strtotime($complaint['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($complaint['description'])): ?>
                                                <small><?php echo htmlspecialchars(substr($complaint['description'], 0, 100)); ?>
                                                <?php if (strlen($complaint['description']) > 100): ?>...<?php endif; ?></small>
                                            <?php else: ?>
                                                <em class="text-muted">No description</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Footer -->
        <div class="text-center mt-4 mb-4">
            <small class="text-muted">
                Report generated by <?php echo htmlspecialchars($_SESSION['full_name']); ?> on <?php echo date('F j, Y \a\t g:i A'); ?>
            </small>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        // Auto-submit form when filters change
        $('#faculty_id, #status').change(function() {
            // Optional: Auto-submit on change
            // $(this).closest('form').submit();
        });
        
        // Print styles
        window.addEventListener('beforeprint', function() {
            document.querySelector('.export-buttons').style.display = 'none';
        });
        
        window.addEventListener('afterprint', function() {
            document.querySelector('.export-buttons').style.display = 'block';
        });
    </script>
</body>
</html>