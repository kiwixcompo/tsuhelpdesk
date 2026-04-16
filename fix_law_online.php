<?php
session_start();
require_once "config.php";

echo "<h1>Fixing Law Programmes on Online Copy</h1>";

// 1. Get the Faculty of Law ID
$sql = "SELECT faculty_id FROM faculties WHERE faculty_code = 'LAW'";
$result = mysqli_query($conn, $sql);
if (!$result || mysqli_num_rows($result) === 0) {
    die("Error: Faculty of Law (LAW) not found in the faculties table.");
}
$faculty = mysqli_fetch_assoc($result);
$law_faculty_id = $faculty['faculty_id'];

// 2. The required programmes
$law_programmes = [
    ['LLB. Commercial Law', 'CL', 'TSU/LAW/CL/YY/XXXX'],
    ['LLB. Islamic Law', 'IL', 'TSU/LAW/IL/YY/XXXX'],
    ['LLB. Law', 'LLB', 'TSU/LAW/LLB/YY/XXXX'],
    ['LLB. Public Law', 'PL', 'TSU/LAW/PL/YY/XXXX'],
    ['LLB. Private and Property Law', 'PP', 'TSU/LAW/PP/YY/XXXX'],
];

// 3. Find Law sub-departments or just the general "Law" department
$sql = "SELECT department_id, department_code, department_name FROM student_departments WHERE faculty_id = $law_faculty_id";
$dept_result = mysqli_query($conn, $sql);
$law_depts = [];
while ($row = mysqli_fetch_assoc($dept_result)) {
    $law_depts[$row['department_code']] = $row;
}

if (empty($law_depts)) {
    // If no law departments exist at all, create a general one.
    $sql = "INSERT INTO student_departments (department_name, department_code, faculty_id) VALUES ('Law', 'LAW', $law_faculty_id)";
    if (mysqli_query($conn, $sql)) {
        $general_law_dept_id = mysqli_insert_id($conn);
        echo "<p>Created general 'Law' department since none existed.</p>";
    } else {
        die("Failed to create Law department: " . mysqli_error($conn));
    }
} else {
    // We check if we have a "LLB" or "LAW" general department to fallback to
    $general_law_dept_id = null;
    foreach ($law_depts as $code => $dept) {
        if ($code == 'LLB' || $code == 'LAW' || stripos($dept['department_name'], 'Law') !== false) {
            $general_law_dept_id = $dept['department_id'];
            break;
        }
    }
    if (!$general_law_dept_id) {
        // Fallback to the first department available under Faculty of Law
        $general_law_dept_id = reset($law_depts)['department_id'];
    }
}

// 4. Insert or update the programmes
echo "<h2>Inserting Programmes</h2><ul>";
foreach ($law_programmes as $prog) {
    $prog_name = mysqli_real_escape_string($conn, $prog[0]);
    $prog_code = mysqli_real_escape_string($conn, $prog[1]);
    $reg_format = mysqli_real_escape_string($conn, $prog[2]);
    
    // Determine which department_id to use
    // If a specific sub-department code exists (e.g. 'CL' for Commercial Law), use it.
    // Otherwise, fallback to the general law department.
    if (isset($law_depts[$prog_code])) {
        $dept_id_to_use = $law_depts[$prog_code]['department_id'];
    } else {
        $dept_id_to_use = $general_law_dept_id;
    }
    
    // Check if it exists
    $check_sql = "SELECT programme_id FROM programmes WHERE programme_code = '$prog_code' AND department_id = $dept_id_to_use";
    $check_res = mysqli_query($conn, $check_sql);
    
    if (mysqli_num_rows($check_res) > 0) {
        // Update
        $row = mysqli_fetch_assoc($check_res);
        $prog_id = $row['programme_id'];
        $upd_sql = "UPDATE programmes SET programme_name = '$prog_name', reg_number_format = '$reg_format' WHERE programme_id = $prog_id";
        if(mysqli_query($conn, $upd_sql)) {
            echo "<li>Updated existing programme: <strong>$prog_name</strong></li>";
        } else {
            echo "<li><span style='color:red;'>Failed to update $prog_name: " . mysqli_error($conn) . "</span></li>";
        }
    } else {
        // Insert
        $ins_sql = "INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
                    VALUES ('$prog_name', '$prog_code', $dept_id_to_use, '$reg_format')";
        if(mysqli_query($conn, $ins_sql)) {
            echo "<li>Successfully inserted programme: <strong>$prog_name</strong></li>";
        } else {
            echo "<li><span style='color:red;'>Failed to insert $prog_name: " . mysqli_error($conn) . "</span></li>";
        }
    }
}
echo "</ul>";
echo "<p><strong>Done! All Law programmes should now be visible on the signup form.</strong></p>";

mysqli_close($conn);
?>
