<?php
header('Content-Type: application/json');
require_once "../config.php";

if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['department_id'])){
    $department_id = intval($_POST['department_id']);
    
    $sql = "SELECT programme_id, programme_name, reg_number_format FROM programmes WHERE department_id = ? ORDER BY programme_name";
    
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $department_id);
        
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            $programmes = [];
            
            while($row = mysqli_fetch_assoc($result)){
                $programmes[] = $row;
            }
            
            echo json_encode($programmes);
        } else {
            echo json_encode([]);
        }
        
        mysqli_stmt_close($stmt);
    } else {
        echo json_encode([]);
    }
} else {
    echo json_encode([]);
}

mysqli_close($conn);
?>