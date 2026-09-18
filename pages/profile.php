<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$uid = $_SESSION['user_id'];
$error = '';
$success = '';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $error = 'Invalid security token.'; }
    else {
        $act = $_POST['action'] ?? '';

        if ($act === 'update_name') {
            $name = trim($_POST['name'] ?? '');
            if (!$name || strlen($name) < 2) {
                $error = 'Name must be at least 2 characters.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET name=? WHERE id=?");
                $stmt->bind_param("si", $name, $uid);
                $stmt->execute();
                $_SESSION['user_name'] = $name;
                $success = 'Name updated.';
            }
        }

        if ($act === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!$current || !$new) {
                $error = 'All password fields are required.';
            } elseif ($new !== $confirm) {
                $error = 'New passwords do not match.';
            } elseif (strlen($new) < 6) {
                $error = 'New password must be at least 6 characters.';
            } else {
                // Verify current password
                $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                if (!password_verify($current, $user['password'])) {
                    $error = 'Current password is incorrect.';
                } else {
                    $pass = password_hash($new, PASSWORD_DEFAULT);
                    $stmt2 = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                    $stmt2->bind_param("si", $pass, $uid);
                    $stmt2->execute();
                    auditLog($conn, 'password_change', 'user', $uid, ['user_id' => $uid]);
                    $success = 'Password changed successfully.';
                }
            }
        }
    }
}

// Fetch user info
$stmt = $conn->prepare("SELECT id, name, username, role, created_at FROM users WHERE id=?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$pageTitle = 'My Profile';
$activePage = 'profile';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">My Profile</div>
    <div class="page-subtitle">Manage your account settings</div>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:800px">

  <!-- Profile Info -->
  <div class="table-card">
    <div style="padding:20px">
      <h3 style="font-size:14px;font-weight:600;margin-bottom:16px">Profile Information</h3>
      <div style="margin-bottom:12px">
        <span class="text-muted" style="font-size:12px">Username</span>
        <div class="text-mono" style="font-size:14px"><?= e($user['username']) ?></div>
      </div>
      <div style="margin-bottom:12px">
        <span class="text-muted" style="font-size:12px">Role</span>
        <div><span class="badge badge-blue"><?= ucfirst($user['role']) ?></span></div>
      </div>
      <div style="margin-bottom:16px">
        <span class="text-muted" style="font-size:12px">Member since</span>
        <div style="font-size:13px"><?= e($user['created_at']) ?></div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_name">
        <div class="form-group">
          <label>Display Name</label>
          <input name="name" class="form-control" value="<?= e($user['name']) ?>" required>
        </div>
        <button type="submit" class="btn btn-primary">Update Name</button>
      </form>
    </div>
  </div>

  <!-- Change Password -->
  <div class="table-card">
    <div style="padding:20px">
      <h3 style="font-size:14px;font-weight:600;margin-bottom:16px">Change Password</h3>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
          <label>Current Password</label>
          <input name="current_password" type="password" class="form-control" required>
        </div>
        <div class="form-group">
          <label>New Password</label>
          <input name="new_password" type="password" class="form-control" minlength="6" required>
        </div>
        <div class="form-group">
          <label>Confirm New Password</label>
          <input name="confirm_password" type="password" class="form-control" minlength="6" required>
        </div>
        <button type="submit" class="btn btn-primary">Change Password</button>
      </form>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
