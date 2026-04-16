<?php
/**
 * Fix: Add GST Unit + fix missing law programmes
 * Run once: https://helpdesk.tsuniversity.ng/fix_gst_and_law.php
 * Delete after running.
 */
require_once "config.php";

$steps = [];

function run(string $label, string $sql): void {
    global $conn, $steps;
    $r = mysqli_query($conn, $sql);
    if ($r) {
        $steps[] = ['ok', $label];
    } else {
        $err = mysqli_error($conn);
        if (str_contains($err, 'Duplicate') || str_contains($err, 'already exists')) {
            $steps[] = ['skip', "$label (already exists)"];
        } else {
            $steps[] = ['err', "$label: $err"];
        }
    }
}

// ── 1. Add GST Unit as a department user (role_id = 7) ───
run('Add GST Unit user account',
    "INSERT IGNORE INTO users (username, password, full_name, role_id)
     VALUES ('gst_unit', MD5('user2026'), 'General Studies (GST) Unit', 7)");

// ── 2. Add GST as a student_department (for forwarding) ──
// First get or create a "General Studies" faculty placeholder
run('Ensure General Studies faculty exists',
    "INSERT IGNORE INTO faculties (faculty_name, faculty_code)
     VALUES ('General Studies', 'GST')");

$gst_fac = mysqli_query($conn, "SELECT faculty_id FROM faculties WHERE faculty_code='GST'");
$gst_fac_id = ($gst_fac && $r = mysqli_fetch_assoc($gst_fac)) ? $r['faculty_id'] : null;

if ($gst_fac_id) {
    run('Add GST Unit department',
        "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id)
         VALUES ('General Studies (GST) Unit', 'GST', $gst_fac_id)");
}

// ── 3. Fix law programmes ─────────────────────────────────
// The issue: programme_code must be unique per department.
// Law has 5 departments each needing their own programme.
// Use INSERT IGNORE with unique codes per department.

$law_fac = mysqli_query($conn, "SELECT faculty_id FROM faculties WHERE faculty_code='LAW'");
$law_fac_id = ($law_fac && $r = mysqli_fetch_assoc($law_fac)) ? $r['faculty_id'] : null;

if ($law_fac_id) {
    $law_depts = [
        ['Commercial Law',          'CL',  'LLB. Commercial Law',          'CL_LLB',  'TSU/LAW/CL/YY/XXXX'],
        ['Islamic Law',             'IL',  'LLB. Islamic Law',             'IL_LLB',  'TSU/LAW/IL/YY/XXXX'],
        ['Law',                     'LLB', 'LLB. Law',                     'LLB_LAW', 'TSU/LAW/LLB/YY/XXXX'],
        ['Public Law',              'PL',  'LLB. Public Law',              'PL_LLB',  'TSU/LAW/PL/YY/XXXX'],
        ['Private and Property Law','PP',  'LLB. Private and Property Law','PP_LLB',  'TSU/LAW/PP/YY/XXXX'],
    ];

    foreach ($law_depts as [$dept_name, $dept_code, $prog_name, $prog_code, $reg_fmt]) {
        // Ensure department exists
        run("Ensure law dept '$dept_name' exists",
            "INSERT IGNORE INTO student_departments (department_name, department_code, faculty_id)
             VALUES ('$dept_name', '$dept_code', $law_fac_id)");

        // Get department_id
        $dr = mysqli_query($conn,
            "SELECT department_id FROM student_departments
             WHERE department_code='$dept_code' AND faculty_id=$law_fac_id
             ORDER BY department_id ASC LIMIT 1");
        if (!$dr || !($drow = mysqli_fetch_assoc($dr))) continue;
        $dept_id = $drow['department_id'];

        // Insert programme with unique code per dept
        run("Add programme '$prog_name'",
            "INSERT IGNORE INTO programmes (programme_name, programme_code, department_id, reg_number_format)
             VALUES ('$prog_name', '$prog_code', $dept_id, '$reg_fmt')");
    }
} else {
    $steps[] = ['err', 'Law faculty (LAW) not found — skipping law programmes'];
}

// ── 4. Add forwarded_to column to student_ict_complaints ─
run('Add forwarded_to column to student_ict_complaints',
    "ALTER TABLE student_ict_complaints
     ADD COLUMN forwarded_to VARCHAR(255) NULL DEFAULT NULL AFTER admin_response");

// ── Output ────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix GST &amp; Law</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4" style="max-width:750px">
    <h3>Fix: GST Unit + Law Programmes</h3>
    <table class="table table-sm table-bordered mt-3">
        <thead class="thead-light"><tr><th width="40">Status</th><th>Step</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$s, $lbl]): ?>
            <tr class="<?php echo $s==='err'?'table-danger':($s==='skip'?'table-warning':'table-success'); ?>">
                <td><?php echo $s==='ok'?'✓':($s==='skip'?'–':'✗'); ?></td>
                <td><?php echo htmlspecialchars($lbl); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php $errs = array_filter($steps, fn($s)=>$s[0]==='err'); ?>
    <?php if (empty($errs)): ?>
        <div class="alert alert-success">
            <strong>✓ Done.</strong> GST Unit added and law programmes fixed.
            Delete this file after verifying.
        </div>
    <?php else: ?>
        <div class="alert alert-danger"><strong>Some steps failed.</strong></div>
    <?php endif; ?>

    <h5 class="mt-4">Law Programmes (after fix)</h5>
    <?php
    $pr = mysqli_query($conn,
        "SELECT p.programme_name, p.programme_code, p.reg_number_format, sd.department_name
         FROM programmes p
         JOIN student_departments sd ON p.department_id = sd.department_id
         JOIN faculties f ON sd.faculty_id = f.faculty_id
         WHERE f.faculty_code = 'LAW'
         ORDER BY sd.department_name");
    if ($pr && mysqli_num_rows($pr)): ?>
    <table class="table table-sm table-striped table-bordered">
        <thead class="thead-light"><tr><th>Programme</th><th>Code</th><th>Department</th><th>Reg Format</th></tr></thead>
        <tbody>
        <?php while ($row = mysqli_fetch_assoc($pr)): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['programme_name']); ?></td>
                <td><?php echo htmlspecialchars($row['programme_code']); ?></td>
                <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                <td><?php echo htmlspecialchars($row['reg_number_format']); ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p class="text-muted">No law programmes found.</p>
    <?php endif; ?>

    <a href="student_signup.php" class="btn btn-primary">Test Signup</a>
    <a href="ict_complaints_admin.php" class="btn btn-secondary ml-2">ICT Complaints</a>
</div>
</body>
</html>
