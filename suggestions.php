<?php
session_start();

// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

require_once "config.php";
require_once "create_suggestions_table.php";

// Initialize notification variables for navbar
$notification_count = 0;
$unread_count = 0;

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

// Process suggestion submission
$suggestion_text = "";
$success_message = "";
$error_message = "";

// Check if user is super admin
$is_super_admin = isset($_SESSION["is_super_admin"]) && $_SESSION["is_super_admin"] == 1;

// Check for success or error messages in session and display them
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["submit_suggestion"])) {
    $suggestion_text = trim($_POST["suggestion_text"]);
    $user_id = $_SESSION["user_id"];
    
    if ($is_super_admin) {
        $_SESSION['error_message'] = "As a super admin, you implement suggestions rather than submit them.";
    } elseif (empty($suggestion_text)) {
        $_SESSION['error_message'] = "Please enter your suggestion.";
    } else {
        $sql = "INSERT INTO suggestions (user_id, suggestion_text) VALUES (?, ?)";
        
        if ($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "is", $user_id, $suggestion_text);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success_message'] = "Your suggestion has been submitted successfully!";
            } else {
                $_SESSION['error_message'] = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($stmt);
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: suggestions.php");
    exit;
}

// Fetch user's suggestions
$user_id = $_SESSION["user_id"];
$suggestions = [];

$sql = "SELECT s.*, u.full_name FROM suggestions s 
        JOIN users u ON s.user_id = u.user_id 
        WHERE s.user_id = ? 
        ORDER BY s.created_at DESC";

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $suggestions[] = $row;
        }
    }
    
    mysqli_stmt_close($stmt);
}

// For super admin, fetch all suggestions
$all_suggestions = [];
if (isset($_SESSION["is_super_admin"]) && $_SESSION["is_super_admin"] == 1) {
    $sql = "SELECT s.*, u.full_name FROM suggestions s 
            JOIN users u ON s.user_id = u.user_id 
            ORDER BY s.created_at DESC";
    
    $result = mysqli_query($conn, $sql);
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $all_suggestions[] = $row;
        }
    }
}

// Process admin response for individual suggestion update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_suggestion"]) && isset($_SESSION["is_super_admin"]) && $_SESSION["is_super_admin"] == 1) {
    $suggestion_id = $_POST["suggestion_id"];
    $status = $_POST["status"];
    $admin_response = trim($_POST["admin_response"]);
    
    $sql = "UPDATE suggestions SET status = ?, admin_response = ? WHERE suggestion_id = ?";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "ssi", $status, $admin_response, $suggestion_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success_message'] = "Suggestion updated successfully!";
            
            // Refresh the suggestions lists
            header("Location: suggestions.php");
            exit;
        } else {
            $_SESSION['error_message'] = "Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Process bulk actions
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["bulk_action"]) && ($_SESSION["is_super_admin"] || $_SESSION["user_type"] == "super_admin")) {
    $selected_suggestions = isset($_POST["selected_suggestions"]) ? $_POST["selected_suggestions"] : [];
    $bulk_action = $_POST["bulk_action"];
    
    if (!empty($selected_suggestions)) {
        if ($bulk_action == "delete") {
            // Mark suggestions as deleted
            $suggestion_ids = implode(",", array_map('intval', $selected_suggestions));
            $sql = "UPDATE suggestions SET is_deleted = 1 WHERE suggestion_id IN ($suggestion_ids)";
            
            if (mysqli_query($conn, $sql)) {
                $_SESSION['success_message'] = count($selected_suggestions) . " suggestion(s) deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Error deleting suggestions: " . mysqli_error($conn);
            }
        } else {
            // Update status for selected suggestions
            $suggestion_ids = implode(",", array_map('intval', $selected_suggestions));
            $sql = "UPDATE suggestions SET status = ? WHERE suggestion_id IN ($suggestion_ids)";
            
            if ($stmt = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($stmt, "s", $bulk_action);
                
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['success_message'] = count($selected_suggestions) . " suggestion(s) updated to '" . $bulk_action . "' status!";
                } else {
                    $_SESSION['error_message'] = "Error updating suggestions: " . mysqli_error($conn);
                }
                
                mysqli_stmt_close($stmt);
            }
        }
        
        // Refresh the page
        header("Location: suggestions.php");
        exit;
    } else {
        $_SESSION['error_message'] = "No suggestions selected for bulk action.";
        header("Location: suggestions.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suggestions - <?php echo htmlspecialchars($app_name); ?></title>
    
    <!-- Dynamic Favicon -->
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .app-logo {
            height: 30px;
            margin-right: 10px;
            object-fit: contain;
        }
        .suggestion-card {
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .suggestion-card.pending {
            border-left-color: #2196f3;
        }
        .suggestion-card.under-implementation {
            border-left-color: #17a2b8;
        }
        .suggestion-card.implemented {
            border-left-color: #28a745;
        }
        .suggestion-card.not-applicable {
            border-left-color: #6c757d;
        }
        .status-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <?php
    // Set up suggestions header variables
    $page_title = 'Suggestions Box';
    $page_subtitle = 'Share your ideas and feedback to improve our system';
    $page_icon = 'fas fa-lightbulb';
    $show_breadcrumb = false;
    
    include 'includes/dashboard_header.php';
    ?>

    <div class="container-fluid">
        <h2 class="mb-4">Suggestions Box</h2>
        
        <?php if(!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if(!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h4>Submit a Suggestion</h4>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="form-group">
                                <label for="suggestion_text">Your Suggestion</label>
                                <textarea class="form-control" id="suggestion_text" name="suggestion_text" rows="5" required><?php echo htmlspecialchars($suggestion_text); ?></textarea>
                                <small class="form-text text-muted">Share your observations and recommended improvements for the system.</small>
                            </div>
                            <button type="submit" name="submit_suggestion" class="btn btn-primary">Submit Suggestion</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>Your Suggestions</h4>
                    </div>
                    <div class="card-body">
                        <?php if(empty($suggestions)): ?>
                            <div class="alert alert-info">You haven't submitted any suggestions yet.</div>
                        <?php else: ?>
                            <?php foreach($suggestions as $suggestion): ?>
                                <div class="card suggestion-card <?php echo strtolower(str_replace(' ', '-', $suggestion['status'])); ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="text-muted"><?php echo date('F j, Y, g:i a', strtotime($suggestion['created_at'])); ?></span>
                                            <span class="badge status-badge <?php 
                                                switch($suggestion['status']) {
                                                    case 'Pending': echo 'badge-warning'; break;
                                                    case 'Under Implementation': echo 'badge-info'; break;
                                                    case 'Implemented': echo 'badge-success'; break;
                                                    case 'Not Applicable': echo 'badge-secondary'; break;
                                                    default: echo 'badge-secondary';
                                                }
                                            ?>"><?php echo $suggestion['status']; ?></span>
                                        </div>
                                        <p class="card-text"><?php echo nl2br(htmlspecialchars($suggestion['suggestion_text'])); ?></p>
                                        <?php if(!empty($suggestion['admin_response'])): ?>
                                            <hr>
                                            <div class="admin-response">
                                                <h6>Admin Response:</h6>
                                                <p><?php echo nl2br(htmlspecialchars($suggestion['admin_response'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if($_SESSION["is_super_admin"]): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4>All Suggestions (Admin View)</h4>
                    </div>
                    <div class="card-body">
                        <?php if(empty($all_suggestions)): ?>
                            <div class="alert alert-info">No suggestions have been submitted yet.</div>
                        <?php else: ?>
                            <!-- Bulk Action Form -->                            
                            <form method="post" id="bulkActionForm">
                                <div class="form-row mb-3">
                                    <div class="col-md-4">
                                        <select name="bulk_action" class="form-control" id="bulkAction">
                                            <option value="">-- Select Bulk Action --</option>
                                            <option value="Pending">Mark as Pending</option>
                                            <option value="Under Implementation">Mark as Under Implementation</option>
                                            <option value="Implemented">Mark as Implemented</option>
                                            <option value="Not Applicable">Mark as Not Applicable</option>
                                            <option value="delete">Delete Selected</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-secondary" id="applyBulkAction">Apply</button>
                                    </div>
                                </div>
                            
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th><input type="checkbox" id="selectAll"></th>
                                                <th>User</th>
                                                <th>Suggestion</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($all_suggestions as $suggestion): ?>
                                                <tr>
                                                    <td><input type="checkbox" name="selected_suggestions[]" value="<?php echo $suggestion['suggestion_id']; ?>"></td>
                                                    <td><?php echo htmlspecialchars($suggestion['full_name']); ?></td>
                                                    <td><?php echo htmlspecialchars(substr($suggestion['suggestion_text'], 0, 100)) . (strlen($suggestion['suggestion_text']) > 100 ? '...' : ''); ?></td>
                                                    <td>
                                                        <span class="badge <?php 
                                                            switch($suggestion['status']) {
                                                                case 'Pending': echo 'badge-warning'; break;
                                                                case 'Under Implementation': echo 'badge-info'; break;
                                                                case 'Implemented': echo 'badge-success'; break;
                                                                case 'Not Applicable': echo 'badge-secondary'; break;
                                                                default: echo 'badge-secondary';
                                                            }
                                                        ?>"><?php echo $suggestion['status']; ?></span>
                                                    </td>
                                                    <td><?php echo date('Y-m-d', strtotime($suggestion['created_at'])); ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#suggestionModal<?php echo $suggestion['suggestion_id']; ?>">Respond</button>
                                                    </td>
                                                </tr>
                                            
                                            <!-- Modal for responding to suggestion -->
                                            <div class="modal fade" id="suggestionModal<?php echo $suggestion['suggestion_id']; ?>" tabindex="-1" role="dialog" aria-labelledby="suggestionModalLabel<?php echo $suggestion['suggestion_id']; ?>" aria-hidden="true">
                                                <div class="modal-dialog modal-lg">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="suggestionModalLabel<?php echo $suggestion['suggestion_id']; ?>">Respond to Suggestion #<?php echo $suggestion['suggestion_id']; ?></h5>
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <form method="post" action="">
                                                                <input type="hidden" name="suggestion_id" value="<?php echo $suggestion['suggestion_id']; ?>">
                                                                
                                                                <div class="form-group">
                                                                    <label>Suggestion from <?php echo htmlspecialchars($suggestion['full_name']); ?> (<?php echo date('F j, Y, g:i a', strtotime($suggestion['created_at'])); ?>):</label>
                                                                    <div class="p-3 bg-light">
                                                                        <?php echo nl2br(htmlspecialchars($suggestion['suggestion_text'])); ?>
                                                                    </div>
                                                                </div>
                                                                
                                                                <div class="form-group">
                                                                    <label for="status<?php echo $suggestion['suggestion_id']; ?>">Update Status:</label>
                                                                    <select class="form-control" id="status<?php echo $suggestion['suggestion_id']; ?>" name="status">
                                                                        <option value="Pending" <?php if($suggestion['status'] == 'Pending') echo 'selected'; ?>>Pending</option>
                                                                        <option value="Under Implementation" <?php if($suggestion['status'] == 'Under Implementation') echo 'selected'; ?>>Under Implementation</option>
                                                                        <option value="Implemented" <?php if($suggestion['status'] == 'Implemented') echo 'selected'; ?>>Implemented</option>
                                                                        <option value="Not Applicable" <?php if($suggestion['status'] == 'Not Applicable' || $suggestion['status'] == 'Rejected') echo 'selected'; ?>>Not Applicable</option>
                                                                    </select>
                                                                </div>
                                                                
                                                                <div class="form-group">
                                                                    <label for="admin_response<?php echo $suggestion['suggestion_id']; ?>">Your Response:</label>
                                                                    <textarea class="form-control" id="admin_response<?php echo $suggestion['suggestion_id']; ?>" name="admin_response" rows="5"><?php echo htmlspecialchars($suggestion['admin_response'] ?? ''); ?></textarea>
                                                                </div>
                                                                
                                                                <button type="submit" name="update_suggestion" class="btn btn-primary">Update Suggestion</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script>
        // Handle select all checkbox
        $(document).ready(function() {
            $('#selectAll').click(function() {
                $('input[name="selected_suggestions[]"]').prop('checked', this.checked);
            });
            
            // Validate bulk action form submission
            $('#bulkActionForm').submit(function(e) {
                const action = $('#bulkAction').val();
                const selectedCount = $('input[name="selected_suggestions[]"]:checked').length;
                
                if (action === '') {
                    e.preventDefault();
                    alert('Please select an action to perform.');
                    return false;
                }
                
                if (selectedCount === 0) {
                    e.preventDefault();
                    alert('Please select at least one suggestion.');
                    return false;
                }
                
                if (action === 'delete') {
                    if (!confirm('Are you sure you want to delete the selected suggestions? This action cannot be undone.')) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                return true;
            });
        });
    </script>
</body>
</html>