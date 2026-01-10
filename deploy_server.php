<?php
/**
 * TSU ICT Help Desk - Automated Deployment Script
 * This script automatically pulls updates from GitHub and deploys them to the server
 * 
 * Usage: Place this file in your web server directory and access via browser
 * URL: https://helpdesk.tsuniversity.edu.ng/deploy_server.php?key=TSU_DEPLOY_2026_SECURE_K3Y_H3LP_D3SK_SYS73M
 * 
 * Security: The SECRET_KEY below is unique and secure for TSU Help Desk
 */

// =====================================================
// CONFIGURATION
// =====================================================

// Secure deployment key for TSU Help Desk System
define('SECRET_KEY', 'TSU_DEPLOY_2026_SECURE_K3Y_H3LP_D3SK_SYS73M');

// GitHub repository details
define('REPO_URL', 'https://github.com/kiwixcompo/tsuhelpdesk.git');
define('BRANCH', 'main');

// Server paths
define('PROJECT_PATH', __DIR__);
define('BACKUP_PATH', __DIR__ . '/backups');
define('LOG_FILE', __DIR__ . '/logs/deploy.log');

// Files to preserve during deployment (won't be overwritten)
$preserve_files = [
    'config.php',
    'deploy_server.php',
    '.htaccess',
    'uploads/',
    'logs/',
    'backups/'
];

// =====================================================
// SECURITY CHECK
// =====================================================

function checkAuth() {
    $provided_key = $_GET['key'] ?? '';
    if ($provided_key !== SECRET_KEY) {
        http_response_code(403);
        die('Access denied. Invalid deployment key.');
    }
}

// =====================================================
// LOGGING FUNCTIONS
// =====================================================

function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;
    
    // Ensure logs directory exists
    $log_dir = dirname(LOG_FILE);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND | LOCK_EX);
    echo "<div class='log-entry'>$log_entry</div>";
    flush();
}

function writeError($message) {
    writeLog("ERROR: $message");
}

function writeSuccess($message) {
    writeLog("SUCCESS: $message");
}

// =====================================================
// UTILITY FUNCTIONS
// =====================================================

function executeCommand($command) {
    $output = [];
    $return_code = 0;
    exec($command . ' 2>&1', $output, $return_code);
    
    $output_string = implode("\n", $output);
    writeLog("Command: $command");
    writeLog("Output: $output_string");
    
    return [
        'success' => $return_code === 0,
        'output' => $output_string,
        'code' => $return_code
    ];
}

function createBackup() {
    writeLog("Creating backup...");
    
    // Ensure backup directory exists
    if (!is_dir(BACKUP_PATH)) {
        mkdir(BACKUP_PATH, 0755, true);
    }
    
    $backup_name = 'backup_' . date('Y-m-d_H-i-s') . '.tar.gz';
    $backup_file = BACKUP_PATH . '/' . $backup_name;
    
    // Create backup excluding certain directories
    $exclude_dirs = '--exclude=backups --exclude=.git --exclude=logs --exclude=uploads';
    $command = "tar -czf $backup_file $exclude_dirs -C " . PROJECT_PATH . " .";
    
    $result = executeCommand($command);
    
    if ($result['success']) {
        writeSuccess("Backup created: $backup_name");
        return $backup_file;
    } else {
        writeError("Failed to create backup: " . $result['output']);
        return false;
    }
}

function preserveFiles() {
    global $preserve_files;
    $temp_dir = PROJECT_PATH . '/temp_preserve';
    
    writeLog("Preserving important files...");
    
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }
    
    foreach ($preserve_files as $file) {
        $source = PROJECT_PATH . '/' . $file;
        $dest = $temp_dir . '/' . $file;
        
        if (file_exists($source)) {
            if (is_dir($source)) {
                $result = executeCommand("cp -r '$source' '$dest'");
            } else {
                $dest_dir = dirname($dest);
                if (!is_dir($dest_dir)) {
                    mkdir($dest_dir, 0755, true);
                }
                copy($source, $dest);
            }
            writeLog("Preserved: $file");
        }
    }
    
    return $temp_dir;
}

function restoreFiles($temp_dir) {
    global $preserve_files;
    
    writeLog("Restoring preserved files...");
    
    foreach ($preserve_files as $file) {
        $source = $temp_dir . '/' . $file;
        $dest = PROJECT_PATH . '/' . $file;
        
        if (file_exists($source)) {
            if (is_dir($source)) {
                $result = executeCommand("cp -r '$source' '$dest'");
            } else {
                $dest_dir = dirname($dest);
                if (!is_dir($dest_dir)) {
                    mkdir($dest_dir, 0755, true);
                }
                copy($source, $dest);
            }
            writeLog("Restored: $file");
        }
    }
    
    // Clean up temporary directory
    executeCommand("rm -rf '$temp_dir'");
    writeLog("Cleaned up temporary files");
}

function deployFromGit() {
    writeLog("Starting Git deployment...");
    
    // Check if git is available
    $git_check = executeCommand('git --version');
    if (!$git_check['success']) {
        writeError("Git is not available on this server");
        return false;
    }
    
    // Check if this is already a git repository
    if (is_dir(PROJECT_PATH . '/.git')) {
        writeLog("Existing Git repository found. Pulling latest changes...");
        
        // Fetch latest changes
        $result = executeCommand('cd ' . PROJECT_PATH . ' && git fetch origin');
        if (!$result['success']) {
            writeError("Failed to fetch from remote: " . $result['output']);
            return false;
        }
        
        // Reset to latest commit (this will overwrite local changes)
        $result = executeCommand('cd ' . PROJECT_PATH . ' && git reset --hard origin/' . BRANCH);
        if (!$result['success']) {
            writeError("Failed to reset to latest commit: " . $result['output']);
            return false;
        }
        
        writeSuccess("Successfully pulled latest changes from Git");
        
    } else {
        writeLog("No Git repository found. Cloning from remote...");
        
        // Clone the repository to a temporary directory
        $temp_clone = PROJECT_PATH . '_temp_clone';
        $result = executeCommand("git clone -b " . BRANCH . " " . REPO_URL . " $temp_clone");
        
        if (!$result['success']) {
            writeError("Failed to clone repository: " . $result['output']);
            return false;
        }
        
        // Move files from temp clone to project directory
        $result = executeCommand("cp -r $temp_clone/* " . PROJECT_PATH . "/");
        if (!$result['success']) {
            writeError("Failed to copy files from clone: " . $result['output']);
            executeCommand("rm -rf $temp_clone");
            return false;
        }
        
        // Copy .git directory
        $result = executeCommand("cp -r $temp_clone/.git " . PROJECT_PATH . "/");
        if (!$result['success']) {
            writeError("Failed to copy .git directory: " . $result['output']);
        }
        
        // Clean up temp clone
        executeCommand("rm -rf $temp_clone");
        
        writeSuccess("Successfully cloned repository");
    }
    
    return true;
}

function setPermissions() {
    writeLog("Setting file permissions...");
    
    // Set directory permissions
    $directories = ['uploads', 'logs', 'backups'];
    foreach ($directories as $dir) {
        $dir_path = PROJECT_PATH . '/' . $dir;
        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0755, true);
        }
        chmod($dir_path, 0755);
        writeLog("Set permissions for: $dir");
    }
    
    // Set file permissions for PHP files
    $result = executeCommand('find ' . PROJECT_PATH . ' -name "*.php" -exec chmod 644 {} \;');
    if ($result['success']) {
        writeLog("Set permissions for PHP files");
    }
    
    writeSuccess("File permissions updated");
}

function getDeploymentInfo() {
    $info = [];
    
    // Get current commit hash
    $result = executeCommand('cd ' . PROJECT_PATH . ' && git rev-parse HEAD');
    if ($result['success']) {
        $info['commit'] = substr(trim($result['output']), 0, 8);
    }
    
    // Get last commit message
    $result = executeCommand('cd ' . PROJECT_PATH . ' && git log -1 --pretty=%B');
    if ($result['success']) {
        $info['message'] = trim($result['output']);
    }
    
    // Get last commit date
    $result = executeCommand('cd ' . PROJECT_PATH . ' && git log -1 --pretty=%cd --date=short');
    if ($result['success']) {
        $info['date'] = trim($result['output']);
    }
    
    return $info;
}

// =====================================================
// MAIN DEPLOYMENT PROCESS
// =====================================================

function runDeployment() {
    writeLog("=== TSU ICT Help Desk Deployment Started ===");
    writeLog("Timestamp: " . date('Y-m-d H:i:s'));
    writeLog("Server: helpdesk.tsuniversity.edu.ng");
    writeLog("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'));
    
    try {
        // Step 1: Create backup
        $backup_file = createBackup();
        if (!$backup_file) {
            throw new Exception("Backup creation failed");
        }
        
        // Step 2: Preserve important files
        $temp_dir = preserveFiles();
        
        // Step 3: Deploy from Git
        if (!deployFromGit()) {
            throw new Exception("Git deployment failed");
        }
        
        // Step 4: Restore preserved files
        restoreFiles($temp_dir);
        
        // Step 5: Set proper permissions
        setPermissions();
        
        // Step 6: Get deployment info
        $info = getDeploymentInfo();
        
        writeSuccess("=== Deployment Completed Successfully ===");
        if (!empty($info)) {
            writeLog("Deployed commit: " . ($info['commit'] ?? 'Unknown'));
            writeLog("Commit message: " . ($info['message'] ?? 'Unknown'));
            writeLog("Commit date: " . ($info['date'] ?? 'Unknown'));
        }
        
        return true;
        
    } catch (Exception $e) {
        writeError("Deployment failed: " . $e->getMessage());
        writeLog("=== Deployment Failed ===");
        return false;
    }
}

// =====================================================
// WEB INTERFACE
// =====================================================

checkAuth();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSU Help Desk - Deployment System</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: #333;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(30, 60, 114, 0.3);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        .header h1 {
            color: #1e3c72;
            margin: 0;
            font-size: 2.5rem;
        }
        .header p {
            color: #6c757d;
            margin: 10px 0 0 0;
            font-size: 1.1rem;
        }
        .tsu-logo {
            color: #1e3c72;
            font-size: 3rem;
            margin-bottom: 15px;
        }
        .log-container {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            max-height: 450px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .log-entry {
            margin: 8px 0;
            padding: 8px 12px;
            border-left: 4px solid #1e3c72;
            background: white;
            border-radius: 4px;
            line-height: 1.4;
        }
        .btn {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(30, 60, 114, 0.4);
            color: white;
            text-decoration: none;
        }
        .status {
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 0;
            font-weight: 600;
            font-size: 16px;
        }
        .status.success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 2px solid #28a745;
        }
        .status.error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 2px solid #dc3545;
        }
        .info-box {
            background: linear-gradient(135deg, #e8f4fd 0%, #cce7ff 100%);
            border: 2px solid #2196f3;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .info-box h3 {
            color: #1e3c72;
            margin-top: 0;
        }
        .info-box p {
            margin-bottom: 8px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 25px;
            border-top: 2px solid #e9ecef;
            color: #6c757d;
            font-size: 14px;
        }
        .deployment-url {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            word-break: break-all;
            margin: 15px 0;
        }
        .warning-box {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 2px solid #ffc107;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        .warning-box h4 {
            color: #856404;
            margin-top: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="tsu-logo">🎓</div>
            <h1>TSU ICT Help Desk</h1>
            <p>Automated Deployment System</p>
            <small style="color: #1e3c72; font-weight: 600;">helpdesk.tsuniversity.edu.ng</small>
        </div>

        <?php if (!isset($_GET['deploy'])): ?>
            <div class="info-box">
                <h3>📋 Deployment Information</h3>
                <p><strong>Repository:</strong> <?php echo REPO_URL; ?></p>
                <p><strong>Branch:</strong> <?php echo BRANCH; ?></p>
                <p><strong>Server:</strong> helpdesk.tsuniversity.edu.ng</p>
                <p><strong>Server Path:</strong> <?php echo PROJECT_PATH; ?></p>
                <p><strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s T'); ?></p>
            </div>

            <div style="text-align: center; margin: 40px 0;">
                <a href="?key=<?php echo urlencode($_GET['key']); ?>&deploy=1" class="btn">
                    🚀 Start Deployment
                </a>
            </div>

            <div class="warning-box">
                <h4>⚠️ Important Security Notes:</h4>
                <ul>
                    <li>This deployment URL should be kept secure and private</li>
                    <li>Only authorized personnel should have access to this page</li>
                    <li>All deployment activities are logged for security purposes</li>
                    <li>Backups are created automatically before each deployment</li>
                </ul>
            </div>

            <div class="info-box">
                <h4>🔄 Deployment Process:</h4>
                <ul>
                    <li>✅ Pulls the latest changes from GitHub repository</li>
                    <li>✅ Creates automatic backup of current system</li>
                    <li>✅ Preserves important files (config.php, uploads/, etc.)</li>
                    <li>✅ Updates all system files with latest version</li>
                    <li>✅ Restores preserved configuration files</li>
                    <li>✅ Sets proper file permissions</li>
                    <li>✅ Provides detailed deployment log</li>
                </ul>
            </div>

            <div class="info-box">
                <h4>📞 Support Information:</h4>
                <p><strong>System:</strong> TSU ICT Help Desk v2.0</p>
                <p><strong>Institution:</strong> Taraba State University</p>
                <p><strong>Deployment Time:</strong> Typically 2-5 minutes</p>
                <p><strong>Backup Location:</strong> /backups/ directory</p>
            </div>

        <?php else: ?>
            <div class="log-container" id="logContainer">
                <h3>📝 Deployment Log - <?php echo date('Y-m-d H:i:s'); ?></h3>
                <?php
                $success = runDeployment();
                ?>
            </div>

            <?php if ($success): ?>
                <div class="status success">
                    ✅ Deployment completed successfully!<br>
                    <small>TSU Help Desk System has been updated to the latest version.</small>
                </div>
            <?php else: ?>
                <div class="status error">
                    ❌ Deployment failed. Check the log above for details.<br>
                    <small>Contact system administrator if the issue persists.</small>
                </div>
            <?php endif; ?>

            <div style="text-align: center; margin: 40px 0;">
                <a href="?key=<?php echo urlencode($_GET['key']); ?>" class="btn">
                    🔄 Deploy Again
                </a>
                <a href="/" class="btn" style="margin-left: 15px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%);">
                    🏠 Go to Help Desk
                </a>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p><strong>TSU ICT Help Desk Deployment System v2.0</strong></p>
            <p>Taraba State University - January 2026</p>
            <p style="margin-top: 15px;">
                <small>🔒 Secure deployment system with automatic backups and logging</small>
            </p>
        </div>
    </div>

    <script>
        // Auto-scroll log container to bottom
        const logContainer = document.getElementById('logContainer');
        if (logContainer) {
            logContainer.scrollTop = logContainer.scrollHeight;
            
            // Auto-refresh scroll position every 500ms during deployment
            const scrollInterval = setInterval(() => {
                logContainer.scrollTop = logContainer.scrollHeight;
            }, 500);
            
            // Stop auto-scrolling after 30 seconds
            setTimeout(() => {
                clearInterval(scrollInterval);
            }, 30000);
        }
        
        // Add loading animation to deployment button
        document.addEventListener('DOMContentLoaded', function() {
            const deployBtn = document.querySelector('a[href*="deploy=1"]');
            if (deployBtn) {
                deployBtn.addEventListener('click', function() {
                    this.innerHTML = '⏳ Starting Deployment...';
                    this.style.pointerEvents = 'none';
                });
            }
        });
    </script>
</body>
</html>