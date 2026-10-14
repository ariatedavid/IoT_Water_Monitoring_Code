<?php

date_default_timezone_set('Asia/Manila');

$servername = "mysql.hostinger.com";
$username   = "u288839337_water_user";
$password   = "DarylDaveMathew123";
$database   = "u288839337_water_monitor";


$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die(
        "Database connection failed. Check XAMPP MySQL credentials and update DB_USERNAME / DB_PASSWORD in your environment or in db.php. " .
        "Original error: " . $conn->connect_error
    );
}

try {
    $conn->select_db($database);
} catch (mysqli_sql_exception $e) {
    $createDb = $conn->query("CREATE DATABASE IF NOT EXISTS $database");
    if (!$createDb) {
        die("Unable to create database '$database'. Please check MySQL permissions. " . $conn->error);
    }

    try {
        $conn->select_db($database);
    } catch (mysqli_sql_exception $inner) {
        die("Unable to use database '$database'. Please check MySQL permissions. " . $conn->error);
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS flow_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flow_rate FLOAT NOT NULL,
    flow_status VARCHAR(32) NOT NULL DEFAULT 'NORMAL',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$flowColCheck = $conn->query("SHOW COLUMNS FROM flow_logs LIKE 'flow_status'");
if ($flowColCheck && $flowColCheck->num_rows === 0) {
    $conn->query("ALTER TABLE flow_logs ADD COLUMN flow_status VARCHAR(32) NOT NULL DEFAULT 'NORMAL' AFTER flow_rate");
}

$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
)");

$defaults = [
    'leak_threshold' => '5',
    'water_cost_per_liter' => '0.05',
    'flow_rate_calibration' => '1.0',
    'max_flow_rate' => '20',
    'min_valid_flow_rate' => '0',
    'zero_flow_threshold' => '0.02',
    'max_rate_of_change' => '10',
    'max_valid_sampling_gap' => '7200',
    'max_integration_interval' => '7200',
    'sensor_timeout' => '300',
    'smoothing_window' => '3',
    'last_sensor_ping' => '',
    'valve_status' => 'closed',
    'valve_reported_status' => 'closed',
    'automatic_shutoff' => '1',
    'leak_detection_enabled' => '1',
    'last_valve_event' => '',
    'last_valve_event_reason' => '',
    'last_valve_event_time' => '',
    'leak_alert_active' => '0',
    'last_leak_alert_error' => '',
    'usage_reset_at' => '',
    'usage_reset_id' => '0',
];

foreach ($defaults as $key => $value) {
    $check = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key = ?");
    if ($check) {
        $check->bind_param('s', $key);
        $check->execute();
        $exists = $check->get_result();

        if ($exists->num_rows === 0) {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('ss', $key, $value);
                $stmt->execute();
            }
        } else {
            $row = $exists->fetch_assoc();
            $currentValue = isset($row['setting_value']) ? $row['setting_value'] : '';

            if ($key === 'flow_rate_calibration' && floatval($currentValue) <= 1.0 && floatval($currentValue) > 0) {
                $updateStmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param('ss', $value, $key);
                    $updateStmt->execute();
                }
            }
        }
    }
}

$checkAdmin = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if ($checkAdmin && $checkAdmin->num_rows === 0) {
    $hash = '$2y$10$I/3wGUyGgbeoNtTbtwjF3e5PAMze9c6kar4DQGeiTu8xHq3JnDVHS';
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES ('admin', ?)");
    if ($stmt) {
        $stmt->bind_param('s', $hash);
        $stmt->execute();
    }
}

$conn->query("SET time_zone = '+08:00'");

?>