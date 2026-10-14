<?php
include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

$currentPage = 'reports';
$pageTitle = 'Reports - IoT Smart Water Monitoring';
$pageHeading = 'Reports';

$threshold = FLOW_DETECT_THRESHOLD;
$leakThreshold = getLeakThreshold($conn);
$totalRecords = $conn->query("SELECT COUNT(*) as total FROM flow_logs WHERE flow_rate > " . floatval(FLOW_DETECT_THRESHOLD))->fetch_assoc()['total'] ?? 0;
$avgFlow = $conn->query("SELECT AVG(flow_rate) as avg_flow FROM flow_logs WHERE flow_rate > " . floatval(FLOW_DETECT_THRESHOLD))->fetch_assoc()['avg_flow'] ?? 0;
$maxFlow = $conn->query("SELECT MAX(flow_rate) as max_flow FROM flow_logs WHERE flow_rate > " . floatval(FLOW_DETECT_THRESHOLD))->fetch_assoc()['max_flow'] ?? 0;
$totalUsage = getTotalUsageLiters($conn);
$waterCostPerLiter = getWaterCostPerLiter($conn);
$estimatedCost = $totalUsage * $waterCostPerLiter;
$reportPeriod = $_GET['period'] ?? 'daily';
$reportPeriod = in_array($reportPeriod, ['daily', 'weekly', 'monthly', 'yearly'], true) ? $reportPeriod : 'daily';
$today = new DateTime('today');
if ($reportPeriod === 'daily') $defaultStart = clone $today;
elseif ($reportPeriod === 'weekly') $defaultStart = (clone $today)->modify('monday this week');
elseif ($reportPeriod === 'monthly') $defaultStart = (clone $today)->modify('first day of this month');
else $defaultStart = (clone $today)->modify('first day of January');
$defaultEnd = clone $today;
$startDate = $_GET['start_date'] ?? $defaultStart->format('Y-m-d');
$endDate = $_GET['end_date'] ?? $defaultEnd->format('Y-m-d');
$startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : $defaultStart->format('Y-m-d');
$endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : $defaultEnd->format('Y-m-d');
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}
$endExclusive = date('Y-m-d', strtotime($endDate . ' +1 day'));
$thresholdValue = floatval(FLOW_DETECT_THRESHOLD);
$activityQuery = $conn->prepare("SELECT id, flow_rate, flow_status, created_at FROM flow_logs WHERE flow_rate > ? AND created_at < ? ORDER BY created_at DESC");
$activityQuery->bind_param("ds", $thresholdValue, $endExclusive);
$activityQuery->execute();
$activityResult = $activityQuery->get_result();
$allActivityRows = [];
while ($activityResult && ($activityRow = $activityResult->fetch_assoc())) $allActivityRows[] = $activityRow;
$activityRows = [];
foreach ($allActivityRows as $activityRow) {
    if ($activityRow['created_at'] >= $startDate) $activityRows[] = $activityRow;
}
$filteredRecords = count($activityRows);
$latestReportReadingId = count($allActivityRows) > 0 ? intval($allActivityRows[0]['id']) : 0;
$filteredUsage = 0;
$chronologicalRows = array_reverse($allActivityRows);
$rangeStartTimestamp = strtotime($startDate);
$rangeEndTimestamp = strtotime($endExclusive);
for ($rowIndex = 1; $rowIndex < count($chronologicalRows); $rowIndex++) {
    $previousTimestamp = max($rangeStartTimestamp, strtotime($chronologicalRows[$rowIndex - 1]['created_at']));
    $currentTimestamp = min($rangeEndTimestamp, strtotime($chronologicalRows[$rowIndex]['created_at']));
    $minutes = ($currentTimestamp - $previousTimestamp) / 60;
    if ($minutes > 0) $filteredUsage += (($chronologicalRows[$rowIndex - 1]['flow_rate'] + $chronologicalRows[$rowIndex]['flow_rate']) / 2) * $minutes;
}
$filteredEstimatedCost = $filteredUsage * $waterCostPerLiter;
$exactPeakReading = null;
foreach ($activityRows as $activityRow) {
    if ($exactPeakReading === null || floatval($activityRow['flow_rate']) > floatval($exactPeakReading['flow_rate'])) {
        $exactPeakReading = $activityRow;
    }
}
$hourlyFlow = [];
foreach ($activityRows as $activityRow) {
    $hour = (new DateTime($activityRow['created_at']))->format('H');
    if (!isset($hourlyFlow[$hour])) $hourlyFlow[$hour] = ['hour' => $hour, 'readings' => 0, 'total' => 0, 'peak' => 0, 'peak_id' => intval($activityRow['id'])];
    $hourlyFlow[$hour]['readings']++;
    $hourlyFlow[$hour]['total'] += floatval($activityRow['flow_rate']);
    if (floatval($activityRow['flow_rate']) > $hourlyFlow[$hour]['peak']) {
        $hourlyFlow[$hour]['peak'] = floatval($activityRow['flow_rate']);
        $hourlyFlow[$hour]['peak_id'] = intval($activityRow['id']);
    }
}
foreach ($hourlyFlow as &$hourData) $hourData['average'] = $hourData['total'] / $hourData['readings'];
unset($hourData);
usort($hourlyFlow, function ($firstHour, $secondHour) {
    return $secondHour['peak'] <=> $firstHour['peak'];
});
$peakHour = $hourlyFlow[0] ?? null;

include "includes/header.php";
include "includes/sidebar.php";
?>

<div class="report-controls">
<div class="report-period-tabs">
<?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $key => $label): ?>
<a class="report-period-tab <?php echo $reportPeriod === $key ? 'active' : ''; ?>" href="reports.php?period=<?php echo $key; ?>"><?php echo $label; ?></a>
<?php endforeach; ?>
</div>
<form class="report-date-filter" method="GET">
<input type="hidden" name="period" value="<?php echo htmlspecialchars($reportPeriod, ENT_QUOTES, 'UTF-8'); ?>">
<label>Start Date<input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>"></label>
<label>End Date<input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>"></label>
<button type="submit" class="btn-report-apply"><i class="bi bi-funnel-fill"></i> Apply</button>
<a class="btn-report-print" href="water_lost.php?period=<?php echo htmlspecialchars($reportPeriod, ENT_QUOTES, 'UTF-8'); ?>&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>"><i class="bi bi-droplet-half"></i> Water Lost Report</a>
<button type="button" class="btn-report-print" onclick="window.print()"><i class="bi bi-printer-fill"></i> Print</button>
</form>
</div>

<div class="page-grid-4 report-summary-cards">
<div class="stat-card">
<div class="stat-card-info">
<h6>Total Records</h6>
<h2><?php echo $filteredRecords; ?></h2>
<p>Selected timeframe</p>
</div>
<div class="stat-icon blue"><i class="bi bi-list-ul"></i></div>
</div>
<div class="stat-card">
<div class="stat-card-info">
<h6>Report Date</h6>
<h2><?php echo $startDate === $endDate
    ? date('F j, Y', strtotime($startDate))
    : date('M j, Y', strtotime($startDate)) . ' - ' . date('M j, Y', strtotime($endDate)); ?></h2>
<p>Generated at <?php echo date('h:i A'); ?></p>
</div>
<div class="stat-icon green"><i class="bi bi-graph-up"></i></div>
</div>
<div class="stat-card">
<div class="stat-card-info">
<h6>Filtered Usage</h6>
<h2><?php echo number_format($filteredUsage, 2); ?></h2>
<p>Liters in selected timeframe</p>
</div>
<div class="stat-icon orange"><i class="bi bi-lightning-fill"></i></div>
</div>
<div class="stat-card">
<div class="stat-card-info">
<h6>Estimated Cost</h6>
<h2>₱<?php echo number_format($filteredEstimatedCost, 2); ?></h2>
<p>Based on ₱<?php echo number_format($waterCostPerLiter, 4); ?> per liter</p>
</div>
<div class="stat-icon blue"><i class="bi bi-cash-coin"></i></div>
</div>
</div>

<div class="panel peak-hours-panel">
<div class="panel-header">
<h5>Exact Peak Water Flow</h5>
<span class="text-muted">Highest recorded sensor reading</span>
</div>
<div class="panel-body p-0">
<?php if ($exactPeakReading): ?>
<div class="exact-peak-result">
<div><span>Peak flow</span><strong><?php echo number_format($exactPeakReading['flow_rate'], 2); ?> L/min</strong></div>
<div><span>Recorded at</span><strong><?php echo htmlspecialchars((new DateTime($exactPeakReading['created_at']))->format('F j, Y, h:i A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
<div><span>Reading ID</span><strong>#<?php echo intval($exactPeakReading['id']); ?></strong></div>
</div>
<?php endif; ?>
<div class="peak-hour-heading">Peak hours ranked by highest reading.</div>
<table class="table table-hover mb-0">
<thead><tr><th>Rank</th><th>Hour</th><th>Peak Reading ID</th><th>Average Flow</th><th>Highest Reading</th></tr></thead>
<tbody>
<?php if ($peakHour): ?>
<?php foreach (array_slice($hourlyFlow, 0, 5) as $rank => $hourData): ?>
<tr>
<td><strong><?php echo $rank + 1; ?></strong></td>
<td><?php echo date('g:00 A', strtotime($hourData['hour'] . ':00')); ?> - <?php echo date('g:59 A', strtotime($hourData['hour'] . ':00')); ?></td>
<td>#<?php echo intval($hourData['peak_id']); ?></td>
<td><?php echo number_format($hourData['average'], 2); ?> L/min</td>
<td><?php echo number_format($hourData['peak'], 2); ?> L/min</td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="5" class="text-center text-muted py-4">No peak-hour data for this timeframe.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<div class="panel content-panel">
<div class="panel-header">
<h5>Water Activity Results</h5>
<span class="text-muted">Each row is one sensor reading</span>
</div>
<div class="panel-body p-0 water-activity-scroll">
<table class="table table-hover mb-0">
<thead>
<tr><th>Reading ID</th><th>Date</th><th>Time</th><th>Flow Rate</th><th>Status</th><th>Activity</th></tr>
</thead>
<tbody>
<?php
if (count($activityRows) > 0):
foreach ($activityRows as $row) {
    $storedStatus = isset($row['flow_status']) && $row['flow_status'] !== '' ? strtoupper($row['flow_status']) : null;
    $status = getFlowStatus($row['flow_rate'], $leakThreshold, getLeakDetectionEnabled($conn), $storedStatus);
    $readingDate = new DateTime($row['created_at']);
    echo "<tr>";
    echo "<td>" . intval($row['id']) . "</td>";
    echo "<td>" . $readingDate->format('F j, Y') . "</td>";
    echo "<td>" . $readingDate->format('h:i A') . "</td>";
    echo "<td>" . number_format($row['flow_rate'], 2) . " L/min</td>";
    echo "<td><span class='badge " . htmlspecialchars($status['badge_class'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($status['badge'], ENT_QUOTES, 'UTF-8') . "</span></td>";
    echo "<td>Water flow reading</td>";
    echo "</tr>";
}
else:
?>
<tr><td colspan="6" class="text-center text-muted py-4">No water activities recorded for this period.</td></tr>
<?php
endif;
?>
</tbody>
</table>
</div>
</div>

<script>
(function () {
    var monitorNewReadings = <?php echo $endDate >= date('Y-m-d') ? 'true' : 'false'; ?>;
    var dateFilter = document.querySelector('.report-date-filter');
    var dateFilterDirty = false;
    var activityScroll = document.querySelector('.water-activity-scroll');
    var reloadBlockedUntil = 0;

    if (dateFilter) {
        dateFilter.addEventListener('input', function () {
            dateFilterDirty = true;
        });
        dateFilter.addEventListener('change', function () {
            dateFilterDirty = true;
        });
    }

    if (activityScroll) {
        var suspendReloadFor = function (duration) {
            reloadBlockedUntil = Math.max(reloadBlockedUntil, Date.now() + duration);
        };

        activityScroll.addEventListener('mouseenter', function () {
            suspendReloadFor(5000);
        });
        activityScroll.addEventListener('mouseleave', function () {
            suspendReloadFor(5000);
        });
        activityScroll.addEventListener('wheel', function () {
            suspendReloadFor(5000);
        }, { passive: true });
        activityScroll.addEventListener('scroll', function () {
            suspendReloadFor(5000);
        }, { passive: true });
        activityScroll.addEventListener('touchstart', function () {
            suspendReloadFor(5000);
        }, { passive: true });
        activityScroll.addEventListener('touchmove', function () {
            suspendReloadFor(5000);
        }, { passive: true });
        activityScroll.addEventListener('mousedown', function () {
            suspendReloadFor(5000);
        });
    }

    setInterval(function () {
        var editingDate = document.activeElement && document.activeElement.type === 'date';
        var scrollReloadAllowed = !activityScroll || Date.now() > reloadBlockedUntil;

        if (monitorNewReadings && document.visibilityState === 'visible' && !editingDate && !dateFilterDirty && scrollReloadAllowed) {
            window.location.reload();
        }
    }, 5000);
}());
</script>

<?php include "includes/footer.php"; ?>
