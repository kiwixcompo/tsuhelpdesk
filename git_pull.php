<?php
/**
 * TSU ICT Help Desk - Server-side Git Pull Trigger
 * Called by sync_and_deploy.bat after pushing to GitHub.
 * Runs git pull on the server repo, then copies files to web root.
 *
 * Access: https://helpdesk.tsuniversity.ng/git_pull.php?key=DEPLOY_TSU_2026
 * DELETE this file if you ever set up SSH-based deployment instead.
 */

define('DEPLOY_KEY', 'DEPLOY_TSU_2026');
define('REPO_PATH',  '/home/tsuniver/repositories/tsuhelpdesk');
define('DEST_PATH',  '/home/tsuniver/helpdesk.tsuniversity.ng');

// ── Auth ─────────────────────────────────────────────────
if (($_GET['key'] ?? '') !== DEPLOY_KEY) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2>');
}

header('Content-Type: text/plain; charset=utf-8');
// Give the script enough time to run git pull + copy files
set_time_limit(120);

// ── Step 1: git pull in the repo directory ───────────────
echo "=== TSU ICT Help Desk — Git Pull + Deploy ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

if (!is_dir(REPO_PATH)) {
    echo "ERROR: Repo not found at " . REPO_PATH . "\n";
    exit(1);
}

echo "[1/3] Running git pull in repo...\n";

// Set HOME so git can find credentials/config
putenv('HOME=/home/tsuniver');

$output = [];
$return = 0;
exec('cd ' . escapeshellarg(REPO_PATH) . ' && git pull origin main 2>&1', $output, $return);

foreach ($output as $line) {
    echo "  " . $line . "\n";
}

if ($return !== 0) {
    echo "\nERROR: git pull failed (exit code $return)\n";
    echo "The files will not be copied. Fix the git issue and try again.\n";
    exit(1);
}

echo "[OK] git pull succeeded.\n\n";

// ── Step 2: Copy files to web root ───────────────────────
echo "[2/3] Copying files to web root...\n";

function copyDir(string $src, string $dst): int {
    $count = 0;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..' || $item === '.git') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            $count += copyDir($s, $d);
        } else {
            copy($s, $d);
            $count++;
        }
    }
    return $count;
}

// Preserve live config.php — never overwrite it
$configBackup = null;
$configPath   = DEST_PATH . '/config.php';
if (file_exists($configPath)) {
    $configBackup = file_get_contents($configPath);
}

$copied = copyDir(REPO_PATH, DEST_PATH);

// Also copy .htaccess (scandir may skip dotfiles)
if (file_exists(REPO_PATH . '/.htaccess')) {
    copy(REPO_PATH . '/.htaccess', DEST_PATH . '/.htaccess');
}

// Restore config.php
if ($configBackup !== null) {
    file_put_contents($configPath, $configBackup);
    echo "  Preserved live config.php\n";
}

// Ensure writable dirs exist
foreach (['uploads', 'logs'] as $dir) {
    $path = DEST_PATH . '/' . $dir;
    if (!is_dir($path)) mkdir($path, 0755, true);
}

echo "[OK] Copied $copied files.\n\n";

// ── Step 3: Verify ───────────────────────────────────────
echo "[3/3] Verifying...\n";
$gitLog = [];
exec('cd ' . escapeshellarg(REPO_PATH) . ' && git log -1 --oneline 2>&1', $gitLog);
echo "  Latest commit: " . ($gitLog[0] ?? 'unknown') . "\n";
echo "  Web root: " . DEST_PATH . "\n";

echo "\n=== DONE ===\n";
echo "Site: https://helpdesk.tsuniversity.ng\n";
