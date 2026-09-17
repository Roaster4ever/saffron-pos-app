<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
http_response_code(404);
$pageTitle = 'Page Not Found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 — <?= SHOP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card" style="text-align:center">
    <div class="login-logo">
      <span class="login-logo-icon">◈</span>
      <div class="login-logo-text"><?= SHOP_NAME ?></div>
    </div>
    <div style="font-size:64px;font-weight:800;color:var(--accent);margin:20px 0 10px">404</div>
    <div style="font-size:16px;color:var(--text2);margin-bottom:20px">The page you're looking for doesn't exist or has been moved.</div>
    <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Back to Dashboard</a>
  </div>
</div>
</body>
</html>
