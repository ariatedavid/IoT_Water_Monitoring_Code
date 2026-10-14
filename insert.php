<?php

include "db.php";
include "includes/flow_helpers.php";
include "includes/email_helpers.php";

updateSensorPing($conn);

$flow_rate = isset($_POST['flow_rate']) ? floatval($_POST['flow_rate']) : null;
$reported_valve = isset($_POST['valve_status']) ? strtolower(trim($_POST['valve_status'])) : null;

if ($reported_valve === 'open' || $reported_valve === 'closed') {
    setValveReportedStatus($conn, $reported_valve);
}

if ($flow_rate !== null) {
    if (flowDetected($flow_rate)) {
        $flowStatus = getFlowStatus($flow_rate, getLeakThreshold($conn), getLeakDetectionEnabled($conn));
        $statusLabel = $flowStatus['status'];

        $stmt = $conn->prepare("INSERT INTO flow_logs (flow_rate, flow_status) VALUES (?, ?)");
        $stmt->bind_param("ds", $flow_rate, $statusLabel);

        if ($stmt->execute()) {
            processLeakValveControl($conn, $flow_rate);
        }
    }

    processAutomaticLeakAlert($conn, $flow_rate);
}

header('Content-Type: text/plain');
echo 'valve_command=' . getValveStatus($conn);

$conn->close();