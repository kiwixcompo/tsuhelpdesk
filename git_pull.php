<?php
/**
 * TSU ICT Help Desk - File Deploy Script
 * Copies only application files (not PHPMailer or other large vendor dirs).
 * Access: https://helpdesk.tsuniversity.ng/git_pull.php?key=DEPLOY_TSU_2026
 */

define('DEPLOY_KEY', 'DEPLOY_TSU_2026');
define('REPO_PATH',  '/home/tsuniver/repositories/tsuhelpdesk');
define('DEST_PATH',  '/home/tsuniver/helpdesk.tsuniversity.ng');

// Directories to SKIP (already on server, never change)
define('SKIP_DIRS', ['PHPMailer', '.git', 'uploads', 'logs']);

if (($_GET['key'] ?? '') !== DEPLOY_KEY) {
    http_response_code(403);
    die('403 Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

echo "=== TSU ICT Help Desk — Deploy ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

if (!is_dir(REPO_PATH)) {
    echo "ERROR: Repo not found at " . REPO_PATH . "\n";
    exit(1);
}

// Show repo HEAD
$headFile = REPO_PATH . '/.git/refs/heads/main';
echo "Repo HEAD : " . (file_exists($headFile) ? trim(file_get_contents($headFile)) : 'unknown') . "\n\n";

// Preserve config.php
$configPath   = DEST_PATH . '/config.php';
$configBackup = file_exists($configPath) ? file_get_contents($configPath) : null;

// ── Copy only root-level PHP files + specific subdirs ────
echo "[1/2] Copying files...\n";
$copied = 0;
$failed = [];
$skipped = 0;

// Copy all root-level files (*.php, *.yml, *.sql, etc.)
$rootItems = scandir(REPO_PATH);
foreach ($rootItems as $item) {
    if ($item === '.' || $item === '..') continue;
    $src = REPO_PATH . '/' . $item;
    $dst = DEST_PATH . '/' . $item;

    if (is_dir($src)) {
        // Skip large/static vendor dirs
        if (in_array($item, SKIP_DIRS)) {
            $skipped++;
            continue;
        }
        // Copy subdirectory recursively
        $result = copyDirFlat($src, $dst);
        $copied += $result[0];
        $failed  = array_merge($failed, $result[1]);
    } else {
        // Root-level file
        if ($item === '.htaccess' || substr($item, 0, 1) !== '.') {
            if (@copy($src, $dst)) {
                $copied++;
            } else {
                $failed[] = $item;
            }
        }
    }
}

function copyDirFlat(string $src, string $dst): array {
    $copied = 0;
    $failed = [];
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    $items = @scandir($src);
    if (!$items) return [$copied, $failed];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            [$c, $f] = copyDirFlat($s, $d);
            $copied += $c;
            $failed  = array_merge($failed, $f);
        } else {
            if (@copy($s, $d)) {
                $copied++;
            } else {
                $failed[] = $item;
            }
        }
    }
    return [$copied, $failed];
}

echo "  Copied  : $copied files\n";
echo "  Skipped : $skipped dirs (PHPMailer etc — already on server)\n";
if (!empty($failed)) {
    echo "  Failed  : " . count($failed) . " — " . implode(', ', array_slice($failed, 0, 5)) . "\n";
}

// Restore config.php
if ($configBackup !== null) {
    @file_put_contents($configPath, $configBackup);
    echo "  Config  : preserved\n";
}

// Ensure writable dirs
foreach (['uploads', 'logs'] as $dir) {
    $path = DEST_PATH . '/' . $dir;
    if (!is_dir($path)) @mkdir($path, 0755, true);
}

// ── Verify key files ─────────────────────────────────────
echo "\n[2/2] Verifying timestamps...\n";
$checkFiles = [
    'git_pull.php',
    'api/ict_complaint_submit.php',
    'includes/logger.php',
    'student_signup.php',
];
foreach ($checkFiles as $f) {
    $p = DEST_PATH . '/' . $f;
    if (file_exists($p)) {
        echo "  $f : " . date('Y-m-d H:i:s', filemtime($p)) . "\n";
    }
}

echo "\n=== DONE — " . date('Y-m-d H:i:s') . " ===\n";
echo "Site: https://helpdesk.tsuniversity.ng\n";
