<?php
session_start();

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";

// Get total count of treated complaints
$total_treated = 0;
$sql = "SELECT COUNT(*) as count FROM complaints WHERE status = 'Treated'";
$result = mysqli_query($conn, $sql);
if($result){
    $row = mysqli_fetch_assoc($result);
    $total_treated = $row['count'];
}

// Pagination
$complaints_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $complaints_per_page;
$total_pages = ceil($total_treated / $complaints_per_page);

// Fetch treated complaints with pagination
$treated_complaints = [];
$sql = "SELECT c.*, u1.full_name as handler_name, u2.full_name as lodged_by_name 
        FROM complaints c 
        LEFT JOIN users u1 ON c.handled_by = u1.user_id
        LEFT JOIN users u2 ON c.lodged_by = u2.user_id
        WHERE c.status = 'Treated'
        ORDER BY c.updated_at DESC
        LIMIT ? OFFSET ?";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "ii", $complaints_per_page, $offset);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $treated_complaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Process complaint deletion
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_complaints"])){
    $complaint_ids = isset($_POST["complaint_ids"]) ? $_POST["complaint_ids"] : [];
    
    if(!empty($complaint_ids)){
        $placeholders = str_repeat('?,', count($complaint_ids) - 1) . '?';
        $types = str_repeat('i', count($complaint_ids));
        
        // For staff, only allow deleting their own complaints
        if($_SESSION["role_id"] != 1){
            $sql = "DELETE FROM complaints WHERE complaint_id IN ($placeholders) AND lodged_by = ?";
            $types .= 'i';
            $complaint_ids[] = $_SESSION["user_id"];
        } else {
            $sql = "DELETE FROM complaints WHERE complaint_id IN ($placeholders)";
        }
        
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, $types, ...$complaint_ids);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "Selected complaints deleted successfully.";
                // Refresh page to update counts and list
                header("Location: treated_complaints.php");
                exit;
            } else{
                $error_message = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please select complaints to delete.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treated Complaints - TSU ICT Help Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">TSU ICT Help Desk</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <?php if($_SESSION["role_id"] == 5): ?>
                            <a class="nav-link" href="i4cus_staff_dashboard.php">Dashboard</a>
                        <?php elseif($_SESSION["role_id"] == 3): ?>
                            <a class="nav-link" href="director_dashboard.php">Dashboard</a>
                        <?php elseif($_SESSION["role_id"] == 4): ?>
                            <a class="nav-link" href="dvc_dashboard.php">Dashboard</a>
                        <?php else: ?>
                            <a class="nav-link" href="dashboard.php">Dashboard</a>
                        <?php endif; ?>
                    </li>
                    <?php if($_SESSION["role_id"] == 1): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="admin.php">Admin Panel</a>
                    </li>
                    <?php if($_SESSION["is_super_admin"]): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">Settings</a>
                    </li>
                    <?php endif; ?>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="account.php">
                            <i class="fas fa-user-circle"></i>
                            <?php 
                            $sql = "SELECT full_name, profile_photo FROM users WHERE user_id = ?";
                            if($stmt = mysqli_prepare($conn, $sql)){
                                mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
                                if(mysqli_stmt_execute($stmt)){
                                    $result = mysqli_stmt_get_result($stmt);
                                    if($user = mysqli_fetch_assoc($result)){
                                        echo htmlspecialchars($user['full_name']);
                                        if($user['profile_photo']){
                                            echo ' <img src="'.htmlspecialchars($user['profile_photo']).'" class="rounded-circle" style="width: 25px; height: 25px; object-fit: cover;">';
                                        }
                                    }
                                }
                                mysqli_stmt_close($stmt);
                            }
                            ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">Treated Complaints</h4>
                        <span class="badge badge-success">Total: <?php echo $total_treated; ?></span>
                    </div>
                    <div class="card-body">
                        <?php if(isset($success_message)): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>
                        <?php if(isset($error_message)): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <?php if(!empty($treated_complaints)): ?>
                            <form method="post" id="complaintForm">
                                <div class="mb-3">
                                    <button type="submit" name="delete_complaints" class="btn btn-danger btn-sm" 
                                            onclick="return confirm('Are you sure you want to delete the selected complaints?');">
                                        Delete Selected
                                    </button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" id="selectAll">
                                                    </div>
                                                </th>
                                                <th>Date Updated</th>
                                                <th>Student ID</th>
                                                <th>Complaint</th>
                                                <th>Lodged By</th>
                                                <th>Handled By</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($treated_complaints as $complaint): ?>
                                                <tr>
                                                    <td>
                                                        <?php 
                                                        // Show checkbox if user is admin or if complaint was lodged by current user
                                                        if($_SESSION["role_id"] == 1 || $complaint['lodged_by'] == $_SESSION["user_id"]): 
                                                        ?>
                                                        <div class="form-check">
                                                            <input type="checkbox" class="form-check-input complaint-checkbox" 
                                                                   name="complaint_ids[]" value="<?php echo $complaint['complaint_id']; ?>">
                                                        </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('M d, Y h:i A', strtotime($complaint['updated_at'])); ?></td>
                                                    <td><?php echo htmlspecialchars($complaint['student_id']); ?></td>
                                                    <td><?php echo substr(htmlspecialchars($complaint['complaint_text']), 0, 50) . '...'; ?></td>
                                                    <td><?php echo htmlspecialchars($complaint['lodged_by_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($complaint['handler_name']); ?></td>
                                                    <td>
                                                        <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                                           class="btn btn-sm btn-info">
                                                            View Details
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>

                            <!-- Pagination -->
                            <?php if($total_pages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php if($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page-1; ?>">Previous</a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page+1; ?>">Next</a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted text-center">No treated complaints found.</p>
                        <?php endif; ?>
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
        // Handle select all checkbox
        $('#selectAll').change(function() {
            $('.complaint-checkbox').prop('checked', $(this).prop('checked'));
        });

        // Update select all checkbox state when individual checkboxes change
        $('.complaint-checkbox').change(function() {
            if ($('.complaint-checkbox:checked').length == $('.complaint-checkbox').length) {
                $('#selectAll').prop('checked', true);
            } else {
                $('#selectAll').prop('checked', false);
            }
        });
    });
    </script>
</body>
</html>