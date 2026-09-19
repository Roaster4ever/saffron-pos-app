<?php
// setup.php — First-run setup wizard
// session handled by config.php
require_once __DIR__ . '/includes/config.php';

// Check if already initialized
$isInit = getSetting('app_initialized', '0');
if ($isInit === '1' || $isInit === 1 || $isInit === true) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shopName     = trim($_POST['shop_name'] ?? '');
    $shopAddress  = trim($_POST['shop_address'] ?? '');
    $shopPhone    = trim($_POST['shop_phone'] ?? '');
    $shopWhatsapp = trim($_POST['shop_whatsapp'] ?? '');
    $shopEmail    = trim($_POST['shop_email'] ?? '');
    $currency     = trim($_POST['currency'] ?? 'Rs: ');
    $taxRate      = floatval($_POST['tax_rate'] ?? 18);
    $adminName    = trim($_POST['admin_name'] ?? '');
    $adminUser    = trim($_POST['admin_username'] ?? '');
    $adminPass    = $_POST['admin_password'] ?? '';
    $adminPass2   = $_POST['admin_password2'] ?? '';
    $invoiceFooter = trim($_POST['invoice_footer'] ?? 'Thank you for your business!');

    // Validation
    if (!$shopName) { $error = 'Shop name is required'; }
    elseif (!$adminName) { $error = 'Admin name is required'; }
    elseif (!$adminUser || strlen($adminUser) < 3) { $error = 'Username must be at least 3 characters'; }
    elseif (strlen($adminPass) < 6) { $error = 'Password must be at least 6 characters'; }
    elseif ($adminPass !== $adminPass2) { $error = 'Passwords do not match'; }
    else {
        // Check username uniqueness
        $stmt = $conn->prepare("SELECT id FROM users WHERE username=?");
        $stmt->bind_param("s", $adminUser);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Username already exists';
        } else {
            $conn->begin_transaction();
            try {
                // Update shop settings
                setSetting($conn, 'shop_name', $shopName);
                setSetting($conn, 'shop_address', $shopAddress);
                setSetting($conn, 'shop_phone', $shopPhone);
                setSetting($conn, 'shop_whatsapp', $shopWhatsapp);
                setSetting($conn, 'shop_email', $shopEmail);
                setSetting($conn, 'currency', $currency);
                setSetting($conn, 'default_tax_rate', (string)$taxRate);
                setSetting($conn, 'invoice_footer', $invoiceFooter);

                // Delete default admin and create new one
                $conn->query("DELETE FROM users WHERE username='admin'");
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt2 = $conn->prepare("INSERT INTO users (name, username, password, role) VALUES (?,?,?,'admin')");
                $stmt2->bind_param("sss", $adminName, $adminUser, $hash);
                $stmt2->execute();

                // Mark as initialized
                setSetting($conn, 'app_initialized', '1');

                auditLog($conn, 'app_setup', 'system', null, [
                    'shop_name' => $shopName,
                    'admin_user' => $adminUser,
                ]);

                $conn->commit();
                header('Location: ' . BASE_URL . '/login.php?msg=Setup+complete.+Please+sign+in.');
                exit;
            } catch (Exception $ex) {
                $conn->rollback();
                error_log('SETUP ERROR: ' . $ex->getMessage());
                $error = 'Setup failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — Saffron POS</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card" style="max-width:480px">
    <div class="login-logo">
      <span class="login-logo-icon">&#9670;</span>
      <div class="login-logo-text">Saffron POS</div>
      <div class="text-muted" style="font-size:12px;margin-top:4px">First-Time Setup</div>
    </div>

    <div style="background:rgba(232,255,58,.08);border:1px solid rgba(232,255,58,.25);border-radius:4px;padding:10px 14px;margin-bottom:16px;font-size:12px;color:var(--accent)">
      This setup will configure your shop details and create a new admin account.
    </div>

    <?php if($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div style="font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Shop Details</div>
      <div class="form-group" style="margin-bottom:10px">
        <label>Shop Name *</label>
        <input type="text" name="shop_name" class="form-control" placeholder="e.g. Saffron Sanitary & Hardware" value="<?= htmlspecialchars($_POST['shop_name'] ?? '') ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:10px">
        <label>Address</label>
        <input type="text" name="shop_address" class="form-control" placeholder="Shop address" value="<?= htmlspecialchars($_POST['shop_address'] ?? '') ?>">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div class="form-group">
          <label>Phone</label>
          <input type="text" name="shop_phone" class="form-control" placeholder="0300-1234567" value="<?= htmlspecialchars($_POST['shop_phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>WhatsApp</label>
          <input type="text" name="shop_whatsapp" class="form-control" placeholder="WhatsApp number" value="<?= htmlspecialchars($_POST['shop_whatsapp'] ?? '') ?>">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="shop_email" class="form-control" placeholder="shop@example.com" value="<?= htmlspecialchars($_POST['shop_email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Default Tax Rate (%)</label>
          <input type="number" name="tax_rate" class="form-control" value="<?= floatval($_POST['tax_rate'] ?? 18) ?>" min="0" max="100" step="0.5">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
        <div class="form-group">
          <label>Currency Symbol</label>
          <input type="text" name="currency" class="form-control" placeholder="Rs: " value="<?= htmlspecialchars($_POST['currency'] ?? 'Rs: ') ?>">
        </div>
        <div class="form-group">
          <label>Invoice Footer</label>
          <input type="text" name="invoice_footer" class="form-control" placeholder="Thank you message" value="<?= htmlspecialchars($_POST['invoice_footer'] ?? 'Thank you for your business!') ?>">
        </div>
      </div>

      <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px">
        <div style="font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Admin Account</div>
        <div class="form-group" style="margin-bottom:10px">
          <label>Your Name</label>
          <input type="text" name="admin_name" class="form-control" placeholder="Full name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required>
        </div>
        <div class="form-group" style="margin-bottom:10px">
          <label>Username *</label>
          <input type="text" name="admin_username" class="form-control" placeholder="Choose a username (min 3 chars)" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" required minlength="3">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
          <div class="form-group">
            <label>Password *</label>
            <input type="password" name="admin_password" class="form-control" placeholder="Min 6 characters" required minlength="6">
          </div>
          <div class="form-group">
            <label>Confirm Password *</label>
            <input type="password" name="admin_password2" class="form-control" placeholder="Re-enter password" required minlength="6">
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary login-btn">Complete Setup &amp; Start</button>
    </form>
  </div>
</div>
</body>
</html>
