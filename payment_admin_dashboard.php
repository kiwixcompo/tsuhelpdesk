<?php
// Start output buffering to prevent header issues
ob_start();

session_start();

// Only allow Payment Admin (role_id = 6)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 6){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";
require_once "calendar_helper.php";

// Initialize notification count
$notification_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $notification_count = getUnreadNotificationCount($conn, $_SESSION["user_id"]);
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

// IMPROVED: Helper function to get image URL with fallback
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

// Initialize variables
$search_id = isset($_GET['search_id']) ? trim($_GET['search_id']) : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Base WHERE conditions - only show payment-related complaints
$where = ["is_payment_related = 1"];
if ($search_id) {
    $where[] = "student_id LIKE '%" . mysqli_real_escape_string($conn, $search_id) . "%'";
    // When searching by student ID, include all complaints regardless of status
} else if ($filter_status) {
    // Only apply status filter when not searching by student ID
    $where[] = "status = '" . mysqli_real_escape_string($conn, $filter_status) . "'";
} else if ($filter_date) {
    // Filter by date
    $where[] = "DATE(created_at) = '" . mysqli_real_escape_string($conn, $filter_date) . "'";
} else {
    // By default, only show non-treated complaints unless explicitly filtered
    $where[] = "status != 'Treated'";
}

// Separate active and archived complaints
$where_active = $where;
$where_archived = $where;
$where_active[] = "status != 'Treated'";
$where_archived[] = "status = 'Treated'";

// Build WHERE clauses
$where_clause_active = $where_active ? 'WHERE ' . implode(' AND ', $where_active) : '';
$where_clause_archived = $where_archived ? 'WHERE ' . implode(' AND ', $where_archived) : '';

// Fetch paginated active complaints with lodger name
$sql_active = "SELECT c.*, u.full_name as lodged_by_name 
               FROM complaints c 
               LEFT JOIN users u ON c.lodged_by = u.user_id 
               $where_clause_active 
               ORDER BY c.created_at DESC LIMIT $per_page OFFSET $offset";
$result_active = mysqli_query($conn, $sql_active);
$active_complaints = [];
while($row = mysqli_fetch_assoc($result_active)){
    $active_complaints[] = $row;
}

// Count total active complaints for pagination
$sql_count = "SELECT COUNT(*) as total FROM complaints $where_clause_active";
$result_count = mysqli_query($conn, $sql_count);
$total_active = ($row = mysqli_fetch_assoc($result_count)) ? $row['total'] : 0;
$total_pages = ceil($total_active / $per_page);

// Fetch archived complaints (all treated) with lodger name
$sql_archived = "SELECT c.*, u.full_name as lodged_by_name 
                FROM complaints c 
                LEFT JOIN users u ON c.lodged_by = u.user_id 
                $where_clause_archived 
                ORDER BY c.created_at DESC";
$result_archived = mysqli_query($conn, $sql_archived);
$archived_complaints = [];
while($row = mysqli_fetch_assoc($result_archived)){
    $archived_complaints[] = $row;
}

// Helper function to get image path
function getImagePath($path) {
    if (strpos($path, 'http') === 0) {
        return $path; // Already a full URL
    } else {
        return "serve_image.php?path=" . urlencode($path);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Admin Dashboard - <?php echo htmlspecialchars($app_name); ?></title>
    
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
    <link rel="stylesheet" href="css/navbar.css">
    <script src="js/session-timeout.js"></script>
    
    <style>
        .app-logo {
            height: 30px;
            margin-right: 10px;
            object-fit: contain;
        }
        
        /* Gallery Styles */
        .gallery-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 15px;
        }
        .gallery-item {
            position: relative;
            width: 100px;
            height: 100px;
            overflow: hidden;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            cursor: pointer;
            border: 1px solid #dee2e6;
        }
        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        .gallery-item img.zoomed {
            transform: scale(2);
            cursor: zoom-out;
        }
        .image-count-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(0,0,0,0.7);
            color: white;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .view-all-btn {
            margin-top: 10px;
        }
        
        /* Image error handling */
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
        
        /* Loading placeholder */
        .image-loading {
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            width: 100%;
            height: 100%;
        }
    </style>
</head>
<body>
    <?php 
    // Set page variables for dashboard header
    $page_title = 'Payment Admin Dashboard';
    $page_subtitle = 'Manage payment-related complaints and issues';
    $page_icon = 'fas fa-credit-card';
    $breadcrumb_items = [
        ['title' => 'Payment Management', 'url' => '#']
    ];
    
    include 'includes/navbar.php'; 
    include 'includes/dashboard_header.php';
    ?>
    <div class="container main-content">
        
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Search & Filter Payment Complaints</h4>
            </div>
            <div class="card-body">
                <form method="get" class="form-inline mb-3">
                    <div class="form-group mr-2">
                        <input type="text" name="search_id" class="form-control" placeholder="Student ID" value="<?php echo htmlspecialchars($search_id ?? ''); ?>">
                    </div>
                    <div class="form-group mr-2">
                        <select name="filter_status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="Pending" <?php if($filter_status=='Pending') echo 'selected'; ?>>Pending</option>
                            <option value="Treated" <?php if($filter_status=='Treated') echo 'selected'; ?>>Treated</option>
                            <option value="Needs More Info" <?php if($filter_status=='Needs More Info') echo 'selected'; ?>>Needs More Info</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-info">Search/Filter</button>
                </form>
                
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">Complaints List</h5>
                            </div>
                            <div class="card-body">
                                <ul class="nav nav-tabs mb-3" id="complaintTabs" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" id="active-tab" data-toggle="tab" href="#active" role="tab">Active Complaints</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" id="archive-tab" data-toggle="tab" href="#archive" role="tab">Archive</a>
                                    </li>
                                </ul>
                                <div class="tab-content" id="complaintTabsContent">
                                    <div class="tab-pane fade show active" id="active" role="tabpanel">
                                        <?php if (empty($active_complaints)): ?>
                                            <div class="alert alert-info">
                                                No active payment-related complaints found.
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>Student ID</th>
                                                            <th>Department</th>
                                                            <th>Staff Name</th>
                                                            <th>Lodged By</th>
                                                            <th style="width: 30%;">Complaint</th>
                                                            <th>Status</th>
                                                            <th>Date Lodged</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach($active_complaints as $row): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($row['department_name'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($row['staff_name'] ?? ''); ?></td>
                                                            <td><?php echo htmlspecialchars($row['lodged_by_name'] ?? ''); ?></td>
                                                            <td style="width: 30%;"><?php echo htmlspecialchars($row['complaint_text'] ?? ''); ?>
<?php 
    if (!empty($row['image_path']) && $row['image_path'] !== '0'): 
        $images = array_filter(explode(",", $row['image_path']));
        $images = array_map('trim', $images);
        $images = array_filter($images); // Remove empty values
        $img_count = count($images);
        if ($img_count > 0):
?>
    <div class="mt-2">
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
                         onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'image-error\'>Image not available</div>';">
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
</td>
                                                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                                            <td>
                                                                <a href="view_complaint.php?id=<?php echo $row['complaint_id']; ?>&payment=1" class="btn btn-sm btn-info">View/Treat</a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <!-- Pagination -->
                                            <?php if ($total_pages > 1): ?>
                                            <nav aria-label="Page navigation">
                                                <ul class="pagination">
                                                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                                        <li class="page-item <?php if($i == $page) echo 'active'; ?>">
                                                            <a class="page-link" href="?page=<?php echo $i; ?>&search_id=<?php echo urlencode($search_id); ?>&filter_status=<?php echo urlencode($filter_status); ?>"><?php echo $i; ?></a>
                                                        </li>
                                                    <?php endfor; ?>
                                                </ul>
                                            </nav>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tab-pane fade" id="archive" role="tabpanel">
                                        <?php if (empty($archived_complaints)): ?>
                                            <div class="alert alert-info">
                                                No archived payment-related complaints found.
                                            </div>
                                        <?php else: ?>
                                            <h5 class="mb-3 text-success">Treated Payment Complaints</h5>
                                            <div class="table-responsive">
                                                <table class="table table-bordered table-hover">
                                                    <thead>
                                                        <tr>
                                                            <th>ID</th>
                                                            <th>Student ID</th>
                                                            <th>Department</th>
                                                            <th>Staff Name</th>
                                                            <th>Complaint</th>
                                                            <th>Status</th>
                                                            <th>Date Lodged</th>
                                                            <th>Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach($archived_complaints as $row): ?>
                                                        <tr class="table-success">
                                                            <td><?php echo $row['complaint_id']; ?></td>
                                                            <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['staff_name']); ?></td>
                                                            <td><?php echo htmlspecialchars($row['complaint_text']); ?></td>
                                                            <td><span class="badge badge-success">Treated</span></td>
                                                            <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                                            <td>
                                                                <a href="view_complaint.php?id=<?php echo $row['complaint_id']; ?>&payment=1" class="btn btn-sm btn-info">View</a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        
        <!-- Complaint Calendar at the bottom of the page -->
        <div class="row mt-4 mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Complaints Calendar</h5>
                        <?php if (isset($_GET['filter_date'])): ?>
                            <a href="<?php echo strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query(array_diff_key($_GET, ['filter_date' => ''])); ?>" class="btn btn-sm btn-light ml-2 float-right">Clear Date Filter</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php 
                        // Pass the viewing_past_date flag to exclude past dates from the main view
                        echo generateComplaintCalendar($conn, $_SESSION["role_id"], " AND is_payment_related = 1"); 
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
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
    
    <!-- Image handling scripts -->
    <script>
    // Toggle zoom on individual images
    function toggleZoom(element) {
        const img = element.querySelector('img');
        if (img && !img.classList.contains('error')) {
            img.classList.toggle('zoomed');
            img.style.cursor = img.classList.contains('zoomed') ? 'zoom-out' : 'zoom-in';
        }
    }
    
    // Show gallery modal with all images
    function showGalleryModal(images) {
        if (!images || images.length === 0) return;
        
        const modal = $('#galleryModal');
        const mainImg = $('#mainGalleryImage');
        const thumbContainer = $('#galleryThumbnails');
        const countSpan = $('#galleryImageCount');
        
        // Clear previous thumbnails
        thumbContainer.empty();
        
        // Set first image as main
        mainImg.attr('src', images[0]);
        mainImg.attr('onerror', "this.onerror=null; this.src='data:image/svg+xml;charset=utf-8,' + encodeURIComponent('<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"300\" height=\"200\"><rect width=\"300\" height=\"200\" fill=\"#f8f9fa\" stroke=\"#dee2e6\"/><text x=\"150\" y=\"100\" text-anchor=\"middle\" dy=\".3em\" fill=\"#6c757d\" font-family=\"Arial\" font-size=\"16\">Image not found</text></svg>');");
        countSpan.text(`1 of ${images.length}`);
        
        // Create thumbnails
        images.forEach((img, index) => {
            const thumb = $(`<img src="${img}" class="gallery-thumb m-1" style="width: 80px; height: 60px; object-fit: cover; cursor: pointer; border: 2px solid #ddd;" onerror="this.onerror=null; this.style.display='none';">`);
            thumb.on('click', () => {
                mainImg.attr('src', img);
                countSpan.text(`${index + 1} of ${images.length}`);
            });
            thumbContainer.append(thumb);
        });
        
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
    }
    </script>
    
    <!-- Auto-refresh complaints script -->
    <script src="assets/js/auto_refresh_complaints.js"></script>
    <script>
    $(function() {
        var userId = <?php echo $_SESSION["user_id"]; ?>;
        var userRoleId = <?php echo $_SESSION["role_id"]; ?>;
        function renderComplaint(complaint, userId, userRoleId) {
            let imagesHtml = '';
            let imgCount = 0;
            let images = [];
            if (complaint.image_path && complaint.image_path !== '0') {
                images = complaint.image_path.split(',').map(s => s.trim()).filter(Boolean);
                imgCount = images.length;
            }
            if (imgCount > 0) {
                imagesHtml = '<div class="mt-2"><strong>Attached Images:</strong><div class="gallery-container">' + images.slice(0,3).map((img, idx) => {
                    let directPath = 'public_image.php?img=' + encodeURIComponent(img.split('/').pop());
                    let badge = (imgCount > 3 && idx === 2) ? `<div class=\"image-count-badge\">+${imgCount-3}</div>` : '';
                    return `<div class=\"gallery-item\" onclick=\"toggleZoom(this)\"><img src=\"${directPath}\" alt=\"Complaint Image ${idx+1}\" loading=\"lazy\" onerror=\"this.onerror=null; this.parentElement.innerHTML='<div class=\\'image-error\\'>Image not available</div>'\;\">${badge}</div>`;
                }).join('') + '</div>';
                let allImages = images.map(img => 'public_image.php?img=' + encodeURIComponent(img.split('/').pop()));
                let viewAllBtn = imgCount > 3 ? `<button type=\"button\" class=\"btn btn-sm btn-info view-all-btn\" onclick=\"showGalleryModal(${JSON.stringify(allImages)})\"><i class=\"fas fa-images\"></i> View All (${imgCount})</button>` : '';
                imagesHtml += viewAllBtn + '</div>';
            }
            return `<tr class=\"new-complaint\"><td>${complaint.student_id || ''}</td><td>${complaint.department_name || ''}</td><td>${complaint.staff_name || ''}</td><td>${complaint.lodged_by_name || ''}</td><td style=\"width: 30%;\">${complaint.complaint_text || ''}${imagesHtml}</td><td>${complaint.status}</td><td>${complaint.created_at ? complaint.created_at.substr(0,10) : ''}</td><td><a href=\"view_complaint.php?id=${complaint.complaint_id}&payment=1\" class=\"btn btn-sm btn-info\">View/Treat</a></td></tr>`;
        }
        function getLastComplaintId() {
            let first = $('#active table tbody tr').first();
            let idMatch = first.find('a.btn-info').attr('href');
            if (idMatch) {
                let match = idMatch.match(/id=(\d+)/);
                if (match) return parseInt(match[1]);
            }
            return 0;
        }
        autoRefreshComplaints({
            container: '#active table tbody',
            afterSelector: 'tr:first',
            getLastId: getLastComplaintId,
            renderComplaint: renderComplaint,
            userId: userId,
            userRoleId: userRoleId
        });
    });
    </script>
    
    <!-- End of page scripts -->
</body>
</html>

<?php
// End output buffering and flush
ob_end_flush();
?>