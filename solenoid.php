<?php
include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

$currentPage = 'solenoid';
$pageTitle = 'Solenoid Valve - IoT Smart Water Monitoring';
$pageHeading = 'Solenoid Valve';

$valveInfo = getValveDisplayInfo($conn);
$lastEvent = $valveInfo['last_event'];
$sensorOnline = isSensorOnline($conn);

include "includes/header.php";
include "includes/sidebar.php";
?>

<div class="page-grid-3">
<div class="stat-card">
<div class="stat-card-info">
<h6>Valve Status</h6>
<h2 class="text-<?php echo $valveInfo['status_class']; ?>" id="valveStatusValue">
<?php echo $valveInfo['status']; ?>
</h2>
</div>
<div class="stat-icon <?php echo ($valveInfo['status_key'] === 'open') ? 'green' : 'gray'; ?>">
<i class="bi bi-funnel-fill"></i>
</div>
</div>

<div class="stat-card">
<div class="stat-card-info">
<h6>Automatic Shut Off</h6>
<h2 class="text-<?php echo $valveInfo['automatic_shutoff'] ? 'success' : 'warning'; ?>" id="autoShutoffValue">
<?php echo $valveInfo['automatic_shutoff'] ? 'Enabled' : 'Disabled'; ?>
</h2>
</div>
<div class="stat-icon orange"><i class="bi bi-shield-check"></i></div>
</div>

<div class="stat-card">
<div class="stat-card-info">
<h6>Leak Detection</h6>
<h2 class="text-<?php echo $valveInfo['leak_detection_enabled'] ? 'success' : 'warning'; ?>" id="leakDetectionValue">
<?php echo $valveInfo['leak_detection_enabled'] ? 'Enabled' : 'Disabled'; ?>
</h2>
</div>
<div class="stat-icon <?php echo $valveInfo['leak_detection_enabled'] ? 'orange' : 'gray'; ?>"><i class="bi bi-exclamation-triangle-fill"></i></div>
</div>

<div class="stat-card">
<div class="stat-card-info">
<h6>ESP32 Connection</h6>
<h2 class="text-<?php echo $sensorOnline ? 'success' : 'danger'; ?>" id="valveDeviceStatus">
<?php echo $sensorOnline ? 'ONLINE' : 'OFFLINE'; ?>
</h2>
</div>
<div class="stat-icon gray"><i class="bi bi-cpu-fill"></i></div>
</div>
</div>

<div class="page-grid-2">
<div class="panel">
<div class="panel-header">
<h5>Manual Control</h5>
</div>
<div class="panel-body">
<p class="text-muted mb-4">
Use the buttons below to manually open or close the solenoid valve.
Commands are sent to the ESP32 on its next sensor ping.
</p>

<div class="valve-control-actions">
<button type="button" class="btn-valve btn-valve-open" id="btnOpenValve"
        <?php echo ($valveInfo['commanded_status'] === 'open') ? 'disabled' : ''; ?>>
<i class="bi bi-unlock-fill"></i> Open Valve
</button>
<button type="button" class="btn-valve btn-valve-close" id="btnCloseValve"
        <?php echo ($valveInfo['commanded_status'] === 'closed') ? 'disabled' : ''; ?>>
<i class="bi bi-lock-fill"></i> Close Valve
</button>
</div>

<div class="valve-status-indicator mt-4" id="valveIndicator">
<div class="valve-indicator-ring <?php echo ($valveInfo['status_key'] === 'open') ? 'open' : 'closed'; ?>">
<i class="bi bi-droplet-half"></i>
</div>
<p class="valve-indicator-label" id="valveIndicatorLabel">
<?php echo $valveInfo['status']; ?>
</p>
<p class="text-muted small" id="valveCommandLabel">
Commanded: <?php echo $valveInfo['commanded_label']; ?>
</p>
</div>

<div class="valve-feedback mt-3" id="valveFeedback" hidden></div>
</div>
</div>

<div class="panel">
<div class="panel-header">
<h5>Leak Protection</h5>
</div>
<div class="panel-body">
<p class="text-muted mb-4">
Use these controls to temporarily disable leak alerts and automatic valve shutdown during off-hours or maintenance periods.
</p>

<label class="toggle-switch mb-4">
<input type="checkbox" id="leakDetectionToggle"
       <?php echo $valveInfo['leak_detection_enabled'] ? 'checked' : ''; ?>>
<span class="toggle-slider"></span>
<span class="toggle-label" id="leakDetectionToggleLabel">
<?php echo $valveInfo['leak_detection_enabled'] ? 'Leak Detection is ON' : 'Leak Detection is OFF'; ?>
</span>
</label>

<label class="toggle-switch">
<input type="checkbox" id="autoShutoffToggle"
       <?php echo $valveInfo['automatic_shutoff'] ? 'checked' : ''; ?>
       <?php echo $valveInfo['leak_detection_enabled'] ? '' : 'disabled'; ?>>
<span class="toggle-slider"></span>
<span class="toggle-label" id="autoShutoffToggleLabel">
<?php echo $valveInfo['automatic_shutoff'] ? 'Automatic Shut Off is ON' : 'Automatic Shut Off is OFF'; ?>
</span>
</label>

<div class="info-grid mt-4">
<div class="info-grid-item">
<b>Leak Detection</b>
<span class="status-dot text-<?php echo $valveInfo['leak_detection_enabled'] ? 'success' : 'warning'; ?>"><?php echo $valveInfo['leak_detection_enabled'] ? 'Monitoring' : 'Paused'; ?></span>
</div>
<div class="info-grid-item">
<b>Auto Shut Off</b>
<span class="status-dot text-<?php echo $valveInfo['automatic_shutoff'] ? 'success' : 'warning'; ?>"><?php echo $valveInfo['automatic_shutoff'] ? 'Enabled' : 'Alert only'; ?></span>
</div>
</div>
</div>
</div>
</div>

<div class="panel content-panel">
<div class="panel-header">
<h5>Valve Activity</h5>
</div>
<div class="panel-body">
<div class="info-grid">
<div class="info-grid-item">
<b>Reported Status</b>
<span id="valveReportedStatus"><?php echo ucfirst($valveInfo['status_key']); ?></span>
</div>
<div class="info-grid-item">
<b>Commanded Status</b>
<span id="valveCommandedStatus"><?php echo $valveInfo['commanded_label']; ?></span>
</div>
<div class="info-grid-item">
<b>Last Event</b>
<span id="lastValveEventAction">
<?php echo $lastEvent['action'] ? str_replace('_', ' ', ucfirst($lastEvent['action'])) : 'None'; ?>
</span>
</div>
<div class="info-grid-item">
<b>Last Event Time</b>
<span id="lastValveEventTime"><?php echo $lastEvent['time'] ?: '—'; ?></span>
</div>
</div>
<?php if ($lastEvent['reason']): ?>
<p class="text-muted mt-3 mb-0" id="lastValveEventReason"><?php echo htmlspecialchars($lastEvent['reason']); ?></p>
<?php else: ?>
<p class="text-muted mt-3 mb-0" id="lastValveEventReason"></p>
<?php endif; ?>
</div>
</div>

<?php
$extraScripts = <<<'HTML'
<script>
(function () {
    const feedback = document.getElementById('valveFeedback');

    function showFeedback(message, type) {
        if (!feedback) return;
        feedback.hidden = false;
        feedback.className = 'valve-feedback mt-3 alert-' + type;
        feedback.textContent = message;
        setTimeout(function () { feedback.hidden = true; }, 3000);
    }

    function postValveAction(action, payload) {
        const body = Object.assign({ action: action }, payload || {});
        return fetch('api/valve_control.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (response) { return response.json(); });
    }

    function applyValveData(data) {
        const statusEl = document.getElementById('valveStatusValue');
        const autoEl = document.getElementById('autoShutoffValue');
        const indicator = document.querySelector('.valve-indicator-ring');
        const indicatorLabel = document.getElementById('valveIndicatorLabel');
        const commandLabel = document.getElementById('valveCommandLabel');
        const reported = document.getElementById('valveReportedStatus');
        const commanded = document.getElementById('valveCommandedStatus');
        const btnOpen = document.getElementById('btnOpenValve');
        const btnClose = document.getElementById('btnCloseValve');
        const toggle = document.getElementById('autoShutoffToggle');
        const leakToggle = document.getElementById('leakDetectionToggle');
        const leakToggleLabel = document.getElementById('leakDetectionToggleLabel');
        const toggleLabel = document.getElementById('autoShutoffToggleLabel');

        if (statusEl) {
            statusEl.className = 'text-' + data.valve_status_class;
            statusEl.textContent = data.valve_status;
        }
        if (autoEl) {
            autoEl.className = 'text-' + (data.automatic_shutoff ? 'success' : 'warning');
            autoEl.textContent = data.automatic_shutoff_label;
        }
        if (indicator) {
            indicator.className = 'valve-indicator-ring ' + data.valve_status_key;
        }
        if (indicatorLabel) indicatorLabel.textContent = data.valve_status;
        if (commandLabel) commandLabel.textContent = 'Commanded: ' + data.valve_command.charAt(0).toUpperCase() + data.valve_command.slice(1);
        if (reported) reported.textContent = data.valve_status;
        if (commanded) commanded.textContent = data.valve_command.charAt(0).toUpperCase() + data.valve_command.slice(1);
        if (btnOpen) btnOpen.disabled = (data.valve_command === 'open');
        if (btnClose) btnClose.disabled = (data.valve_command === 'closed');
        if (toggle) toggle.checked = data.automatic_shutoff;
        if (toggleLabel) {
            toggleLabel.textContent = data.automatic_shutoff
                ? 'Automatic Shut Off is ON'
                : 'Automatic Shut Off is OFF';
        }
        if (leakToggle) leakToggle.checked = data.leak_detection_enabled;
        if (leakToggleLabel) {
            leakToggleLabel.textContent = data.leak_detection_enabled
                ? 'Leak Detection is ON'
                : 'Leak Detection is OFF';
        }

        if (data.last_valve_event) {
            const event = data.last_valve_event;
            const actionEl = document.getElementById('lastValveEventAction');
            const timeEl = document.getElementById('lastValveEventTime');
            const reasonEl = document.getElementById('lastValveEventReason');
            if (actionEl && event.action) {
                actionEl.textContent = event.action.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
            }
            if (timeEl) timeEl.textContent = event.time || '—';
            if (reasonEl) reasonEl.textContent = event.reason || '';
        }
    }

    function refreshValveData() {
        fetch('api/valve_control.php')
            .then(function (response) { return response.json(); })
            .then(applyValveData)
            .catch(function () {});
    }

    const btnOpen = document.getElementById('btnOpenValve');
    const btnClose = document.getElementById('btnCloseValve');
    const toggle = document.getElementById('autoShutoffToggle');
    const leakToggle = document.getElementById('leakDetectionToggle');

    if (btnOpen) {
        btnOpen.addEventListener('click', function () {
            postValveAction('open').then(function (data) {
                if (data.success) {
                    applyValveData(data);
                    showFeedback('Open command sent to solenoid valve.', 'success');
                }
            });
        });
    }

    if (btnClose) {
        btnClose.addEventListener('click', function () {
            postValveAction('close').then(function (data) {
                if (data.success) {
                    applyValveData(data);
                    showFeedback('Close command sent to solenoid valve.', 'success');
                }
            });
        });
    }

    if (toggle) {
        toggle.addEventListener('change', function () {
            if (toggle.checked && leakToggle && !leakToggle.checked) {
                leakToggle.checked = true;
            }
            postValveAction('set_automatic_shutoff', { enabled: toggle.checked }).then(function (data) {
                if (data.success) {
                    applyValveData(data);
                    if (toggle && leakToggle) {
                        toggle.disabled = !leakToggle.checked;
                    }
                    showFeedback(
                        toggle.checked
                            ? 'Automatic shut off enabled.'
                            : 'Automatic shut off disabled — alerts only.',
                        'success'
                    );
                }
            });
        });
    }

    if (leakToggle) {
        leakToggle.addEventListener('change', function () {
            if (!leakToggle.checked && toggle) {
                toggle.checked = false;
            }
            postValveAction('set_leak_detection', { enabled: leakToggle.checked }).then(function (data) {
                if (data.success) {
                    applyValveData(data);
                    if (toggle) {
                        toggle.disabled = !leakToggle.checked;
                    }
                    showFeedback(
                        leakToggle.checked
                            ? 'Leak detection enabled.'
                            : 'Leak detection disabled — auto shutoff turned off.',
                        'success'
                    );
                }
            });
        });
    }

    setInterval(refreshValveData, 5000);
})();
</script>
HTML;
?>

<?php include "includes/footer.php"; ?>
