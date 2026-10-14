<?php

include "db.php";

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
    $exists = $check->get_result()->num_rows > 0;

    if (!$exists) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
    }
}

echo "System settings table ready.";

$conn->close();
