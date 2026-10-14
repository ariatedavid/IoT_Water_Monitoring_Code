<?php
require_once __DIR__ . '/../includes/flow_helpers.php';

function assertApproximatelyEquals($label, $actual, $expected, $epsilon = 0.05) {
    $difference = abs(floatval($actual) - floatval($expected));
    if ($difference > $epsilon) {
        fwrite(STDERR, "FAIL: {$label} | expected={$expected} actual={$actual} diff={$difference}\n");
        exit(1);
    }
    echo "PASS: {$label} -> {$actual}\n";
}

function assertTrue($label, $condition) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$settings = [
    'flow_rate_calibration' => 1.0,
    'zero_flow_threshold' => 0.02,
    'max_valid_sampling_gap' => 7200,
    'max_integration_interval' => 7200,
    'max_flow_rate' => 30.0,
    'max_rate_of_change' => 10.0,
    'min_valid_flow_rate' => 0.0,
];

$cases = [
    ['1 L/min for 60 seconds = 1 L', [[1.0, '2024-01-01 00:00:00'], [1.0, '2024-01-01 00:01:00']], 1.0],
    ['10 L/min for 60 seconds = 10 L', [[10.0, '2024-01-01 00:00:00'], [10.0, '2024-01-01 00:01:00']], 10.0],
    ['10 L/min for 10 minutes = 100 L', [[10.0, '2024-01-01 00:00:00'], [10.0, '2024-01-01 00:10:00']], 100.0],
    ['0 L/min for 24 hours = 0 L', [[0.0, '2024-01-01 00:00:00'], [0.0, '2024-01-01 00:00:00'], [0.0, '2024-01-02 00:00:00']], 0.0],
    ['0.5 L/min for 2 hours = 60 L', [[0.5, '2024-01-01 00:00:00'], [0.5, '2024-01-01 02:00:00']], 60.0],
    ['Duplicate sensor reading does not double consumption', [[1.0, '2024-01-01 00:00:00'], [1.0, '2024-01-01 00:00:30'], [1.0, '2024-01-01 00:01:00']], 1.0],
    ['10-minute communication gap does not incorrectly add 10 minutes of flow', [[1.0, '2024-01-01 00:00:00'], [1.0, '2024-01-01 00:20:00']], 0.0, ['max_valid_sampling_gap' => 600]],
    ['Sensor restart does not create a huge consumption spike', [[0.0, '2024-01-01 00:00:00'], [0.0, '2024-01-01 00:01:00'], [1.0, '2024-01-01 00:02:00'], [1.0, '2024-01-01 00:03:00']], 1.5],
    ['Counter rollover/reset is handled correctly', [[5.0, '2024-01-01 00:00:00'], [2.0, '2024-01-01 00:01:00'], [2.0, '2024-01-01 00:02:00']], 5.5],
    ['20-liter calibration run produces approximately 20 liters after calibration', [[20.0, '2024-01-01 00:00:00'], [20.0, '2024-01-01 00:01:00']], 20.0],
];

foreach ($cases as $case) {
    [$label, $samples, $expected] = $case;
    $caseSettings = $settings;
    if (isset($case[3]) && is_array($case[3])) {
        $caseSettings = array_merge($caseSettings, $case[3]);
    }
    $rows = [];
    foreach ($samples as $sample) {
        $rows[] = ['flow_rate' => $sample[0], 'created_at' => $sample[1]];
    }
    $actual = calculateConsumptionFromSamples($rows, $caseSettings);
    assertApproximatelyEquals($label, $actual, $expected, 0.1);
}

$invalid = [
    ['flow_rate' => -2.0, 'created_at' => '2024-01-01 00:00:00'],
    ['flow_rate' => 1000.0, 'created_at' => '2024-01-01 00:01:00'],
    ['flow_rate' => 1.0, 'created_at' => '2024-01-01 00:00:00'],
    ['flow_rate' => 1.0, 'created_at' => '2024-01-01 00:00:00'],
];

assertTrue('Invalid readings are rejected', calculateConsumptionFromSamples($invalid, $settings) === 0.0);

echo "All flow calculation tests passed.\n";
