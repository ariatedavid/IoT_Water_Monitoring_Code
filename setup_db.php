<?php

$servername = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USERNAME') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'water_monitor';

$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die(
        "Database setup failed. Check XAMPP MySQL credentials and update DB_USERNAME / DB_PASSWORD in your environment or in setup_db.php. " .
        "Original error: " . $conn->connect_error
    );
}

$conn->query("CREATE DATABASE IF NOT EXISTS $database");
$conn->select_db($database);

$conn->query("CREATE TABLE IF NOT EXISTS flow_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flow_rate FLOAT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

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
    $check = $conn->prepare("SELECT setting_key FROM system_settings WHERE setting_key = ?");
    $check->bind_param("s", $key);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
    }
}

$checkAdmin = $conn->query("SELECT id FROM users WHERE username = 'admin'");
if ($checkAdmin->num_rows === 0) {
    $hash = '$2y$10$I/3wGUyGgbeoNtTbtwjF3e5PAMze9c6kar4DQGeiTu8xHq3JnDVHS';
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES ('admin', ?)");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    echo "Created default user: admin / admin123<br>";
}

echo "Database setup complete. All tables are ready.";

$conn->close();
