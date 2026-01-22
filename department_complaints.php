<?php
session_start();

// Only allow departments (role_id = 7)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role_id"] != 7){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "includes/notifications.php";

$success_message = "";
$error_message = "";

// Handle bulk delete
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["bulk_delete"])){
    if(isset($_POST["selected_complaints"]) && is_array($_POST["selected_complaints"])){
        $complaint_ids = array_map('intval', $_POST["selected_complaints"]);
        $placeholders = str_repeat('?,', count($complaint_ids) - 1) . '?';
        
        $sql = "DELETE FROM complaints WHERE complaint_id IN ($placeholders) AND lodged_by = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            $types = str_repeat('i', count($complaint_ids)) . 'i';
            $params = array_merge($complaint_ids, [$_SESSION["user_id"]]);
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            
            if(mysqli_stmt_execute($stmt)){
                $deleted_count = mysqli_stmt_affected_rows($stmt);
                $success_message = "Successfully deleted $deleted_count complaint(s).";
            } else {
                $error_message = "Error deleting complaints. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please select complaints to delete.";
    }
}

// Handle single complaint edit
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["edit_complaint"])){
    $complaint_id = intval($_POST["complaint_id"]);
    $complaint_text = trim($_POST["complaint_text"]);
    $is_urgent = isset($_POST["is_urgent"]) ? 1 : 0;
    
    if(!empty($complaint_text)){
        // Handle image uploads for edit
        $image_paths = array();
        $existing_images = $_POST["existing_images"] ?? '';
        
        if(isset($_FILES["edit_images"]) && !empty($_FILES["edit_images"]["name"][0])){
            $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
            $target_dir = "uploads/";
            
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            
            foreach($_FILES["edit_images"]["tmp_name"] as $key => $tmp_name){
                if($_FILES["edit_images"]["error"][$key] == 0){
                    $filename = $_FILES["edit_images"]["name"][$key];
                    $filetype = $_FILES["edit_images"]["type"][$key];
                    $filesize = $_FILES["edit_images"]["size"][$key];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if(!array_key_exists($ext, $allowed)) continue;
                    
                    $maxsize = 5 * 1024 * 1024;
                    if($filesize > $maxsize) continue;
                    
                    if(in_array($filetype, $allowed)){
                        $new_filename = uniqid() . "." . $ext;
                        $target_file = $target_dir . $new_filename;
                        
                        if(move_uploaded_file($tmp_name, $target_file)){
                            chmod($target_file, 0644);
                            $image_paths[] = $new_filename;
                        }
                    }
                }
            }
        }
        
        // Merge new images with existing
        $all_images = $existing_images;
        if(!empty($image_paths)){
            $all_images = $existing_images ? $existing_images . ',' . implode(",", $image_paths) : implode(",", $image_paths);
        }
        
        $sql = "UPDATE complaints SET complaint_text = ?, is_urgent = ?, image_path = ? WHERE complaint_id = ? AND lodged_by = ?";
        if($stmt = mysqli_prepare($conn, $sql)){
            mysqli_stmt_bind_param($stmt, "sisii", $complaint_text, $is_urgent, $all_images, $complaint_id, $_SESSION["user_id"]);
            
            if(mysqli_stmt_execute($stmt)){
                $success_message = "Complaint updated successfully.";
            } else {
                $error_message = "Error updating complaint. Please try again.";
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $error_message = "Please enter complaint details.";
    }
}

// Handle single delete
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["delete_single"])){
    $complaint_id = intval($_POST["complaint_id"]);
    
    $sql = "DELETE FROM complaints WHERE complaint_id = ? AND lodged_by = ?";
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "ii", $complaint_id, $_SESSION["user_id"]);
        
        if(mysqli_stmt_execute($stmt)){
            $success_message = "Complaint deleted successfully.";
        } else {
            $error_message = "Error deleting complaint. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}

// Get department's complaints with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM complaints WHERE lodged_by = ?";
$total_complaints = 0;
if($stmt = mysqli_prepare($conn, $count_sql)){
    mysqli_stmt_bind_param($stmt, "i", $_SESSION["user_id"]);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if($row = mysqli_fetch_assoc($result)){
        $total_complaints = $row['total'];
    }
    mysqli_stmt_close($stmt);
}

$total_pages = ceil($total_complaints / $per_page);

// Get complaints for current page
$complaints = array();
$sql = "SELECT c.*, u.full_name as handler_name 
        FROM complaints c 
        LEFT JOIN users u ON c.handled_by = u.user_id 
        WHERE c.lodged_by = ? 
        ORDER BY c.created_at DESC 
        LIMIT ? OFFSET ?";

if($stmt = mysqli_prepare($conn, $sql)){
    mysqli_stmt_bind_param($stmt, "iii", $_SESSION["user_id"], $per_page, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $complaints = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
}

// Fetch app settings
$app_name = 'TSU ICT Help Desk';
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Complaints - <?php echo htmlspecialchars($app_name); ?></title>
    
    <!-- Dynamic Favicon -->
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/navbar.css">
    
    <style>
        .complaint-row {
            transition: background-color 0.2s;
        }
        .complaint-row:hover {
            background-color: #f8f9fa;
        }
        .complaint-row.selected {
            background-color: #e3f2fd;
        }
        .bulk-actions {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
            display: none;
        }
        .bulk-actions.show {
            display: block;
        }
        .complaint-text {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .action-buttons .btn {
            margin: 2px;
        }
        .pagination-wrapper {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-list-alt mr-2"></i>Manage Department Complaints</h2>
                    <div>
                        <a href="department_dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                        <a href="department_dashboard.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Complaint
                        </a>
                    </div>
                </div>
                
                <?php if(!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if(!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error_message; ?>
                        <button type="button" class="close" data-dismiss="alert">
                            <span>&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <!-- Bulk Actions Panel -->
                <div id="bulkActions" class="bulk-actions">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><span id="selectedCount">0</span> complaint(s) selected</strong>
                        </div>
                        <div>
                            <button type="button" class="btn btn-danger" onclick="confirmBulkDelete()">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearSelection()">
                                <i class="fas fa-times"></i> Clear Selection
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-clipboard-list mr-2"></i>
                                Your Complaints (<?php echo $total_complaints; ?> total)
                            </h5>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAll">
                                <label class="form-check-label" for="selectAll">
                                    Select All
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if(empty($complaints)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox text-muted" style="font-size: 48px;"></i>
                                <h5 class="mt-3">No Complaints Found</h5>
                                <p class="text-muted">You haven't submitted any complaints yet.</p>
                                <a href="department_dashboard.php" class="btn btn-primary">Submit Your First Complaint</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th width="50">
                                                <input type="checkbox" id="selectAllTable" onchange="toggleSelectAll()">
                                            </th>
                                            <th>Complaint</th>
                                            <th>Status</th>
                                            <th>Priority</th>
                                            <th>Handler</th>
                                            <th>Created</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($complaints as $complaint): ?>
                                            <tr class="complaint-row" data-id="<?php echo $complaint['complaint_id']; ?>">
                                                <td>
                                                    <input type="checkbox" class="complaint-checkbox" 
                                                           value="<?php echo $complaint['complaint_id']; ?>" 
                                                           onchange="updateSelection()">
                                                </td>
                                                <td>
                                                    <div class="complaint-text" title="<?php echo htmlspecialchars($complaint['complaint_text']); ?>">
                                                        <?php echo htmlspecialchars($complaint['complaint_text']); ?>
                                                    </div>
                                                    <small class="text-muted">ID: #<?php echo $complaint['complaint_id']; ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $complaint['status'] == 'Treated' ? 'success' : ($complaint['status'] == 'In Progress' ? 'warning' : 'secondary'); ?>">
                                                        <?php echo $complaint['status']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if($complaint['is_urgent']): ?>
                                                        <span class="badge badge-danger">Urgent</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-info">Normal</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if($complaint['handler_name']): ?>
                                                        <small class="text-success">
                                                            <i class="fas fa-user-check"></i> 
                                                            <?php echo htmlspecialchars($complaint['handler_name']); ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <small class="text-muted">
                                                            <i class="fas fa-clock"></i> Unassigned
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M j, Y H:i', strtotime($complaint['created_at'])); ?></small>
                                                </td>
                                                <td class="action-buttons">
                                                    <a href="view_complaint.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                                       class="btn btn-sm btn-info" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-warning" 
                                                            onclick="editComplaint(<?php echo $complaint['complaint_id']; ?>)" 
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger" 
                                                            onclick="deleteComplaint(<?php echo $complaint['complaint_id']; ?>)" 
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if($total_pages > 1): ?>
                                <div class="pagination-wrapper">
                                    <nav>
                                        <ul class="pagination">
                                            <?php if($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?php echo $page-1; ?>">Previous</a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php for($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
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
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Complaint Modal -->
    <div class="modal fade" id="editComplaintModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Complaint</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form id="editComplaintForm" method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" id="editComplaintId" name="complaint_id">
                        <input type="hidden" id="existingImages" name="existing_images">
                        
                        <div class="form-group">
                            <label>Complaint Details</label>
                            <textarea id="editComplaintText" name="complaint_text" class="form-control" rows="5" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Add New Images (Optional)</label>
                            <input type="file" name="edit_images[]" class="form-control-file" accept="image/*" multiple>
                            <small class="form-text text-muted">Supported formats: JPG, JPEG, PNG, GIF (Max size: 5MB per image)</small>
                        </div>
                        
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="editIsUrgent" name="is_urgent">
                                <label class="custom-control-label" for="editIsUrgent">
                                    <i class="fas fa-exclamation-triangle mr-1 text-danger"></i>Mark as Urgent
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_complaint" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Complaint
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this complaint?</p>
                    <p class="text-danger"><strong>This action cannot be undone.</strong></p>
                </div>
                <div class="modal-footer">
                    <form id="deleteForm" method="post">
                        <input type="hidden" id="deleteComplaintId" name="complaint_id">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_single" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bulk Delete Form (hidden) -->
    <form id="bulkDeleteForm" method="post" style="display: none;">
        <input type="hidden" name="bulk_delete" value="1">
        <div id="selectedComplaintsInputs"></div>
    </form>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
    let selectedComplaints = [];
    
    function updateSelection() {
        selectedComplaints = [];
        $('.complaint-checkbox:checked').each(function() {
            selectedComplaints.push($(this).val());
        });
        
        $('#selectedCount').text(selectedComplaints.length);
        
        if (selectedComplaints.length > 0) {
            $('#bulkActions').addClass('show');
        } else {
            $('#bulkActions').removeClass('show');
        }
        
        // Update select all checkbox
        const totalCheckboxes = $('.complaint-checkbox').length;
        const checkedCheckboxes = $('.complaint-checkbox:checked').length;
        
        $('#selectAllTable').prop('indeterminate', checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes);
        $('#selectAllTable').prop('checked', checkedCheckboxes === totalCheckboxes);
    }
    
    function toggleSelectAll() {
        const isChecked = $('#selectAllTable').is(':checked');
        $('.complaint-checkbox').prop('checked', isChecked);
        updateSelection();
    }
    
    function clearSelection() {
        $('.complaint-checkbox').prop('checked', false);
        $('#selectAllTable').prop('checked', false);
        updateSelection();
    }
    
    function editComplaint(complaintId) {
        // Show loading state
        $('#editComplaintText').val('Loading...');
        $('#editComplaintModal').modal('show');
        
        // Fetch complaint details via AJAX
        $.ajax({
            url: 'api/get_complaint_details.php',
            type: 'GET',
            data: { id: complaintId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const complaint = response.complaint;
                    $('#editComplaintId').val(complaintId);
                    $('#editComplaintText').val(complaint.complaint_text);
                    $('#editIsUrgent').prop('checked', complaint.is_urgent == 1);
                    $('#existingImages').val(complaint.image_path || '');
                } else {
                    alert('Error loading complaint details: ' + (response.error || 'Unknown error'));
                    $('#editComplaintModal').modal('hide');
                }
            },
            error: function() {
                alert('Error loading complaint details. Please try again.');
                $('#editComplaintModal').modal('hide');
            }
        });
    }
    
    function deleteComplaint(complaintId) {
        $('#deleteComplaintId').val(complaintId);
        $('#deleteModal').modal('show');
    }
    
    function confirmBulkDelete() {
        if (selectedComplaints.length === 0) {
            alert('Please select complaints to delete.');
            return;
        }
        
        if (confirm(`Are you sure you want to delete ${selectedComplaints.length} complaint(s)? This action cannot be undone.`)) {
            // Add selected complaint IDs to the form
            $('#selectedComplaintsInputs').empty();
            selectedComplaints.forEach(function(id) {
                $('#selectedComplaintsInputs').append(`<input type="hidden" name="selected_complaints[]" value="${id}">`);
            });
            
            $('#bulkDeleteForm').submit();
        }
    }
    
    $(document).ready(function() {
        // Auto-dismiss alerts
        $('.alert').delay(5000).fadeOut();
        
        // Initialize selection state
        updateSelection();
    });
    </script>
</body>
</html>