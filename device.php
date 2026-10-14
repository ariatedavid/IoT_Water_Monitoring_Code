<?php
include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

$currentPage = 'device';
$pageTitle = 'Device Status - IoT Smart Water Monitoring';
$pageHeading = 'Device Status';

$latestData = getLatestFlowReading($conn);
$hasFlow = $latestData !== null;
$lastFlowUpdate = $hasFlow ? $latestData['created_at'] : null;
$lastSensorPing = getLastSensorPing($conn);
$sensorOnline = isSensorOnline($conn);
$valveInfo = getValveDisplayInfo($conn);

$recentFlow = false;
if ($hasFlow) {
    $recentFlow = (time() - strtotime($latestData['created_at'])) < SENSOR_ONLINE_TIMEOUT;
}

$deviceStatus = $sensorOnline ? 'ONLINE' : 'OFFLINE';
$statusClass = $sensorOnline ? 'success' : 'danger';
$sensorState = ($hasFlow && $recentFlow) ? 'ACTIVE' : ($sensorOnline ? 'READY' : 'STANDBY');
$sensorStateClass = ($hasFlow && $recentFlow) ? 'success' : 'secondary';
$lastDataReceived = $lastFlowUpdate ?: ($lastSensorPing ?: 'No sensor data');

include "includes/header.php";
include "includes/sidebar.php";
?>

<div class="page-grid-4">
<div class="stat-card">
<div class="stat-card-info">
<h6>ESP32 Module</h6>
<h2 class="text-<?php echo $statusClass; ?>"><?php echo $deviceStatus; ?></h2>
</div>
<div class="stat-icon gray"><i class="bi bi-cpu-fill"></i></div>
</div>
<div class="stat-card">
<div class="stat-card-info">
<h6>Flow Sensor</h6>
<h2 class="text-<?php echo $sensorStateClass; ?>"><?php echo $sensorState; ?></h2>
</div>
<div class="stat-icon blue"><i class="bi bi-droplet-fill"></i></div>
</div>
<div class="stat-card">
<div class="stat-card-info">
<h6>Solenoid Valve</h6>
<h2 class="text-<?php echo $valveInfo['status_class']; ?>"><?php echo $valveInfo['status']; ?></h2>
</div>
<div class="stat-icon <?php echo ($valveInfo['status_key'] === 'open') ? 'green' : 'gray'; ?>">
<i class="bi bi-funnel-fill"></i>
</div>
</div>
<div class="stat-card">
<div class="stat-card-info">
<h6>Database</h6>
<h2 class="text-success">CONNECTED</h2>
</div>
<div class="stat-icon green"><i class="bi bi-database-fill"></i></div>
</div>
</div>

<div class="panel content-panel">
<div class="panel-header">
<h5>Device Information</h5>
</div>
<div class="panel-body">
<div class="info-grid">
<div class="info-grid-item">
<b>Device</b>
<span>ESP32</span>
</div>
<div class="info-grid-item">
<b>Flow Sensor</b>
<span>YF-S201 Flow Sensor</span>
</div>
<div class="info-grid-item">
<b>Solenoid Valve</b>
<span><?php echo $valveInfo['status']; ?> (Commanded: <?php echo $valveInfo['commanded_label']; ?>)</span>
</div>
<div class="info-grid-item">
<b>Automatic Shut Off</b>
<span><?php echo $valveInfo['automatic_shutoff'] ? 'Enabled' : 'Disabled'; ?></span>
</div>
<div class="info-grid-item">
<b>Last Sensor Ping</b>
<span><?php echo $lastSensorPing ?: 'Never'; ?></span>
</div>
<div class="info-grid-item">
<b>Last Flow Reading</b>
<span><?php echo $lastDataReceived; ?></span>
</div>
</div>
</div>
</div>

<script>
(function () {
    const activeFormControls = ['TEXTAREA', 'INPUT', 'SELECT'];
    setInterval(function () {
        const active = document.activeElement;
        const isTyping = active && activeFormControls.indexOf(active.tagName) !== -1 && (active.type !== 'button' && active.type !== 'submit' && active.type !== 'reset');
        if (document.visibilityState === 'visible' && !isTyping) {
            window.location.reload();
        }
    }, 5000);
}());
</script>

<?php include "includes/footer.php"; ?>
