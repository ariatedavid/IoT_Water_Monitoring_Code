<?php
// Include PHPMailer classes into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Manually load the PHPMailer files using your exact folder path
require 'PHPMailer-7.1.1/src/Exception.php';
require 'PHPMailer-7.1.1/src/PHPMailer.php';
require 'PHPMailer-7.1.1/src/SMTP.php';

include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

// --- START: Email Processing Logic with PHPMailer ---
$emailFeedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_manual_alert'])) {
    $customTitle = trim($_POST['alert_title'] ?? 'Manual Alert Notification');
    $customMessage = trim($_POST['custom_message'] ?? '');
    
    if (!empty($customMessage) && !empty($customTitle)) {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();                                               // Send using SMTP
            $mail->Host       = 'smtp.gmail.com';                          // Gmail SMTP server
            $mail->SMTPAuth   = true;                                      // Enable SMTP authentication
            $mail->Username   = 'dladea024@gmail.com';                    // Your Gmail address
            $mail->Password   = 'drtlcufkikiikzaz';                        // Your Google App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;               // Enable implicit TLS encryption
            $mail->Port       = 465;                                       // TCP port to connect to for Gmail

            // Recipients
            $mail->setFrom('dladea0024@gmail.com', 'IoT Water Monitor');   // Sender email and name
            $mail->addAddress('mathewmileschavez@gmail.com');               // Recipient 1
            $mail->addAddress('ariatedavidangelo@gmail.com');              // Recipient 2

            // Content
            $mail->isHTML(true);                                           // Set email format to HTML
            $mail->Subject = htmlspecialchars($customTitle);               // Custom title
            $mail->Body    = nl2br(htmlspecialchars($customMessage));
            $mail->AltBody = $customMessage;

            $mail->send();
            $emailFeedback = '<div class="alert alert-success" style="margin-bottom: 1rem;">Email sent successfully!</div>';
        } catch (Exception $e) {
            $emailFeedback = '<div class="alert alert-danger" style="margin-bottom: 1rem;">Message could not be sent. Mailer Error: ' . $mail->ErrorInfo . '</div>';
        }
    } else {
        $emailFeedback = '<div class="alert alert-warning" style="margin-bottom: 1rem;">Please enter both a title and a message before sending.</div>';
    }
}
// --- END: Email Processing Logic ---

$currentPage = 'alerts';
$pageTitle = 'Alerts - IoT Smart Water Monitoring';
$pageHeading = 'Alerts';

$threshold = getLeakThreshold($conn);
$latestData = getLatestFlowReading($conn);
$hasFlow = $latestData !== null;
$currentFlow = $hasFlow ? $latestData['flow_rate'] : null;
$automaticShutoff = getAutomaticShutoff($conn);
$valveInfo = getValveDisplayInfo($conn);
$lastEvent = getLastValveEvent($conn);

$alerts = [];
if ($hasFlow) {
    $storedStatus = isset($latestData['flow_status']) && $latestData['flow_status'] !== '' ? $latestData['flow_status'] : null;
    $status = getFlowStatus($currentFlow, $threshold, getLeakDetectionEnabled($conn), $storedStatus);
    if ($status['status'] === 'LEAK DETECTED') {
        $alerts[] = [
            'type' => 'critical',
            'icon' => 'bi-exclamation-lg',
            'title' => 'Leak Detected',
            'message' => 'Sensor detected flow rate of ' . number_format($currentFlow, 2) .
                         ' L/min (threshold: ' . number_format($threshold, 1) . ' L/min).' .
                         ($automaticShutoff
                             ? ' Solenoid valve was automatically closed.'
                             : ' Automatic shut off is disabled — valve was not closed.'),
            'time' => $latestData['created_at'],
            'badge' => 'Critical',
        ];
    } else {
        $alerts[] = [
            'type' => 'success',
            'icon' => 'bi-check-lg',
            'title' => 'Normal Flow',
            'message' => 'Sensor reading ' . number_format($currentFlow, 2) .
                         ' L/min is within normal range.',
            'time' => $latestData['created_at'],
            'badge' => 'Normal',
        ];
    }
} else {
    $alerts[] = [
        'type' => 'neutral',
        'icon' => 'bi-hourglass',
        'title' => 'No Flow Detected',
        'message' => 'Waiting for sensor readings. Alerts will update when water flow is detected.',
        'time' => '—',
        'badge' => 'Standby',
    ];
}

if ($lastEvent['action'] === 'auto_close' && $lastEvent['time']) {
    $alerts[] = [
        'type' => 'critical',
        'icon' => 'bi-funnel-fill',
        'title' => 'Valve Auto-Closed',
        'message' => $lastEvent['reason'],
        'time' => $lastEvent['time'],
        'badge' => 'Valve Action',
    ];
}

$alerts[] = [
    'type' => $valveInfo['status_key'] === 'open' ? 'success' : 'neutral',
    'icon' => 'bi-funnel-fill',
    'title' => 'Solenoid Valve Status',
    'message' => 'Valve is currently ' . strtolower($valveInfo['status']) .
                 '. Automatic shut off is ' . ($automaticShutoff ? 'enabled' : 'disabled') . '.',
    'time' => getLastSensorPing($conn) ?: '—',
    'badge' => $valveInfo['status'],
];

include "includes/header.php";
include "includes/sidebar.php";
?>

<div class="page-grid-2">
<?php foreach ($alerts as $alert): ?>
<div class="panel">
<div class="panel-body">
<div class="alert-item">
<div class="alert-item-icon <?php echo $alert['type']; ?>">
<i class="bi <?php echo $alert['icon']; ?>"></i>
</div>
<div class="alert-item-content">
<strong><?php echo $alert['title']; ?></strong>
<p><?php echo $alert['message']; ?></p>
<span class="alert-badge <?php echo $alert['type']; ?>"><?php echo $alert['badge']; ?></span>
<small class="text-muted d-block mt-2"><?php echo $alert['time']; ?></small>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
</div>

<!-- START: Send Manual Email Alert Panel -->

<!-- END: Send Manual Email Alert Panel -->

<div class="panel content-panel">
<div class="panel-header">
<h5>Alert History</h5>
</div>
<div class="panel-body p-0">
<table class="table table-hover mb-0">
<thead>
<tr><th>ID</th><th>Flow Rate</th><th>Alert</th><th>Date</th></tr>
</thead>
<tbody>
<?php
$logs = getFlowLogs($conn, 10);
if ($logs && $logs->num_rows > 0):
while ($row = $logs->fetch_assoc()) {
    $storedStatus = isset($row['flow_status']) && $row['flow_status'] !== '' ? strtoupper($row['flow_status']) : null;
    $status = getFlowStatus($row['flow_rate'], $threshold, getLeakDetectionEnabled($conn), $storedStatus);
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . number_format($row['flow_rate'], 2) . " L/min</td>";
    echo "<td><span class='badge " . $status['badge_class'] . "'>" . $status['badge'] . "</span></td>";
    echo "<td>" . $row['created_at'] . "</td>";
    echo "</tr>";
}
else:
?>
<tr><td colspan="4" class="text-center text-muted py-4">No flow readings recorded yet.</td></tr>
<?php
endif;
?>
</tbody>
</table>
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