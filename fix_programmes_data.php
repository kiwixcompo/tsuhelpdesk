<?php
/**
 * TSU ICT Help Desk - Fix Programmes Data
 * Populates the programmes table by matching department names (not codes).
 * Safe to run multiple times — clears and re-inserts.
 *
 * URL: https://helpdesk.tsuniversity.ng/fix_programmes_data.php
 */
ob_start();
require_once 'config.php';
set_time_limit(300);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Programmes Data</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #1e3c72; margin: 0; padding: 20px; }
        .wrap { max-width: 960px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 30px; }
        h1 { color: #1e3c72; }
        .log { background: #f8f9fa; border-radius: 8px; padding: 20px; font-family: monospace; font-size: 13px; max-height: 500px; overflow-y: auto; }
        .ok  { color: #155724; background: #d4edda; padding: 4px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        .err { color: #721c24; background: #f8d7da; padding: 4px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        .inf { color: #0d47a1; background: #e8f4fd; padding: 4px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        .warn{ color: #856404; background: #fff3cd; padding: 4px 8px; border-radius: 4px; margin: 2px 0; display: block; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #dee2e6; padding: 6px 10px; text-align: left; font-size: 13px; }
        th { background: #e9ecef; }
        .btn { display: inline-block; background: #1e3c72; color: #fff; padding: 10px 20px; border-radius: 8px; text-decoration: none; margin: 5px; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap">
<h1>🔧 Fix Programmes Data</h1>
<div class="log" id="log">
<?php

function p($msg, $cls = 'inf') {
    echo "<span class='$cls'>[" . date('H:i:s') . "] $msg</span>\n";
    flush(); ob_flush();
}

// ── 1. Verify tables exist ────────────────────────────────
foreach (['faculties','student_departments','programmes'] as $t) {
    $r = mysqli_query($conn, "SHOW TABLES LIKE '$t'");
    if (mysqli_num_rows($r) === 0) {
        p("MISSING TABLE: $t — run update_online_database.php first", 'err');
        exit;
    }
    p("Table OK: $t", 'ok');
}

// ── 2. Show what's currently in the DB ───────────────────
$fac_count  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM faculties"))['c'];
$dept_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM student_departments"))['c'];
$prog_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM programmes"))['c'];
p("Current counts — faculties: $fac_count | departments: $dept_count | programmes: $prog_count", 'inf');

if ($dept_count == 0) {
    p("No departments found — run populate_departments.php first, then re-run this script", 'err');
    exit;
}

// ── 3. Build a lookup: department_name (lowercase) → department_id ──
$dept_map = [];
$res = mysqli_query($conn, "SELECT department_id, department_name FROM student_departments");
while ($row = mysqli_fetch_assoc($res)) {
    $dept_map[strtolower(trim($row['department_name']))] = (int)$row['department_id'];
}
p("Loaded " . count($dept_map) . " departments into lookup map", 'inf');

// ── 4. Programme definitions ─────────────────────────────
// Format: [programme_name, department_name_as_in_db, reg_number_format]
// reg_number_format uses YY (last 2 digits of year) and XXXX (last 4 digits of reg no.)
// Use 'N/A' for programmes that don't have a standard reg number format.
$programmes = [
    // ── Faculty of Agriculture ──────────────────────────
    ['B.AGRIC Agricultural Economics and Extension',    'Agricultural Economics and Extension',     'TSU/FAG/AE/YY/XXXX'],
    ['B.SC. Agricultural Economics',                    'Agricultural Economics and Extension',     'TSU/FAG/AEC/YY/XXXX'],
    ['B.SC. Agricultural Extension',                    'Agricultural Economics and Extension',     'TSU/FAG/AEX/YY/XXXX'],
    ['B.AGRIC Agronomy',                                'Agronomy',                                 'TSU/FAG/AG/YY/XXXX'],
    ['B.AGRIC Animal Science',                          'Animal Science',                           'TSU/FAG/AS/YY/XXXX'],
    ['B.AGRIC Crop Protection',                         'Crop Protection',                          'TSU/FAG/CP/YY/XXXX'],
    ['B.SC. Family and Consumer Science',               'Family and Consumer Science',              'TSU/FAG/FSC/YY/XXXX'],
    ['B.AGRIC Forestry and Wildlife Management',        'Forestry and Wildlife Management',         'TSU/FAG/FW/YY/XXXX'],
    ['B.SC. Home Economics',                            'Home Economics',                           'TSU/FAG/HE/YY/XXXX'],
    ['B.SC. Soil Science and Land Resource Management', 'Soil Science and Land Resource Management','TSU/FAG/SS/YY/XXXX'],

    // ── Faculty of Health Sciences ──────────────────────
    ['B.EHS Environmental Health',                      'Environmental Health',                     'TSU/FAH/EH/YY/XXXX'],
    ['BMLS Medical Laboratory Science',                 'Medical Laboratory Science',               'TSU/FAH/ML/YY/XXXX'],
    ['BNSC Nursing',                                    'Nursing',                                  'TSU/FAH/NS/YY/XXXX'],
    ['B.SC. Public Health',                             'Public Health',                            'TSU/FAH/PU/YY/XXXX'],

    // ── Faculty of Arts ─────────────────────────────────
    ['B.A. Arabic',                                     'Arabic',                                   'TSU/FART/AR/YY/XXXX'],
    ['B.A. Christian Religious Studies',                'Christian Religious Studies',              'TSU/FART/CR/YY/XXXX'],
    ['B.A. French',                                     'French',                                   'TSU/FART/FL/YY/XXXX'],
    ['B.A. Hausa',                                      'Hausa',                                    'TSU/FART/HL/YY/XXXX'],
    ['B.A. History and Diplomatic Studies',             'History and Diplomatic Studies',           'TSU/FART/HS/YY/XXXX'],
    ['B.A. Islamic Studies',                            'Islamic Studies',                          'TSU/FART/IS/YY/XXXX'],
    ['B.A. Linguistics (English)',                      'Linguistics English',                      'TSU/FART/LE/YY/XXXX'],
    ['B.A. English',                                    'English',                                  'TSU/FART/LG/YY/XXXX'],
    ['B.A. Linguistics (Hausa)',                        'Linguistics Hausa',                        'TSU/FART/LH/YY/XXXX'],
    ['B.A. Linguistics (Mumuye)',                       'Linguistics Mumuye',                       'TSU/FART/MU/YY/XXXX'],
    ['B.A. Theatre and Film Studies',                   'Theatre and Film Studies',                 'TSU/FART/TF/YY/XXXX'],

    // ── Faculty of Communication and Media Studies ──────
    ['B.SC. Advertising',                               'Advertising',                              'TSU/FCMS/AD/YY/XXXX'],
    ['B.SC. Broadcasting',                              'Broadcasting',                             'TSU/FCMS/BC/YY/XXXX'],
    ['B.SC. Journalism and Media Studies',              'Journalism and Media Studies',             'TSU/FCMS/JM/YY/XXXX'],
    ['B.SC. Mass Communication',                        'Mass Communication',                       'TSU/FCMS/MC/YY/XXXX'],
    ['B.SC. Public Relations',                          'Public Relations',                         'TSU/FCMS/PR/YY/XXXX'],

    // ── Faculty of Education ─────────────────────────────
    ['B.AGRIC (ED) Agricultural Education',             'Agricultural Education',                   'TSU/FED/AE/YY/XXXX'],
    ['B.ED. Educational Administration and Planning',   'Educational Administration and Planning',  'TSU/FED/AP/YY/XXXX'],
    ['B.SC. (ED) Business Education',                   'Business Education',                       'TSU/FED/BE/YY/XXXX'],
    ['B.SC. (ED) Biology Education',                    'Biology Education',                        'TSU/FED/BL/YY/XXXX'],
    ['B.SC. (ED) Chemistry Education',                  'Chemistry Education',                      'TSU/FED/CH/YY/XXXX'],
    ['B.SC. (ED) Computer Science Education',           'Computer Science Education',               'TSU/FED/CS/YY/XXXX'],
    ['B.A. (ED) Christian Religious Studies',           'Christian Religious Studies Education',    'TSU/FED/CSR/YY/XXXX'],
    ['B.SC. (ED) Economics Education',                  'Economics Education',                      'TSU/FED/EC/YY/XXXX'],
    ['B.A. (ED) English Education',                     'English Education',                        'TSU/FED/EL/YY/XXXX'],
    ['B.ED. Primary Education',                         'Primary Education',                        'TSU/FED/EP/YY/XXXX'],
    ['B.ED. Guidance and Counselling',                  'Guidance and Counselling',                 'TSU/FED/GC/YY/XXXX'],
    ['B.SC. (ED) Geography Education',                  'Geography Education',                      'TSU/FED/GE/YY/XXXX'],
    ['B.SC. (ED) Home Economics Education',             'Home Economics Education',                 'TSU/FED/HE/YY/XXXX'],
    ['B.SC. (ED) Human Kinetics',                       'Human Kinetics',                           'TSU/FED/HK/YY/XXXX'],
    ['B.A. (ED) Hausa Education',                       'Hausa Education',                          'TSU/FED/HL/YY/XXXX'],
    ['B.A. (ED) History Education',                     'History Education',                        'TSU/FED/HS/YY/XXXX'],
    ['B.SC. (ED) Industrial Technical Education',       'Industrial Technical Education',           'TSU/FED/INT/YY/XXXX'],
    ['B.SC. (ED) Integrated Science Education',         'Integrated Science Education',             'TSU/FED/IS/YY/XXXX'],
    ['B.A. (ED) Islamic Religious Studies',             'Islamic Religious Studies Education',      'TSU/FED/ISL/YY/XXXX'],
    ['B.SC. (ED) Library and Information Science',      'Library and Information Science Education','TSU/FED/LI/YY/XXXX'],
    ['B.SC. (ED) Mathematics Education',                'Mathematics Education',                    'TSU/FED/MT/YY/XXXX'],
    ['B.SC. (ED) Physics Education',                    'Physics Education',                        'TSU/FED/PH/YY/XXXX'],
    ['B.SC. (ED) Political Science Education',          'Political Science Education',              'TSU/FED/PL/YY/XXXX'],
    ['B.SC. (ED) Social Studies Education',             'Social Studies Education',                 'TSU/FED/SS/YY/XXXX'],

    // ── Faculty of Engineering ───────────────────────────
    ['B.ENG. Agricultural and Bio-Resource Engineering','Agricultural and Bio-Resource Engineering','TSU/FEN/AE/YY/XXXX'],
    ['B.ENG. Civil Engineering',                        'Civil Engineering',                        'TSU/FEN/CE/YY/XXXX'],
    ['B.ENG. Electrical and Electronics Engineering',   'Electrical and Electronics Engineering',   'TSU/FEN/EE/YY/XXXX'],
    ['B.ENG. Mechanical Engineering',                   'Mechanical Engineering',                   'TSU/FEN/ME/YY/XXXX'],
    ['B.ENG. Mining and Mineral Processing Engineering','Mining and Mineral Processing Engineering','TSU/FEN/MPE/YY/XXXX'],

    // ── Faculty of Management Sciences ──────────────────
    ['B.SC. Accounting',                                'Accounting',                               'TSU/FMS/AC/YY/XXXX'],
    ['B.SC. Business Administration',                   'Business Administration',                  'TSU/FMS/BM/YY/XXXX'],
    ['B.SC. Public Administration',                     'Public Administration',                    'TSU/FMS/PB/YY/XXXX'],
    ['B.SC. Tourism Management',                        'Tourism Management',                       'TSU/FMS/TR/YY/XXXX'],

    // ── Faculty of Sciences ──────────────────────────────
    ['B.SC. Biochemistry',                              'Biochemistry',                             'TSU/FSC/BCH/YY/XXXX'],
    ['B.SC. Botany',                                    'Botany',                                   'TSU/FSC/BO/YY/XXXX'],
    ['B.SC. Biotechnology',                             'Biotechnology',                            'TSU/FSC/BTH/YY/XXXX'],
    ['B.SC. Chemistry',                                 'Chemistry',                                'TSU/FSC/CH/YY/XXXX'],
    ['B.SC. Computer Science',                          'Computer Science',                         'TSU/FSC/CS/YY/XXXX'],
    ['B.SC. Ecology and Conservation',                  'Ecology and Conservation',                 'TSU/FSC/EC/YY/XXXX'],
    ['B.SC. Industrial Chemistry',                      'Industrial Chemistry',                     'TSU/FSC/ICH/YY/XXXX'],
    ['B.SC. Microbiology',                              'Microbiology',                             'TSU/FSC/MCB/YY/XXXX'],
    ['B.SC. Mathematics',                               'Mathematics',                              'TSU/FSC/MT/YY/XXXX'],
    ['B.SC. Physics',                                   'Physics',                                  'TSU/FSC/PH/YY/XXXX'],
    ['B.SC. Statistics',                                'Statistics',                               'TSU/FSC/ST/YY/XXXX'],
    ['B.SC. Zoology',                                   'Zoology',                                  'TSU/FSC/ZO/YY/XXXX'],

    // ── Faculty of Social Sciences ───────────────────────
    ['B.SC. Geography',                                 'Geography',                                'TSU/FSS/GE/YY/XXXX'],
    ['B.SC. Peace and Conflict Studies',                'Peace and Conflict Studies',               'TSU/FSS/PC/YY/XXXX'],
    ['B.SC. Political Science and International Relations','Political Science and International Relations','TSU/FSS/PL/YY/XXXX'],
    ['B.SC. Sociology',                                 'Sociology',                                'TSU/FSS/SO/YY/XXXX'],
    ['B.SC. Economics',                                 'Economics',                                'TSU/FSMS/EC/YY/XXXX'],

    // ── Faculty of Law ───────────────────────────────────
    ['LLB. Law',                                        'Law',                                      'TSU/LAW/LLB/YY/XXXX'],
    ['LLB. Commercial Law',                             'Commercial Law',                           'TSU/LAW/CL/YY/XXXX'],
    ['LLB. Islamic Law',                                'Islamic Law',                              'TSU/LAW/IL/YY/XXXX'],
    ['LLB. Public Law',                                 'Public Law',                               'TSU/LAW/PL/YY/XXXX'],
    ['LLB. Private and Property Law',                   'Private and Property Law',                 'TSU/LAW/PP/YY/XXXX'],
];

// ── 5. Clear existing programmes ─────────────────────────
mysqli_query($conn, "DELETE FROM programmes");
p("Cleared existing programmes table", 'inf');

// ── 6. Insert programmes ──────────────────────────────────
$inserted = 0;
$skipped  = 0;

$stmt = mysqli_prepare($conn,
    "INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) VALUES (?, ?, ?, ?)"
);

foreach ($programmes as [$prog_name, $dept_name, $reg_fmt]) {
    $key = strtolower(trim($dept_name));

    // Try exact match first, then partial match
    $dept_id = $dept_map[$key] ?? null;
    if ($dept_id === null) {
        // Partial match — find any department whose name contains the search key
        foreach ($dept_map as $k => $id) {
            if (str_contains($k, $key) || str_contains($key, $k)) {
                $dept_id = $id;
                break;
            }
        }
    }

    if ($dept_id === null) {
        p("SKIP (no dept match): $prog_name → \"$dept_name\"", 'warn');
        $skipped++;
        continue;
    }

    // Derive a short code from the reg format (e.g. TSU/FAG/AE/YY/XXXX → AE)
    $parts = explode('/', $reg_fmt);
    $code  = $parts[2] ?? 'N/A';

    mysqli_stmt_bind_param($stmt, 'ssis', $prog_name, $code, $dept_id, $reg_fmt);
    if (mysqli_stmt_execute($stmt)) {
        p("Inserted: $prog_name (dept_id=$dept_id)", 'ok');
        $inserted++;
    } else {
        p("DB error for $prog_name: " . mysqli_stmt_error($stmt), 'err');
    }
}

mysqli_stmt_close($stmt);
p("Done — inserted: $inserted | skipped (no dept): $skipped", 'inf');

// ── 7. Final counts ───────────────────────────────────────
$final = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM programmes"))['c'];
p("Programmes now in DB: $final", 'ok');

?>
</div>

<?php
// ── 8. Show summary table ─────────────────────────────────
$res = mysqli_query($conn, "
    SELECT f.faculty_name, sd.department_name, COUNT(p.programme_id) AS prog_count
    FROM student_departments sd
    JOIN faculties f ON sd.faculty_id = f.faculty_id
    LEFT JOIN programmes p ON p.department_id = sd.department_id
    GROUP BY sd.department_id
    ORDER BY f.faculty_name, sd.department_name
");
?>
<h2 style="color:#1e3c72;margin-top:30px;">Department → Programme Count</h2>
<table>
    <tr><th>Faculty</th><th>Department</th><th>Programmes</th></tr>
    <?php while ($row = mysqli_fetch_assoc($res)): ?>
    <tr>
        <td><?= htmlspecialchars($row['faculty_name']) ?></td>
        <td><?= htmlspecialchars($row['department_name']) ?></td>
        <td style="text-align:center;<?= $row['prog_count']==0?'color:red;font-weight:bold':'' ?>">
            <?= $row['prog_count'] ?>
        </td>
    </tr>
    <?php endwhile; ?>
</table>

<div style="text-align:center;margin:30px 0;">
    <a href="student_signup.php" class="btn">🎓 Test Student Registration</a>
    <a href="/" class="btn">🏠 Home</a>
</div>
<p style="text-align:center;color:#6c757d;font-size:12px;">Delete this file after use for security.</p>
</div>
</body>
</html>
<?php mysqli_close($conn); ?>
