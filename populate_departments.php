<?php
/**
 * TSU ICT Help Desk - Populate All Departments
 * This script ensures all departments are properly populated
 * 
 * Usage: Upload to server and run via browser
 * URL: https://helpdesk.tsuniversity.edu.ng/populate_departments.php
 */

require_once 'config.php';

// Set execution time limit
set_time_limit(300);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSU Help Desk - Populate Departments</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: #333;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(30, 60, 114, 0.3);
        }
        .log-entry {
            margin: 5px 0;
            padding: 8px 12px;
            border-radius: 4px;
            line-height: 1.4;
        }
        .log-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .log-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .log-info { background: #e8f4fd; color: #0d47a1; border-left: 4px solid #2196f3; }
    </style>
</head>
<body>
    <div class="container">
        <h1 style="color: #1e3c72; text-align: center;">🏛️ TSU Departments Population</h1>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; font-family: monospace; font-size: 14px;">

<?php

function logMessage($message, $type = 'info') {
    $class = 'log-' . $type;
    echo "<div class='log-entry $class'>[" . date('H:i:s') . "] $message</div>\n";
    flush();
}

// Clear existing departments
mysqli_query($conn, "DELETE FROM student_departments");
logMessage("Cleared existing departments", 'info');

// Comprehensive departments data
$departments_data = [
    // Faculty of Agriculture (FAG)
    ['Agricultural Economics and Extension', 'AE', 'FAG'],
    ['Agronomy', 'AG', 'FAG'],
    ['Animal Science', 'AS', 'FAG'],
    ['Crop Protection', 'CP', 'FAG'],
    ['Family and Consumer Science', 'FSC', 'FAG'],
    ['Forestry and Wildlife Management', 'FW', 'FAG'],
    ['Home Economics', 'HE', 'FAG'],
    ['Soil Science and Land Resource Management', 'SS', 'FAG'],
    
    // Faculty of Health Sciences (FAH)
    ['Environmental Health', 'EH', 'FAH'],
    ['Medical Laboratory Science', 'ML', 'FAH'],
    ['Nursing', 'NS', 'FAH'],
    ['Public Health', 'PU', 'FAH'],
    
    // Faculty of Arts (FART)
    ['Arabic', 'AR', 'FART'],
    ['Christian Religious Studies', 'CR', 'FART'],
    ['French', 'FL', 'FART'],
    ['Hausa', 'HL', 'FART'],
    ['History and Diplomatic Studies', 'HS', 'FART'],
    ['Islamic Studies', 'IS', 'FART'],
    ['Linguistics English', 'LE', 'FART'],
    ['English', 'LG', 'FART'],
    ['Linguistics Hausa', 'LH', 'FART'],
    ['Linguistics Mumuye', 'MU', 'FART'],
    ['Theatre and Film Studies', 'TF', 'FART'],
    
    // Faculty of Communication and Media Studies (FCMS)
    ['Advertising', 'AD', 'FCMS'],
    ['Broadcasting', 'BC', 'FCMS'],
    ['Journalism and Media Studies', 'JM', 'FCMS'],
    ['Mass Communication', 'MC', 'FCMS'],
    ['Public Relations', 'PR', 'FCMS'],
    
    // Faculty of Education (FED)
    ['Agricultural Education', 'AE', 'FED'],
    ['Educational Administration and Planning', 'AP', 'FED'],
    ['Business Education', 'BE', 'FED'],
    ['Biology Education', 'BL', 'FED'],
    ['Chemistry Education', 'CH', 'FED'],
    ['Computer Science Education', 'CS', 'FED'],
    ['Christian Religious Studies Education', 'CSR', 'FED'],
    ['Economics Education', 'EC', 'FED'],
    ['English Education', 'EL', 'FED'],
    ['Primary Education', 'EP', 'FED'],
    ['Guidance and Counselling', 'GC', 'FED'],
    ['Geography Education', 'GE', 'FED'],
    ['Home Economics Education', 'HE', 'FED'],
    ['Human Kinetics', 'HK', 'FED'],
    ['Hausa Education', 'HL', 'FED'],
    ['History Education', 'HS', 'FED'],
    ['Industrial Technical Education', 'INT', 'FED'],
    ['Integrated Science Education', 'IS', 'FED'],
    ['Islamic Religious Studies Education', 'ISL', 'FED'],
    ['Library and Information Science Education', 'LI', 'FED'],
    ['Mathematics Education', 'MT', 'FED'],
    ['Physics Education', 'PH', 'FED'],
    ['Political Science Education', 'PL', 'FED'],
    ['Social Studies Education', 'SS', 'FED'],
    
    // Faculty of Engineering (FEN)
    ['Agricultural and Bio-Resource Engineering', 'AE', 'FEN'],
    ['Civil Engineering', 'CE', 'FEN'],
    ['Electrical and Electronics Engineering', 'EE', 'FEN'],
    ['Mechanical Engineering', 'ME', 'FEN'],
    ['Mining and Mineral Processing Engineering', 'MPE', 'FEN'],
    
    // Faculty of Management Sciences (FMS)
    ['Accounting', 'AC', 'FMS'],
    ['Business Administration', 'BM', 'FMS'],
    ['Public Administration', 'PB', 'FMS'],
    ['Tourism Management', 'TR', 'FMS'],
    
    // Faculty of Sciences (FSC)
    ['Biochemistry', 'BCH', 'FSC'],
    ['Botany', 'BO', 'FSC'],
    ['Biotechnology', 'BTH', 'FSC'],
    ['Chemistry', 'CH', 'FSC'],
    ['Computer Science', 'CS', 'FSC'],
    ['Ecology and Conservation', 'EC', 'FSC'],
    ['Industrial Chemistry', 'ICH', 'FSC'],
    ['Microbiology', 'MCB', 'FSC'],
    ['Mathematics', 'MT', 'FSC'],
    ['Physics', 'PH', 'FSC'],
    ['Statistics', 'ST', 'FSC'],
    ['Zoology', 'ZO', 'FSC'],
    
    // Faculty of Social and Management Sciences (FSMS)
    ['Economics', 'EC', 'FSMS'],
    
    // Faculty of Social Sciences (FSS)
    ['Geography', 'GE', 'FSS'],
    ['Peace and Conflict Studies', 'PC', 'FSS'],
    ['Political Science and International Relations', 'PL', 'FSS'],
    ['Sociology', 'SO', 'FSS'],
    
    // Faculty of Law (LAW)
    ['Commercial Law', 'CL', 'LAW'],
    ['Islamic Law', 'IL', 'LAW'],
    ['Law', 'LLB', 'LAW'],
    ['Public Law', 'PL', 'LAW'],
    ['Private and Property Law', 'PP', 'LAW'],
];

$inserted = 0;
$failed = 0;

foreach ($departments_data as $dept) {
    $dept_name = mysqli_real_escape_string($conn, $dept[0]);
    $dept_code = mysqli_real_escape_string($conn, $dept[1]);
    $faculty_code = mysqli_real_escape_string($conn, $dept[2]);
    
    $sql = "INSERT INTO student_departments (department_name, department_code, faculty_id) 
            SELECT '$dept_name', '$dept_code', faculty_id 
            FROM faculties WHERE faculty_code = '$faculty_code'";
    
    if (mysqli_query($conn, $sql)) {
        if (mysqli_affected_rows($conn) > 0) {
            $inserted++;
            logMessage("✅ Inserted: $dept_name ($faculty_code)", 'success');
        } else {
            logMessage("⚠️ No faculty found for: $dept_name ($faculty_code)", 'error');
            $failed++;
        }
    } else {
        logMessage("❌ Failed: $dept_name - " . mysqli_error($conn), 'error');
        $failed++;
    }
}

logMessage("Departments population completed: $inserted inserted, $failed failed", 'info');

// Show final counts
$dept_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM student_departments");
$dept_total = mysqli_fetch_assoc($dept_count)['count'];

$faculty_count = mysqli_query($conn, "SELECT COUNT(*) as count FROM faculties");
$faculty_total = mysqli_fetch_assoc($faculty_count)['count'];

logMessage("Final counts: $faculty_total faculties, $dept_total departments", 'info');

mysqli_close($conn);

?>

        </div>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="fix_programmes_data.php" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600;">🔧 Fix Programmes Data</a>
        </div>
    </div>
</body>
</html>