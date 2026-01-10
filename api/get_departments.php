<?php
header('Content-Type: application/json');
require_once "../config.php";

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['faculty_id'])){
    $faculty_id = intval($_POST['faculty_id']);
    
    $sql = "SELECT department_id, department_name FROM student_departments WHERE faculty_id = ? ORDER BY department_name";
    
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $faculty_id);
        
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            $departments = [];
            
            while($row = mysqli_fetch_assoc($result)){
                $departments[] = $row;
            }
            
            echo json_encode($departments);
        } else {
            echo json_encode(['error' => 'Query execution failed: ' . mysqli_error($conn)]);
        }
        
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode(['error' => 'Query preparation failed: ' . mysqli_error($conn)]);
    }
} else {
    echo json_encode(['error' => 'Invalid request method or missing faculty_id']);
}

mysqli_close($conn);
?>