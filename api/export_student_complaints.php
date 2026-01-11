<?php
session_start();
require_once "../config.php";

// Check if user is logged in and has appropriate role (Admin or Director)
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(403);
    die('Access denied');
}

// Check if user has permission (Admin = 1, Director = 3)
if(!in_array($_SESSION["role_id"], [1, 3])){
    http_response_code(403);
    die('Access denied');
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$faculty_filter = $_GET['faculty_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query conditions
$where_conditions = ["1=1"];
$params = [];
$param_types = "";

if (!empty($date_from)) {
    $where_conditions[] = "sc.created_at >= ?";
    $params[] = $date_from . " 00:00:00";
    $param_types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "sc.created_at <= ?";
    $params[] = $date_to . " 23:59:59";
    $param_types .= "s";
}

if (!empty($faculty_filter)) {
    $where_conditions[] = "f.faculty_id = ?";
    $params[] = $faculty_filter;
    $param_types .= "i";
}

if (!empty($status_filter)) {
    $where_conditions[] = "sc.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Get complaints data
$complaints_sql = "SELECT 
    sc.complaint_id,
    sc.course_code,
    sc.course_title,
    sc.complaint_type,
    sc.description,
    sc.status,
    sc.created_at,
    sc.updated_at,
    s.first_name,
    s.middle_name,
    s.last_name,
    s.registration_number,
    s.email,
    s.year_of_entry,
    sd.department_name,
    sd.department_code,
    f.faculty_name,
    f.faculty_code,
    p.programme_name,
    CONCAT(s.first_name, ' ', IFNULL(CONCAT(s.middle_name, ' '), ''), s.last_name) as full_name
FROM student_complaints sc
JOIN students s ON sc.student_id = s.student_id
JOIN student_departments sd ON s.department_id = sd.department_id
JOIN faculties f ON sd.faculty_id = f.faculty_id
JOIN programmes p ON s.programme_id = p.programme_id
WHERE $where_clause
ORDER BY f.faculty_name, sd.department_name, sc.created_at DESC";

$complaints = [];
if ($stmt = mysqli_prepare($conn, $complaints_sql)) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    }
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $complaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Group complaints by department
$complaints_by_dept = [];
$summary_stats = [];

foreach ($complaints as $complaint) {
    $dept_key = $complaint['faculty_code'] . '_' . $complaint['department_code'];
    $dept_name = $complaint['faculty_name'] . ' - ' . $complaint['department_name'];
    
    if (!isset($complaints_by_dept[$dept_key])) {
        $complaints_by_dept[$dept_key] = [
            'name' => $dept_name,
            'faculty_name' => $complaint['faculty_name'],
            'department_name' => $complaint['department_name'],
            'complaints' => []
        ];
        $summary_stats[$dept_key] = [
            'name' => $dept_name,
            'total' => 0,
            'pending' => 0,
            'under_review' => 0,
            'resolved' => 0,
            'rejected' => 0,
            'fa' => 0,
            'f' => 0,
            'incorrect_grade' => 0
        ];
    }
    
    $complaints_by_dept[$dept_key]['complaints'][] = $complaint;
    
    // Update statistics
    $summary_stats[$dept_key]['total']++;
    $summary_stats[$dept_key][strtolower(str_replace(' ', '_', $complaint['status']))]++;
    
    switch ($complaint['complaint_type']) {
        case 'FA':
            $summary_stats[$dept_key]['fa']++;
            break;
        case 'F':
            $summary_stats[$dept_key]['f']++;
            break;
        case 'Incorrect Grade':
            $summary_stats[$dept_key]['incorrect_grade']++;
            break;
    }
}

// Generate filename
$filename = 'TSU_Student_Complaints_Report_' . date('Y-m-d_H-i-s') . '.xls';

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Start Excel output
echo '<?xml version="1.0"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">';

// Styles
echo '<Styles>
 <Style ss:ID="HeaderStyle">
  <Font ss:Bold="1" ss:Color="#FFFFFF"/>
  <Interior ss:Color="#1e3c72" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Borders>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
  </Borders>
 </Style>
 <Style ss:ID="DataStyle">
  <Alignment ss:Vertical="Top" ss:WrapText="1"/>
  <Borders>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CCCCCC"/>
  </Borders>
 </Style>
 <Style ss:ID="SummaryHeaderStyle">
  <Font ss:Bold="1" ss:Color="#FFFFFF"/>
  <Interior ss:Color="#4a90e2" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
 </Style>
 <Style ss:ID="NumberStyle">
  <Alignment ss:Horizontal="Center"/>
 </Style>
</Styles>';

// Summary Sheet
echo '<Worksheet ss:Name="Summary Report">
<Table>';

// Summary header
echo '<Row>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">Department</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">Total Complaints</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">Pending</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">Under Review</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">Resolved</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">Rejected</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">FA</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">F</Data></Cell>
 <Cell ss:StyleID="SummaryHeaderStyle"><Data ss:Type="String">Incorrect Grade</Data></Cell>
</Row>';

// Summary data
foreach ($summary_stats as $dept_key => $stats) {
    echo '<Row>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($stats['name']) . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['total'] . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['pending'] . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['under_review'] . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['resolved'] . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['rejected'] . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['fa'] . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['f'] . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $stats['incorrect_grade'] . '</Data></Cell>
    </Row>';
}

echo '</Table></Worksheet>';

// Individual department sheets
foreach ($complaints_by_dept as $dept_key => $dept_data) {
    // Clean sheet name (Excel has restrictions)
    $sheet_name = substr(preg_replace('/[^\w\s-]/', '', $dept_data['name']), 0, 31);
    
    echo '<Worksheet ss:Name="' . htmlspecialchars($sheet_name) . '">
    <Table>';
    
    // Department header
    echo '<Row>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Student Name</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Registration Number</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Email</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Programme</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Year of Entry</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Course Code</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Course Title</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Complaint Type</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Status</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Description</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Date Submitted</Data></Cell>
     <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Last Updated</Data></Cell>
    </Row>';
    
    // Department data
    foreach ($dept_data['complaints'] as $complaint) {
        echo '<Row>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['full_name']) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['registration_number']) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['email']) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['programme_name']) . '</Data></Cell>
         <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $complaint['year_of_entry'] . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['course_code']) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['course_title']) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['complaint_type']) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['status']) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['description'] ?? '') . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . date('Y-m-d H:i:s', strtotime($complaint['created_at'])) . '</Data></Cell>
         <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . date('Y-m-d H:i:s', strtotime($complaint['updated_at'])) . '</Data></Cell>
        </Row>';
    }
    
    echo '</Table></Worksheet>';
}

// All Complaints Sheet (Combined)
echo '<Worksheet ss:Name="All Complaints">
<Table>';

echo '<Row>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Faculty</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Department</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Student Name</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Registration Number</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Email</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Programme</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Year of Entry</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Course Code</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Course Title</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Complaint Type</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Status</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Description</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Date Submitted</Data></Cell>
 <Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">Last Updated</Data></Cell>
</Row>';

foreach ($complaints as $complaint) {
    echo '<Row>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['faculty_name']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['department_name']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['full_name']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['registration_number']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['email']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['programme_name']) . '</Data></Cell>
     <Cell ss:StyleID="NumberStyle"><Data ss:Type="Number">' . $complaint['year_of_entry'] . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['course_code']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['course_title']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['complaint_type']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['status']) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . htmlspecialchars($complaint['description'] ?? '') . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . date('Y-m-d H:i:s', strtotime($complaint['created_at'])) . '</Data></Cell>
     <Cell ss:StyleID="DataStyle"><Data ss:Type="String">' . date('Y-m-d H:i:s', strtotime($complaint['updated_at'])) . '</Data></Cell>
    </Row>';
}

echo '</Table></Worksheet>';

echo '</Workbook>';

mysqli_close($conn);
?>