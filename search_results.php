<?php
session_start();
require_once 'config/db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get search parameters
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$query = "SELECT c.*, u.name as staff_name FROM complaints c LEFT JOIN users u ON c.staff_id = u.id WHERE 1=1";

if (!empty($student_id)) {
    $query .= " AND c.student_id LIKE '%$student_id%'";
}
if (!empty($start_date)) {
    $query .= " AND DATE(c.created_at) >= '$start_date'";
}
if (!empty($end_date)) {
    $query .= " AND DATE(c.created_at) <= '$end_date'";
}
if (!empty($status)) {
    $query .= " AND c.status = '$status'";
}

$query .= " ORDER BY c.created_at DESC";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results - ICT Complaint Desk</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-4">
        <h2>Search Results</h2>
        
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student ID</th>
                        <th>Subject</th>
                        <th>Staff Name</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo $row['student_id']; ?></td>
                        <td><?php echo $row['subject']; ?></td>
                        <td><?php echo $row['staff_name']; ?></td>
                        <td><?php echo $row['status']; ?></td>
                        <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                        <td>
                            <a href="view_complaint.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <?php
        // Determine back dashboard URL based on user role
        $back_dashboard = 'dashboard.php'; // Default for regular users
        
        // Always prioritize role-based redirection
        if ($_SESSION['role_id'] == 5) { // i4Cus Staff
            $back_dashboard = 'i4cus_staff_dashboard.php';
        } elseif ($_SESSION['role_id'] == 6) { // Payment Admin
            $back_dashboard = 'payment_admin_dashboard.php';
        } elseif ($_SESSION['role_id'] == 3) { // Director
            $back_dashboard = 'director_dashboard.php';
        } elseif ($_SESSION['role_id'] == 4) { // DVC
            $back_dashboard = 'dvc_dashboard.php';
        } elseif ($_SESSION['role_id'] == 1) { // Admin
            $back_dashboard = 'admin.php';
        }
        ?>
        <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>