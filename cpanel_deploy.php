<?php
/**
 * TSU ICT Help Desk - cPanel File Deployer
 * Copies files from the Git repository folder to the live web directory.
 *
 * Access: https://helpdesk.tsuniversity.ng/cpanel_deploy.php?key=DEPLOY_TSU_2026
 *
 * HOW IT WORKS:
 *  1. cPanel Git pulls the repo into ~/repositories/tsuhelpdesk  (you do this manually)
 *  2. Visit this URL — it copies everything to ~/helpdesk.tsuniversity.ng/
 *
 * DELETE THIS FILE after the migration is stable.
 */

define('DEPLOY_KEY',  'DEPLOY_TSU_2026');
define('REPO_PATH',   '/home/tsuniver/repositories/tsuhelpdesk');
define('DEST_PATH',   '/home/tsuniver/helpdesk.tsuniversity.ng');

// ── Auth ─────────────────────────────────────────────────
if (($_GET['key'] ?? '') !== DEPLOY_KEY) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2>');
}

// ── Helpers ──────────────────────────────────────────────
$log = [];

function logLine(string $msg): void {
    global $log;
    $log[] = $msg;
    echo $msg . "<br>\n";
    flush();
}

function copyDir(string $src, string $dst): void {
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        // Skip .git directory
        if ($item === '.git') continue;
        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;
        if (is_dir($srcPath)) {
            copyDir($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
}

// ── Run ──────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Deploy</title></head><body>";
echo "<h2>TSU ICT Help Desk — Deployer</h2>";
echo "<pre style='font-family:monospace;font-size:13px;'>\n";

$start = microtime(true);

// Check repo exists
if (!is_dir(REPO_PATH)) {
    logLine("ERROR: Repository not found at " . REPO_PATH);
    logLine("Make sure you clicked 'Update from Remote' in cPanel Git Version Control first.");
    echo "</pre></body></html>";
    exit;
}

// Check dest exists
if (!is_dir(DEST_PATH)) {
    mkdir(DEST_PATH, 0755, true);
    logLine("Created destination: " . DEST_PATH);
}

logLine("Source : " . REPO_PATH);
logLine("Dest   : " . DEST_PATH);
logLine("Started: " . date('Y-m-d H:i:s'));
logLine("---");

// Preserve config.php — don't overwrite it on the live server
$preserveConfig = false;
$configBackup   = null;
if (file_exists(DEST_PATH . '/config.php')) {
    $configBackup   = file_get_contents(DEST_PATH . '/config.php');
    $preserveConfig = true;
    logLine("Preserving live config.php...");
}

// Copy everything
logLine("Copying files...");
copyDir(REPO_PATH, DEST_PATH);

// Also copy .htaccess (scandir skips dotfiles on some systems)
if (file_exists(REPO_PATH . '/.htaccess')) {
    copy(REPO_PATH . '/.htaccess', DEST_PATH . '/.htaccess');
    logLine("Copied .htaccess");
}

// Restore config.php
if ($preserveConfig && $configBackup !== null) {
    file_put_contents(DEST_PATH . '/config.php', $configBackup);
    logLine("Restored live config.php");
}

// Ensure uploads/ and logs/ are writable
foreach (['uploads', 'logs'] as $dir) {
    $path = DEST_PATH . '/' . $dir;
    if (!is_dir($path)) mkdir($path, 0755, true);
    chmod($path, 0755);
}

// Remove this deployer from the live directory (security)
$selfInDest = DEST_PATH . '/cpanel_deploy.php';
if (file_exists($selfInDest)) {
    unlink($selfInDest);
    logLine("Removed cpanel_deploy.php from live directory (security)");
}

$elapsed = round(microtime(true) - $start, 2);
logLine("---");
logLine("Done in {$elapsed}s");
logLine("Files deployed to: " . DEST_PATH);

echo "</pre>";
echo "<p style='color:green;font-weight:bold'>✅ Deployment complete!</p>";
echo "<p><a href='https://helpdesk.tsuniversity.ng/'>Visit the site →</a></p>";
echo "</body></html>";
