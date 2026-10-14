CREATE DATABASE IF NOT EXISTS water_monitor;
DROP DATABASE water_monitor;
USE water_monitor;

CREATE DATABASE IF NOT EXISTS water_monitor;

USE water_monitor;

CREATE TABLE flow_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flow_rate FLOAT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
);

INSERT INTO system_settings (setting_key, setting_value) VALUES
('leak_threshold', '5'),
('water_cost_per_liter', '0.05'),
('last_sensor_ping', ''),
('valve_status', 'closed'),
('valve_reported_status', 'closed'),
('automatic_shutoff', '1'),
('last_valve_event', ''),
('last_valve_event_reason', ''),
('last_valve_event_time', ''),
('leak_alert_active', '0'),
('last_leak_alert_error', '');

-- Default login: admin / admin123
INSERT INTO users (username, password) VALUES
('admin', '$2y$10$I/3wGUyGgbeoNtTbtwjF3e5PAMze9c6kar4DQGeiTu8xHq3JnDVHS');

SELECT * FROM flow_logs;