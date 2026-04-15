<?php
/**
 * Fix duplicate departments in student_departments table
 * Run once: https://helpdesk.tsuniversity.ng/fix_duplicate_departments.php
 * Delete after running.
 */
require_once "config.php";

$steps = [];

function run(string $label, string $sql, bool $silent = false): bool {
    global $conn, $steps;
    $result = mysqli_query($conn, $sql);
    if ($result) {
        if (!$silent) $steps[] = ['ok', $label];
        return true;
    } else {
        $err = mysqli_error($conn);
        $steps[] = ['err', "$label: $err"];
        return false;
    }
}

// ── Step 1: Show current duplicates ──────────────────────
$dup_sql = "SELECT department_name, faculty_id, COUNT(*) as cnt
            FROM student_departments
            GROUP BY department_name, faculty_id
            HAVING cnt > 1
            ORDER BY faculty_id, department_name";
$dup_result = mysqli_query($conn, $dup_sql);
$duplicates = [];
if ($dup_result) {
    while ($row = mysqli_fetch_assoc($dup_result)) {
        $duplicates[] = $row;
    }
}

// ── Step 2: For each duplicate group, keep the lowest ID and delete the rest ──
$deleted_total = 0;
foreach ($duplicates as $dup) {
    $name     = mysqli_real_escape_string($conn, $dup['department_name']);
    $fac_id   = (int) $dup['faculty_id'];

    // Get all IDs for this duplicate group, ordered ascending
    $ids_result = mysqli_query($conn,
        "SELECT department_id FROM student_departments
         WHERE department_name = '$name' AND faculty_id = $fac_id
         ORDER BY department_id ASC");

    $ids = [];
    while ($row = mysqli_fetch_assoc($ids_result)) {
        $ids[] = $row['department_id'];
    }

    // Keep the first (lowest) ID, delete the rest
    $keep = array_shift($ids);
    if (!empty($ids)) {
        $to_delete = implode(',', $ids);

        // Reassign any programmes pointing to deleted departments to the kept one
        run("Reassign programmes from dept IDs ($to_delete) → $keep",
            "UPDATE programmes SET department_id = $keep
             WHERE department_id IN ($to_delete)");

        // Reassign any students pointing to deleted departments
        run("Reassign students from dept IDs ($to_delete) → $keep",
            "UPDATE students SET department_id = $keep
             WHERE department_id IN ($to_delete)");

        // Now delete the duplicates
        $del = mysqli_query($conn,
            "DELETE FROM student_departments WHERE department_id IN ($to_delete)");
        if ($del) {
            $deleted = mysqli_affected_rows($conn);
            $deleted_total += $deleted;
            $steps[] = ['ok', "Deleted $deleted duplicate(s) of '$name' (faculty $fac_id), kept ID $keep"];
        } else {
            $steps[] = ['err', "Failed to delete duplicates of '$name': " . mysqli_error($conn)];
        }
    }
}

if ($deleted_total === 0 && empty($duplicates)) {
    $steps[] = ['ok', 'No duplicates found — database is clean'];
}

// ── Step 3: Also fix duplicate programmes ────────────────
$dup_prog_sql = "SELECT programme_name, department_id, COUNT(*) as cnt
                 FROM programmes
                 GROUP BY programme_name, department_id
                 HAVING cnt > 1";
$dup_prog = mysqli_query($conn, $dup_prog_sql);
$prog_deleted = 0;
if ($dup_prog) {
    while ($row = mysqli_fetch_assoc($dup_prog)) {
        $pname  = mysqli_real_escape_string($conn, $row['programme_name']);
        $dep_id = (int) $row['department_id'];

        $pids_r = mysqli_query($conn,
            "SELECT programme_id FROM programmes
             WHERE programme_name = '$pname' AND department_id = $dep_id
             ORDER BY programme_id ASC");
        $pids = [];
        while ($pr = mysqli_fetch_assoc($pids_r)) $pids[] = $pr['programme_id'];

        $keep_p = array_shift($pids);
        if (!empty($pids)) {
            $del_p = implode(',', $pids);
            // Reassign students
            run("Reassign students from programme IDs ($del_p) → $keep_p",
                "UPDATE students SET programme_id = $keep_p WHERE programme_id IN ($del_p)");
            $dp = mysqli_query($conn, "DELETE FROM programmes WHERE programme_id IN ($del_p)");
            if ($dp) {
                $prog_deleted += mysqli_affected_rows($conn);
                $steps[] = ['ok', "Deleted " . mysqli_affected_rows($conn) . " duplicate programme(s) of '$pname'"];
            }
        }
    }
}

// ── Output ────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix Duplicate Departments</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4" style="max-width:800px">
    <h3>Fix Duplicate Departments &amp; Programmes</h3>

    <?php if (!empty($duplicates)): ?>
    <div class="alert alert-warning">
        Found <strong><?php echo count($duplicates); ?></strong> duplicate department group(s) before cleanup.
    </div>
    <?php endif; ?>

    <table class="table table-sm table-bordered mt-3">
        <thead class="thead-light"><tr><th width="40">Status</th><th>Step</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$status, $label]): ?>
            <tr class="<?php echo $status === 'err' ? 'table-danger' : 'table-success'; ?>">
                <td><?php echo $status === 'ok' ? '✓' : '✗'; ?></td>
                <td><?php echo htmlspecialchars($label); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php $errors = array_filter($steps, fn($s) => $s[0] === 'err'); ?>
    <?php if (empty($errors)): ?>
        <div class="alert alert-success">
            <strong>✓ Cleanup complete.</strong>
            Deleted <?php echo $deleted_total; ?> duplicate department(s) and <?php echo $prog_deleted; ?> duplicate programme(s).
            <br>Delete this file from the server after verifying the signup form works correctly.
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <strong>Some steps failed.</strong> Check errors above.
        </div>
    <?php endif; ?>

    <h5 class="mt-4">Current Departments (after cleanup)</h5>
    <?php
    $check = mysqli_query($conn,
        "SELECT sd.department_id, sd.department_name, sd.department_code, f.faculty_name
         FROM student_departments sd
         JOIN faculties f ON sd.faculty_id = f.faculty_id
         ORDER BY f.faculty_name, sd.department_name");
    if ($check): ?>
    <table class="table table-sm table-striped table-bordered">
        <thead class="thead-light">
            <tr><th>ID</th><th>Department</th><th>Code</th><th>Faculty</th></tr>
        </thead>
        <tbody>
        <?php while ($row = mysqli_fetch_assoc($check)): ?>
            <tr>
                <td><?php echo $row['department_id']; ?></td>
                <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                <td><?php echo htmlspecialchars($row['department_code']); ?></td>
                <td><?php echo htmlspecialchars($row['faculty_name']); ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <a href="student_signup.php" class="btn btn-primary">Test Signup Form</a>
    <a href="admin.php" class="btn btn-secondary ml-2">Admin Panel</a>
</div>
</body>
</html>
