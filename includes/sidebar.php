<?php
$currentPage = $currentPage ?? 'dashboard';

$navItems = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'index.php', 'icon' => 'bi-grid-1x2-fill'],
    'alerts'    => ['label' => 'Alerts', 'href' => 'alerts.php', 'icon' => 'bi-bell-fill'],
    'reports'   => ['label' => 'Reports', 'href' => 'reports.php', 'icon' => 'bi-bar-chart-fill'],
    'water_lost' => ['label' => 'Water Lost', 'href' => 'water_lost.php', 'icon' => 'bi-droplet-half'],
    'threshold' => ['label' => 'Threshold', 'href' => 'threshold.php', 'icon' => 'bi-sliders'],
    'solenoid'  => ['label' => 'Solenoid Valve', 'href' => 'solenoid.php', 'icon' => 'bi-funnel-fill'],
    'device'    => ['label' => 'Device Status', 'href' => 'device.php', 'icon' => 'bi-cpu-fill'],
];
?>

<aside class="sidebar">

<div class="sidebar-brand">
<?php include __DIR__ . '/brand_logo.php'; ?>
<div class="brand-text">
<strong>Water Monitoring</strong>
<span>Letran IoT System</span>
</div>
</div>

<nav class="sidebar-nav">
<div class="nav-section-label">Home</div>
<ul>
<?php foreach ($navItems as $key => $item): ?>
<li>
<a class="nav-link<?php echo ($currentPage === $key) ? ' active' : ''; ?>"
   href="<?php echo $item['href']; ?>">
<i class="bi <?php echo $item['icon']; ?>"></i>
<span><?php echo $item['label']; ?></span>
</a>
</li>
<?php endforeach; ?>
</ul>
</nav>

<div class="sidebar-bottom">
<a class="nav-link" href="logout.php">
<i class="bi bi-box-arrow-right"></i>
<span>Logout</span>
</a>

<div class="sidebar-promo">
<div class="promo-badge"><i class="bi bi-droplet-fill"></i></div>
<div class="promo-content">
<span class="promo-kicker">Executive view</span>
<strong>Periodic Water Monitoring</strong>
</div>
</div>
</div>

</aside>

<main class="main-content">

<header class="page-header">
<div class="page-header-left">
<div class="page-kicker">Operations overview</div>
<h1><?php echo htmlspecialchars($pageHeading); ?></h1>
</div>
<div class="page-header-right">
<div class="header-date-wrap">
<div class="header-date-label">Live clock</div>
<div class="header-date" id="clock"></div>
</div>
<a href="logout.php" class="btn-header">
<i class="bi bi-box-arrow-right"></i> Logout
</a>
<div class="header-avatar">
<img src="images/logo.jpg" alt="Profile" width="42" height="42"
     onerror="this.style.display='none';this.parentElement.classList.add('header-avatar-fallback');">
<i class="bi bi-person-fill"></i>
</div>
</div>
</header>

<div class="page-body">
