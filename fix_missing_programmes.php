<?php
/**
 * Fix missing programmes on the server database.
 * This inserts all programmes that should exist based on the faculty/department structure.
 * Run once: https://helpdesk.tsuniversity.ng/fix_missing_programmes.php
 * Delete after running.
 */
require_once "config.php";

$steps = [];
$inserted = 0;
$skipped = 0;

function insertProg(string $name, string $code, string $dept_code, string $fac_code, string $reg_fmt): void {
    global $conn, $steps, $inserted, $skipped;

    // Get department_id
    $r = mysqli_query($conn,
        "SELECT sd.department_id FROM student_departments sd
         JOIN faculties f ON sd.faculty_id = f.faculty_id
         WHERE sd.department_code = '$dept_code' AND f.faculty_code = '$fac_code'
         ORDER BY sd.department_id ASC LIMIT 1");

    if (!$r || !($row = mysqli_fetch_assoc($r))) {
        $steps[] = ['warn', "Dept '$dept_code' in '$fac_code' not found — skipping '$name'"];
        return;
    }
    $dept_id = $row['department_id'];

    // Use a unique programme_code per department (append dept_code to avoid conflicts)
    $unique_code = strtoupper($dept_code) . '_' . strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
    if (strlen($unique_code) > 10) $unique_code = substr($unique_code, 0, 10);

    $res = mysqli_query($conn,
        "INSERT IGNORE INTO programmes (programme_name, programme_code, department_id, reg_number_format)
         VALUES ('$name', '$unique_code', $dept_id, '$reg_fmt')");

    if ($res && mysqli_affected_rows($conn) > 0) {
        $steps[] = ['ok', "Inserted: $name ($unique_code) → dept $dept_id"];
        $inserted++;
    } else {
        $steps[] = ['skip', "Already exists: $name in dept $dept_id"];
        $skipped++;
    }
}

// ── Faculty of Agriculture (FAG) ─────────────────────────
insertProg('B.Sc. Agricultural Extension & Economics', 'AGEX', 'AEE', 'FAG', 'TSU/FAG/AEE/YY/XXXX');
insertProg('B.Sc. Agronomy', 'AGRO', 'AGR', 'FAG', 'TSU/FAG/AGR/YY/XXXX');
insertProg('B.Sc. Animal Science', 'ANSC', 'ANS', 'FAG', 'TSU/FAG/ANS/YY/XXXX');
insertProg('B.Sc. Crop Production', 'CROP', 'CP', 'FAG', 'TSU/FAG/CP/YY/XXXX');
insertProg('B.Sc. Forestry & Wildlife Conservation', 'FORE', 'FWC', 'FAG', 'TSU/FAG/FWC/YY/XXXX');
insertProg('B.Sc. Home Economics', 'HOME', 'HEC', 'FAG', 'TSU/FAG/HEC/YY/XXXX');
insertProg('B.Sc. Soil Science & Land Resources Management', 'SOIL', 'SLR', 'FAG', 'TSU/FAG/SLR/YY/XXXX');

// ── Faculty of Arts (FART) ────────────────────────────────
insertProg('B.A. Arabic', 'AR', 'AR', 'FART', 'TSU/FART/AR/YY/XXXX');
insertProg('B.A. Christian Religious Studies', 'CRS', 'CR', 'FART', 'TSU/FART/CR/YY/XXXX');
insertProg('B.A. English & Literary Studies', 'ELS', 'ELS', 'FART', 'TSU/FART/ELS/YY/XXXX');
insertProg('B.A. French', 'FR', 'FR', 'FART', 'TSU/FART/FR/YY/XXXX');
insertProg('B.A. History', 'HIS', 'HIS', 'FART', 'TSU/FART/HIS/YY/XXXX');
insertProg('B.A. Islamic Studies', 'ISL', 'IS', 'FART', 'TSU/FART/IS/YY/XXXX');
insertProg('B.A. Languages & Linguistics', 'LANG', 'LL', 'FART', 'TSU/FART/LL/YY/XXXX');
insertProg('B.A. Theatre & Film Studies', 'TFS', 'TFS', 'FART', 'TSU/FART/TFS/YY/XXXX');

// ── Faculty of Communication & Media Studies (FCMS) ──────
insertProg('B.Sc. Mass Communication', 'MCOM', 'MC', 'FCMS', 'TSU/FCMS/MC/YY/XXXX');

// ── Faculty of Education (FED) ────────────────────────────
insertProg('B.Ed. Arts Education', 'ARED', 'AED', 'FED', 'TSU/FED/AED/YY/XXXX');
insertProg('B.Ed. Counselling, Educational Psychology & Human Development', 'CEPH', 'CEP', 'FED', 'TSU/FED/CEP/YY/XXXX');
insertProg('B.Ed. Educational Foundations', 'EDFO', 'EDF', 'FED', 'TSU/FED/EDF/YY/XXXX');
insertProg('B.Ed. Human Kinetics & Physical Education', 'HKPE', 'HKP', 'FED', 'TSU/FED/HKP/YY/XXXX');
insertProg('B.Ed. Library & Information Science', 'LIBS', 'LIS', 'FED', 'TSU/FED/LIS/YY/XXXX');
insertProg('B.Ed. Science Education', 'SCED', 'SCE', 'FED', 'TSU/FED/SCE/YY/XXXX');
insertProg('B.Ed. Social Science Education', 'SSED', 'SSE', 'FED', 'TSU/FED/SSE/YY/XXXX');
insertProg('B.Ed. Vocational & Technology Education', 'VTED', 'VTE', 'FED', 'TSU/FED/VTE/YY/XXXX');

// ── Faculty of Engineering (FENG) ────────────────────────
insertProg('B.Eng. Agricultural & Bio-Resources Engineering', 'ABRE', 'ABE', 'FENG', 'TSU/FENG/ABE/YY/XXXX');
insertProg('B.Eng. Civil Engineering', 'CIVE', 'CE', 'FENG', 'TSU/FENG/CE/YY/XXXX');
insertProg('B.Eng. Electrical/Electronics Engineering', 'ELEE', 'EEE', 'FENG', 'TSU/FENG/EEE/YY/XXXX');
insertProg('B.Eng. Mechanical Engineering', 'MECE', 'ME', 'FENG', 'TSU/FENG/ME/YY/XXXX');

// ── Faculty of Health Sciences (FAH) ─────────────────────
insertProg('B.Sc. Environmental Health', 'ENVH', 'EH', 'FAH', 'TSU/FAH/EH/YY/XXXX');
insertProg('B.Sc. Medical Laboratory Science', 'MLAB', 'MLS', 'FAH', 'TSU/FAH/MLS/YY/XXXX');
insertProg('B.Sc. Nursing', 'NURS', 'NUR', 'FAH', 'TSU/FAH/NUR/YY/XXXX');
insertProg('B.Sc. Public Health', 'PUBH', 'PU', 'FAH', 'TSU/FAH/PU/YY/XXXX');

// ── Faculty of Law (LAW) ──────────────────────────────────
insertProg('LLB. Commercial Law', 'CL', 'CL', 'LAW', 'TSU/LAW/CL/YY/XXXX');
insertProg('LLB. Islamic Law', 'IL', 'IL', 'LAW', 'TSU/LAW/IL/YY/XXXX');
insertProg('LLB. Law', 'LLB', 'LLB', 'LAW', 'TSU/LAW/LLB/YY/XXXX');
insertProg('LLB. Public Law', 'PL', 'PL', 'LAW', 'TSU/LAW/PL/YY/XXXX');
insertProg('LLB. Private and Property Law', 'PP', 'PP', 'LAW', 'TSU/LAW/PP/YY/XXXX');

// ── Faculty of Management Sciences (FMS) ─────────────────
insertProg('B.Sc. Accounting', 'ACCT', 'ACC', 'FMS', 'TSU/FMS/ACC/YY/XXXX');
insertProg('B.Sc. Business Administration', 'BADM', 'BA', 'FMS', 'TSU/FMS/BA/YY/XXXX');
insertProg('B.Sc. Hospitality & Tourism Management', 'HOTM', 'HTM', 'FMS', 'TSU/FMS/HTM/YY/XXXX');
insertProg('B.Sc. Public Administration', 'PADM', 'PA', 'FMS', 'TSU/FMS/PA/YY/XXXX');

// ── Faculty of Sciences (FSC) ─────────────────────────────
insertProg('B.Sc. Biological Sciences', 'BIOS', 'BS', 'FSC', 'TSU/FSC/BS/YY/XXXX');
insertProg('B.Sc. Chemical Sciences', 'CHEM', 'CS', 'FSC', 'TSU/FSC/CS/YY/XXXX');
insertProg('B.Sc. Computer Science', 'COMP', 'CSC', 'FSC', 'TSU/FSC/CSC/YY/XXXX');
insertProg('B.Sc. Mathematics & Statistics', 'MATH', 'MS', 'FSC', 'TSU/FSC/MS/YY/XXXX');
insertProg('B.Sc. Physics', 'PHYS', 'PHY', 'FSC', 'TSU/FSC/PHY/YY/XXXX');

// ── Faculty of Social Sciences (FOSS) ────────────────────
insertProg('B.Sc. Economics', 'ECON', 'ECO', 'FOSS', 'TSU/FOSS/ECO/YY/XXXX');
insertProg('B.Sc. Geography', 'GEOG', 'GEO', 'FOSS', 'TSU/FOSS/GEO/YY/XXXX');
insertProg('B.Sc. Peace & Conflict Studies', 'PACS', 'PCS', 'FOSS', 'TSU/FOSS/PCS/YY/XXXX');
insertProg('B.Sc. Political & International Relations', 'POLR', 'PIR', 'FOSS', 'TSU/FOSS/PIR/YY/XXXX');
insertProg('B.Sc. Sociology', 'SOCI', 'SOC', 'FOSS', 'TSU/FOSS/SOC/YY/XXXX');

// ── Faculty of Computing & AI (FCA) ──────────────────────
insertProg('B.Sc Computer Science', 'BSCCS', 'CS', 'FCA', 'TSU/FCA/CS/YY/XXXX');
insertProg('B.Sc Data Science', 'BSCDS', 'DSAI', 'FCA', 'N/A');
insertProg('B.Sc Information and Communication Technology', 'BSCICT', 'ICT', 'FCA', 'N/A');
insertProg('B.Sc Software Engineering', 'BSCSE', 'SE', 'FCA', 'N/A');

// ── Faculty of Religion & Philosophy (FRP) ───────────────
insertProg('B.A. Islamic Studies', 'BAISL', 'ISL', 'FRP', 'TSU/FRP/ISL/YY/XXXX');
insertProg('B.A. Christian Religious Studies', 'BACRS', 'CRS', 'FRP', 'TSU/FRP/CRS/YY/XXXX');

// ── Output ────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix Missing Programmes</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4" style="max-width:800px">
    <h3>Fix Missing Programmes</h3>
    <div class="alert alert-info">
        Inserted: <strong><?php echo $inserted; ?></strong> &nbsp;|&nbsp;
        Already existed: <strong><?php echo $skipped; ?></strong>
    </div>

    <table class="table table-sm table-bordered">
        <thead class="thead-light"><tr><th width="40">Status</th><th>Step</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$s, $lbl]): ?>
            <tr class="<?php echo $s==='ok'?'table-success':($s==='warn'?'table-warning':''); ?>">
                <td><?php echo $s==='ok'?'✓':($s==='warn'?'⚠':'–'); ?></td>
                <td><?php echo htmlspecialchars($lbl); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="alert alert-success mt-3">
        <strong>✓ Done.</strong> Students should now see their programme names correctly.
        Delete this file after verifying.
    </div>

    <h5 class="mt-4">Students with still-missing programmes</h5>
    <?php
    $missing = mysqli_query($conn,
        "SELECT s.student_id, CONCAT(s.first_name,' ',s.last_name) AS name,
                s.registration_number, s.programme_id,
                sd.department_name, f.faculty_name
         FROM students s
         LEFT JOIN programmes p ON s.programme_id = p.programme_id
         LEFT JOIN student_departments sd ON s.department_id = sd.department_id
         LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
         WHERE p.programme_id IS NULL
         ORDER BY f.faculty_name, sd.department_name");
    if ($missing && mysqli_num_rows($missing) > 0): ?>
    <table class="table table-sm table-striped table-bordered">
        <thead class="thead-light">
            <tr><th>Student</th><th>Reg No</th><th>Programme ID</th><th>Department</th><th>Faculty</th></tr>
        </thead>
        <tbody>
        <?php while ($r = mysqli_fetch_assoc($missing)): ?>
            <tr class="table-warning">
                <td><?php echo htmlspecialchars($r['name']); ?></td>
                <td><?php echo htmlspecialchars($r['registration_number']); ?></td>
                <td><?php echo $r['programme_id']; ?></td>
                <td><?php echo htmlspecialchars($r['department_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($r['faculty_name'] ?? 'N/A'); ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p class="text-success">✓ All students have valid programme records.</p>
    <?php endif; ?>

    <a href="student_login.php" class="btn btn-primary">Test Student Login</a>
    <a href="manage_students.php" class="btn btn-secondary ml-2">Manage Students</a>
</div>
</body>
</html>
