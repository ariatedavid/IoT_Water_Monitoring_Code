<?php
include "db.php";

$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$check = $conn->query("SELECT id FROM users WHERE username = 'admin'");

if ($check->num_rows === 0) {
    $hash = '$2y$10$I/3wGUyGgbeoNtTbtwjF3e5PAMze9c6kar4DQGeiTu8xHq3JnDVHS';
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES ('admin', ?)");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    echo "Default user created: admin / admin123";
} else {
    echo "Users table ready. Default admin already exists.";
}

$conn->close();
