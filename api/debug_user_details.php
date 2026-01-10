<?php
// Debug version - shows all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

echo "<h2>User Details API Debug</h2>";

echo "<h3>1. Session Check</h3>";
echo "<pre>";
echo "Session ID: " . session_id() . "\n";
echo "Logged in: " . (isset($_SESSION["loggedin"]) ? ($_SESSION["loggedin"] ? 'Yes' : 'No') : 'Not set') . "\n";
echo "Role ID: " . (isset($_SESSION["role_id"]) ? $_SESSION["role_id"] : 'Not set') . "\n";
echo "User ID: " . (isset($_SESSION["user_id"]) ? $_SESSION["user_id"] : 'Not set') . "\n";
echo "</pre>";

echo "<h3>2. Parameter Check</h3>";
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
echo "<p>Requested User ID: " . $user_id . "</p>";

echo "<h3>3. Database Connection</h3>";
try {
    require_once "../config.php";
    if (isset($conn) && $conn) {
        echo "<p style='color: green;'>✓ Database connection successful</p>";
    } else {
        echo "<p style='color: red;'>✗ Database connection failed</p>";
        echo "<p>Error: " . mysqli_connect_error() . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Config file error: " . $e->getMessage() . "</p>";
}

if (isset($conn) && $conn && $user_id > 0) {
    echo "<h3>4. Database Query Test</h3>";
    
    $sql = "SELECT u.user_id, u.username, u.full_name, u.email, u.created_at, 
                   r.role_name, r.role_id,
                   (SELECT COUNT(*) FROM complaints WHERE lodged_by = u.user_id) as complaint_count,
                   (SELECT COUNT(*) FROM complaints WHERE handled_by = u.user_id) as handled_count
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id 
            WHERE u.user_id = ?";
    
    echo "<p>SQL Query: " . htmlspecialchars($sql) . "</p>";
    
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        echo "<p style='color: green;'>✓ Query prepared successfully</p>";
        
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo "<p style='color: green;'>✓ Query executed successfully</p>";
            
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
            
            if ($user) {
                echo "<p style='color: green;'>✓ User found</p>";
                echo "<pre>" . print_r($user, true) . "</pre>";
            } else {
                echo "<p style='color: red;'>✗ User not found</p>";
            }
            
        } else {
            echo "<p style='color: red;'>✗ Query execution failed: " . mysqli_error($conn) . "</p>";
        }
        
        mysqli_stmt_close($stmt);
    } else {
        echo "<p style='color: red;'>✗ Query preparation failed: " . mysqli_error($conn) . "</p>";
    }
    
    echo "<h3>5. Table Structure Check</h3>";
    
    // Check if tables exist
    $tables = ['users', 'roles', 'complaints'];
    foreach ($tables as $table) {
        $check_sql = "SHOW TABLES LIKE '$table'";
        $result = mysqli_query($conn, $check_sql);
        if (mysqli_num_rows($result) > 0) {
            echo "<p style='color: green;'>✓ Table '$table' exists</p>";
        } else {
            echo "<p style='color: red;'>✗ Table '$table' missing</p>";
        }
    }
}

echo "<h3>6. Test JSON Output</h3>";
echo "<p>Testing JSON encoding...</p>";

$test_data = [
    'success' => true,
    'message' => 'Test successful',
    'timestamp' => date('Y-m-d H:i:s')
];

$json = json_encode($test_data);
if ($json === false) {
    echo "<p style='color: red;'>✗ JSON encoding failed: " . json_last_error_msg() . "</p>";
} else {
    echo "<p style='color: green;'>✓ JSON encoding successful</p>";
    echo "<pre>" . $json . "</pre>";
}

echo "<h3>7. PHP Version and Extensions</h3>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>MySQLi Extension: " . (extension_loaded('mysqli') ? 'Loaded' : 'Not loaded') . "</p>";
echo "<p>JSON Extension: " . (extension_loaded('json') ? 'Loaded' : 'Not loaded') . "</p>";

if (isset($_GET['id'])) {
    echo "<h3>8. Try Simple JSON Response</h3>";
    echo "<p>Attempting to output JSON for user ID " . $user_id . "...</p>";
    
    // Try to output JSON
    if (isset($conn) && $conn && isset($user) && $user) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'user' => $user,
            'debug' => 'JSON output successful'
        ]);
    }
}
?>