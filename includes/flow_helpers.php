<?php

define('FLOW_DETECT_THRESHOLD', 0.01);
define('SENSOR_ONLINE_TIMEOUT', 120);

function flowDetected($rate) {
    return floatval($rate) > FLOW_DETECT_THRESHOLD;
}

function getSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row ? $row['setting_value'] : $default;
}

function setSetting($conn, $key, $value) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL
        )"
    );
    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("sss", $key, $value, $value);
    return $stmt->execute();
}

function getLeakThreshold($conn) {
    return floatval(getSetting($conn, 'leak_threshold', '5'));
}

function setLeakThreshold($conn, $value) {
    return setSetting($conn, 'leak_threshold', strval($value));
}

function getWaterCostPerLiter($conn) {
    return max(0, floatval(getSetting($conn, 'water_cost_per_liter', '0.05')));
}

function setWaterCostPerLiter($conn, $value) {
    return setSetting($conn, 'water_cost_per_liter', strval(max(0, floatval($value))));
}

function getFlowRateCalibrationMultiplier($conn) {
    $value = floatval(getSetting($conn, 'flow_rate_calibration', '1.0'));
    return $value > 0 ? $value : 1.0;
}

function setFlowRateCalibrationMultiplier($conn, $value) {
    $value = max(0.1, floatval($value));
    return setSetting($conn, 'flow_rate_calibration', strval($value));
}

function getFlowCalculationSettings($conn) {
    return [
        'leak_threshold' => getLeakThreshold($conn),
        'flow_rate_calibration' => getFlowRateCalibrationMultiplier($conn),
        'max_flow_rate' => max(0.0, floatval(getSetting($conn, 'max_flow_rate', '20'))),
        'min_valid_flow_rate' => max(0.0, floatval(getSetting($conn, 'min_valid_flow_rate', '0'))),
        'zero_flow_threshold' => max(0.0, floatval(getSetting($conn, 'zero_flow_threshold', '0.02'))),
        'max_rate_of_change' => max(0.0, floatval(getSetting($conn, 'max_rate_of_change', '10'))),
        'max_valid_sampling_gap' => max(1.0, floatval(getSetting($conn, 'max_valid_sampling_gap', '7200'))),
        'max_integration_interval' => max(1.0, floatval(getSetting($conn, 'max_integration_interval', '7200'))),
        'sensor_timeout' => max(1.0, floatval(getSetting($conn, 'sensor_timeout', '300'))),
        'smoothing_window' => max(1, intval(getSetting($conn, 'smoothing_window', '3'))),
    ];
}

function normalizeFlowRate($rate, $settings = []) {
    $rate = floatval($rate);
    if (!is_finite($rate) || is_nan($rate)) {
        return null;
    }

    if ($rate < 0) {
        return null;
    }

    $maxFlowRate = isset($settings['max_flow_rate']) ? floatval($settings['max_flow_rate']) : 20.0;
    $minFlowRate = isset($settings['min_valid_flow_rate']) ? floatval($settings['min_valid_flow_rate']) : 0.0;
    $zeroFlowThreshold = isset($settings['zero_flow_threshold']) ? floatval($settings['zero_flow_threshold']) : 0.02;

    if ($rate > $maxFlowRate) {
        return null;
    }

    if ($rate < $minFlowRate) {
        return 0.0;
    }

    if ($rate < $zeroFlowThreshold) {
        return 0.0;
    }

    return $rate;
}

function calculateConsumptionFromSamples($samples, $settings = []) {
    $settings = array_merge([
        'flow_rate_calibration' => 1.0,
        'zero_flow_threshold' => 0.02,
        'max_valid_sampling_gap' => 7200,
        'max_integration_interval' => 7200,
        'max_rate_of_change' => 10.0,
        'min_valid_flow_rate' => 0.0,
        'max_flow_rate' => 20.0,
    ], $settings);

    if (!is_array($samples) || count($samples) === 0) {
        return 0.0;
    }

    $normalizedRows = [];
    $previousTimestamp = null;
    foreach ($samples as $sample) {
        if (!is_array($sample)) {
            continue;
        }
        $rawRate = $sample['flow_rate'] ?? null;
        $timestamp = $sample['created_at'] ?? ($sample['timestamp'] ?? null);
        $normalizedRate = normalizeFlowRate($rawRate, $settings);
        if ($normalizedRate === null) {
            continue;
        }

        $parsedTime = strtotime((string) $timestamp);
        if ($parsedTime === false) {
            continue;
        }

        if ($previousTimestamp !== null && $parsedTime <= $previousTimestamp) {
            continue;
        }

        $normalizedRows[] = ['flow_rate' => $normalizedRate, 'created_at' => $parsedTime];
        $previousTimestamp = $parsedTime;
    }

    if (count($normalizedRows) < 2) {
        return 0.0;
    }

    $total = 0.0;
    for ($i = 1; $i < count($normalizedRows); $i++) {
        $previousRow = $normalizedRows[$i - 1];
        $currentRow = $normalizedRows[$i];
        $previousRate = floatval($previousRow['flow_rate']);
        $currentRate = floatval($currentRow['flow_rate']);
        $elapsedSeconds = $currentRow['created_at'] - $previousRow['created_at'];

        if ($elapsedSeconds <= 0) {
            continue;
        }

        if ($elapsedSeconds > floatval($settings['max_valid_sampling_gap'])) {
            continue;
        }

        if (($currentRate > 0 && $previousRate > 0) && abs($currentRate - $previousRate) > floatval($settings['max_rate_of_change'])) {
            continue;
        }

        if ($currentRate < $previousRate && ($previousRate - $currentRate) > floatval($settings['max_rate_of_change'])) {
            continue;
        }

        $total += calculateFlowVolumeFromInterval(
            $previousRate,
            $currentRate,
            date('Y-m-d H:i:s', $previousRow['created_at']),
            date('Y-m-d H:i:s', $currentRow['created_at']),
            floatval($settings['flow_rate_calibration']),
            $settings
        );
    }

    return round($total, 2);
}

function getValveStatus($conn) {
    $status = strtolower(getSetting($conn, 'valve_status', 'closed'));
    return ($status === 'open') ? 'open' : 'closed';
}

function setValveStatus($conn, $status) {
    $status = strtolower($status) === 'open' ? 'open' : 'closed';
    return setSetting($conn, 'valve_status', $status);
}

function getValveReportedStatus($conn) {
    $status = strtolower(getSetting($conn, 'valve_reported_status', ''));
    if ($status === 'open' || $status === 'closed') {
        return $status;
    }
    return getValveStatus($conn);
}

function setValveReportedStatus($conn, $status) {
    $status = strtolower($status) === 'open' ? 'open' : 'closed';
    return setSetting($conn, 'valve_reported_status', $status);
}

function getAutomaticShutoff($conn) {
    return getSetting($conn, 'automatic_shutoff', '1') === '1';
}

function setAutomaticShutoff($conn, $enabled) {
    return setSetting($conn, 'automatic_shutoff', $enabled ? '1' : '0');
}

function getLeakDetectionEnabled($conn) {
    return getSetting($conn, 'leak_detection_enabled', '1') === '1';
}

function setLeakDetectionEnabled($conn, $enabled) {
    return setSetting($conn, 'leak_detection_enabled', $enabled ? '1' : '0');
}

function logValveEvent($conn, $action, $reason) {
    setSetting($conn, 'last_valve_event', $action);
    setSetting($conn, 'last_valve_event_reason', $reason);
    setSetting($conn, 'last_valve_event_time', date('Y-m-d H:i:s'));
}

function getLastValveEvent($conn) {
    return [
        'action' => getSetting($conn, 'last_valve_event', ''),
        'reason' => getSetting($conn, 'last_valve_event_reason', ''),
        'time' => getSetting($conn, 'last_valve_event_time', ''),
    ];
}

function processLeakValveControl($conn, $flowRate) {
    if (!getLeakDetectionEnabled($conn)) {
        return false;
    }

    $threshold = getLeakThreshold($conn);
    $status = getFlowStatus($flowRate, $threshold, true);

    if ($status['status'] !== 'LEAK DETECTED') {
        return false;
    }

    if (!getAutomaticShutoff($conn)) {
        return false;
    }

    if (getValveStatus($conn) === 'closed') {
        return false;
    }

    setValveStatus($conn, 'closed');
    logValveEvent($conn, 'auto_close', 'Leak detected — valve automatically closed');
    return true;
}

function getValveDisplayInfo($conn) {
    $commanded = getValveStatus($conn);
    $reported = getValveReportedStatus($conn);
    $isOpen = ($reported === 'open');

    return [
        'status' => $isOpen ? 'Open' : 'Closed',
        'status_key' => $reported,
        'commanded_status' => $commanded,
        'commanded_label' => ucfirst($commanded),
        'status_class' => $isOpen ? 'success' : 'secondary',
        'status_badge_class' => $isOpen ? 'bg-success' : 'bg-secondary',
        'automatic_shutoff' => getAutomaticShutoff($conn),
        'leak_detection_enabled' => getLeakDetectionEnabled($conn),
        'last_event' => getLastValveEvent($conn),
    ];
}

function buildValveData($conn) {
    $info = getValveDisplayInfo($conn);
    return [
        'valve_status' => $info['status'],
        'valve_status_key' => $info['status_key'],
        'valve_command' => $info['commanded_status'],
        'valve_status_class' => $info['status_class'],
        'valve_badge_class' => $info['status_badge_class'],
        'automatic_shutoff' => $info['automatic_shutoff'],
        'automatic_shutoff_label' => $info['automatic_shutoff'] ? 'Enabled' : 'Disabled',
        'leak_detection_enabled' => $info['leak_detection_enabled'],
        'leak_detection_label' => $info['leak_detection_enabled'] ? 'Enabled' : 'Disabled',
        'last_valve_event' => $info['last_event'],
    ];
}

function updateSensorPing($conn) {
    return setSetting($conn, 'last_sensor_ping', date('Y-m-d H:i:s'));
}

function getLastSensorPing($conn) {
    return getSetting($conn, 'last_sensor_ping', '');
}

function isSensorOnline($conn) {
    $ping = getLastSensorPing($conn);
    if ($ping === '') {
        return false;
    }
    return (time() - strtotime($ping)) < SENSOR_ONLINE_TIMEOUT;
}

function getLatestFlowReading($conn) {
    $threshold = FLOW_DETECT_THRESHOLD;
    $result = $conn->query(
        "SELECT * FROM flow_logs WHERE flow_rate > $threshold ORDER BY id DESC LIMIT 1"
    );
    return $result ? $result->fetch_assoc() : null;
}

function getFlowLogs($conn, $limit = 10) {
    $limit = intval($limit);
    return $conn->query(
        "SELECT * FROM flow_logs WHERE flow_rate > " . floatval(FLOW_DETECT_THRESHOLD) . " ORDER BY id DESC LIMIT $limit"
    );
}

function getFlowStatus($flowRate, $threshold, $detectionEnabled = true, $storedStatus = null) {
    $status = null;
    if ($storedStatus !== null) {
        $statusValue = strtoupper(trim((string) $storedStatus));
        if ($statusValue === 'LEAK DETECTED' || $statusValue === 'NORMAL') {
            $status = $statusValue;
        }
    }

    if ($status === null && !$detectionEnabled) {
        $status = 'NORMAL';
    }

    if ($status === null) {
        $status = (floatval($flowRate) > floatval($threshold)) ? 'LEAK DETECTED' : 'NORMAL';
    }

    return [
        'status' => $status,
        'color' => ($status === 'LEAK DETECTED') ? 'danger' : 'success',
        'badge' => ($status === 'LEAK DETECTED') ? 'LEAK' : 'NORMAL',
        'badge_class' => ($status === 'LEAK DETECTED') ? 'bg-danger' : 'bg-success',
    ];
}

function calculateFlowVolumeFromInterval($previousFlowRate, $currentFlowRate, $previousTimestamp, $currentTimestamp, $calibrationMultiplier = 1.0, $settings = []) {
    $settings = is_array($settings) ? $settings : [];

    $previousTime = strtotime((string) $previousTimestamp);
    $currentTime = strtotime((string) $currentTimestamp);
    if ($previousTime === false || $currentTime === false || $currentTime <= $previousTime) {
        return 0.0;
    }

    $elapsedSeconds = $currentTime - $previousTime;
    $elapsedMinutes = $elapsedSeconds / 60.0;
    if ($elapsedMinutes <= 0) {
        return 0.0;
    }

    $integrationLimitSeconds = isset($settings['max_integration_interval']) ? max(1.0, floatval($settings['max_integration_interval'])) : 7200.0;
    if ($elapsedSeconds > $integrationLimitSeconds) {
        return 0.0;
    }

    $maxFlowRate = isset($settings['max_flow_rate']) ? floatval($settings['max_flow_rate']) : 20.0;
    $zeroFlowThreshold = isset($settings['zero_flow_threshold']) ? floatval($settings['zero_flow_threshold']) : 0.02;
    $maxRateOfChange = isset($settings['max_rate_of_change']) ? floatval($settings['max_rate_of_change']) : 10.0;

    $previousFlowRate = normalizeFlowRate($previousFlowRate, $settings);
    $currentFlowRate = normalizeFlowRate($currentFlowRate, $settings);
    if ($previousFlowRate === null || $currentFlowRate === null) {
        return 0.0;
    }

    if (abs($currentFlowRate - $previousFlowRate) > $maxRateOfChange && max($previousFlowRate, $currentFlowRate) > $zeroFlowThreshold) {
        return 0.0;
    }

    if ($currentFlowRate < $previousFlowRate && ($previousFlowRate - $currentFlowRate) > $maxRateOfChange) {
        return 0.0;
    }

    $calibrationMultiplier = max(0.1, floatval($calibrationMultiplier));
    $previousFlowRate *= $calibrationMultiplier;
    $currentFlowRate *= $calibrationMultiplier;

    if ($previousFlowRate > $maxFlowRate || $currentFlowRate > $maxFlowRate) {
        return 0.0;
    }

    if ($previousFlowRate < $zeroFlowThreshold && $currentFlowRate < $zeroFlowThreshold) {
        return 0.0;
    }

    $avgFlow = (($previousFlowRate + $currentFlowRate) / 2.0);
    return max(0.0, $avgFlow * $elapsedMinutes);
}

function getTotalUsageLiters($conn) {
    $resetId = intval(getSetting($conn, 'usage_reset_id', '0'));

    if ($resetId > 0) {
        $thresholdValue = floatval(FLOW_DETECT_THRESHOLD);
        $stmt = $conn->prepare(
            "SELECT flow_rate, created_at FROM flow_logs
             WHERE id > ? AND flow_rate > ? ORDER BY id ASC"
        );
        $stmt->bind_param("id", $resetId, $thresholdValue);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query(
            "SELECT flow_rate, created_at FROM flow_logs WHERE flow_rate > " . floatval(FLOW_DETECT_THRESHOLD) . " ORDER BY created_at ASC"
        );
    }

    if (!$result || $result->num_rows === 0) {
        return 0;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return calculateConsumptionFromSamples($rows, getFlowCalculationSettings($conn));
}


function getWaterLossLiters($conn) {
    $thresholdValue = floatval(FLOW_DETECT_THRESHOLD);
    $result = $conn->query(
        "SELECT id, flow_rate, flow_status, created_at FROM flow_logs WHERE flow_rate > " . $thresholdValue . " ORDER BY created_at ASC, id ASC"
    );

    if (!$result || $result->num_rows === 0) {
        return 0;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $calibrationMultiplier = getFlowRateCalibrationMultiplier($conn);
    $total = 0.0;
    for ($i = 1; $i < count($rows); $i++) {
        $previousRow = $rows[$i - 1];
        $currentRow = $rows[$i];

        $previousStatus = getFlowStatus(
            $previousRow['flow_rate'],
            $thresholdValue,
            getLeakDetectionEnabled($conn),
            isset($previousRow['flow_status']) && $previousRow['flow_status'] !== '' ? strtoupper($previousRow['flow_status']) : null
        );
        $currentStatus = getFlowStatus(
            $currentRow['flow_rate'],
            $thresholdValue,
            getLeakDetectionEnabled($conn),
            isset($currentRow['flow_status']) && $currentRow['flow_status'] !== '' ? strtoupper($currentRow['flow_status']) : null
        );

        $segmentVolume = calculateFlowVolumeFromInterval(
            floatval($previousRow['flow_rate']),
            floatval($currentRow['flow_rate']),
            $previousRow['created_at'],
            $currentRow['created_at'],
            $calibrationMultiplier
        );

        $isLeakInterval = ($previousStatus['status'] === 'LEAK DETECTED') || ($currentStatus['status'] === 'LEAK DETECTED');

        if ($isLeakInterval) {
            $total += $segmentVolume;
        }
    }

    return round($total, 2);
}

function getWaterLossLitersByRange($conn, $startDate, $endDate) {
    $start = date('Y-m-d 00:00:00', strtotime($startDate));
    $endExclusive = date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day'));
    $startTimestamp = strtotime($start);
    $endTimestamp = strtotime($endExclusive);
    $thresholdValue = floatval(FLOW_DETECT_THRESHOLD);
    $rows = [];
    $previousQuery = $conn->prepare(
        "SELECT id, flow_rate, flow_status, created_at FROM flow_logs
            WHERE flow_rate > ? AND created_at < ?
         ORDER BY created_at DESC, id DESC LIMIT 1"
    );
        $previousQuery->bind_param('ds', $thresholdValue, $start);
    $previousQuery->execute();
    $previousResult = $previousQuery->get_result();
    if ($previousResult && ($previousRow = $previousResult->fetch_assoc())) {
        $rows[] = $previousRow;
    }

    $stmt = $conn->prepare(
        "SELECT id, flow_rate, flow_status, created_at FROM flow_logs
            WHERE flow_rate > ? AND created_at >= ? AND created_at < ?
         ORDER BY created_at ASC, id ASC"
    );
        $stmt->bind_param('dss', $thresholdValue, $start, $endExclusive);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }

    $nextQuery = $conn->prepare(
        "SELECT id, flow_rate, flow_status, created_at FROM flow_logs
            WHERE flow_rate > ? AND created_at >= ?
         ORDER BY created_at ASC, id ASC LIMIT 1"
    );
        $nextQuery->bind_param('ds', $thresholdValue, $endExclusive);
    $nextQuery->execute();
    $nextResult = $nextQuery->get_result();
    if ($nextResult && ($nextRow = $nextResult->fetch_assoc())) {
        $rows[] = $nextRow;
    }

    if (count($rows) < 2) {
        return 0.0;
    }

    $calibrationMultiplier = getFlowRateCalibrationMultiplier($conn);
    $total = 0.0;
    for ($i = 1; $i < count($rows); $i++) {
        $previousRow = $rows[$i - 1];
        $currentRow = $rows[$i];
        $previousTimestamp = strtotime($previousRow['created_at']);
        $currentTimestamp = strtotime($currentRow['created_at']);
        if ($previousTimestamp === false || $currentTimestamp === false || $currentTimestamp <= $previousTimestamp) {
            continue;
        }

        $previousStatus = getFlowStatus(
            $previousRow['flow_rate'],
            $thresholdValue,
            getLeakDetectionEnabled($conn),
            isset($previousRow['flow_status']) && $previousRow['flow_status'] !== '' ? strtoupper($previousRow['flow_status']) : null
        );
        $currentStatus = getFlowStatus(
            $currentRow['flow_rate'],
            $thresholdValue,
            getLeakDetectionEnabled($conn),
            isset($currentRow['flow_status']) && $currentRow['flow_status'] !== '' ? strtoupper($currentRow['flow_status']) : null
        );

        $isLeakInterval = ($previousStatus['status'] === 'LEAK DETECTED') || ($currentStatus['status'] === 'LEAK DETECTED');
        if ($isLeakInterval) {
            $clippedStart = max($previousTimestamp, $startTimestamp);
            $clippedEnd = min($currentTimestamp, $endTimestamp);
            if ($clippedEnd <= $clippedStart) {
                continue;
            }

            $segmentDuration = $currentTimestamp - $previousTimestamp;
            $previousRate = floatval($previousRow['flow_rate']);
            $currentRate = floatval($currentRow['flow_rate']);
            $clippedStartRatio = ($clippedStart - $previousTimestamp) / $segmentDuration;
            $clippedEndRatio = ($clippedEnd - $previousTimestamp) / $segmentDuration;
            $clippedStartRate = $previousRate + (($currentRate - $previousRate) * $clippedStartRatio);
            $clippedEndRate = $previousRate + (($currentRate - $previousRate) * $clippedEndRatio);

            $total += calculateFlowVolumeFromInterval(
                $clippedStartRate,
                $clippedEndRate,
                date('Y-m-d H:i:s', $clippedStart),
                date('Y-m-d H:i:s', $clippedEnd),
                $calibrationMultiplier
            );
        }
    }

    return round($total, 2);
}

function getDailyUsageLiters($conn, $year, $month) {
    $endDate = date('Y-m-d H:i:s', strtotime(sprintf('%04d-%02d-01', $year, $month) . ' +1 month'));

    $thresholdValue = floatval(FLOW_DETECT_THRESHOLD);
    $stmt = $conn->prepare("SELECT flow_rate, created_at FROM flow_logs WHERE flow_rate > ? AND created_at < ? ORDER BY id ASC");
    $stmt->bind_param("ds", $thresholdValue, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $dailyUsage = [];
    $previous = null;
    while ($row = $result->fetch_assoc()) {
        $current = ['flow_rate' => floatval($row['flow_rate']), 'timestamp' => strtotime($row['created_at'])];
        if ($previous !== null && $current['timestamp'] > $previous['timestamp']) {
            $avgFlow = ($previous['flow_rate'] + $current['flow_rate']) / 2;
            $segmentStart = $previous['timestamp'];
            while ($segmentStart < $current['timestamp']) {
                $date = date('Y-m-d', $segmentStart);
                $segmentEnd = min($current['timestamp'], strtotime($date . ' +1 day'));
                if (substr($date, 0, 7) === sprintf('%04d-%02d', $year, $month)) {
                    $dailyUsage[$date] = ($dailyUsage[$date] ?? 0) + ($avgFlow * (($segmentEnd - $segmentStart) / 60));
                }
                $segmentStart = $segmentEnd;
            }
        }
        $previous = $current;
    }
    return $dailyUsage;
}

function getDailyActivities($conn, $year, $month, $threshold) {
    $startDate = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $endDate = date('Y-m-d H:i:s', strtotime($startDate . ' +1 month'));

    $thresholdValue = floatval(FLOW_DETECT_THRESHOLD);
    $stmt = $conn->prepare("SELECT id, flow_rate, flow_status, created_at FROM flow_logs WHERE flow_rate > ? AND created_at >= ? AND created_at < ? ORDER BY created_at DESC");
    $stmt->bind_param("dss", $thresholdValue, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $activities = [];
    while ($row = $result->fetch_assoc()) {
        $date = date('Y-m-d', strtotime($row['created_at']));
        $storedStatus = isset($row['flow_status']) && $row['flow_status'] !== '' ? strtoupper($row['flow_status']) : null;
        $status = getFlowStatus($row['flow_rate'], $threshold, getLeakDetectionEnabled($conn), $storedStatus);
        $activities[$date][] = [
            'id' => intval($row['id']),
            'flow_rate' => round(floatval($row['flow_rate']), 2),
            'status' => $status['badge'],
            'created_at' => $row['created_at'],
        ];
    }
    return $activities;
}

function getUsageActivityReport($conn, $period, $count = 12) {
    $period = in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true) ? $period : 'daily';
    $count = max(1, min(52, intval($count)));
    $start = new DateTime('today');
    if ($period === 'daily') $start->modify('-' . ($count - 1) . ' days');
    if ($period === 'weekly') $start->modify('-' . ($count - 1) . ' weeks')->modify('monday this week');
    if ($period === 'monthly') $start->modify('first day of January');
    if ($period === 'yearly') {
        $minimumResult = $conn->query("SELECT MIN(created_at) AS first_activity FROM flow_logs WHERE flow_rate > $threshold");
        $minimumRow = $minimumResult ? $minimumResult->fetch_assoc() : null;
        $start = new DateTime($minimumRow['first_activity'] ?? date('Y-01-01'));
        $start->modify('first day of January');
    }
    $startDate = $start->format('Y-m-d 00:00:00');

    $thresholdValue = floatval(FLOW_DETECT_THRESHOLD);
    $stmt = $conn->prepare("SELECT id, flow_rate, created_at FROM flow_logs WHERE flow_rate > ? AND created_at >= ? ORDER BY created_at ASC");
    $stmt->bind_param("ds", $thresholdValue, $startDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = [];
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;

    foreach ($rows as $row) {
        $date = new DateTime($row['created_at']);
        if ($period === 'daily') $key = $date->format('Y-m-d');
        elseif ($period === 'weekly') $key = $date->format('o-\WW');
        elseif ($period === 'monthly') $key = $date->format('Y-m');
        else $key = $date->format('Y');
        if (!isset($report[$key])) {
            $report[$key] = [
                'period' => $key,
                'readings' => 0,
                'liters' => 0,
                'peak' => 0,
                'last_activity' => $row['created_at'],
                'activities' => [],
                'monthly_results' => [],
            ];
        }
        $report[$key]['readings']++;
        $report[$key]['peak'] = max($report[$key]['peak'], floatval($row['flow_rate']));
        $report[$key]['last_activity'] = $row['created_at'];
        $report[$key]['activities'][] = [
            'id' => intval($row['id']),
            'flow_rate' => round(floatval($row['flow_rate']), 2),
            'created_at' => $row['created_at'],
        ];
        if ($period === 'yearly') {
            $monthKey = $date->format('Y-m');
            if (!isset($report[$key]['monthly_results'][$monthKey])) {
                $report[$key]['monthly_results'][$monthKey] = ['month' => $monthKey, 'readings' => 0, 'liters' => 0, 'peak' => 0];
            }
            $report[$key]['monthly_results'][$monthKey]['readings']++;
            $report[$key]['monthly_results'][$monthKey]['peak'] = max($report[$key]['monthly_results'][$monthKey]['peak'], floatval($row['flow_rate']));
        }
    }

    for ($i = 1; $i < count($rows); $i++) {
        $previousTime = strtotime($rows[$i - 1]['created_at']);
        $currentTime = strtotime($rows[$i]['created_at']);
        if ($currentTime <= $previousTime) continue;
        $date = new DateTime($rows[$i]['created_at']);
        if ($period === 'daily') $key = $date->format('Y-m-d');
        elseif ($period === 'weekly') $key = $date->format('o-\WW');
        elseif ($period === 'monthly') $key = $date->format('Y-m');
        else $key = $date->format('Y');
        if (isset($report[$key])) $report[$key]['liters'] += (($rows[$i - 1]['flow_rate'] + $rows[$i]['flow_rate']) / 2) * (($currentTime - $previousTime) / 60);
        if ($period === 'yearly' && isset($report[$key]['monthly_results'])) {
            $monthKey = $date->format('Y-m');
            if (isset($report[$key]['monthly_results'][$monthKey])) {
                $report[$key]['monthly_results'][$monthKey]['liters'] += (($rows[$i - 1]['flow_rate'] + $rows[$i]['flow_rate']) / 2) * (($currentTime - $previousTime) / 60);
            }
        }
    }

    if ($period === 'monthly') {
        $monthStart = new DateTime(date('Y-01-01'));
        for ($monthIndex = 0; $monthIndex < 12; $monthIndex++) {
            $key = $monthStart->format('Y-m');
            if (!isset($report[$key])) {
                $report[$key] = [
                    'period' => $key,
                    'readings' => 0,
                    'liters' => 0,
                    'peak' => 0,
                    'last_activity' => '—',
                    'activities' => [],
                    'monthly_results' => [],
                ];
            }
            $monthStart->modify('+1 month');
        }
    }

    if ($period === 'yearly') {
        $yearStart = clone $start;
        $currentYear = new DateTime(date('Y-01-01'));
        while ($yearStart <= $currentYear) {
            $key = $yearStart->format('Y');
            if (!isset($report[$key])) {
                $report[$key] = [
                    'period' => $key,
                    'readings' => 0,
                    'liters' => 0,
                    'peak' => 0,
                    'last_activity' => '—',
                    'activities' => [],
                    'monthly_results' => [],
                ];
            }
            $yearStart->modify('+1 year');
        }
    }

    if ($period === 'yearly') {
        foreach ($report as $yearKey => &$yearReport) {
            for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
                $monthKey = $yearKey . '-' . str_pad($monthNumber, 2, '0', STR_PAD_LEFT);
                if (!isset($yearReport['monthly_results'][$monthKey])) {
                    $yearReport['monthly_results'][$monthKey] = ['month' => $monthKey, 'readings' => 0, 'liters' => 0, 'peak' => 0];
                }
            }
            ksort($yearReport['monthly_results']);
        }
        unset($yearReport);
    }

    krsort($report);
    return array_values($report);
}

function getDashboardVersions($conn) {
    $latestLogResult = $conn->query("SELECT COALESCE(MAX(id), 0) AS latest_log_id FROM flow_logs");
    $latestLog = $latestLogResult ? $latestLogResult->fetch_assoc() : null;
    $latestLogId = $latestLog ? intval($latestLog['latest_log_id']) : 0;

    $settings = [];
    $settingsResult = $conn->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
    while ($settingsResult && ($row = $settingsResult->fetch_assoc())) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $lastPing = $settings['last_sensor_ping'] ?? '';
    $pingTime = $lastPing !== '' ? strtotime($lastPing) : false;
    $sensorOnline = $pingTime !== false && (time() - $pingTime) < SENSOR_ONLINE_TIMEOUT;
    $summarySettingKeys = [
        'flow_rate_calibration',
        'leak_detection_enabled',
        'leak_threshold',
        'usage_reset_id',
        'water_cost_per_liter',
    ];
    $summarySettings = array_intersect_key($settings, array_flip($summarySettingKeys));

    return [
        'dashboard_version' => hash('sha256', json_encode([
            'latest_log_id' => $latestLogId,
            'sensor_online' => $sensorOnline,
            'settings' => $settings,
        ])),
        'usage_summary_version' => hash('sha256', json_encode([
            'latest_log_id' => $latestLogId,
            'settings' => $summarySettings,
        ])),
    ];
}

function buildDashboardData($conn, $calendarYear = null, $calendarMonth = null, $includeUsageSummary = true) {
    $versions = getDashboardVersions($conn);
    $threshold = getLeakThreshold($conn);
    $leakDetectionEnabled = getLeakDetectionEnabled($conn);
    $latestData = getLatestFlowReading($conn);
    $hasFlow = $latestData !== null;
    $currentFlow = $hasFlow ? floatval($latestData['flow_rate']) : null;
    $sensorOnline = isSensorOnline($conn);

    if ($hasFlow) {
        $storedStatus = isset($latestData['flow_status']) && $latestData['flow_status'] !== '' ? $latestData['flow_status'] : null;
        $flowStatus = getFlowStatus($currentFlow, $threshold, $leakDetectionEnabled, $storedStatus);
    } else {
        $flowStatus = [
            'status' => 'NO FLOW',
            'color' => 'secondary',
            'badge' => 'NO FLOW',
            'badge_class' => 'bg-secondary',
        ];
    }

    $chartLogs = getFlowLogs($conn, 10);
    $chartLabels = [];
    $chartValues = [];

    if ($chartLogs && $chartLogs->num_rows > 0) {
        $chartRows = [];
        while ($row = $chartLogs->fetch_assoc()) {
            $chartRows[] = $row;
        }
        $chartRows = array_reverse($chartRows);

        foreach ($chartRows as $row) {
            $chartLabels[] = date('h:i:s A', strtotime($row['created_at']));
            $chartValues[] = round($row['flow_rate'], 2);
        }
    }

    $logs = getFlowLogs($conn, 10);
    $logRows = [];

    if ($logs) {
        while ($row = $logs->fetch_assoc()) {
            $storedStatus = isset($row['flow_status']) && $row['flow_status'] !== '' ? strtoupper($row['flow_status']) : null;
            $status = getFlowStatus($row['flow_rate'], $threshold, $leakDetectionEnabled, $storedStatus);
            $logRows[] = [
                'id' => intval($row['id']),
                'flow_rate' => round($row['flow_rate'], 2),
                'status' => $status['badge'],
                'status_class' => $status['badge_class'],
                'created_at' => $row['created_at'],
            ];
        }
    }

    $recentFlow = false;
    if ($hasFlow) {
        $recentFlow = (time() - strtotime($latestData['created_at'])) < SENSOR_ONLINE_TIMEOUT;
    }

    $lastUpdate = $hasFlow
        ? $latestData['created_at']
        : (getLastSensorPing($conn) ?: 'No Data');

    $valveData = buildValveData($conn);

    $usageSummary = [];
    if ($includeUsageSummary) {
        $totalUsage = getTotalUsageLiters($conn);
        $waterLossLiters = getWaterLossLiters($conn);
        $waterCostPerLiter = getWaterCostPerLiter($conn);
        $calendarYear = $calendarYear ?? intval(date('Y'));
        $calendarMonth = $calendarMonth ?? intval(date('n'));
        $dailyUsage = getDailyUsageLiters($conn, $calendarYear, $calendarMonth);
        $calendarCosts = [];
        foreach ($dailyUsage as $date => $usage) {
            $calendarCosts[$date] = round(floatval($usage) * floatval($waterCostPerLiter), 2);
        }
        $dailyActivities = getDailyActivities($conn, $calendarYear, $calendarMonth, $threshold);
        $usageSummary = [
            'total_usage' => round($totalUsage, 2),
            'water_lost_liters' => round($waterLossLiters, 2),
            'water_lost_estimated_cost' => round($waterLossLiters * $waterCostPerLiter, 2),
            'water_cost_per_liter' => $waterCostPerLiter,
            'estimated_cost' => round($totalUsage * $waterCostPerLiter, 2),
            'calendar_month' => date('F Y', mktime(0, 0, 0, $calendarMonth, 1, $calendarYear)),
            'calendar_month_key' => sprintf('%04d-%02d', $calendarYear, $calendarMonth),
            'calendar_days' => array_map(function ($usage) { return round($usage, 2); }, $dailyUsage),
            'calendar_costs' => $calendarCosts,
            'calendar_activities' => $dailyActivities,
        ];
    }

    return array_merge([
        'dashboard_version' => $versions['dashboard_version'],
        'usage_summary_version' => $versions['usage_summary_version'],
        'last_reading_id' => $hasFlow ? intval($latestData['id']) : 0,
        'has_flow' => $hasFlow,
        'current_flow' => $currentFlow,
        'last_update' => $lastUpdate,
        'leak_threshold' => $threshold,
        'leak_detection_enabled' => $leakDetectionEnabled,
        'leak_status' => $flowStatus['status'],
        'status_color' => $flowStatus['color'],
        'device_status' => $sensorOnline ? 'ONLINE' : 'OFFLINE',
        'device_status_class' => $sensorOnline ? 'success' : 'danger',
        'sensor_state' => ($hasFlow && $recentFlow) ? 'ACTIVE' : ($sensorOnline ? 'READY' : 'STANDBY'),
        'sensor_state_class' => ($hasFlow && $recentFlow) ? 'success' : 'secondary',
        'sensor_online' => $sensorOnline,
        'monitoring_status' => ($hasFlow && $recentFlow)
            ? 'Active'
            : ($sensorOnline ? 'Sensor connected' : 'Waiting for sensor'),
        'chart_labels' => $chartLabels,
        'chart_values' => $chartValues,
        'logs' => $logRows,
    ], $usageSummary, $valveData);
}