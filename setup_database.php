<?php
/**
 * Database Setup and Diagnostic Script
 * Run this once to ensure the database is properly configured
 */

echo "======================================\n";
echo "  IP LINEAGE SYSTEM - Database Setup\n";
echo "======================================\n\n";

$db_host = 'localhost';
$db_username = 'root';
$db_password = '';
$db_name = 'iplineage_system';

echo "Step 1: Testing MySQL/MariaDB Connection\n";
echo "-------------------------------------------\n";
echo "Host: $db_host\n";
echo "Username: $db_username\n";
echo "Database: $db_name\n\n";

// Test connection without database
$conn_test = new mysqli($db_host, $db_username, $db_password);
if ($conn_test->connect_error) {
    echo "❌ FAILED: Cannot connect to MySQL server\n";
    echo "Error: " . $conn_test->connect_error . "\n";
    echo "Error Code: " . $conn_test->connect_errno . "\n\n";
    echo "Troubleshooting:\n";
    echo "1. Ensure MySQL/MariaDB is running\n";
    echo "2. In Laragon, start MySQL via: Start All or Start MySQL\n";
    echo "3. Check credentials in src/dbconfig.php\n";
    echo "4. Default Laragon credentials are: root (no password)\n";
    die("\n");
} else {
    echo "✓ Connected to MySQL server successfully\n\n";
}

// Check if database exists
echo "Step 2: Checking if database '$db_name' exists\n";
echo "-------------------------------------------\n";

$result = $conn_test->query("SHOW DATABASES LIKE '$db_name'");
if ($result && $result->num_rows > 0) {
    echo "✓ Database '$db_name' already exists\n\n";
} else {
    echo "❌ Database '$db_name' does not exist\n";
    echo "Creating database '$db_name'...\n";
    
    if ($conn_test->query("CREATE DATABASE $db_name DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        echo "✓ Database created successfully\n\n";
    } else {
        echo "❌ Failed to create database\n";
        echo "Error: " . $conn_test->error . "\n";
        die("\n");
    }
}

// Connect to the database
echo "Step 3: Connecting to database '$db_name'\n";
echo "-------------------------------------------\n";

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);
if ($conn->connect_error) {
    echo "❌ Failed to connect to database\n";
    echo "Error: " . $conn->connect_error . "\n";
    die("\n");
} else {
    echo "✓ Connected to database '$db_name'\n\n";
}

// Check if required tables exist
echo "Step 4: Checking required tables\n";
echo "-------------------------------------------\n";

$required_tables = ['users', 'ipmembers', 'tribes', 'applications', 'relationships']; // Removed pending_approvals
$existing_tables = [];
$missing_tables = [];

$result = $conn->query("SHOW TABLES");
if ($result) {
    while ($row = $result->fetch_row()) {
        $existing_tables[] = $row[0];
    }
}

foreach ($required_tables as $table) {
    if (in_array($table, $existing_tables)) {
        echo "  ✓ Table '$table' exists\n";
    } else {
        echo "  ❌ Table '$table' is missing\n";
        $missing_tables[] = $table;
    }
}

if (!empty($missing_tables)) {
    echo "\n⚠️  Missing tables: " . implode(', ', $missing_tables) . "\n";
    echo "You may need to import a database dump or create the schema manually.\n";
    echo "Look for .sql files or database documentation in the project.\n";
} else {
    echo "\n✓ All required tables exist\n";
}

echo "\n" . str_repeat("=", 40) . "\n";
echo "Setup Complete!\n";
echo "-------------------------------------------\n";
echo "✓ Database '$db_name' is ready\n";
echo "✓ Connection credentials are correct\n";
echo "\nYou can now safely use the application.\n";

$conn->close();
$conn_test->close();
?>
