<?php
/**
 * TSU ICT Help Desk - Server-side Deploy Script
 * Copies files from the server's git repo to the live web root.
 * The git pull is triggered separately via cPanel Git Version Control.
 *
 * Access: https://helpdesk.tsuniversity.ng/git_pull.php?key=DEPLOY_TSU_2026
 */

define('DEPLOY_KEY', 'DEPLOY_TSU_2026');
define('REPO_PATH',  '/home/tsuniver/repositories/tsuhelpdesk');
define('DEST_PATH',  '/home/tsuniver/helpdesk.tsuniversity.ng');

// ── Auth ─────────────────────────────────────────────────
if (($_GET['key'] ?? '') !== DEPLOY_KEY) {
    http_response_code(403);
    die('403 Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

echo "=== TSU ICT Help Desk — Deploy ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ── Check repo exists ────────────────────────────────────
if (!is_dir(REPO_PATH)) {
    echo "ERROR: Repo not found at " . REPO_PATH . "\n";
    echo "Go to cPanel > Git Version Control and make sure the repo is cloned.\n";
    exit(1);
}

// ── Attempt git pull (non-fatal if it fails) ─────────────
echo "[1/3] Attempting git pull...\n";
putenv('HOME=/home/tsuniver');
putenv('GIT_TERMINAL_PROMPT=0'); // prevent git from hanging waiting for input

$pullOutput = [];
$pullReturn = 0;
exec('cd ' . escapeshellarg(REPO_PATH) . ' && git pull origin main 2>&1', $pullOutput, $pullReturn);

foreach ($pullOutput as $line) {
    echo "  " . $line . "\n";
}

if ($pullReturn !== 0) {
    echo "\n  [WARNING] git pull exited with code $pullReturn.\n";
    echo "  This usually means the server repo needs SSH key auth set up.\n";
    echo "  Continuing with file copy using whatever is currently in the repo...\n";
    echo "  To fix: go to cPanel > Git Version Control > Update from Remote manually.\n\n";
} else {
    echo "[OK] git pull succeeded.\n\n";
}

// ── Show current repo HEAD ───────────────────────────────
$headOutput = [];
exec('cd ' . escapeshellarg(REPO_PATH) . ' && git log -1 --oneline 2>&1', $headOutput);
echo "  Repo HEAD: " . ($headOutput[0] ?? 'unknown') . "\n\n";

// ── Copy files to web root ───────────────────────────────
echo "[2/3] Copying files to web root...\n";

function copyDir(string $src, string $dst): int {
    $count = 0;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $items = scandir($src);
    if (!$items) return 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            $count += copyDir($s, $d);
        } else {
            if (copy($s, $d)) {
                $count++;
            } else {
                echo "  [WARN] Could not copy: $item\n";
            }
        }
    }
    return $count;
}

// Preserve live config.php — never overwrite server credentials
$configBackup = null;
$configPath   = DEST_PATH . '/config.php';
if (file_exists($configPath)) {
    $configBackup = file_get_contents($configPath);
    echo "  Preserving live config.php...\n";
}

$copied = copyDir(REPO_PATH, DEST_PATH);

// Copy .htaccess explicitly (scandir skips dotfiles on some systems)
if (file_exists(REPO_PATH . '/.htaccess')) {
    copy(REPO_PATH . '/.htaccess', DEST_PATH . '/.htaccess');
}

// Restore config.php
if ($configBackup !== null) {
    file_put_contents($configPath, $configBackup);
    echo "  Restored live config.php\n";
}

// Ensure writable dirs exist
foreach (['uploads', 'logs'] as $dir) {
    $path = DEST_PATH . '/' . $dir;
    if (!is_dir($path)) mkdir($path, 0755, true);
}

echo "[OK] Copied $copied files to web root.\n\n";

// ── Summary ──────────────────────────────────────────────
echo "[3/3] Summary\n";
echo "  Source : " . REPO_PATH . "\n";
echo "  Dest   : " . DEST_PATH . "\n";
echo "  Files  : $copied copied\n";
echo "  Config : preserved (not overwritten)\n";

if ($pullReturn !== 0) {
    echo "\n[ACTION NEEDED] git pull failed — the files copied are from the\n";
    echo "  PREVIOUS repo state, not the latest GitHub push.\n";
    echo "  Fix: cPanel > Git Version Control > Update from Remote\n";
    echo "  Then run sync_and_deploy.bat again.\n";
}

echo "\n=== DONE ===\n";
echo "Site: https://helpdesk.tsuniversity.ng\n";
