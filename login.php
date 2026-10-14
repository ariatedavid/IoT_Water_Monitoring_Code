<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

include "db.php";

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: index.php");
            exit;
        }

        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in - IoT Smart Water Monitoring</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>" rel="stylesheet">
</head>
<body class="login-body">

<div class="login-wrapper">
<div class="login-card">

<div class="login-header">
<div class="login-icon">
<img src="images/logo.jpg" alt="Letran Logo" width="80" height="80"
     onerror="this.style.display='none';this.parentElement.classList.add('login-icon-fallback');">
<i class="bi bi-droplet-fill"></i>
</div>

<h1 class="login-title">Welcome Back</h1>
<p class="login-subtitle">Sign in to your water monitoring dashboard</p>
</div>

<?php if ($error): ?>
<div class="login-alert"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" class="login-form">
<div class="form-group">
<label for="username">Username</label>
<input type="text" id="username" name="username"
       placeholder="Enter your username"
       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
       required>
</div>

<div class="form-group">
<label for="password">Password</label>
<input type="password" id="password" name="password"
       placeholder="Enter your password" required>
</div>

<button class="btn-login" type="submit">Log in</button>
</form>

<p class="login-footer">Knights Café • Colegio de San Juan de Letran Manila</p>

</div>
</div>

</body>
</html>
