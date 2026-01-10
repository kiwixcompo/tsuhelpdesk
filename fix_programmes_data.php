<?php
/**
 * TSU ICT Help Desk - Fix Programmes Data
 * This script populates the programmes table with comprehensive data
 * and provides debugging information for the dropdown issue
 * 
 * Usage: Upload to server and run via browser
 * URL: https://helpdesk.tsuniversity.edu.ng/fix_programmes_data.php
 */

// Start output buffering for clean display
ob_start();

// Include database configuration
require_once 'config.php';

// Set execution time limit
set_time_limit(300);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSU Help Desk - Fix Programmes Data</title>
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
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(30, 60, 114, 0.3);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        .header h1 {
            color: #1e3c72;
            margin: 0;
            font-size: 2.5rem;
        }
        .log-container {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 25px;
            margin: 25px 0;
            max-height: 500px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
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
        .log-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .debug-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .debug-section h3 {
            color: #1e3c72;
            margin-top: 0;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .data-table th, .data-table td {
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            text-align: left;
        }
        .data-table th {
            background: #e9ecef;
            font-weight: 600;
        }
        .btn {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 60, 114, 0.4);
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 TSU ICT Help Desk</h1>
            <p>Fix Programmes Data & Debug Dropdowns</p>
        </div>

        <div class="log-container" id="logContainer">
            <h3>📝 Process Log</h3>

<?php

// Logging functions
function logMessage($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $class = 'log-' . $type;
    echo "<div class='log-entry $class'>[$timestamp] $message</div>\n";
    flush();
    ob_flush();
}

function logSuccess($message) { logMessage("✅ SUCCESS: $message", 'success'); }
function logError($message) { logMessage("❌ ERROR: $message", 'error'); }
function logInfo($message) { logMessage("ℹ️ INFO: $message", 'info'); }
function logWarning($message) { logMessage("⚠️ WARNING: $message", 'warning'); }

// Database helper functions
function tableExists($conn, $table) {
    $sql = "SHOW TABLES LIKE '$table'";
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

function executeQuery($conn, $sql, $description) {
    if (mysqli_query($conn, $sql)) {
        logSuccess($description);
        return true;
    } else {
        logError("$description - " . mysqli_error($conn));
        return false;
    }
}

// Start process
logInfo("Starting programmes data fix and debugging process");

// Test database connection
if (!$conn) {
    logError("Database connection failed: " . mysqli_connect_error());
    exit;
}

logSuccess("Database connection established");

// =====================================================
// DIAGNOSTIC CHECKS
// =====================================================

logInfo("Running diagnostic checks...");

// Check if required tables exist
$required_tables = ['faculties', 'student_departments', 'programmes'];
$missing_tables = [];

foreach ($required_tables as $table) {
    if (!tableExists($conn, $table)) {
        $missing_tables[] = $table;
        logError("Missing table: $table");
    } else {
        logSuccess("Table exists: $table");
    }
}

if (!empty($missing_tables)) {
    logError("Missing required tables. Please run the database update script first.");
    echo "</div><div class='debug-section'><h3>❌ Missing Tables</h3><p>The following tables are missing: " . implode(', ', $missing_tables) . "</p><p>Please run <strong>update_online_database.php</strong> first.</p></div>";
    exit;
}

// =====================================================
// POPULATE COMPREHENSIVE PROGRAMMES DATA
// =====================================================

logInfo("Populating comprehensive programmes data...");

// Clear existing programmes to avoid duplicates
$clear_result = mysqli_query($conn, "DELETE FROM programmes");
if ($clear_result) {
    logSuccess("Cleared existing programmes data");
} else {
    logWarning("Could not clear existing programmes: " . mysqli_error($conn));
}

// Comprehensive programmes data
$programmes_data = [
    // Faculty of Agriculture (FAG)
    ['B.AGRIC (Agricultural Economics and Extension)', 'AE', 'AE', 'FAG', 'TSU/FAG/AE/YY/XXXX'],
    ['B.SC. Agricultural Economics', 'AEC', 'AE', 'FAG', 'TSU/FAG/AEC/YY/XXXX'],
    ['B.SC. Agricultural Extension', 'AEX', 'AE', 'FAG', 'TSU/FAG/AEX/YY/XXXX'],
    ['B.AGRIC (Agronomy)', 'AG', 'AG', 'FAG', 'TSU/FAG/AG/YY/XXXX'],
    ['B.AGRIC (Animal Science)', 'AS', 'AS', 'FAG', 'TSU/FAG/AS/YY/XXXX'],
    ['B.AGRIC (Crop Protection)', 'CP', 'CP', 'FAG', 'TSU/FAG/CP/YY/XXXX'],
    ['B.SC. Family and Consumer Science', 'FSC', 'FSC', 'FAG', 'TSU/FAG/FSC/YY/XXXX'],
    ['B.AGRIC (Forestry and Wildlife Management)', 'FW', 'FW', 'FAG', 'TSU/FAG/FW/YY/XXXX'],
    ['B.SC. Home Economics', 'HE', 'HE', 'FAG', 'TSU/FAG/HE/YY/XXXX'],
    ['Soil Science & Land Resource Management', 'SS', 'SS', 'FAG', 'TSU/FAG/SS/YY/XXXX'],
    
    // Faculty of Health Sciences (FAH)
    ['B.EHS Environmental Health', 'EH', 'EH', 'FAH', 'TSU/FAH/EH/YY/XXXX'],
    ['BMLS. Medical Laboratory Science', 'ML', 'ML', 'FAH', 'TSU/FAH/ML/YY/XXXX'],
    ['BNSC. Nursing', 'NS', 'NS', 'FAH', 'TSU/FAH/NS/YY/XXXX'],
    ['B.SC. Public Health', 'PU', 'PU', 'FAH', 'TSU/FAH/PU/YY/XXXX'],
    
    // Faculty of Arts (FART)
    ['B.A. Arabic', 'AR', 'AR', 'FART', 'TSU/FART/AR/YY/XXXX'],
    ['B.A. Christian Religious Studies', 'CR', 'CR', 'FART', 'TSU/FART/CR/YY/XXXX'],
    ['B.A. French', 'FL', 'FL', 'FART', 'TSU/FART/FL/YY/XXXX'],
    ['B.A. Hausa', 'HL', 'HL', 'FART', 'TSU/FART/HL/YY/XXXX'],
    ['B.A.(HONS) History and Diplomatic Studies', 'HS', 'HS', 'FART', 'TSU/FART/HS/YY/XXXX'],
    ['B.A. Islamic Studies', 'IS', 'IS', 'FART', 'TSU/FART/IS/YY/XXXX'],
    ['B.A. Linguistics English', 'LE', 'LE', 'FART', 'TSU/FART/LE/YY/XXXX'],
    ['B.A. English', 'LG', 'LG', 'FART', 'TSU/FART/LG/YY/XXXX'],
    ['B.A. Linguistics Hausa', 'LH', 'LH', 'FART', 'TSU/FART/LH/YY/XXXX'],
    ['B.A. Linguistic Mumuye', 'MU', 'MU', 'FART', 'TSU/FART/MU/YY/XXXX'],
    ['B.A. (HONS) Theatre and Film Studies', 'TF', 'TF', 'FART', 'TSU/FART/TF/YY/XXXX'],
    
    // Faculty of Communication and Media Studies (FCMS)
    ['B.SC. Advertising', 'AD', 'AD', 'FCMS', 'TSU/FCMS/AD/YY/XXXX'],
    ['B.SC. Broadcasting', 'BC', 'BC', 'FCMS', 'TSU/FCMS/BC/YY/XXXX'],
    ['B.SC. Journalism and Media Studies', 'JM', 'JM', 'FCMS', 'TSU/FCMS/JM/YY/XXXX'],
    ['B.Sc. Mass Communication', 'MC', 'MC', 'FCMS', 'TSU/FCMS/MC/YY/XXXX'],
    ['B.SC. Public Relations', 'PR', 'PR', 'FCMS', 'TSU/FCMS/PR/YY/XXXX'],
    
    // Faculty of Education (FED) - Key programmes
    ['B.AGRIC (ED) Agricultural Education', 'AE', 'AE', 'FED', 'TSU/FED/AE/YY/XXXX'],
    ['B.ED. Educational Administration and Planning', 'AP', 'AP', 'FED', 'TSU/FED/AP/YY/XXXX'],
    ['B.SC. (ED) Business Education', 'BE', 'BE', 'FED', 'TSU/FED/BE/YY/XXXX'],
    ['B.SC. (ED) Biology', 'BL', 'BL', 'FED', 'TSU/FED/BL/YY/XXXX'],
    ['B.SC. (ED) Chemistry', 'CH', 'CH', 'FED', 'TSU/FED/CH/YY/XXXX'],
    ['B.SC. (ED) Computer Science Education', 'CS', 'CS', 'FED', 'TSU/FED/CS/YY/XXXX'],
    ['B.SC. (ED.) Mathematics', 'MT', 'MT', 'FED', 'TSU/FED/MT/YY/XXXX'],
    ['B.SC. (ED) Physics', 'PH', 'PH', 'FED', 'TSU/FED/PH/YY/XXXX'],
    
    // Faculty of Engineering (FEN)
    ['B.ENG. (HONS) Agric and Bio-Resource Engineering', 'AE', 'AE', 'FEN', 'TSU/FEN/AE/YY/XXXX'],
    ['B.ENG. (HONS) Civil Engineering', 'CE', 'CE', 'FEN', 'TSU/FEN/CE/YY/XXXX'],
    ['B.ENG. (HONS) Electrical and Electronics Engineering', 'EE', 'EE', 'FEN', 'TSU/FEN/EE/YY/XXXX'],
    ['B.ENG. (HONS) Mechanical Engineering', 'ME', 'ME', 'FEN', 'TSU/FEN/ME/YY/XXXX'],
    ['B.ENG. (HONS) Mining and Mineral Processing Engineering', 'MPE', 'MPE', 'FEN', 'TSU/FEN/MPE/YY/XXXX'],
    
    // Faculty of Management Sciences (FMS)
    ['B.SC. Accounting', 'AC', 'AC', 'FMS', 'TSU/FMS/AC/YY/XXXX'],
    ['B.SC. Business Administration', 'BM', 'BM', 'FMS', 'TSU/FMS/BM/YY/XXXX'],
    ['B.SC. Public Administration', 'PB', 'PB', 'FMS', 'TSU/FMS/PB/YY/XXXX'],
    ['B.SC. Tourism Management', 'TR', 'TR', 'FMS', 'TSU/FMS/TR/YY/XXXX'],
    
    // Faculty of Sciences (FSC)
    ['B.SC. Biochemistry', 'BCH', 'BCH', 'FSC', 'TSU/FSC/BCH/YY/XXXX'],
    ['B.SC. Botany', 'BO', 'BO', 'FSC', 'TSU/FSC/BO/YY/XXXX'],
    ['B.SC. Biotechnology', 'BTH', 'BTH', 'FSC', 'TSU/FSC/BTH/YY/XXXX'],
    ['B.SC. Chemistry', 'CH', 'CH', 'FSC', 'TSU/FSC/CH/YY/XXXX'],
    ['B.SC. Computer Science', 'CS', 'CS', 'FSC', 'TSU/FSC/CS/YY/XXXX'],
    ['B.SC. Ecology and Conservation', 'EC', 'EC', 'FSC', 'TSU/FSC/EC/YY/XXXX'],
    ['B.SC. Industrial Chemistry', 'ICH', 'ICH', 'FSC', 'TSU/FSC/ICH/YY/XXXX'],
    ['B.SC. Microbiology', 'MCB', 'MCB', 'FSC', 'TSU/FSC/MCB/YY/XXXX'],
    ['B.SC. Mathematics', 'MT', 'MT', 'FSC', 'TSU/FSC/MT/YY/XXXX'],
    ['B.SC. Physics', 'PH', 'PH', 'FSC', 'TSU/FSC/PH/YY/XXXX'],
    ['B.SC. Statistics', 'ST', 'ST', 'FSC', 'TSU/FSC/ST/YY/XXXX'],
    ['B.SC. Zoology', 'ZO', 'ZO', 'FSC', 'TSU/FSC/ZO/YY/XXXX'],
    
    // Faculty of Social Sciences (FSS)
    ['B.SC. Geography', 'GE', 'GE', 'FSS', 'TSU/FSS/GE/YY/XXXX'],
    ['B.SC. Peace and Conflict Studies', 'PC', 'PC', 'FSS', 'TSU/FSS/PC/YY/XXXX'],
    ['B.SC. Political Science and International Relations', 'PL', 'PL', 'FSS', 'TSU/FSS/PL/YY/XXXX'],
    ['B.SC. Sociology', 'SO', 'SO', 'FSS', 'TSU/FSS/SO/YY/XXXX'],
    
    // Faculty of Law (LAW)
    ['LLB. Commercial Law', 'CL', 'CL', 'LAW', 'TSU/LAW/CL/YY/XXXX'],
    ['LLB. Islamic Law', 'IL', 'IL', 'LAW', 'TSU/LAW/IL/YY/XXXX'],
    ['LLB. Law', 'LLB', 'LLB', 'LAW', 'TSU/LAW/LLB/YY/XXXX'],
    ['LLB. Public Law', 'PL', 'PL', 'LAW', 'TSU/LAW/PL/YY/XXXX'],
    ['LLB. Private and Property Law', 'PP', 'PP', 'LAW', 'TSU/LAW/PP/YY/XXXX'],
];

$programmes_inserted = 0;
$programmes_failed = 0;

foreach ($programmes_data as $prog) {
    $programme_name = mysqli_real_escape_string($conn, $prog[0]);
    $programme_code = mysqli_real_escape_string($conn, $prog[1]);
    $dept_code = mysqli_real_escape_string($conn, $prog[2]);
    $faculty_code = mysqli_real_escape_string($conn, $prog[3]);
    $reg_format = mysqli_real_escape_string($conn, $prog[4]);
    
    $sql = "INSERT INTO programmes (programme_name, programme_code, department_id, reg_number_format) 
            SELECT '$programme_name', '$programme_code', sd.department_id, '$reg_format'
            FROM student_departments sd 
            JOIN faculties f ON sd.faculty_id = f.faculty_id 
            WHERE sd.department_code = '$dept_code' AND f.faculty_code = '$faculty_code'";
    
    if (mysqli_query($conn, $sql)) {
        if (mysqli_affected_rows($conn) > 0) {
            $programmes_inserted++;
            logSuccess("Inserted: $programme_name");
        } else {
            logWarning("No matching department for: $programme_name (Dept: $dept_code, Faculty: $faculty_code)");
        }
    } else {
        $programmes_failed++;
        logError("Failed to insert: $programme_name - " . mysqli_error($conn));
    }
}

logSuccess("Programmes insertion completed: $programmes_inserted inserted, $programmes_failed failed");

echo "</div>";

// =====================================================
// DIAGNOSTIC INFORMATION
// =====================================================

echo "<div class='debug-section'>";
echo "<h3>📊 Database Diagnostic Information</h3>";

// Count records in each table
$tables_info = [];
foreach ($required_tables as $table) {
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $tables_info[$table] = $row['count'];
    }
}

echo "<table class='data-table'>";
echo "<tr><th>Table</th><th>Record Count</th></tr>";
foreach ($tables_info as $table => $count) {
    echo "<tr><td>$table</td><td>$count</td></tr>";
}
echo "</table>";

echo "</div>";

// Show sample data from each table
echo "<div class='debug-section'>";
echo "<h3>📋 Sample Data</h3>";

// Faculties
echo "<h4>Faculties (Sample)</h4>";
$result = mysqli_query($conn, "SELECT * FROM faculties LIMIT 5");
if ($result && mysqli_num_rows($result) > 0) {
    echo "<table class='data-table'>";
    echo "<tr><th>ID</th><th>Name</th><th>Code</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr><td>{$row['faculty_id']}</td><td>{$row['faculty_name']}</td><td>{$row['faculty_code']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No faculties found</p>";
}

// Departments
echo "<h4>Departments (Sample)</h4>";
$result = mysqli_query($conn, "SELECT sd.*, f.faculty_name FROM student_departments sd JOIN faculties f ON sd.faculty_id = f.faculty_id LIMIT 10");
if ($result && mysqli_num_rows($result) > 0) {
    echo "<table class='data-table'>";
    echo "<tr><th>ID</th><th>Department</th><th>Code</th><th>Faculty</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr><td>{$row['department_id']}</td><td>{$row['department_name']}</td><td>{$row['department_code']}</td><td>{$row['faculty_name']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No departments found</p>";
}

// Programmes
echo "<h4>Programmes (Sample)</h4>";
$result = mysqli_query($conn, "SELECT p.*, sd.department_name, f.faculty_name FROM programmes p 
                               JOIN student_departments sd ON p.department_id = sd.department_id 
                               JOIN faculties f ON sd.faculty_id = f.faculty_id LIMIT 15");
if ($result && mysqli_num_rows($result) > 0) {
    echo "<table class='data-table'>";
    echo "<tr><th>ID</th><th>Programme</th><th>Code</th><th>Department</th><th>Faculty</th><th>Reg Format</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr><td>{$row['programme_id']}</td><td>{$row['programme_name']}</td><td>{$row['programme_code']}</td><td>{$row['department_name']}</td><td>{$row['faculty_name']}</td><td>{$row['reg_number_format']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>No programmes found</p>";
}

echo "</div>";

// =====================================================
// API TESTING SECTION
// =====================================================

echo "<div class='debug-section'>";
echo "<h3>🔧 API Testing</h3>";
echo "<p>Test the API endpoints to ensure they're working correctly:</p>";

// Get a sample faculty ID for testing
$result = mysqli_query($conn, "SELECT faculty_id, faculty_name FROM faculties LIMIT 1");
if ($result && mysqli_num_rows($result) > 0) {
    $faculty = mysqli_fetch_assoc($result);
    $test_faculty_id = $faculty['faculty_id'];
    $test_faculty_name = $faculty['faculty_name'];
    
    echo "<p><strong>Test Faculty:</strong> {$test_faculty_name} (ID: {$test_faculty_id})</p>";
    
    // Test departments API
    $dept_result = mysqli_query($conn, "SELECT department_id, department_name FROM student_departments WHERE faculty_id = $test_faculty_id LIMIT 1");
    if ($dept_result && mysqli_num_rows($dept_result) > 0) {
        $dept = mysqli_fetch_assoc($dept_result);
        $test_dept_id = $dept['department_id'];
        $test_dept_name = $dept['department_name'];
        
        echo "<p><strong>Test Department:</strong> {$test_dept_name} (ID: {$test_dept_id})</p>";
        
        // Test programmes API
        $prog_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM programmes WHERE department_id = $test_dept_id");
        if ($prog_result) {
            $prog_count = mysqli_fetch_assoc($prog_result);
            echo "<p><strong>Programmes in this department:</strong> {$prog_count['count']}</p>";
        }
        
        echo "<div style='margin: 15px 0;'>";
        echo "<a href='api/get_departments.php' class='btn' onclick='testDepartmentsAPI($test_faculty_id); return false;'>Test Departments API</a>";
        echo "<a href='api/get_programmes.php' class='btn' onclick='testProgrammesAPI($test_dept_id); return false;'>Test Programmes API</a>";
        echo "</div>";
        
        echo "<div id='apiResults' style='margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 5px; display: none;'></div>";
    }
}

echo "</div>";

mysqli_close($conn);

?>

        <div style="text-align: center; margin: 30px 0;">
            <a href="student_signup.php" class="btn">🎓 Test Student Registration</a>
            <a href="/" class="btn">🏠 Go to Help Desk</a>
        </div>

        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #6c757d;">
            <p><strong>TSU ICT Help Desk - Programmes Data Fix</strong></p>
            <p>Taraba State University - January 2026</p>
            <small>🔒 Delete this file after successful fix for security</small>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script>
        // Auto-scroll log container to bottom
        const logContainer = document.getElementById('logContainer');
        if (logContainer) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }
        
        // API testing functions
        function testDepartmentsAPI(facultyId) {
            $('#apiResults').show().html('<p>Testing Departments API...</p>');
            
            $.ajax({
                url: 'api/get_departments.php',
                type: 'POST',
                data: {faculty_id: facultyId},
                dataType: 'json',
                success: function(response) {
                    $('#apiResults').html('<h4>Departments API Response:</h4><pre>' + JSON.stringify(response, null, 2) + '</pre>');
                },
                error: function(xhr, status, error) {
                    $('#apiResults').html('<h4>Departments API Error:</h4><p>Status: ' + status + '</p><p>Error: ' + error + '</p><pre>' + xhr.responseText + '</pre>');
                }
            });
        }
        
        function testProgrammesAPI(departmentId) {
            $('#apiResults').show().html('<p>Testing Programmes API...</p>');
            
            $.ajax({
                url: 'api/get_programmes.php',
                type: 'POST',
                data: {department_id: departmentId},
                dataType: 'json',
                success: function(response) {
                    $('#apiResults').html('<h4>Programmes API Response:</h4><pre>' + JSON.stringify(response, null, 2) + '</pre>');
                },
                error: function(xhr, status, error) {
                    $('#apiResults').html('<h4>Programmes API Error:</h4><p>Status: ' + status + '</p><p>Error: ' + error + '</p><pre>' + xhr.responseText + '</pre>');
                }
            });
        }
    </script>
</body>
</html>