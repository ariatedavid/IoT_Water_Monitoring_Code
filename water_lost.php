<?php
include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

$currentPage = 'water_lost';
$pageTitle = 'Water Lost Report - IoT Smart Water Monitoring';
$pageHeading = 'Water Lost Report';

$waterCostPerLiter = getWaterCostPerLiter($conn);
$reportPeriod = $_GET['period'] ?? 'daily';
$reportPeriod = in_array($reportPeriod, ['daily', 'weekly', 'monthly', 'yearly'], true) ? $reportPeriod : 'daily';

$latestLeakResult = $conn->query(
    "SELECT MAX(created_at) AS latest_leak_at FROM flow_logs
     WHERE flow_rate > " . floatval(FLOW_DETECT_THRESHOLD) . "
       AND UPPER(TRIM(flow_status)) = 'LEAK DETECTED'"
);
$latestLeakRow = $latestLeakResult ? $latestLeakResult->fetch_assoc() : null;
$latestLeakDate = !empty($latestLeakRow['latest_leak_at'])
    ? date('Y-m-d', strtotime($latestLeakRow['latest_leak_at']))
    : null;

$today = new DateTime('today');
if ($reportPeriod === 'daily') {
    $defaultStart = clone $today;
} elseif ($reportPeriod === 'weekly') {
    $defaultStart = (clone $today)->modify('monday this week');
} elseif ($reportPeriod === 'monthly') {
    $defaultStart = (clone $today)->modify('first day of this month');
} else {
    $defaultStart = (clone $today)->modify('first day of January');
}

$defaultEnd = clone $today;
$hasExplicitDateRange = isset($_GET['start_date']) || isset($_GET['end_date']);
if (!$hasExplicitDateRange && $latestLeakDate !== null) {
    $defaultRangeEnd = $defaultEnd->format('Y-m-d');
    $defaultRangeStart = $defaultStart->format('Y-m-d');
    $leaksInDefaultRange = $conn->prepare(
        "SELECT id FROM flow_logs
         WHERE flow_rate > ? AND UPPER(TRIM(flow_status)) = 'LEAK DETECTED'
           AND created_at >= ? AND created_at < ?
         LIMIT 1"
    );
    $defaultStartValue = $defaultRangeStart . ' 00:00:00';
    $defaultEndValue = date('Y-m-d 00:00:00', strtotime($defaultRangeEnd . ' +1 day'));
    $flowThreshold = floatval(FLOW_DETECT_THRESHOLD);
    $leaksInDefaultRange->bind_param('dss', $flowThreshold, $defaultStartValue, $defaultEndValue);
    $leaksInDefaultRange->execute();
    $defaultRangeLeakResult = $leaksInDefaultRange->get_result();

    if (!$defaultRangeLeakResult || $defaultRangeLeakResult->num_rows === 0) {
        $today = new DateTime($latestLeakDate);
        if ($reportPeriod === 'daily') {
            $defaultStart = clone $today;
        } elseif ($reportPeriod === 'weekly') {
            $defaultStart = (clone $today)->modify('monday this week');
        } elseif ($reportPeriod === 'monthly') {
            $defaultStart = (clone $today)->modify('first day of this month');
        } else {
            $defaultStart = (clone $today)->modify('first day of January');
        }
        $defaultEnd = clone $today;
    }
}

$startDate = $_GET['start_date'] ?? $defaultStart->format('Y-m-d');
$endDate = $_GET['end_date'] ?? $defaultEnd->format('Y-m-d');
$startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : $defaultStart->format('Y-m-d');
$endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) ? $endDate : $defaultEnd->format('Y-m-d');
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$endExclusive = date('Y-m-d', strtotime($endDate . ' +1 day'));
$thresholdValue = floatval(FLOW_DETECT_THRESHOLD);

$activityQuery = $conn->prepare(
    "SELECT id, flow_rate, flow_status, created_at FROM flow_logs WHERE flow_rate > ? AND created_at >= ? AND created_at < ? ORDER BY created_at DESC"
);
$activityQuery->bind_param('dss', $thresholdValue, $startDate, $endExclusive);
$activityQuery->execute();
$activityResult = $activityQuery->get_result();

$leakRows = [];
while ($activityResult && ($row = $activityResult->fetch_assoc())) {
    $storedStatus = isset($row['flow_status']) && $row['flow_status'] !== '' ? strtoupper($row['flow_status']) : null;
    $status = getFlowStatus($row['flow_rate'], $thresholdValue, getLeakDetectionEnabled($conn), $storedStatus);
    if ($status['status'] === 'LEAK DETECTED') {
        $leakRows[] = [
            'id' => intval($row['id']),
            'flow_rate' => floatval($row['flow_rate']),
            'created_at' => $row['created_at'],
            'status' => $status['badge'],
        ];
    }
}

$waterLostLiters = getWaterLossLitersByRange($conn, $startDate, $endDate);
$cumulativeWaterLostLiters = getWaterLossLiters($conn);
$cumulativeWaterLostCost = $cumulativeWaterLostLiters * $waterCostPerLiter;
$peakLeakRow = null;
foreach ($leakRows as $row) {
    if ($peakLeakRow === null || $row['flow_rate'] > $peakLeakRow['flow_rate']) {
        $peakLeakRow = $row;
    }
}

$breakdown = [];
$chartLabels = [];
$chartValues = [];
$selectedStart = new DateTime($startDate);
$selectedEnd = new DateTime($endDate);
$periodCursor = clone $selectedStart;

if ($reportPeriod === 'daily') {
    while ($periodCursor <= $selectedEnd) {
        $dayLabel = $periodCursor->format('Y-m-d');
        $dayEnd = (clone $periodCursor)->modify('+1 day');
        $dayLiters = getWaterLossLitersByRange($conn, $dayLabel, $dayLabel);
        $breakdown[] = [
            'label' => date('F j, Y', strtotime($dayLabel)),
            'start' => $dayLabel,
            'end' => $dayLabel,
            'liters' => $dayLiters,
            'cost' => $dayLiters * $waterCostPerLiter,
        ];
        $chartLabels[] = date('M j, Y', strtotime($dayLabel));
        $chartValues[] = round((float) $dayLiters, 2);
        $periodCursor = $dayEnd;
    }
} elseif ($reportPeriod === 'weekly') {
    while ($periodCursor <= $selectedEnd) {
        $weekStart = (clone $periodCursor)->modify('monday this week');
        if ($weekStart < $selectedStart) {
            $weekStart = clone $selectedStart;
        }
        $weekEnd = (clone $weekStart)->modify('+6 days');
        if ($weekEnd > $selectedEnd) {
            $weekEnd = clone $selectedEnd;
        }
        $weekLiters = getWaterLossLitersByRange($conn, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'));
        $breakdown[] = [
            'label' => $weekStart->format('M j') . ' - ' . $weekEnd->format('M j, Y'),
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekEnd->format('Y-m-d'),
            'liters' => $weekLiters,
            'cost' => $weekLiters * $waterCostPerLiter,
        ];
        $chartLabels[] = $weekStart->format('M j') . ' - ' . $weekEnd->format('M j');
        $chartValues[] = round((float) $weekLiters, 2);
        $periodCursor = (clone $weekEnd)->modify('+1 day');
    }
} elseif ($reportPeriod === 'monthly') {
    $monthCursor = new DateTime($selectedStart->format('Y-m-01'));
    $limitMonth = new DateTime($selectedEnd->format('Y-m-01'));
    while ($monthCursor <= $limitMonth) {
        $monthStart = clone $monthCursor;
        $monthEnd = (clone $monthCursor)->modify('last day of this month');
        if ($monthEnd > $selectedEnd) {
            $monthEnd = clone $selectedEnd;
        }
        $monthLiters = getWaterLossLitersByRange($conn, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
        $breakdown[] = [
            'label' => $monthStart->format('F Y'),
            'start' => $monthStart->format('Y-m-d'),
            'end' => $monthEnd->format('Y-m-d'),
            'liters' => $monthLiters,
            'cost' => $monthLiters * $waterCostPerLiter,
        ];
        $chartLabels[] = $monthStart->format('M Y');
        $chartValues[] = round((float) $monthLiters, 2);
        $monthCursor->modify('+1 month');
    }
} else {
    $yearCursor = new DateTime($selectedStart->format('Y-01-01'));
    $limitYear = new DateTime($selectedEnd->format('Y-01-01'));
    while ($yearCursor <= $limitYear) {
        $yearStart = clone $yearCursor;
        $yearEnd = (clone $yearCursor)->modify('last day of December this year');
        if ($yearEnd > $selectedEnd) {
            $yearEnd = clone $selectedEnd;
        }
        $yearLiters = getWaterLossLitersByRange($conn, $yearStart->format('Y-m-d'), $yearEnd->format('Y-m-d'));
        $breakdown[] = [
            'label' => $yearStart->format('Y'),
            'start' => $yearStart->format('Y-m-d'),
            'end' => $yearEnd->format('Y-m-d'),
            'liters' => $yearLiters,
            'cost' => $yearLiters * $waterCostPerLiter,
        ];
        $chartLabels[] = $yearStart->format('Y');
        $chartValues[] = round((float) $yearLiters, 2);
        $yearCursor->modify('+1 year');
    }
}

$extraHead = '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
$extraScripts = '';

include "includes/header.php";
include "includes/sidebar.php";
?>

<div class="report-controls">
    <div class="report-period-tabs">
        <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $key => $label): ?>
            <a class="report-period-tab <?php echo $reportPeriod === $key ? 'active' : ''; ?>" href="water_lost.php?period=<?php echo $key; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>
    <form class="report-date-filter" method="GET">
        <input type="hidden" name="period" value="<?php echo htmlspecialchars($reportPeriod, ENT_QUOTES, 'UTF-8'); ?>">
        <label>Start Date<input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>"></label>
        <label>End Date<input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>"></label>
        <button type="submit" class="btn-report-apply"><i class="bi bi-funnel-fill"></i> Apply</button>
        <a class="btn-report-print" href="reports.php?period=<?php echo htmlspecialchars($reportPeriod, ENT_QUOTES, 'UTF-8'); ?>&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>"><i class="bi bi-bar-chart-fill"></i> Back to Reports</a>
    </form>
</div>

<div class="page-grid-4 report-summary-cards">
    <div class="stat-card">
        <div class="stat-card-info">
            <h6>Total Water Lost</h6>
            <h2><?php echo number_format($cumulativeWaterLostLiters, 2); ?></h2>
            <p>Cumulative total, matching the dashboard</p>
        </div>
        <div class="stat-icon red"><i class="bi bi-droplet-half"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <h6>Total Estimated Loss</h6>
            <h2>₱<?php echo number_format($cumulativeWaterLostCost, 2); ?></h2>
            <p>Cumulative cost at ₱<?php echo number_format($waterCostPerLiter, 4); ?> per liter</p>
        </div>
        <div class="stat-icon orange"><i class="bi bi-cash-coin"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <h6>Leak Events</h6>
            <h2><?php echo count($leakRows); ?></h2>
            <p>Detected leak readings</p>
        </div>
        <div class="stat-icon purple"><i class="bi bi-exclamation-triangle-fill"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-info">
            <h6>Range</h6>
            <h2><?php echo $startDate === $endDate ? date('M j', strtotime($startDate)) : date('M j', strtotime($startDate)) . ' - ' . date('M j', strtotime($endDate)); ?></h2>
            <p><?php echo getLeakDetectionEnabled($conn) ? 'Leak detection active' : 'Leak detection disabled'; ?></p>
        </div>
        <div class="stat-icon blue"><i class="bi bi-calendar3"></i></div>
    </div>
</div>

<div class="panel peak-hours-panel">
    <div class="panel-header">
        <h5>Peak Leak Reading</h5>
        <span class="text-muted">Highest leak flow in this period</span>
    </div>
    <div class="panel-body p-0">
        <?php if ($peakLeakRow): ?>
            <div class="exact-peak-result">
                <div><span>Peak leak flow</span><strong><?php echo number_format($peakLeakRow['flow_rate'], 2); ?> L/min</strong></div>
                <div><span>Recorded at</span><strong><?php echo htmlspecialchars((new DateTime($peakLeakRow['created_at']))->format('F j, Y, h:i A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Reading ID</span><strong>#<?php echo intval($peakLeakRow['id']); ?></strong></div>
            </div>
        <?php else: ?>
            <div class="exact-peak-result">
                <div><span>No recorded leak</span><strong>0.00 L/min</strong></div>
                <div><span>Range</span><strong><?php echo htmlspecialchars($startDate . ' to ' . $endDate, ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div><span>Status</span><strong>Clear</strong></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="panel content-panel">
    <div class="panel-header">
            <h5>Water Lost Trend</h5>
        <span class="text-muted">Selected-period trend: <?php echo htmlspecialchars($reportPeriod, ENT_QUOTES, 'UTF-8'); ?> intervals</span>
    </div>
    <div class="panel-body" style="height: 260px;">
        <canvas id="waterLostTrendChart"></canvas>
    </div>
</div>

<div class="panel content-panel">
    <div class="panel-header">
        <h5>Leak Loss Breakdown</h5>
        <span class="text-muted">Selected-period totals by <?php echo htmlspecialchars($reportPeriod, ENT_QUOTES, 'UTF-8'); ?> interval</span>
    </div>
    <div class="panel-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Water Lost</th>
                    <th>Estimated Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($breakdown) > 0): ?>
                    <?php foreach ($breakdown as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format($item['liters'], 2); ?> L</td>
                            <td>₱<?php echo number_format($item['cost'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">No leak-loss data available for this report period.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel content-panel">
    <div class="panel-header">
        <h5>Leak Activity Results</h5>
        <span class="text-muted">Filtered leak readings for the selected timeframe</span>
    </div>
    <div class="panel-body p-0 water-activity-scroll">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Reading ID</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Flow Rate</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($leakRows) > 0): ?>
                    <?php foreach ($leakRows as $row): ?>
                        <?php $readingDate = new DateTime($row['created_at']); ?>
                        <tr>
                            <td>#<?php echo intval($row['id']); ?></td>
                            <td><?php echo $readingDate->format('F j, Y'); ?></td>
                            <td><?php echo $readingDate->format('h:i A'); ?></td>
                            <td><?php echo number_format($row['flow_rate'], 2); ?> L/min</td>
                            <td><span class="badge bg-danger"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No leak activity recorded for this period.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const waterLostLabels = <?php echo json_encode($chartLabels); ?>;
const waterLostValues = <?php echo json_encode($chartValues); ?>;

const waterLostCtx = document.getElementById('waterLostTrendChart');
if (waterLostCtx) {
    new Chart(waterLostCtx, {
        type: 'line',
        data: {
            labels: waterLostLabels,
            datasets: [{
                label: 'Water Lost (L)',
                data: waterLostValues,
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.18)',
                borderWidth: 3,
                pointBackgroundColor: '#ef4444',
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.35
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Water Lost: ' + context.parsed.y.toFixed(2) + ' L';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Liters'
                    },
                    ticks: {
                        callback: function(value) {
                            return value + 'L';
                        }
                    }
                }
            }
        }
    });
}
</script>

<?php include "includes/footer.php"; ?>
