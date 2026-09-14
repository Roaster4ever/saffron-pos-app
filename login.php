<?php
require_once __DIR__ . '/includes/config.php';
if (isset($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/index.php'); exit; }

$isInit = getSetting('app_initialized', '0');
if ($isInit === '0' || $isInit === 0 || $isInit === false || $isInit === null) {
    header('Location: ' . BASE_URL . '/setup.php');
    exit;
}

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$maxAttempts = 5;
$lockoutSeconds = 300;

$stmt = $conn->prepare("SELECT attempt_count, first_attempt FROM login_attempts WHERE ip_address = ? AND first_attempt > ?");
$cutoff = time() - $lockoutSeconds;
$stmt->bind_param("si", $ip, $cutoff);
$stmt->execute();
$attempts = $stmt->get_result()->fetch_assoc();
if ($attempts && $attempts['attempt_count'] >= $maxAttempts) {
    $error = 'Too many failed attempts. Try again in ' . ceil(($lockoutSeconds - (time() - $attempts['first_attempt'])) / 60) . ' minutes.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    if (!csrf_verify()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username && $password) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user && password_verify($password, $user['password'])) {
                $conn->query("DELETE FROM login_attempts WHERE ip_address = '" . $conn->real_escape_string($ip) . "'");
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $stmtClose = $conn->prepare("UPDATE user_sessions SET sign_out = NOW(), duration = CONCAT(FLOOR(TIMESTAMPDIFF(SECOND, sign_in, NOW()) / 3600), 'h ', FLOOR(MOD(TIMESTAMPDIFF(SECOND, sign_in, NOW()), 3600) / 60), 'm') WHERE user_id = ? AND sign_out IS NULL");
                $stmtClose->bind_param("i", $user['id']);
                $stmtClose->execute();
                $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '';
                $stmt2 = $conn->prepare("INSERT INTO user_sessions (user_id, username, sign_in, ip_address) VALUES (?, ?, NOW(), ?)");
                $stmt2->bind_param("iss", $user['id'], $user['username'], $ipAddr);
                $stmt2->execute();
                $_SESSION['session_id'] = $conn->insert_id;
                header('Location: ' . BASE_URL . '/index.php');
                exit;
            } else {
                $safeIp = $conn->real_escape_string($ip);
                $conn->query("INSERT INTO login_attempts (ip_address, attempt_count, first_attempt) VALUES ('$safeIp', 1, " . time() . ") ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1");
                $error = 'Invalid username or password.';
            }
        } else {
            $error = 'Please fill in all fields.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — <?= SHOP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <span class="login-logo-icon">◈</span>
      <div class="login-logo-text"><?= SHOP_NAME ?></div>
      <div class="text-muted" style="font-size:12px;margin-top:4px">Point of Sale System</div>
    </div>
    <?php if(isset($_GET['msg'])): ?>
      <div class="alert alert-success"><?= e(str_replace('+', ' ', $_GET['msg'])) ?></div>
    <?php endif; ?>
    <?php if($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" class="form-control" placeholder="Enter username" autofocus required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
      </div>
      <button type="submit" class="btn btn-primary login-btn">Sign In</button>
    </form>
  </div>
</div>
</body>
</html>
