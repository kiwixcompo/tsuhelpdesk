<?php
session_start();
require_once "config.php";

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// Get the date parameter
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Validate date format
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
    $date = date('Y-m-d'); // Default to today if invalid format
}

// Fetch app settings for header use
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

// Helper function to get image URL with fallback
function getImageUrl($image) {
    $image = trim($image);
    if (empty($image) || $image === '0') {
        return '';
    }
    
    // Clean the filename
    $filename = basename($image);
    
    // Use the public image serving script for better error handling
    return 'public_image.php?img=' . urlencode($filename);
}

// Alternative direct path function (for fallback)
function getDirectImagePath($image) {
    $image = trim($image);
    if (empty($image) || $image === '0') {
        return '';
    }
    
    // Clean the filename
    $filename = basename($image);
    
    // Always use the public image serving script
    return 'public_image.php?img=' . urlencode($filename);
}

// Build the query based on user role
$where_conditions = ["DATE(c.created_at) = ?"];
$params = [$date];
$param_types = "s";

// Add role-specific conditions
if ($_SESSION["role_id"] == 1) { // Admin - all complaints
    // No additional conditions
} elseif ($_SESSION["role_id"] == 2) { // Staff - regular complaints
    $where_conditions[] = "c.is_i4cus = 0 AND c.is_payment_related = 0";
} elseif ($_SESSION["role_id"] == 3) { // Director - all complaints
    // No additional conditions
} elseif ($_SESSION["role_id"] == 4) { // DVC - all complaints
    // No additional conditions
} elseif ($_SESSION["role_id"] == 5) { // i4Cus Staff - i4cus complaints
    $where_conditions[] = "c.is_i4cus = 1";
} elseif ($_SESSION["role_id"] == 6) { // Payment Admin - payment complaints
    $where_conditions[] = "c.is_payment_related = 1";
} elseif ($_SESSION["role_id"] == 8) { // Deputy Director ICT - i4cus complaints
    $where_conditions[] = "c.is_i4cus = 1";
} else { // Regular user - only their complaints
    $where_conditions[] = "c.lodged_by = ?";
    $params[] = $_SESSION['user_id'];
    $param_types .= "i";
}

// Build WHERE clause
$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Fetch complaints with user names (removed departments table join)
$sql = "SELECT c.*, 
               u1.full_name as lodged_by_name,
               u2.full_name as handler_name
        FROM complaints c 
        LEFT JOIN users u1 ON c.lodged_by = u1.user_id
        LEFT JOIN users u2 ON c.handled_by = u2.user_id
        $where_clause 
        ORDER BY c.status != 'Treated', c.is_urgent DESC, c.created_at DESC";

$complaints = [];
$untreated_count = 0;
$treated_count = 0;

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $complaints[] = $row;
            if($row['status'] == 'Treated') {
                $treated_count++;
            } else {
                $untreated_count++;
            }
        }
    }
    mysqli_stmt_close($stmt);
}

// Format date for display
$formatted_date = date('l, F j, Y', strtotime($date));

// Determine if this is a past date
$is_past_date = $date < date('Y-m-d');

// Handle status update form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status']) && isset($_POST['complaint_id'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $status = $_POST['status'];
    $feedback = trim($_POST['feedback']);
    $handler_id = $_SESSION['user_id'];
    $sql = "UPDATE complaints SET status=?, feedback=?, handled_by=?, updated_at=NOW() WHERE complaint_id=?";
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "ssii", $status, $feedback, $handler_id, $complaint_id);
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Complaint updated successfully.";
            // Refresh to show updated status
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $error_message = "Failed to update complaint. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaints for <?php echo htmlspecialchars($formatted_date); ?> - <?php echo htmlspecialchars($app_name); ?></title>
    <?php if($app_favicon): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($app_favicon); ?>" type="image/x-icon">
    <?php endif; ?>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <style>
        .app-logo {
            height: 30px;
            margin-right: 10px;
        }
        .complaint-card {
            margin-bottom: 20px;
            border-left: 5px solid #007bff;
        }
        .complaint-card.treated {
            border-left: 5px solid #28a745;
        }
        .complaint-card.urgent {
            border-left: 5px solid #dc3545;
        }
        .status-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        .gallery-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .gallery-item {
            width: 80px;
            height: 80px;
            border-radius: 4px;
            overflow: hidden;
            position: relative;
            cursor: pointer;
        }
        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .gallery-item.zoomed img {
            transform: scale(1.1);
        }
        .image-count-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 6px;
            border-radius: 0 0 0 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .view-all-btn {
            margin-top: 10px;
        }
        .image-error {
            background: #f8f9fa;
            border: 1px dashed #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            text-align: center;
            width: 100%;
            height: 100%;
            border-radius: 4px;
        }
        .date-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .date-stat {
            padding: 10px 15px;
            border-radius: 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-width: 120px;
        }
        .date-stat .number {
            font-size: 24px;
            font-weight: bold;
        }
        .date-stat .label {
            font-size: 14px;
        }
        .total-stat {
            background-color: #f8f9fa;
        }
        .untreated-stat {
            background-color: #fff3cd;
            color: #856404;
        }
        .treated-stat {
            background-color: #d4edda;
            color: #155724;
        }
        .past-date-alert {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <?php if($app_logo && file_exists($app_logo)): ?>
                    <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="app-logo">
                <?php endif; ?>
                <?php echo htmlspecialchars($app_name); ?>
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <?php if($_SESSION["role_id"] == 6): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="payment_admin_dashboard.php">Dashboard</a>
                        </li>
                    <?php elseif($_SESSION["role_id"] == 5): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="i4cus_staff_dashboard.php">Dashboard</a>
                        </li>
                    <?php elseif($_SESSION["role_id"] == 4): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="dvc_dashboard.php">Dashboard</a>
                        </li>
                    <?php elseif($_SESSION["role_id"] == 3): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="director_dashboard.php">Dashboard</a>
                        </li>
                    <?php elseif($_SESSION["role_id"] == 1): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="admin.php">Dashboard</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">Dashboard</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="account.php">Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">Messages</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Complaints for <?php echo htmlspecialchars($formatted_date); ?></h2>
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
            <a href="<?php echo $back_dashboard; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if($is_past_date): ?>
            <div class="alert alert-warning past-date-alert">
                <i class="fas fa-calendar-alt"></i> You are viewing complaints from a past date.
            </div>
        <?php endif; ?>

        <div class="date-summary">
            <div class="date-stat total-stat">
                <div class="number"><?php echo count($complaints); ?></div>
                <div class="label">Total Complaints</div>
            </div>
            <div class="date-stat untreated-stat">
                <div class="number"><?php echo $untreated_count; ?></div>
                <div class="label">Untreated</div>
            </div>
            <div class="date-stat treated-stat">
                <div class="number"><?php echo $treated_count; ?></div>
                <div class="label">Treated</div>
            </div>
        </div>

        <?php if(empty($complaints)): ?>
            <div class="alert alert-info">
                No complaints found for this date.
            </div>
        <?php else: ?>
            <?php foreach($complaints as $complaint): ?>
                <div class="card complaint-card <?php echo $complaint['status'] == 'Treated' ? 'treated' : ''; ?> <?php echo $complaint['is_urgent'] ? 'urgent' : ''; ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Student ID:</strong> <?php echo htmlspecialchars($complaint['student_id']); ?>
                            <?php if($complaint['is_urgent']): ?>
                                <span class="badge badge-danger ml-2">URGENT</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="badge <?php echo $complaint['status'] == 'Treated' ? 'badge-success' : 'badge-warning'; ?> status-badge">
                                <?php echo htmlspecialchars($complaint['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <?php if(isset($complaint['department_name'])): ?>
                                    <p><strong>Department:</strong> <?php echo htmlspecialchars($complaint['department_name'] ?? 'N/A'); ?></p>
                                <?php endif; ?>
                                <p><strong>Staff Name:</strong> <?php echo htmlspecialchars($complaint['staff_name'] ?? 'N/A'); ?></p>
                                <p><strong>Lodged By:</strong> <?php echo htmlspecialchars($complaint['lodged_by_name'] ?? 'N/A'); ?></p>
                                <p><strong>Complaint:</strong> <?php echo htmlspecialchars($complaint['complaint_text']); ?></p>
                                <?php if($complaint['status'] == 'Treated' && !empty($complaint['resolution'])): ?>
                                    <p><strong>Resolution:</strong> <?php echo htmlspecialchars($complaint['resolution']); ?></p>
                                    <p><strong>Handled By:</strong> <?php echo htmlspecialchars($complaint['handler_name'] ?? 'N/A'); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <?php 
                                if (!empty($complaint['image_path']) && $complaint['image_path'] !== '0'): 
                                    $images = array_filter(explode(",", $complaint['image_path']));
                                    $images = array_map('trim', $images);
                                    $images = array_filter($images); // Remove empty values
                                    $img_count = count($images);
                                    if ($img_count > 0):
                                ?>
                                <div>
                                    <strong>Attached Images:</strong>
                                    <div class="gallery-container">
                                        <?php foreach($images as $index => $image): 
                                            if($index < 3): 
                                                $image_url = getImageUrl($image);
                                                $direct_path = getDirectImagePath($image);
                                        ?>
                                            <div class="gallery-item" onclick="toggleZoom(this)">
                                                <img src="<?php echo htmlspecialchars($direct_path); ?>" 
                                                     alt="Complaint Image <?php echo $index+1; ?>"
                                                     loading="lazy"
                                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-error\'>Image not available</div>'">
                                                <?php if($img_count > 3 && $index == 2): ?>
                                                    <div class="image-count-badge">+<?php echo $img_count - 3; ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if($img_count > 3): ?>
                                        <button type="button" class="btn btn-sm btn-info view-all-btn" 
                                                onclick="showGalleryModal(<?php echo htmlspecialchars(json_encode(array_map('getDirectImagePath', $images))); ?>)">
                                            <i class="fas fa-images"></i> View All (<?php echo $img_count; ?>)
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php 
                                    endif;
                                endif; 
                                ?>
                            </div>
                        </div>
                        <!-- Status update form for permitted users -->
                        <?php
                        $can_update = false;
                        if (in_array($_SESSION['role_id'], [1,3,4,5,6]) || $_SESSION['user_id'] == $complaint['handled_by']) {
                            if ($complaint['status'] != 'Treated') {
                                $can_update = true;
                            }
                        }
                        ?>
                        <?php if($can_update): ?>
                        <form method="post" class="mb-3">
                            <input type="hidden" name="complaint_id" value="<?php echo $complaint['complaint_id']; ?>">
                            <div class="form-group">
                                <label for="status_<?php echo $complaint['complaint_id']; ?>">Update Status</label>
                                <select name="status" id="status_<?php echo $complaint['complaint_id']; ?>" class="form-control" required>
                                    <option value="Pending"<?php echo ($complaint['status']=='Pending'?' selected':''); ?>>Pending</option>
                                    <option value="Treated"<?php echo ($complaint['status']=='Treated'?' selected':''); ?>>Treated</option>
                                    <option value="Needs More Info"<?php echo ($complaint['status']=='Needs More Info'?' selected':''); ?>>Needs More Info</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="feedback_<?php echo $complaint['complaint_id']; ?>">Feedback</label>
                                <textarea name="feedback" id="feedback_<?php echo $complaint['complaint_id']; ?>" class="form-control" rows="3"><?php echo htmlspecialchars($complaint['feedback']??''); ?></textarea>
                            </div>
                            <button type="submit" name="update_status" class="btn btn-success">Update & Give Feedback</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer text-muted d-flex justify-content-between">
                        <div>
                            <small><i class="far fa-clock"></i> <?php echo date('F j, Y g:i A', strtotime($complaint['created_at'])); ?></small>
                        </div>
                        <div>
                            <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Gallery Modal -->
    <div class="modal fade" id="galleryModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Image Gallery</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <img id="mainGalleryImage" class="img-fluid" style="max-height: 400px;" alt="Gallery Image">
                        <div class="mt-2">
                            <span id="galleryImageCount"></span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center mb-3">
                        <button class="btn btn-sm btn-outline-secondary mr-2" onclick="navigateGallery(-1)">
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="navigateGallery(1)">
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div id="galleryThumbnails" class="d-flex flex-wrap justify-content-center"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        function toggleZoom(element) {
            element.classList.toggle('zoomed');
        }
        
        function showGalleryModal(images) {
            const modal = $('#galleryModal');
            const mainImage = $('#mainGalleryImage');
            const thumbContainer = $('#galleryThumbnails');
            const countDisplay = $('#galleryImageCount');
            
            // Clear existing thumbnails
            thumbContainer.empty();
            
            // Set the first image as main image
            if (images.length > 0) {
                mainImage.attr('src', images[0]);
                countDisplay.text(`1 of ${images.length}`);
                
                // Add thumbnails
                images.forEach((src, index) => {
                    const thumb = $('<img>')
                        .addClass('gallery-thumb')
                        .attr('src', src)
                        .css({
                            width: '60px',
                            height: '60px',
                            objectFit: 'cover',
                            margin: '5px',
                            cursor: 'pointer',
                            border: index === 0 ? '2px solid #007bff' : '2px solid transparent'
                        })
                        .click(function() {
                            mainImage.attr('src', src);
                            countDisplay.text(`${index + 1} of ${images.length}`);
                            $('.gallery-thumb').css('border', '2px solid transparent');
                            $(this).css('border', '2px solid #007bff');
                        });
                    
                    thumbContainer.append(thumb);
                });
            }
            
            modal.modal('show');
        }
        
        function navigateGallery(direction) {
            const images = $('.gallery-thumb').map(function() { return $(this).attr('src'); }).get();
            if (images.length === 0) return;
            
            const currentSrc = $('#mainGalleryImage').attr('src');
            const currentIndex = images.indexOf(currentSrc);
            const newIndex = (currentIndex + direction + images.length) % images.length;
            
            $('#mainGalleryImage').attr('src', images[newIndex]);
            $('#galleryImageCount').text(`${newIndex + 1} of ${images.length}`);
            
            // Update thumbnail highlighting
            $('.gallery-thumb').css('border', '2px solid transparent');
            $('.gallery-thumb').eq(newIndex).css('border', '2px solid #007bff');
        }
    </script>
</body>
</html>