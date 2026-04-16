<?php
require 'config.php';
$res = mysqli_query($conn, 'SHOW COLUMNS FROM departments');
while($row = mysqli_fetch_assoc($res)) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
echo "\nDATA:\n";
$res = mysqli_query($conn, 'SELECT * FROM departments LIMIT 100');
while($row = mysqli_fetch_assoc($res)) {
    echo print_r($row, true);
}
