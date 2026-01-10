<?php
/**
 * TSU ICT Help Desk - Automated Deployment Script
 * This script automatically pulls updates from GitHub and deploys them to the server
 * 
 * Usage: Place this file in your web server directory and access via browser
 * URL: https://yourdomain.com/deploy.php?key=YOUR_SECRET_KEY
 * 
 * Security: Change the SECRET_KEY below to a strong, unique value
 */

// =====================================================
// CONFIGURATION
// =====================================================

// Change this to a strong, unique secret key
define('SECRET_KEY', 'tsu_helpdesk_deploy_2026_secure_key');

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
    'deploy.php',
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
    writeLog("Server: " . $_SERVER['HTTP_HOST']);
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
    <title>TSU Help Desk - Deployment</title>
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
            max-width: 800px;
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
        }
        .header p {
            color: #6c757d;
            margin: 10px 0 0 0;
        }
        .log-container {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .log-entry {
            margin: 5px 0;
            padding: 5px;
            border-left: 3px solid #1e3c72;
            background: white;
            border-radius: 3px;
        }
        .btn {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 60, 114, 0.4);
        }
        .status {
            padding: 10px 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-weight: 600;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info-box {
            background: #e8f4fd;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 TSU ICT Help Desk</h1>
            <p>Automated Deployment System</p>
        </div>

        <?php if (!isset($_GET['deploy'])): ?>
            <div class="info-box">
                <h3>📋 Deployment Information</h3>
                <p><strong>Repository:</strong> <?php echo REPO_URL; ?></p>
                <p><strong>Branch:</strong> <?php echo BRANCH; ?></p>
                <p><strong>Server Path:</strong> <?php echo PROJECT_PATH; ?></p>
                <p><strong>Current Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>

            <div style="text-align: center; margin: 30px 0;">
                <a href="?key=<?php echo urlencode($_GET['key']); ?>&deploy=1" class="btn">
                    🚀 Start Deployment
                </a>
            </div>

            <div class="info-box">
                <h4>⚠️ Important Notes:</h4>
                <ul>
                    <li>This will pull the latest changes from GitHub</li>
                    <li>A backup will be created automatically</li>
                    <li>Important files (config.php, uploads/) will be preserved</li>
                    <li>The deployment process may take a few minutes</li>
                </ul>
            </div>

        <?php else: ?>
            <div class="log-container" id="logContainer">
                <h3>📝 Deployment Log</h3>
                <?php
                $success = runDeployment();
                ?>
            </div>

            <?php if ($success): ?>
                <div class="status success">
                    ✅ Deployment completed successfully!
                </div>
            <?php else: ?>
                <div class="status error">
                    ❌ Deployment failed. Check the log above for details.
                </div>
            <?php endif; ?>

            <div style="text-align: center; margin: 30px 0;">
                <a href="?key=<?php echo urlencode($_GET['key']); ?>" class="btn">
                    🔄 Deploy Again
                </a>
                <a href="/" class="btn" style="margin-left: 10px;">
                    🏠 Go to Application
                </a>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p>TSU ICT Help Desk Deployment System v2.0</p>
            <p>Taraba State University - January 2026</p>
        </div>
    </div>

    <script>
        // Auto-scroll log container to bottom
        const logContainer = document.getElementById('logContainer');
        if (logContainer) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }
    </script>
</body>
</html>