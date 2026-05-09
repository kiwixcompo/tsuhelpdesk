<?php
/**
 * TSU ICT Help Desk — Fix Student Programme Assignments
 *
 * After fix_programmes_data.php re-inserted all programmes with new IDs,
 * existing students still hold the old programme_id values (now orphaned).
 *
 * This script re-maps each student to the correct programme by:
 *   1. Matching on department_id (students already have the right department)
 *   2. Picking the first programme in that department (most departments have
 *      exactly one programme; where there are multiple, we use the reg_number
 *      code embedded in the student's registration number to narrow it down)
 *
 * Safe to run multiple times — only updates students whose programme_id is
 * currently invalid (not found in the programmes table).
 *
 * URL: https://helpdesk.tsuniversity.ng/fix_student_programmes.php
 */
ob_start();
require_once 'config.php';
set_time_limit(300);

function p($msg, $cls = 'inf') {
    echo "<span class='$cls'>[" . date('H:i:s') . "] " . htmlspecialchars($msg) . "</span>\n";
    flush(); ob_flush();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Student Programmes</title>
    <style>
        body  { font-family: 'Segoe UI', sans-serif; background: #1e3c72; margin: 0; padding: 20px; }
        .wrap { max-width: 960px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 30px; }
        h1    { color: #1e3c72; }
        .log  { background: #f8f9fa; border-radius: 8px; padding: 20px; font-family: monospace;
                font-size: 13px; max-height: 600px; overflow-y: auto; white-space: pre-wrap; }
        .ok   { color: #155724; background: #d4edda; padding: 3px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        .err  { color: #721c24; background: #f8d7da; padding: 3px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        .inf  { color: #0d47a1; background: #e8f4fd; padding: 3px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        .warn { color: #856404; background: #fff3cd; padding: 3px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #dee2e6; padding: 6px 10px; text-align: left; font-size: 13px; }
        th    { background: #e9ecef; }
        .btn  { display: inline-block; background: #1e3c72; color: #fff; padding: 10px 20px;
                border-radius: 8px; text-decoration: none; margin: 5px; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap">
<h1>🔧 Fix Student Programme Assignments</h1>
<div class="log">
<?php

// ── 1. Verify tables ──────────────────────────────────────────────────────────
foreach (['students', 'programmes', 'student_departments'] as $t) {
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$t'");
    if (mysqli_num_rows($r) === 0) {
        p("MISSING TABLE: $t", 'err'); exit;
    }
    p("Table OK: $t", 'ok');
}

// ── 2. Count students with broken programme links ─────────────────────────────
$broken_q = mysqli_query($conn,
    "SELECT COUNT(*) c FROM students s
     LEFT JOIN programmes p ON s.programme_id = p.programme_id
     WHERE p.programme_id IS NULL"
);
$broken_count = mysqli_fetch_assoc($broken_q)['c'];
p("Students with broken programme_id: $broken_count", $broken_count > 0 ? 'warn' : 'ok');

if ($broken_count === 0) {
    p("Nothing to fix — all students already have valid programme assignments.", 'ok');
    echo "</div><div style='text-align:center;margin:20px'><a href='manage_students.php' class='btn'>👥 View Students</a></div>";
    echo "</div></body></html>";
    exit;
}

// ── 3. Build programme lookup: department_id → [programme_id, programme_code] ─
// For departments with multiple programmes we'll use the reg number to pick the right one.
$prog_map = []; // dept_id => [ [programme_id, programme_code, reg_number_format], ... ]
$res = mysqli_query($conn, "SELECT programme_id, programme_code, department_id, reg_number_format FROM programmes ORDER BY programme_id");
while ($row = mysqli_fetch_assoc($res)) {
    $did = (int)$row['department_id'];
    if (!isset($prog_map[$did])) $prog_map[$did] = [];
    $prog_map[$did][] = [
        'id'     => (int)$row['programme_id'],
        'code'   => strtoupper(trim($row['programme_code'])),
        'format' => $row['reg_number_format'],
    ];
}
p("Loaded programmes for " . count($prog_map) . " departments", 'inf');

// ── 4. Fetch all students with broken programme links ─────────────────────────
$students_q = mysqli_query($conn,
    "SELECT s.student_id, s.registration_number, s.department_id, s.programme_id
     FROM students s
     LEFT JOIN programmes p ON s.programme_id = p.programme_id
     WHERE p.programme_id IS NULL"
);

$fixed   = 0;
$skipped = 0;
$errors  = 0;

$update_stmt = mysqli_prepare($conn, "UPDATE students SET programme_id = ? WHERE student_id = ?");

while ($student = mysqli_fetch_assoc($students_q)) {
    $sid    = (int)$student['student_id'];
    $reg    = strtoupper(trim($student['registration_number']));
    $did    = (int)$student['department_id'];

    if (!isset($prog_map[$did]) || empty($prog_map[$did])) {
        p("SKIP student $sid ($reg) — no programmes found for department_id=$did", 'warn');
        $skipped++;
        continue;
    }

    $candidates = $prog_map[$did];
    $chosen     = null;

    if (count($candidates) === 1) {
        // Only one programme in this department — use it
        $chosen = $candidates[0];
    } else {
        // Multiple programmes — try to match the programme code embedded in the reg number
        // Reg format: TSU/FAC/CODE/YY/XXXX  e.g. TSU/FSC/CS/24/0001
        // The 3rd segment (index 2) is the programme code
        $parts = explode('/', $reg);
        $reg_code = isset($parts[2]) ? strtoupper(trim($parts[2])) : '';

        foreach ($candidates as $c) {
            if ($c['code'] === $reg_code) {
                $chosen = $c;
                break;
            }
        }

        // If still no match, fall back to the first programme in the department
        if ($chosen === null) {
            $chosen = $candidates[0];
            p("FALLBACK student $sid ($reg) — reg code '$reg_code' not matched, using first programme: {$chosen['code']}", 'warn');
        }
    }

    mysqli_stmt_bind_param($update_stmt, 'ii', $chosen['id'], $sid);
    if (mysqli_stmt_execute($update_stmt)) {
        p("Fixed student $sid ($reg) → programme_id={$chosen['id']} ({$chosen['code']})", 'ok');
        $fixed++;
    } else {
        p("ERROR updating student $sid: " . mysqli_stmt_error($update_stmt), 'err');
        $errors++;
    }
}

mysqli_stmt_close($update_stmt);

p("─────────────────────────────────────────────────────", 'inf');
p("Done — Fixed: $fixed | Skipped (no dept match): $skipped | Errors: $errors", $errors > 0 ? 'err' : 'ok');

// ── 5. Verify — any still broken? ────────────────────────────────────────────
$still_broken = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM students s
     LEFT JOIN programmes p ON s.programme_id = p.programme_id
     WHERE p.programme_id IS NULL"
))['c'];

if ($still_broken === 0) {
    p("✅ All students now have valid programme assignments.", 'ok');
} else {
    p("⚠️  $still_broken student(s) still have broken programme links — check the warnings above.", 'warn');
}

?>
</div>

<?php
// ── 6. Summary table ──────────────────────────────────────────────────────────
$summary = mysqli_query($conn,
    "SELECT p.programme_name, COUNT(s.student_id) AS student_count
     FROM students s
     JOIN programmes p ON s.programme_id = p.programme_id
     GROUP BY p.programme_id
     ORDER BY p.programme_name"
);
?>
<h2 style="color:#1e3c72;margin-top:30px;">Students per Programme (after fix)</h2>
<table>
    <tr><th>Programme</th><th>Students</th></tr>
    <?php while ($row = mysqli_fetch_assoc($summary)): ?>
    <tr>
        <td><?= htmlspecialchars($row['programme_name']) ?></td>
        <td style="text-align:center"><?= $row['student_count'] ?></td>
    </tr>
    <?php endwhile; ?>
</table>

<?php
// Show any remaining broken students
$still_q = mysqli_query($conn,
    "SELECT s.student_id, s.registration_number, s.programme_id, sd.department_name
     FROM students s
     LEFT JOIN programmes p ON s.programme_id = p.programme_id
     LEFT JOIN student_departments sd ON s.department_id = sd.department_id
     WHERE p.programme_id IS NULL
     LIMIT 50"
);
if (mysqli_num_rows($still_q) > 0):
?>
<h2 style="color:#dc3545;margin-top:30px;">⚠️ Students Still Without Valid Programme</h2>
<table>
    <tr><th>Student ID</th><th>Reg Number</th><th>Old programme_id</th><th>Department</th></tr>
    <?php while ($row = mysqli_fetch_assoc($still_q)): ?>
    <tr>
        <td><?= $row['student_id'] ?></td>
        <td><?= htmlspecialchars($row['registration_number']) ?></td>
        <td><?= $row['programme_id'] ?></td>
        <td><?= htmlspecialchars($row['department_name'] ?? 'Unknown') ?></td>
    </tr>
    <?php endwhile; ?>
</table>
<?php endif; ?>

<div style="text-align:center;margin:30px 0;">
    <a href="manage_students.php" class="btn">👥 View Students</a>
    <a href="student_dashboard.php" class="btn">🎓 Student Dashboard</a>
</div>
<p style="text-align:center;color:#6c757d;font-size:12px;">
    Delete this file after use for security.
</p>
</div>
</body>
</html>
<?php mysqli_close($conn); ?>
