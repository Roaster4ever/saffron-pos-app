<?php
require_once __DIR__ . '/includes/config.php';
http_response_code(500);
$pageTitle = 'Server Error';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>500 — <?= SHOP_NAME ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card" style="text-align:center">
    <div class="login-logo">
      <span class="login-logo-icon">◈</span>
      <div class="login-logo-text"><?= SHOP_NAME ?></div>
    </div>
    <div style="font-size:64px;font-weight:800;color:var(--red);margin:20px 0 10px">500</div>
    <div style="font-size:16px;color:var(--text2);margin-bottom:8px">Something went wrong on our end.</div>
    <div style="font-size:13px;color:var(--text3);margin-bottom:20px">The error has been logged. Please try again or contact support if the problem persists.</div>
    <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Back to Dashboard</a>
  </div>
</div>
</body>
</html>
