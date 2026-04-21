<?php
/**
 * TSU ICT Help Desk - File Deploy Script
 * Pure PHP file copy — no shell commands needed.
 * Access: https://helpdesk.tsuniversity.ng/git_pull.php?key=DEPLOY_TSU_2026
 */

define('DEPLOY_KEY', 'DEPLOY_TSU_2026');
define('REPO_PATH',  '/home/tsuniver/repositories/tsuhelpdesk');
define('DEST_PATH',  '/home/tsuniver/helpdesk.tsuniversity.ng');

if (($_GET['key'] ?? '') !== DEPLOY_KEY) {
    http_response_code(403);
    die('403 Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
// Flush output immediately so we can see progress
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

set_time_limit(300);

echo "=== TSU ICT Help Desk — Deploy ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ── Verify repo exists ───────────────────────────────────
if (!is_dir(REPO_PATH)) {
    echo "ERROR: Repo not found at " . REPO_PATH . "\n";
    exit(1);
}
echo "Repo path : " . REPO_PATH . "\n";
echo "Dest path : " . DEST_PATH . "\n\n";

// ── Test write permission on dest ────────────────────────
echo "Testing write permission...\n";
$testFile = DEST_PATH . '/_deploy_test.tmp';
if (@file_put_contents($testFile, 'test') === false) {
    echo "ERROR: Cannot write to " . DEST_PATH . "\n";
    echo "The web server user does not have write permission to the web root.\n";
    echo "Fix: In cPanel File Manager, right-click the web root folder,\n";
    echo "     select 'Change Permissions', and ensure group/world write is enabled.\n";
    exit(1);
}
@unlink($testFile);
echo "[OK] Write permission confirmed.\n\n";

// ── Show repo HEAD ───────────────────────────────────────
$headFile = REPO_PATH . '/.git/refs/heads/main';
if (file_exists($headFile)) {
    echo "Repo HEAD : " . trim(file_get_contents($headFile)) . "\n\n";
}

// ── Preserve config.php ──────────────────────────────────
$configPath   = DEST_PATH . '/config.php';
$configBackup = file_exists($configPath) ? file_get_contents($configPath) : null;
if ($configBackup !== null) {
    echo "Preserving config.php...\n";
}

// ── Pure PHP recursive copy ──────────────────────────────
echo "[1/3] Copying files...\n";
flush();

$copied = 0;
$failed = [];

function deployDir(string $src, string $dst, array &$copied, array &$failed): void {
    if (!is_dir($dst)) {
        if (!@mkdir($dst, 0755, true)) {
            $failed[] = "mkdir: $dst";
            return;
        }
    }
    $items = @scandir($src);
    if (!$items) return;

    foreach ($items as $item) {
        // Skip .git and large vendor folders that don't change
        if ($item === '.' || $item === '..' || $item === '.git' || $item === 'PHPMailer') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            deployDir($s, $d, $copied, $failed);
        } else {
            if (@copy($s, $d)) {
                $copied++;
            } else {
                $failed[] = $item;
            }
        }
    }
}

deployDir(REPO_PATH, DEST_PATH, $copied, $failed);

// Copy .htaccess explicitly
if (file_exists(REPO_PATH . '/.htaccess')) {
    @copy(REPO_PATH . '/.htaccess', DEST_PATH . '/.htaccess');
}

echo "[OK] Copied: $copied files\n";
if (!empty($failed)) {
    echo "[WARN] Failed (" . count($failed) . "): " . implode(', ', array_slice($failed, 0, 10)) . "\n";
}
echo "\n";

// ── Restore config.php ───────────────────────────────────
if ($configBackup !== null) {
    @file_put_contents($configPath, $configBackup);
    echo "[OK] config.php restored.\n\n";
}

// ── Ensure writable dirs ─────────────────────────────────
foreach (['uploads', 'logs'] as $dir) {
    $path = DEST_PATH . '/' . $dir;
    if (!is_dir($path)) @mkdir($path, 0755, true);
}

// ── Verify timestamps ────────────────────────────────────
echo "[2/3] Verifying...\n";
foreach (['git_pull.php', 'api/ict_complaint_submit.php', 'includes/logger.php'] as $f) {
    $p = DEST_PATH . '/' . $f;
    if (file_exists($p)) {
        echo "  $f : " . date('Y-m-d H:i:s', filemtime($p)) . "\n";
    }
}

echo "\n[3/3] Done.\n";
echo "  Copied : $copied files\n";
echo "  Config : preserved\n";
echo "\n=== COMPLETE ===\n";
echo "Site: https://helpdesk.tsuniversity.ng\n";
