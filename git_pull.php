<?php
/**
 * TSU ICT Help Desk — GitHub Direct Deploy
 *
 * Fetches each changed file directly from GitHub raw content API
 * and writes it to the web root. No shell access needed.
 * No dependency on the server-side git repo.
 *
 * Access: https://helpdesk.tsuniversity.ng/git_pull.php?key=DEPLOY_TSU_2026
 */

define('DEPLOY_KEY',  'DEPLOY_TSU_2026');
define('DEST_PATH',   '/home/tsuniver/helpdesk.tsuniversity.ng');
define('GITHUB_USER', 'kiwixcompo');
define('GITHUB_REPO', 'tsuhelpdesk');
define('GITHUB_BRANCH','main');

// Files/dirs to NEVER overwrite on the server
define('PRESERVE', ['config.php', 'config.local.php']);

// Large vendor dirs to skip (already on server)
define('SKIP_DIRS', ['PHPMailer', '.git', 'uploads', 'logs', 'node_modules']);

if (($_GET['key'] ?? '') !== DEPLOY_KEY) {
    http_response_code(403);
    die('403 Forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

echo "=== TSU ICT Help Desk — GitHub Direct Deploy ===\n";
echo "Time   : " . date('Y-m-d H:i:s') . "\n";
echo "Repo   : https://github.com/" . GITHUB_USER . "/" . GITHUB_REPO . "\n";
echo "Branch : " . GITHUB_BRANCH . "\n\n";

// ── Fetch file list from GitHub API ─────────────────────
// Uses the Git Trees API to get a flat list of all files recursively
$apiUrl = "https://api.github.com/repos/" . GITHUB_USER . "/" . GITHUB_REPO
        . "/git/trees/" . GITHUB_BRANCH . "?recursive=1";

$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "User-Agent: TSU-Deploy/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
    'timeout' => 30,
]]);

echo "[1/3] Fetching file list from GitHub API...\n";
$json = @file_get_contents($apiUrl, false, $ctx);
if (!$json) {
    echo "ERROR: Could not reach GitHub API.\n";
    echo "Check that allow_url_fopen is enabled on the server.\n";
    exit(1);
}

$data = json_decode($json, true);
if (empty($data['tree'])) {
    echo "ERROR: GitHub API returned unexpected response.\n";
    echo substr($json, 0, 300) . "\n";
    exit(1);
}

// Filter to blobs (files) only, skip large dirs
$files = array_filter($data['tree'], function($item) {
    if ($item['type'] !== 'blob') return false;
    foreach (SKIP_DIRS as $skip) {
        if (strpos($item['path'], $skip . '/') === 0 || $item['path'] === $skip) return false;
    }
    return true;
});

echo "[OK] Found " . count($files) . " files to deploy.\n\n";

// ── Download and write each file ─────────────────────────
echo "[2/3] Downloading and writing files...\n";

$copied  = 0;
$skipped = 0;
$failed  = [];

$rawBase = "https://raw.githubusercontent.com/" . GITHUB_USER . "/"
         . GITHUB_REPO . "/" . GITHUB_BRANCH . "/";

foreach ($files as $file) {
    $path    = $file['path'];
    $destFile = DEST_PATH . '/' . $path;

    // Never overwrite preserved files
    if (in_array($path, PRESERVE)) {
        $skipped++;
        continue;
    }

    // Ensure destination directory exists
    $destDir = dirname($destFile);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }

    // Fetch from GitHub raw
    $rawUrl  = $rawBase . $path;
    $content = @file_get_contents($rawUrl, false, $ctx);

    if ($content === false) {
        $failed[] = $path;
        continue;
    }

    if (@file_put_contents($destFile, $content) !== false) {
        $copied++;
    } else {
        $failed[] = $path;
    }
}

echo "[OK] Copied  : $copied files\n";
echo "[OK] Skipped : $skipped files (preserved)\n";
if (!empty($failed)) {
    echo "[WARN] Failed: " . count($failed) . "\n";
    foreach (array_slice($failed, 0, 10) as $f) echo "  - $f\n";
}

// Ensure writable dirs exist
foreach (['uploads', 'logs'] as $dir) {
    $p = DEST_PATH . '/' . $dir;
    if (!is_dir($p)) @mkdir($p, 0755, true);
}

// ── Verify key files ─────────────────────────────────────
echo "\n[3/3] Verifying timestamps...\n";
foreach (['student_dashboard.php', 'api/student_ict_complaint_manage.php',
          'ict_complaints_admin.php', 'git_pull.php'] as $f) {
    $p = DEST_PATH . '/' . $f;
    if (file_exists($p)) {
        echo "  $f : " . date('Y-m-d H:i:s', filemtime($p)) . "\n";
    }
}

echo "\n=== DONE — " . date('Y-m-d H:i:s') . " ===\n";
echo "Site: https://helpdesk.tsuniversity.ng\n";
