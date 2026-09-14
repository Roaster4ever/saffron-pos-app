<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-Content-Type-Options" content="nosniff">
<meta http-equiv="X-Frame-Options" content="SAMEORIGIN">
<title><?= e($pageTitle ?? 'POS') ?> — <?= SHOP_NAME ?></title>
<?php if (getenv('BUKHARI_DESKTOP') === '1'): ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/fonts-local.css">
<?php else: ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap"></noscript>
<?php endif; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
<link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>/favicon.ico?v=2">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <span class="brand-icon">&#9670;</span>
      <span class="brand-name"><?= SHOP_NAME ?></span>
    </div>
    <nav class="sidebar-nav">
      <a href="<?= BASE_URL ?>/index.php" class="nav-item <?= ($activePage??'')==='dashboard'?'active':'' ?>">
        <span class="nav-icon">&#9638;</span><span>Dashboard</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/pos.php" class="nav-item <?= ($activePage??'')==='pos'?'active':'' ?>">
        <span class="nav-icon">&#10070;</span><span>POS</span>
      </a>
      <div class="nav-section">Sales</div>
      <a href="<?= BASE_URL ?>/pages/sales.php" class="nav-item <?= ($activePage??'')==='sales'?'active':'' ?>">
        <span class="nav-icon">&#9776;</span><span>Sales</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/quotations.php" class="nav-item <?= ($activePage??'')==='quotations'?'active':'' ?>">
        <span class="nav-icon">&#9998;</span><span>Quotations</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/deliveries.php" class="nav-item <?= ($activePage??'')==='deliveries'?'active':'' ?>">
        <span class="nav-icon">&#9829;</span><span>Deliveries</span>
      </a>
      <div class="nav-section">Inventory</div>
      <a href="<?= BASE_URL ?>/pages/products.php" class="nav-item <?= ($activePage??'')==='products'?'active':'' ?>">
        <span class="nav-icon">&#9635;</span><span>Products</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/brands.php" class="nav-item <?= ($activePage??'')==='brands'?'active':'' ?>">
        <span class="nav-icon">&#9830;</span><span>Brands</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/categories.php" class="nav-item <?= ($activePage??'')==='categories'?'active':'' ?>">
        <span class="nav-icon">&#9641;</span><span>Categories</span>
      </a>
      <div class="nav-section">Business</div>
      <a href="<?= BASE_URL ?>/pages/customers.php" class="nav-item <?= ($activePage??'')==='customers'?'active':'' ?>">
        <span class="nav-icon">&#9823;</span><span>Customers</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/suppliers.php" class="nav-item <?= ($activePage??'')==='suppliers'?'active':'' ?>">
        <span class="nav-icon">&#9824;</span><span>Suppliers</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/orders.php" class="nav-item <?= ($activePage??'')==='orders'?'active':'' ?>">
        <span class="nav-icon">&#9641;</span><span>Purchase Orders</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/receivables.php" class="nav-item <?= ($activePage??'')==='receivables'?'active':'' ?>">
        <span class="nav-icon">&#9733;</span><span>Receivables</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/expenses.php" class="nav-item <?= ($activePage??'')==='expenses'?'active':'' ?>">
        <span class="nav-icon">&#9632;</span><span>Expenses</span>
      </a>
      <?php if(isAdmin()): ?>
      <div class="nav-section">Admin</div>
      <a href="<?= BASE_URL ?>/pages/reports.php" class="nav-item <?= ($activePage??'')==='reports'?'active':'' ?>">
        <span class="nav-icon">&#9879;</span><span>Reports</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/tax_bulk.php" class="nav-item <?= ($activePage??'')==='tax_bulk'?'active':'' ?>">
        <span class="nav-icon">&#37;</span><span>Tax Settings</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/data_management.php" class="nav-item <?= ($activePage??'')==='data_management'?'active':'' ?>">
        <span class="nav-icon">&#9881;</span><span>Data & Backup</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/users.php" class="nav-item <?= ($activePage??'')==='users'?'active':'' ?>">
        <span class="nav-icon">&#9822;</span><span>Users</span>
      </a>
      <a href="<?= BASE_URL ?>/pages/sessions.php" class="nav-item <?= ($activePage??'')==='sessions'?'active':'' ?>">
        <span class="nav-icon">&#9881;</span><span>Sessions</span>
      </a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="user-chip">
        <span class="user-avatar"><?= strtoupper(substr($_SESSION['user_name']??'U',0,1)) ?></span>
        <div>
          <div class="user-name"><?= e($_SESSION['user_name']??'') ?></div>
          <div class="user-role"><?= e($_SESSION['user_role']??'') ?></div>
        </div>
      </div>
      <a href="<?= BASE_URL ?>/logout.php" class="btn-logout">Logout</a>
    </div>
  </aside>
  <main class="main-content">
    <?php if(isset($_GET['msg'])): ?>
      <div class="alert alert-success"><?= e($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['error'])): ?>
      <div class="alert alert-danger"><?= e($_GET['error']) ?></div>
    <?php endif; ?>
