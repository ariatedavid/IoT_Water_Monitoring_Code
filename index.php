<?php
include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

$resetMessage = '';
if (isset($_GET['reset_usage'])) {
    $resetResult = $conn->query("SELECT COALESCE(MAX(id), 0) AS reset_id FROM flow_logs");
    $resetRow = $resetResult ? $resetResult->fetch_assoc() : null;
    $resetId = $resetRow ? intval($resetRow['reset_id']) : 0;

    if (setSetting($conn, 'usage_reset_id', strval($resetId))) {
        header('Location: index.php');
        exit;
    }
    $resetMessage = 'Unable to reset water usage.';
}

$currentPage = 'dashboard';
$pageTitle = 'IoT-Based Smart Water Monitoring System';
$pageHeading = 'Operations Dashboard';
$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

$calendarYear = intval($_GET['calendar_year'] ?? date('Y'));
$calendarMonth = intval($_GET['calendar_month'] ?? date('n'));
if ($calendarYear < 2000 || $calendarYear > 2100) $calendarYear = intval(date('Y'));
if ($calendarMonth < 1 || $calendarMonth > 12) $calendarMonth = intval(date('n'));
$data = buildDashboardData($conn, $calendarYear, $calendarMonth);
$hasFlow = $data['has_flow'];

$healthPercent = 0;
$healthLabel = 'Waiting for sensor';
$healthDetail = 'No live data received from the ESP32 sensor yet.';

if ($data['sensor_online']) {
    $healthPercent = 90;
    $healthDetail = 'Sensor is online and communicating normally.';

    if ($data['leak_status'] === 'LEAK DETECTED') {
        $healthPercent -= 38;
        $healthDetail = 'Leak alarm is active. Immediate inspection is recommended.';
    } elseif ($data['current_flow'] !== null && floatval($data['current_flow']) > 15) {
        $healthPercent -= 18;
        $healthDetail = 'Flow is unusually high and may indicate abnormal usage.';
    } elseif ($data['current_flow'] !== null && floatval($data['current_flow']) > 8) {
        $healthPercent -= 8;
        $healthDetail = 'Flow is above the usual range but within manageable limits.';
    } elseif ($data['current_flow'] !== null && floatval($data['current_flow']) < 0.5) {
        $healthPercent += 8;
        $healthDetail = 'System is stable and currently in a low-use standby state.';
    }

    if (
        isset($data['valve_status']) &&
        strtolower($data['valve_status']) === 'closed' &&
        isset($data['current_flow']) &&
        floatval($data['current_flow']) > 0.5
    ) {
        $healthPercent -= 22;
        $healthDetail = 'Water is flowing while the valve remains closed. Check the valve control logic.';
    }

    if ($data['valve_status'] === 'Open') {
        $healthPercent += 6;
    }

    if ($data['leak_status'] !== 'LEAK DETECTED') {
        $healthPercent += 8;
    }

    $healthPercent = max(0, min(100, round($healthPercent)));

    if ($healthPercent >= 85) {
        $healthLabel = 'Excellent';
    } elseif ($healthPercent >= 70) {
        $healthLabel = 'Stable';
    } elseif ($healthPercent >= 50) {
        $healthLabel = 'Watch closely';
    } else {
        $healthLabel = 'Critical';
    }
} elseif ($hasFlow) {
    $healthPercent = 45;
    $healthLabel = 'Signal weak';
    $healthDetail = 'Flow was detected, but the sensor connection is unstable or recently dropped.';
} else {
    $healthPercent = 0;
    $healthLabel = 'Waiting for sensor';
    $healthDetail = 'No live data received from the ESP32 sensor yet.';
}

include "includes/header.php";
include "includes/sidebar.php";
?>

<div class="dashboard-grid" id="dashboard" data-live="true">

<div class="dashboard-main">

<div class="stat-cards">
<div class="stat-card">
<div class="stat-card-info">
<h6>Flow Rate</h6>
<div id="flowRateDisplay">
<?php if ($hasFlow): ?>
<h2 id="currentFlowValue"><?php echo number_format($data['current_flow'], 2); ?></h2>
<p id="currentFlowUnit">L/min</p>
<?php else: ?>
<h2 class="stat-muted" id="currentFlowValue">No flow</h2>
<p id="currentFlowUnit">Waiting for water flow</p>
<?php endif; ?>
</div>
</div>
<div class="stat-icon blue"><i class="bi bi-droplet-fill"></i></div>
</div>

<div class="stat-card">
<div class="stat-card-info">
<h6>Water Consumption</h6>
<h2 id="totalUsageValue"><?php echo number_format($data['total_usage'], 2); ?></h2>
<p>Liters</p>
<p class="usage-cost">Estimated cost: <strong id="estimatedCostValue">₱<?php echo number_format($data['estimated_cost'], 2); ?></strong></p>
<a href="index.php?reset_usage=1" class="btn-reset-usage" title="Reset total water usage" onclick="if (!confirm('Reset total water usage? Leak and monitoring logs will be kept.')) return false; window.isResettingUsage = true;">
<i class="bi bi-arrow-counterclockwise"></i> Reset total
</a>
</div>
<div class="stat-icon green"><i class="bi bi-moisture"></i></div>
</div>

<div class="stat-card">
<div class="stat-card-info">
<h6>Water Lost</h6>
<h2 id="waterLostValue"><?php echo number_format($data['water_lost_liters'], 2); ?></h2>
<p>Liters</p>
<p class="usage-cost">Estimated loss: <strong id="waterLostCostValue">₱<?php echo number_format($data['water_lost_estimated_cost'], 2); ?></strong></p>
</div>
<div class="stat-icon red"><i class="bi bi-droplet-half"></i></div>
</div>

<div class="stat-card" id="leakCard">
<div class="stat-card-info">
<h6>Leak Status</h6>
<h2 class="text-<?php echo $data['status_color']; ?>" id="leakStatusValue">
<?php echo $data['leak_status']; ?>
</h2>
</div>
<div class="stat-icon <?php echo ($data['leak_status'] === 'LEAK DETECTED') ? 'red' : 'orange'; ?>">
<i class="bi bi-exclamation-triangle-fill"></i>
</div>
</div>

<div class="stat-card">
<div class="stat-card-info">
<h6>Device Status</h6>
<h2 class="text-<?php echo $data['device_status_class']; ?>" id="deviceStatusValue">
<?php echo $data['device_status']; ?>
</h2>
</div>
<div class="stat-icon gray"><i class="bi bi-cpu-fill"></i></div>
</div>

<div class="stat-card" id="valveCard">
<div class="stat-card-info">
<h6>Valve Control</h6>
<h2 class="text-<?php echo $data['valve_status_class']; ?>" id="valveStatusDisplay">
<?php echo $data['valve_status']; ?>
</h2>
</div>
<div class="stat-icon <?php echo ($data['valve_status_key'] === 'open') ? 'green' : 'gray'; ?>">
<i class="bi bi-funnel-fill"></i>
</div>
</div>
</div>

<div class="panel dashboard-calendar-panel" id="waterUsageCalendarPanel">
<div class="panel-header">
<h5>Periodic Usage Calendar</h5>
<div class="calendar-navigation">
<button type="button" class="calendar-nav-button" id="calendarPreviousMonth" aria-label="Previous month">‹</button>
<span class="calendar-month" id="calendarMonthLabel"><?php echo htmlspecialchars($data['calendar_month'], ENT_QUOTES, 'UTF-8'); ?></span>
<button type="button" class="calendar-nav-button" id="calendarNextMonth" aria-label="Next month">›</button>
</div>
</div>
<div class="panel-body">
<div class="usage-calendar" id="usageCalendar">
<div class="calendar-weekdays">
<?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?><span><?php echo $weekday; ?></span><?php endforeach; ?>
</div>
<div class="calendar-days">
<?php
$firstWeekday = intval(date('w', mktime(0, 0, 0, $calendarMonth, 1, $calendarYear)));
for ($blank = 0; $blank < $firstWeekday; $blank++):
?><span class="calendar-day calendar-day-empty"></span><?php endfor; ?>
<?php for ($day = 1, $daysInMonth = intval(date('t', mktime(0, 0, 0, $calendarMonth, 1, $calendarYear))); $day <= $daysInMonth; $day++):
    $dateKey = sprintf('%04d-%02d-%02d', $calendarYear, $calendarMonth, $day);
    $isToday = $dateKey === date('Y-m-d');
    $usage = $data['calendar_days'][$dateKey] ?? 0;
?><span class="calendar-day<?php echo $isToday ? ' calendar-day-today' : ''; ?>" data-calendar-date="<?php echo $dateKey; ?>"><strong><?php echo $day; ?></strong><small><?php echo number_format($usage, 2); ?> L</small></span>
<?php endfor; ?>
</div>
</div>
</div>
</div>

<div class="panel">
<div class="panel-header">
<h5>Live Flow Monitoring</h5>
<div class="panel-tabs">
<span class="panel-tab active">Real-time</span>
</div>
</div>
<div class="panel-body" id="chartContainer">
<?php if ($hasFlow): ?>
<canvas id="flowChart" height="90"></canvas>
<?php else: ?>
<div class="empty-state" id="chartPlaceholder">
No flow data yet. Readings will appear when water flow is detected.
</div>
<?php endif; ?>
</div>
</div>

<div class="panel">
<div class="panel-header">
<h5>Periodic Water Monitoring</h5>
</div>
<div class="panel-body p-0">
<table class="table table-hover mb-0">
<thead>
<tr>
<th>ID</th>
<th>Flow Rate</th>
<th>Status</th>
<th>Date</th>
</tr>
</thead>
<tbody id="logsTableBody">
<?php if (count($data['logs']) > 0): ?>
<?php foreach ($data['logs'] as $row): ?>
<tr>
<td><?php echo $row['id']; ?></td>
<td><?php echo number_format($row['flow_rate'], 2); ?> L/min</td>
<td><span class="badge <?php echo $row['status_class']; ?>"><?php echo $row['status']; ?></span></td>
<td><?php echo date('Y-m-d h:i:s A', strtotime($row['created_at'])); ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr id="logsEmptyRow">
<td colspan="4" class="text-center text-muted py-4">No flow readings recorded yet.</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</div>

<div class="dashboard-side">

<div class="panel">
<div class="panel-header">
<h5>Operational Health</h5>
</div>
<div class="panel-body">
<div class="gauge-wrap">
<div class="health-gauge" id="healthGauge" style="--gauge: <?php echo $healthPercent; ?>">
<span id="healthGaugeValue"><?php echo $healthPercent; ?>%</span>
</div>
<p class="gauge-label" id="healthGaugeLabel"><?php echo $healthLabel; ?></p>
<div class="gauge-detail"><?php echo htmlspecialchars($healthDetail, ENT_QUOTES, 'UTF-8'); ?></div>
<div class="gauge-status-grid">
    <span class="gauge-pill <?php echo $data['sensor_online'] ? 'good' : 'warning'; ?>"><?php echo $data['sensor_online'] ? 'Sensor online' : 'Sensor offline'; ?></span>
    <span class="gauge-pill <?php echo $data['leak_status'] === 'LEAK DETECTED' ? 'critical' : 'good'; ?>"><?php echo $data['leak_status'] === 'LEAK DETECTED' ? 'Leak alert' : 'No leak'; ?></span>
    <span class="gauge-pill <?php echo strtolower($data['valve_status_key'] ?? 'closed') === 'open' ? 'good' : 'warning'; ?>"><?php echo $data['valve_status']; ?></span>
</div>
</div>
</div>
</div>

<div class="panel">
<div class="panel-header">
<h5>Safety Controls</h5>
</div>
<div class="panel-body">
<label class="toggle-switch mb-3">
<input type="checkbox" id="dashboardLeakDetectionToggle" <?php echo $data['leak_detection_enabled'] ? 'checked' : ''; ?>>
<span class="toggle-slider"></span>
<span class="toggle-label" id="dashboardLeakDetectionLabel"><?php echo $data['leak_detection_enabled'] ? 'Leak Detection is ON' : 'Leak Detection is OFF'; ?></span>
</label>

<label class="toggle-switch">
<input type="checkbox" id="dashboardAutoShutoffToggle" <?php echo $data['automatic_shutoff'] ? 'checked' : ''; ?> <?php echo $data['leak_detection_enabled'] ? '' : 'disabled'; ?>>
<span class="toggle-slider"></span>
<span class="toggle-label" id="dashboardAutoShutoffLabel"><?php echo $data['automatic_shutoff'] ? 'Automatic Shut Off is ON' : 'Automatic Shut Off is OFF'; ?></span>
</label>
</div>
</div>

<div class="panel">
<div class="panel-header">
<h5>Alert Monitoring</h5>
</div>
<div class="panel-body">
<div class="alert-list" id="automationAlerts">
<?php if ($hasFlow && $data['leak_status'] === 'LEAK DETECTED'): ?>
<div class="alert-item">
<div class="alert-item-icon critical"><i class="bi bi-exclamation-lg"></i></div>
<div class="alert-item-content">
<strong>High Flow Spike Detected</strong>
<p>Flow rate exceeded <?php echo number_format($data['leak_threshold'], 1); ?> L/min threshold.
<?php if ($data['automatic_shutoff']): ?>
 Solenoid valve has been automatically closed.
<?php else: ?>
 Automatic shut off is disabled — alert only.
<?php endif; ?>
</p>
<span class="alert-badge critical">Critical</span>

</div>
</div>
<?php elseif ($hasFlow): ?>
<div class="alert-item">
<div class="alert-item-icon success"><i class="bi bi-check-lg"></i></div>
<div class="alert-item-content">
<strong>Normal Flow Detected</strong>
<p>Current reading is within the safe operating range.</p>
<span class="alert-badge success">Normal</span>
</div>
</div>
<?php else: ?>
<div class="alert-item">
<div class="alert-item-icon neutral"><i class="bi bi-hourglass"></i></div>
<div class="alert-item-content">
<strong>No Flow Detected</strong>
<p>Waiting for the sensor to detect water flow.</p>
<span class="alert-badge neutral">Standby</span>
</div>
</div>

<?php endif; ?>
</div>
</div>
</div>

<div class="panel">
<div class="panel-header">
<h5>System Overview</h5>
</div>
<div class="panel-body">
<div class="info-grid">
<div class="info-grid-item">
<b>Database</b>
<span class="status-dot text-success">Connected</span>
</div>
<div class="info-grid-item">
<b>ESP32</b>
<span id="esp32Info"><?php echo $data['sensor_online'] ? 'Online' : 'Offline'; ?></span>
</div>
<div class="info-grid-item">
<b>Last Update</b>
<span id="lastUpdateValue"><?php echo $data['last_update'] === 'No Data' ? 'No Data' : date('Y-m-d h:i:s A', strtotime($data['last_update'])); ?></span>
</div>
<div class="info-grid-item">
<b>Monitoring</b>
<span id="monitoringStatus"><?php echo $data['monitoring_status']; ?></span>
</div>
<div class="info-grid-item">
<b>Solenoid Valve</b>
<span id="valveInfoStatus"><?php echo $data['valve_status']; ?></span>
</div>
<div class="info-grid-item">
<b>Auto Shut Off</b>
<span id="autoShutoffInfo"><?php echo $data['automatic_shutoff_label']; ?></span>
</div>
</div>
</div>
</div>

</div>
</div>

<div class="calendar-modal" id="calendarActivities" hidden aria-hidden="true">
<div class="calendar-modal-backdrop" data-calendar-close></div>
<div class="calendar-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="selectedCalendarDate">
<div class="calendar-activities-header"><div><span class="calendar-modal-eyebrow">Water activity</span><h6 id="selectedCalendarDate">Daily Activities</h6></div><button type="button" class="calendar-close" id="closeCalendarActivities" aria-label="Close activities">&times;</button></div>
<div class="calendar-activity-summary"><div><span>Consumption</span><strong id="selectedCalendarUsage">0.00 L</strong></div><div><span>Estimated cost</span><strong id="selectedCalendarCost">₱0.00</strong></div><div><span>Readings</span><strong id="selectedCalendarReadings">0</strong></div></div>
<div id="calendarActivityList"></div>
</div>
</div>

<script>
window.flowChartData = {
    labels: <?php echo json_encode($data['chart_labels']); ?>,
    values: <?php echo json_encode($data['chart_values']); ?>
};
window.calendarData = { calendar_month: <?php echo json_encode($data['calendar_month']); ?>, calendar_month_key: <?php echo json_encode($data['calendar_month_key']); ?>, calendar_days: <?php echo json_encode($data['calendar_days']); ?>, calendar_costs: <?php echo json_encode($data['calendar_costs']); ?>, calendar_activities: <?php echo json_encode($data['calendar_activities']); ?> };
window.calendarActivities = <?php echo json_encode($data['calendar_activities']); ?>;
window.calendarDays = <?php echo json_encode($data['calendar_days']); ?>;
window.calendarCosts = <?php echo json_encode($data['calendar_costs']); ?>;
window.waterCostPerLiter = <?php echo json_encode($data['water_cost_per_liter']); ?>;
window.lastReadingId = <?php echo $data['last_reading_id']; ?>;
window.dashboardVersion = <?php echo json_encode($data['dashboard_version']); ?>;
window.usageSummaryVersion = <?php echo json_encode($data['usage_summary_version']); ?>;
</script>

<script>
(function () {
    function postValveAction(action, payload) {
        const body = Object.assign({ action: action }, payload || {});
        return fetch('api/valve_control.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (response) { return response.json(); });
    }

    const leakToggle = document.getElementById('dashboardLeakDetectionToggle');
    const leakLabel = document.getElementById('dashboardLeakDetectionLabel');
    const autoToggle = document.getElementById('dashboardAutoShutoffToggle');
    const autoLabel = document.getElementById('dashboardAutoShutoffLabel');

    if (leakToggle) {
        if (autoToggle) {
            autoToggle.disabled = !leakToggle.checked;
        }

        leakToggle.addEventListener('change', function () {
            if (!leakToggle.checked && autoToggle) {
                autoToggle.checked = false;
            }
            postValveAction('set_leak_detection', { enabled: leakToggle.checked }).then(function (data) {
                if (data.success) {
                    if (leakLabel) {
                        leakLabel.textContent = data.leak_detection_enabled
                            ? 'Leak Detection is ON'
                            : 'Leak Detection is OFF';
                    }
                    if (autoToggle) {
                        autoToggle.disabled = !data.leak_detection_enabled;
                        autoToggle.checked = data.automatic_shutoff && data.leak_detection_enabled;
                    }
                    window.location.reload();
                }
            });
        });
    }

    if (autoToggle) {
        autoToggle.addEventListener('change', function () {
            if (autoToggle.checked && leakToggle) {
                leakToggle.checked = true;
            }
            postValveAction('set_automatic_shutoff', { enabled: autoToggle.checked }).then(function (data) {
                if (data.success) {
                    if (autoLabel) {
                        autoLabel.textContent = data.automatic_shutoff
                            ? 'Automatic Shut Off is ON'
                            : 'Automatic Shut Off is OFF';
                    }
                    if (leakToggle) {
                        leakToggle.checked = data.leak_detection_enabled;
                        autoToggle.disabled = !data.leak_detection_enabled;
                    }
                    window.location.reload();
                }
            });
        });
    }
})();

setInterval(function () {
    if (document.visibilityState === 'visible' && !window.isResettingUsage) {
        window.location.reload();
    }
}, 5000);
</script>

<?php include "includes/footer.php"; ?>
<?php include "includes/footer.php"; ?>
