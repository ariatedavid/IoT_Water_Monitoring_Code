<?php
include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

$currentPage = 'threshold';
$pageTitle = 'Threshold - IoT Smart Water Monitoring';
$pageHeading = 'Threshold Settings';

$leakThreshold = getLeakThreshold($conn);
$waterCostPerLiter = getWaterCostPerLiter($conn);
$flowRateCalibration = getFlowRateCalibrationMultiplier($conn);
$maxFlowRate = floatval(getSetting($conn, 'max_flow_rate', '20'));
$minimumDetectableFlow = floatval(getSetting($conn, 'min_valid_flow_rate', '0'));
$zeroFlowThreshold = floatval(getSetting($conn, 'zero_flow_threshold', '0.02'));
$maxRateOfChange = floatval(getSetting($conn, 'max_rate_of_change', '10'));
$maxValidSamplingGap = floatval(getSetting($conn, 'max_valid_sampling_gap', '7200'));
$maxIntegrationInterval = floatval(getSetting($conn, 'max_integration_interval', '7200'));
$sensorTimeout = floatval(getSetting($conn, 'sensor_timeout', '300'));
$smoothingWindow = intval(getSetting($conn, 'smoothing_window', '3'));
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leakThreshold = floatval($_POST['leak_threshold'] ?? 5);
    $waterCostPerLiter = max(0, floatval($_POST['water_cost_per_liter'] ?? 0.05));
    $flowRateCalibration = max(0.1, floatval($_POST['flow_rate_calibration'] ?? 1.0));
    $maxFlowRate = max(0, floatval($_POST['max_flow_rate'] ?? 20));
    $minimumDetectableFlow = max(0, floatval($_POST['min_valid_flow_rate'] ?? 0));
    $zeroFlowThreshold = max(0, floatval($_POST['zero_flow_threshold'] ?? 0.02));
    $maxRateOfChange = max(0, floatval($_POST['max_rate_of_change'] ?? 10));
    $maxValidSamplingGap = max(1, floatval($_POST['max_valid_sampling_gap'] ?? 7200));
    $maxIntegrationInterval = max(1, floatval($_POST['max_integration_interval'] ?? 7200));
    $sensorTimeout = max(1, floatval($_POST['sensor_timeout'] ?? 300));
    $smoothingWindow = max(1, intval($_POST['smoothing_window'] ?? 3));
    setLeakThreshold($conn, $leakThreshold);
    setWaterCostPerLiter($conn, $waterCostPerLiter);
    setFlowRateCalibrationMultiplier($conn, $flowRateCalibration);
    setSetting($conn, 'max_flow_rate', strval($maxFlowRate));
    setSetting($conn, 'min_valid_flow_rate', strval($minimumDetectableFlow));
    setSetting($conn, 'zero_flow_threshold', strval($zeroFlowThreshold));
    setSetting($conn, 'max_rate_of_change', strval($maxRateOfChange));
    setSetting($conn, 'max_valid_sampling_gap', strval($maxValidSamplingGap));
    setSetting($conn, 'max_integration_interval', strval($maxIntegrationInterval));
    setSetting($conn, 'sensor_timeout', strval($sensorTimeout));
    setSetting($conn, 'smoothing_window', strval($smoothingWindow));
    $saved = true;
}

include "includes/header.php";
include "includes/sidebar.php";
?>

<?php if ($saved): ?>
<div class="alert alert-success mb-4">Threshold settings saved.</div>
<?php endif; ?>

<div class="page-grid-2">
<div class="panel">
<div class="panel-header">
<h5>Leak Threshold</h5>
</div>
<div class="panel-body">
<form method="POST">
<div class="mb-3">
<label for="leak_threshold" class="form-label">Leak Threshold (L/min)</label>
<input type="number" step="0.1" min="0" class="form-control"
       id="leak_threshold" name="leak_threshold"
       value="<?php echo $leakThreshold; ?>" required>
<small class="text-muted">Leak alerts trigger when the measured flow exceeds this value. This is separate from sensor calibration.</small>
</div>
<div class="mb-3">
<label for="water_cost_per_liter" class="form-label">Water Cost per Liter (₱)</label>
<input type="number" step="0.0001" min="0" class="form-control"
    id="water_cost_per_liter" name="water_cost_per_liter"
    value="<?php echo htmlspecialchars(number_format($waterCostPerLiter, 4, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
<small class="text-muted">Used to estimate the cost beside total water usage.</small>
</div>
<hr>
<h6 class="mt-4">Sensor Calibration</h6>
<div class="mb-3">
<label for="max_flow_rate" class="form-label">Maximum Flow Rate (L/min)</label>
<input type="number" step="0.1" min="0" class="form-control" id="max_flow_rate" name="max_flow_rate" value="<?php echo htmlspecialchars(number_format($maxFlowRate, 1, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
<small class="text-muted">Recommended: 15–30 L/min for a residential line. Above this is treated as invalid sensor data.</small>
</div>
<div class="mb-3">
<label for="min_valid_flow_rate" class="form-label">Minimum Detectable Flow (L/min)</label>
<input type="number" step="0.01" min="0" class="form-control" id="min_valid_flow_rate" name="min_valid_flow_rate" value="<?php echo htmlspecialchars(number_format($minimumDetectableFlow, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
<small class="text-muted">Below this threshold the reading is treated as zero-flow noise.</small>
</div>
<div class="mb-3">
<label for="zero_flow_threshold" class="form-label">Zero-Flow Threshold (L/min)</label>
<input type="number" step="0.01" min="0" class="form-control" id="zero_flow_threshold" name="zero_flow_threshold" value="<?php echo htmlspecialchars(number_format($zeroFlowThreshold, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
<small class="text-muted">Minor fluctuations below this value are ignored and will not accumulate consumption.</small>
</div>
<div class="mb-3">
<label for="flow_rate_calibration" class="form-label">Flow Calibration Factor (x)</label>
<input type="number" step="0.01" min="0.1" max="10" class="form-control"
    id="flow_rate_calibration" name="flow_rate_calibration"
    value="<?php echo htmlspecialchars(number_format($flowRateCalibration, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
<small class="text-muted">Use 1.0 for an unadjusted sensor, or a verified calibration result from a physical benchmark test.</small>
</div>
<div class="mb-3">
<label for="max_valid_sampling_gap" class="form-label">Maximum Valid Sampling Gap (seconds)</label>
<input type="number" step="1" min="1" class="form-control" id="max_valid_sampling_gap" name="max_valid_sampling_gap" value="<?php echo intval($maxValidSamplingGap); ?>" required>
<small class="text-muted">Longer gaps are treated as communication loss and no flow is assumed during the gap.</small>
</div>
<div class="mb-3">
<label for="max_integration_interval" class="form-label">Maximum Integration Interval (seconds)</label>
<input type="number" step="1" min="1" class="form-control" id="max_integration_interval" name="max_integration_interval" value="<?php echo intval($maxIntegrationInterval); ?>" required>
<small class="text-muted">This prevents stale readings from being integrated over too long a period.</small>
</div>
<div class="mb-3">
<label for="max_rate_of_change" class="form-label">Maximum Rate of Change (L/min)</label>
<input type="number" step="0.1" min="0" class="form-control" id="max_rate_of_change" name="max_rate_of_change" value="<?php echo htmlspecialchars(number_format($maxRateOfChange, 1, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" required>
<small class="text-muted">Sudden unrealistic spikes are flagged and excluded instead of being counted as valid water use.</small>
</div>
<div class="mb-3">
<label for="sensor_timeout" class="form-label">Sensor Timeout (seconds)</label>
<input type="number" step="1" min="1" class="form-control" id="sensor_timeout" name="sensor_timeout" value="<?php echo intval($sensorTimeout); ?>" required>
<small class="text-muted">If no reading arrives within this interval, the sensor is marked offline.</small>
</div>
<div class="mb-3">
<label for="smoothing_window" class="form-label">Smoothing Window (samples)</label>
<input type="number" step="1" min="1" max="20" class="form-control" id="smoothing_window" name="smoothing_window" value="<?php echo intval($smoothingWindow); ?>" required>
<small class="text-muted">A small rolling filter can suppress sensor noise while preserving legitimate flow changes.</small>
</div>
<button type="submit" class="btn btn-primary">Save Settings</button>
</form>
</div>
</div>

<div class="panel">
<div class="panel-header">
<h5>Current Settings</h5>
</div>
<div class="panel-body">
<div class="info-grid">
<div class="info-grid-item">
<b>Leak Threshold</b>
<span><?php echo number_format($leakThreshold, 1); ?> L/min</span>
</div>
<div class="info-grid-item">
<b>Water Cost</b>
<span>₱<?php echo number_format($waterCostPerLiter, 4); ?> / Liter</span>
</div>
<div class="info-grid-item">
<b>Flow Calibration</b>
<span><?php echo number_format($flowRateCalibration, 2); ?>x</span>
</div>
<div class="info-grid-item">
<b>Zero-Flow</b>
<span><?php echo number_format($zeroFlowThreshold, 2); ?> L/min</span>
</div>
<div class="info-grid-item">
<b>Max Valid Gap</b>
<span><?php echo intval($maxValidSamplingGap); ?> s</span>
</div>
<div class="info-grid-item">
<b>Sensor Timeout</b>
<span><?php echo intval($sensorTimeout); ?> s</span>
</div>
<div class="info-grid-item">
<b>Status</b>
<span class="status-dot text-success">Active</span>
</div>
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
