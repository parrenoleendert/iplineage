<?php
// Database Configuration
$db_host = 'localhost';
$db_username = 'root';
$db_password = '';
$db_name = 'iplineage_system';

// Create connection with error suppression removed for debugging
$conn = @new mysqli($db_host, $db_username, $db_password, $db_name);

// Check connection
if ($conn->connect_error) {
    // Build comprehensive error message
    $error_msg = "Database Connection Error\n";
    $error_msg .= "Host: $db_host\n";
    $error_msg .= "Database: $db_name\n";
    $error_msg .= "Error Code: " . $conn->connect_errno . "\n";
    $error_msg .= "Error: " . $conn->connect_error . "\n\n";
    
    // Provide helpful suggestions based on error code
    if ($conn->connect_errno == 2002 || $conn->connect_errno == 1045) {
        $error_msg .= "Possible causes:\n";
        $error_msg .= "1. MySQL/MariaDB service is not running\n";
        $error_msg .= "2. Incorrect database credentials\n";
        $error_msg .= "3. MySQL is listening on a different port\n";
    }
    
    die($error_msg);
}


$conn->set_charset("utf8");

$GLOBALS['conn'] = $conn;
?>