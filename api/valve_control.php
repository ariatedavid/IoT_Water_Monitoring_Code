<?php

include "../auth.php";
include "../db.php";
include "../includes/flow_helpers.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'open':
            setValveStatus($conn, 'open');
            logValveEvent($conn, 'manual_open', 'Valve opened manually from web app');
            break;

        case 'close':
            setValveStatus($conn, 'closed');
            logValveEvent($conn, 'manual_close', 'Valve closed manually from web app');
            break;

        case 'set_automatic_shutoff':
            $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($enabled) {
                setLeakDetectionEnabled($conn, true);
            }
            setAutomaticShutoff($conn, $enabled);
            logValveEvent(
                $conn,
                $enabled ? 'auto_shutoff_on' : 'auto_shutoff_off',
                $enabled
                    ? 'Automatic shut off enabled and leak detection turned on'
                    : 'Automatic shut off disabled — alerts only on leak'
            );
            break;

        case 'set_leak_detection':
            $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            setLeakDetectionEnabled($conn, $enabled);
            if (!$enabled) {
                setAutomaticShutoff($conn, false);
                setSetting($conn, 'leak_alert_active', '0');
                setSetting($conn, 'last_leak_alert_error', '');
            }
            logValveEvent(
                $conn,
                $enabled ? 'leak_detection_on' : 'leak_detection_off',
                $enabled
                    ? 'Leak detection enabled'
                    : 'Leak detection disabled — auto shutoff turned off'
            );
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            $conn->close();
            exit;
    }

    echo json_encode(array_merge(['success' => true], buildValveData($conn)));
    $conn->close();
    exit;
}

echo json_encode(buildValveData($conn));
$conn->close();
