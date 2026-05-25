<?php
// Test database connection
$db_host = 'localhost';
$db_username = 'root';
$db_password = '';
$db_name = 'iplineage_system';

echo "Testing database connection...\n";
echo "Host: $db_host\n";
echo "Username: $db_username\n";
echo "Database: $db_name\n\n";

// Try to create connection
$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

// Check connection
if ($conn->connect_error) {
    echo "❌ Connection FAILED\n";
    echo "Error Code: " . $conn->connect_errno . "\n";
    echo "Error Message: " . $conn->connect_error . "\n\n";
    
    // Try without database name to test if MySQL is running
    echo "\nTrying to connect without database name...\n";
    $conn2 = new mysqli($db_host, $db_username, $db_password);
    if ($conn2->connect_error) {
        echo "❌ MySQL server is not responding\n";
        echo "Error: " . $conn2->connect_error . "\n";
        echo "\nPossible causes:\n";
        echo "1. MySQL/MariaDB service is not running\n";
        echo "2. Database credentials are incorrect\n";
        echo "3. MySQL port is not accessible\n";
    } else {
        echo "✓ MySQL is running, but database or credentials might be wrong\n";
        echo "Trying to select database '$db_name'...\n";
        if ($conn2->select_db($db_name)) {
            echo "✓ Database '$db_name' exists\n";
        } else {
            echo "❌ Database '$db_name' does not exist or is not accessible\n";
            echo "Available databases:\n";
            $result = $conn2->query("SHOW DATABASES");
            if ($result) {
                while ($row = $result->fetch_array()) {
                    echo "  - " . $row[0] . "\n";
                }
            }
        }
        $conn2->close();
    }
} else {
    echo "✓ Connection SUCCESS\n";
    echo "Connected to: $db_host / $db_name\n";
    $conn->close();
}
?>
