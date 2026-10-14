<?php

include "auth.php";
include "db.php";
include "includes/flow_helpers.php";

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_GET['check'] ?? '') === '1') {
	echo json_encode(getDashboardVersions($conn));
	exit;
}

$calendarYear = intval($_GET['calendar_year'] ?? date('Y'));
$calendarMonth = intval($_GET['calendar_month'] ?? date('n'));
if ($calendarYear < 2000 || $calendarYear > 2100) $calendarYear = intval(date('Y'));
if ($calendarMonth < 1 || $calendarMonth > 12) $calendarMonth = intval(date('n'));
$includeUsageSummary = ($_GET['live'] ?? '') !== '1';

echo json_encode(buildDashboardData($conn, $calendarYear, $calendarMonth, $includeUsageSummary));
