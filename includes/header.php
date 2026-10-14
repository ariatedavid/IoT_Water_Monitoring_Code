<?php
$pageTitle = $pageTitle ?? 'IoT-Based Smart Water Monitoring System';
$pageHeading = $pageHeading ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? 'Knights Café • Colegio de San Juan de Letran Manila';
$extraHead = $extraHead ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="style.css?v=<?php echo filemtime(__DIR__ . '/../style.css'); ?>" rel="stylesheet">
<?php echo $extraHead; ?>
</head>
<body>

<div class="app-shell">
