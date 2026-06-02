<?php
session_start();

// Check if user is logged in and is super admin
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !$_SESSION["is_super_admin"]){
    header("location: index.php");
    exit;
}

require_once "config.php";

$success_message = $error_message = "";

// Process settings update
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["update_settings"])){
    foreach($_POST as $key => $value){
        if(strpos($key, 'setting_') === 0){
            $setting_key = substr($key, 8); // Remove 'setting_' prefix
            $setting_value = trim($value);
            
            $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "ss", $setting_value, $setting_key);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    }
    
    // Handle file uploads
    foreach($_FILES as $key => $file){
        if(strpos($key, 'setting_') === 0 && $file['error'] == 0){
            $setting_key = substr($key, 8); // Remove 'setting_' prefix
            
            // Different file types for favicon vs other images
            if($setting_key == 'app_favicon'){
                $allowed = array("ico" => "image/x-icon", "png" => "image/png", "jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif");
            } else {
                $allowed = array("jpg" => "image/jpg", "jpeg" => "image/jpeg", "gif" => "image/gif", "png" => "image/png");
            }
            
            $filename = $file["name"];
            $filetype = $file["type"];
            $filesize = $file["size"];
            
            // Verify file extension
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if(!array_key_exists($ext, $allowed)) {
                $error_message = "Error: Please select a valid image file format for " . $setting_key;
                continue;
            }
            
            // Verify file size - 5MB maximum
            $maxsize = 5 * 1024 * 1024;
            if($filesize > $maxsize) {
                $error_message = "Error: File size is larger than the allowed limit (5MB) for " . $setting_key;
                continue;
            }
            
            $target_dir = "uploads/settings/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            // Delete old file if exists
            $sql = "SELECT setting_value FROM settings WHERE setting_key = ?";
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "s", $setting_key);
                if(mysqli_stmt_execute($stmt)){
                    $result = mysqli_stmt_get_result($stmt);
                    if($row = mysqli_fetch_assoc($result)){
                        if($row['setting_value'] && file_exists($row['setting_value'])){
                            unlink($row['setting_value']);
                        }
                    }
                }
                mysqli_stmt_close($stmt);
            }
            
            // Special naming for favicon
            if($setting_key == 'app_favicon'){
                $new_filename = 'favicon.' . $ext;
            } else {
                $new_filename = $setting_key . '_' . uniqid() . '.' . $ext;
            }
            
            $target_file = $target_dir . $new_filename;
            
            if(move_uploaded_file($file["tmp_name"], $target_file)){
                $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
                if($stmt = mysqli_prepare($conn, $sql)){
                    mysqli_stmt_bind_param($stmt, "ss", $target_file, $setting_key);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
    
    if(empty($error_message)){
        $success_message = "Settings updated successfully.";
        // Invalidate settings cache
        if (isset($_SESSION['app_settings'])) {
            unset($_SESSION['app_settings']);
        }
    }
}

// Fetch all settings
$settings = [];
$sql = "SELECT * FROM settings ORDER BY setting_label";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        $settings[] = $row;
    }
}

// Get specific settings for header use
$app_name = '';
$app_logo = '';
$app_favicon = '';
foreach($settings as $setting){
    if($setting['setting_key'] == 'app_name') $app_name = $setting['setting_value'];
    if($setting['setting_key'] == 'app_logo') $app_logo = $setting['setting_value'];
    if($setting['setting_key'] == 'app_favicon') $app_favicon = $setting['setting_value'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - <?php echo htmlspecialchars($app_name ?: 'TSU ICT Help Desk'); ?></title>
    
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
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <?php
    // Set up settings header variables
    $page_title = 'System Settings';
    $page_subtitle = 'Configure application settings and preferences';
    $page_icon = 'fas fa-cog';
    $show_breadcrumb = true;
    $breadcrumb_items = [
        ['title' => 'Admin', 'url' => 'admin.php'],
        ['title' => 'System Settings', 'url' => '']
    ];
    
    include 'includes/dashboard_header.php';
    ?>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4><i class="fas fa-cog"></i> System Settings</h4>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($success_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                                <button type="button" class="close" data-dismiss="alert">
                                    <span>&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if(!empty($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                                <button type="button" class="close" data-dismiss="alert">
                                    <span>&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if(empty($settings)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> No settings found. Please contact the administrator to configure system settings.
                            </div>
                        <?php else: ?>
                            <form method="post" enctype="multipart/form-data">
                                <div class="row">
                                    <?php foreach($settings as $setting): ?>
                                        <div class="col-md-6 mb-4">
                                            <div class="card">
                                                <div class="card-body">
                                                    <label class="font-weight-bold">
                                                        <?php 
                                                        // Add icons for different setting types
                                                        $icon = '';
                                                        switch($setting['setting_key']) {
                                                            case 'app_name': $icon = '<i class="fas fa-tag"></i> '; break;
                                                            case 'app_logo': $icon = '<i class="fas fa-image"></i> '; break;
                                                            case 'app_favicon': $icon = '<i class="fas fa-star"></i> '; break;
                                                            case 'primary_color': $icon = '<i class="fas fa-palette"></i> '; break;
                                                            case 'secondary_color': $icon = '<i class="fas fa-palette"></i> '; break;
                                                            case 'puter_auth_token': $icon = '<i class="fas fa-key text-warning"></i> '; break;
                                                            default: $icon = '<i class="fas fa-cog"></i> '; break;
                                                        }
                                                        echo $icon . htmlspecialchars($setting['setting_label']); 
                                                        ?>
                                                    </label>
                                                    
                                                    <?php if($setting['setting_type'] == 'text'): ?>
                                                        <?php if($setting['setting_key'] == 'puter_auth_token'): ?>
                                                            <!-- Puter Live Interactive Account Manager -->
                                                            <div class="card border-primary mb-3 shadow-sm">
                                                                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-2" style="background: linear-gradient(135deg, #1e3c72, #2a5298) !important;">
                                                                    <h6 class="mb-0 text-white font-weight-bold" style="font-size: 0.9rem;"><i class="fas fa-user-circle mr-1"></i> Puter AI Active Browser Session</h6>
                                                                    <span id="puterLiveStatusBadge" class="badge badge-light" style="font-weight: 700;">Checking...</span>
                                                                </div>
                                                                <div class="card-body bg-light py-3">
                                                                    <p class="small text-muted mb-3" style="line-height: 1.45;">
                                                                        <i class="fas fa-info-circle text-primary"></i> <strong>Log in directly in your browser</strong> to run all AI features (Draft with AI, Rephrase, Auto-response suggestion) under your chosen upgraded Puter account credits and rate limits.
                                                                    </p>
                                                                    <div id="puterLiveUserPanel" class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 12px;">
                                                                        <div>
                                                                            <strong style="font-size: 1.05rem; color: #1e3c72;" id="puterLiveUsername"><i class="fas fa-spinner fa-spin mr-1"></i> Loading...</strong>
                                                                            <div class="text-muted small mt-1" id="puterLiveUserQuota">Connecting to Puter auth session...</div>
                                                                        </div>
                                                                        <button type="button" id="btnPuterLiveConnect" class="btn btn-sm btn-primary px-3 font-weight-bold" style="border-radius: 6px; box-shadow: 0 2px 6px rgba(30, 60, 114, 0.2);">
                                                                            <i class="fas fa-sign-in-alt mr-1"></i> Connect Account
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="border rounded p-3 mb-3 bg-white" style="border-style: dashed !important; border-width: 2px !important;">
                                                                <label class="font-weight-bold text-muted small uppercase mb-2"><i class="fas fa-tools mr-1"></i> Advanced: Enterprise Global API Auth Token (Optional)</label>
                                                                <div class="input-group">
                                                                    <input type="password" name="setting_<?php echo $setting['setting_key']; ?>" 
                                                                           id="puterTokenField"
                                                                           class="form-control form-control-sm" value="<?php echo htmlspecialchars($setting['setting_value'] ?? ''); ?>"
                                                                           placeholder="Enter Puter Auth Token">
                                                                    <div class="input-group-append">
                                                                        <button class="btn btn-outline-secondary btn-sm" type="button" id="togglePuterToken">
                                                                            <i class="fas fa-eye" id="togglePuterTokenIcon"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                                <small class="form-text text-muted mt-2" style="font-size: 0.78rem;">
                                                                    Provide a global API Auth Token to authenticate all student/staff browsers universally without requiring individual logins. Leave blank to prioritize the active browser login above.
                                                                </small>
                                                            </div>
                                                             
                                                             <!-- Puter Diagnostics Console -->
                                                             <div class="mt-3 p-3 bg-light rounded border" id="puterDiagnosticContainer" style="display:none; font-size: 0.85rem;">
                                                                 <div class="font-weight-bold mb-2 text-secondary" style="border-bottom: 1px solid #dee2e6; padding-bottom: 4px;"><i class="fas fa-terminal"></i> Puter AI Integration Status</div>
                                                                 <div class="mb-1">
                                                                     <strong>Active Account:</strong> <span id="diagPuterAccount" class="text-muted"><i class="fas fa-spinner fa-spin"></i> Checking...</span>
                                                                 </div>
                                                                 <div class="mb-2">
                                                                     <strong>AI Usage Test:</strong> <span id="diagPuterStatus" class="text-muted">Awaiting test...</span>
                                                                 </div>
                                                                 <button type="button" class="btn btn-xs btn-outline-info" id="btnTestPuterAI" style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px;">
                                                                     <i class="fas fa-play mr-1"></i> Run Connection & Quota Test
                                                                 </button>
                                                             </div>
                                                        <?php else: ?>
                                                            <input type="text" name="setting_<?php echo $setting['setting_key']; ?>" 
                                                                   class="form-control" value="<?php echo htmlspecialchars($setting['setting_value'] ?? ''); ?>"
                                                                   placeholder="Enter <?php echo strtolower($setting['setting_label']); ?>">
                                                        <?php endif; ?>
                                                    
                                                    <?php elseif($setting['setting_type'] == 'textarea'): ?>
                                                        <textarea name="setting_<?php echo $setting['setting_key']; ?>" 
                                                                  class="form-control" rows="3" 
                                                                  placeholder="Enter <?php echo strtolower($setting['setting_label']); ?>"><?php echo htmlspecialchars($setting['setting_value']); ?></textarea>
                                                    
                                                    <?php elseif($setting['setting_type'] == 'color'): ?>
                                                        <div class="input-group">
                                                            <input type="color" name="setting_<?php echo $setting['setting_key']; ?>" 
                                                                   class="form-control" value="<?php echo htmlspecialchars($setting['setting_value']); ?>"
                                                                   style="height: 50px;">
                                                            <div class="input-group-append">
                                                                <span class="input-group-text"><?php echo htmlspecialchars($setting['setting_value']); ?></span>
                                                            </div>
                                                        </div>
                                                    
                                                    <?php elseif($setting['setting_type'] == 'image'): ?>
                                                        <?php if($setting['setting_value'] && file_exists($setting['setting_value'])): ?>
                                                            <div class="mb-3 text-center">
                                                                <p class="mb-2"><strong>Current Image:</strong></p>
                                                                <?php if($setting['setting_key'] == 'app_favicon'): ?>
                                                                    <img src="<?php echo htmlspecialchars($setting['setting_value']); ?>" 
                                                                         alt="<?php echo htmlspecialchars($setting['setting_label']); ?>"
                                                                         style="width: 32px; height: 32px; border: 2px solid #ddd; padding: 5px;">
                                                                <?php else: ?>
                                                                    <img src="<?php echo htmlspecialchars($setting['setting_value']); ?>" 
                                                                         alt="<?php echo htmlspecialchars($setting['setting_label']); ?>"
                                                                         style="max-height: 100px; max-width: 200px; border: 2px solid #ddd; padding: 5px;">
                                                                <?php endif; ?>
                                                                <div class="mt-2">
                                                                    <small class="text-muted">
                                                                        Path: <?php echo htmlspecialchars($setting['setting_value']); ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <input type="file" name="setting_<?php echo $setting['setting_key']; ?>" 
                                                               class="form-control-file" 
                                                               accept="<?php echo $setting['setting_key'] == 'app_favicon' ? 'image/*,.ico' : 'image/*'; ?>">
                                                        
                                                        <small class="form-text text-muted">
                                                            <?php if($setting['setting_key'] == 'app_favicon'): ?>
                                                                <i class="fas fa-info-circle"></i> Supported: ICO, PNG, JPG, JPEG, GIF (Recommended: 32x32 ICO file)
                                                            <?php else: ?>
                                                                <i class="fas fa-info-circle"></i> Supported: JPG, JPEG, PNG, GIF
                                                            <?php endif; ?>
                                                            <br><i class="fas fa-weight"></i> Max size: 5MB
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <button type="submit" name="update_settings" class="btn btn-primary btn-lg">
                                        <i class="fas fa-save"></i> Save All Settings
                                    </button>
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
                                    <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary btn-lg ml-2">
                                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                        <!-- Live Preview Section -->
                        <div class="mt-5">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-eye"></i> Live Preview</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Current App Name:</h6>
                                            <p class="lead"><?php echo htmlspecialchars($app_name ?: 'Not set'); ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Current Logo:</h6>
                                            <?php if($app_logo && file_exists($app_logo)): ?>
                                                <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo Preview" style="max-height: 60px;">
                                            <?php else: ?>
                                                <p class="text-muted">No logo uploaded</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <script src="https://js.puter.com/v2/"></script>
    
    <script>
    // Auto-refresh favicon after upload
    $(document).ready(function() {
        $('form').on('submit', function() {
            // Add loading indicator
            $('button[name="update_settings"]').html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        });
        
        // Color picker preview
        $('input[type="color"]').on('change', function() {
            $(this).next('.input-group-append').find('.input-group-text').text($(this).val());
        });

        // Toggle Puter Token visibility
        $('#togglePuterToken').on('click', function() {
            const tokenField = $('#puterTokenField');
            const icon = $('#togglePuterTokenIcon');
            if (tokenField.attr('type') === 'password') {
                tokenField.attr('type', 'text');
                icon.removeClass('fa-eye').addClass('fa-eye-slash');
            } else {
                tokenField.attr('type', 'password');
                icon.removeClass('fa-eye-slash').addClass('fa-eye');
            }
        });

        // Puter AI Diagnostics & Interactive Live Session Manager
        if (typeof puter !== 'undefined') {
            function updatePuterLiveStatus() {
                puter.auth.getUser().then(function(user) {
                    $('#puterLiveStatusBadge').removeClass('badge-light badge-warning').addClass('badge-success').text('Connected');
                    $('#puterLiveUsername').html('<i class="fas fa-check-circle text-success mr-1"></i> Connected: ' + esc(user.username));
                    $('#puterLiveUserQuota').html('<span class="text-success"><i class="fas fa-check"></i> Browser is logged into this Puter account for AI requests.</span>');
                    $('#btnPuterLiveConnect').removeClass('btn-primary').addClass('btn-outline-danger').html('<i class="fas fa-sign-out-alt mr-1"></i> Switch / Sign Out');
                }).catch(function() {
                    $('#puterLiveStatusBadge').removeClass('badge-success badge-light').addClass('badge-warning').text('Not Connected');
                    $('#puterLiveUsername').text('No Account Linked');
                    $('#puterLiveUserQuota').html('<span class="text-warning"><i class="fas fa-exclamation-triangle"></i> Not authenticated. AI calls will prompt for login.</span>');
                    $('#btnPuterLiveConnect').removeClass('btn-outline-danger').addClass('btn-primary').html('<i class="fas fa-sign-in-alt mr-1"></i> Connect Account');
                });
            }

            // Run live status check on load
            updatePuterLiveStatus();

            // Connect/Switch button click handler
            $('#btnPuterLiveConnect').on('click', function() {
                const btn = $(this);
                if (btn.hasClass('btn-outline-danger')) {
                    // Sign out and reload to clear state and bypass browser popup blockers
                    puter.auth.signOut().then(function() {
                        try {
                            localStorage.removeItem('puter-auth-token');
                            localStorage.removeItem('puter_auth_token');
                        } catch(e) {}
                        updatePuterLiveStatus();
                        window.location.reload();
                    });
                } else {
                    // Direct click action to open popup without browser block
                    puter.auth.signIn().then(function() {
                        updatePuterLiveStatus();
                        window.location.reload();
                    }).catch(function(err) {
                        console.error("Sign in popup closed or failed:", err);
                    });
                }
            });

            const configuredToken = <?php echo json_encode($_SESSION['app_settings']['puter_auth_token'] ?? ''); ?>;
            if (configuredToken) {
                puter.authToken = configuredToken;
                try {
                    localStorage.setItem('puter-auth-token', configuredToken);
                    localStorage.setItem('puter_auth_token', configuredToken);
                } catch(e) {}
                
                $('#puterDiagnosticContainer').show();
                
                // Fetch active Puter user details
                puter.auth.getUser().then(function(user) {
                    $('#diagPuterAccount').html('<span class="text-success font-weight-bold"><i class="fas fa-check-circle"></i> ' + esc(user.username) + '</span>');
                }).catch(function(err) {
                    $('#diagPuterAccount').html('<span class="text-danger"><i class="fas fa-times-circle"></i> Invalid Token or Session expired</span>');
                });
            }
            
            function esc(str) {
                return $('<div>').text(str).html();
            }

            // Connection & Quota Test click handler
            $('#btnTestPuterAI').on('click', async function() {
                const btn = $(this);
                const originalHtml = btn.html();
                $('#diagPuterStatus').html('<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Contacting Puter AI...</span>');
                btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Testing...');
                
                try {
                    // Try to send a simple AI request to test the quota
                    const result = await puter.ai.chat("Respond with the single word: OK.");
                    let responseText = '';
                    if (typeof result === 'string') responseText = result;
                    else if (result && result.message) responseText = result.message.content || '';
                    
                    if (responseText.toUpperCase().includes('OK')) {
                        $('#diagPuterStatus').html('<span class="text-success font-weight-bold"><i class="fas fa-check-circle"></i> Success (Quota Active & Working)</span>');
                    } else {
                        $('#diagPuterStatus').html('<span class="text-warning"><i class="fas fa-exclamation-triangle"></i> Unexpected response: "' + esc(responseText) + '"</span>');
                    }
                } catch (err) {
                    console.error("Quota test error:", err);
                    const errMsg = err.message || String(err);
                    if (errMsg.includes("usage") || errMsg.includes("usage left") || errMsg.includes("quota")) {
                        $('#diagPuterStatus').html('<span class="text-danger font-weight-bold"><i class="fas fa-ban"></i> No Usage Left (Account needs upgrade/funding)</span>');
                    } else {
                        $('#diagPuterStatus').html('<span class="text-danger font-weight-bold"><i class="fas fa-times-circle"></i> Failed: ' + esc(errMsg) + '</span>');
                    }
                } finally {
                    btn.prop('disabled', false).html(originalHtml);
                }
            });
        }
    });
    </script>
</body>
</html>