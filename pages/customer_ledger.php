<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$customerId = intval($_GET['id'] ?? $_GET['customer_id'] ?? $_POST['id'] ?? 0);
if (!$customerId) { header('Location: customers.php'); exit; }

// Fetch customer
$stmt = $conn->prepare("SELECT * FROM customers_v2 WHERE id=?");
$stmt->bind_param("i", $customerId);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
if (!$customer) { header('Location: customers.php?error=Customer+not+found'); exit; }

// Handle payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment') {
    if (!csrf_verify()) { header('Location: ?id='.$customerId.'&error=Invalid+security+token'); exit; }
    $amount = floatval($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'cash';
    $ref = trim($_POST['reference_no'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($amount <= 0) { header('Location: ?id='.$customerId.'&error=Invalid+amount'); exit; }

    // Record payment
    $stmt = $conn->prepare("INSERT INTO customer_payments (customer_id, amount, payment_method, reference_no, note, user_id) VALUES (?,?,?,?,?,?)");
    $userId = $_SESSION['user_id'] ?? null;
    $stmt->bind_param("idsssi", $customerId, $amount, $method, $ref, $note, $userId);
    $stmt->execute();

    // Update ledger
    updateCustomerBalance($conn, $customerId, 'payment', $amount, 'payment', $conn->insert_id, $note ?: "Payment via $method");

    auditLog($conn, 'customer_payment', 'customer', $customerId, ['amount' => $amount, 'method' => $method]);
    header('Location: ?id='.$customerId.'&msg=Payment+recorded'); exit;
}

// Handle adjustment (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust' && isAdmin()) {
    if (!csrf_verify()) { header('Location: ?id='.$customerId.'&error=Invalid+security+token'); exit; }
    $amount = floatval($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($amount == 0) { header('Location: ?id='.$customerId.'&error=Amount+required'); exit; }

    updateCustomerBalance($conn, $customerId, 'adjustment', $amount, null, null, $note ?: 'Manual adjustment');
    auditLog($conn, 'customer_adjustment', 'customer', $customerId, ['amount' => $amount, 'note' => $note]);
    header('Location: ?id='.$customerId.'&msg=Balance+adjusted'); exit;
}

// Fetch ledger entries
$stmt2 = $conn->prepare("SELECT l.*, u.name user_name FROM customer_ledger l LEFT JOIN users u ON l.user_id=u.id WHERE l.customer_id=? ORDER BY l.created_at ASC, l.id ASC");
$stmt2->bind_param("i", $customerId);
$stmt2->execute();
$ledger = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch payments
$stmt3 = $conn->prepare("SELECT cp.*, u.name user_name FROM customer_payments cp LEFT JOIN users u ON cp.user_id=u.id WHERE cp.customer_id=? ORDER BY cp.created_at DESC");
$stmt3->bind_param("i", $customerId);
$stmt3->execute();
$payments = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);

// Current balance
$balance = getCustomerBalance($conn, $customerId);

$pageTitle = e($customer['name']) . ' — Ledger';
$activePage = 'customers';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title"><?= e($customer['name']) ?></div>
    <div class="page-subtitle"><?= ucfirst($customer['type']) ?> &middot; <?= e($customer['phone'] ?: 'No phone') ?></div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn btn-primary" onclick="openModal('paymentModal')">Receive Payment</button>
    <?php if(isAdmin()): ?><button class="btn btn-secondary" onclick="openModal('adjustModal')">Adjust Balance</button><?php endif; ?>
    <a href="customers.php" class="btn btn-secondary">Back</a>
  </div>
</div>

<!-- Customer Info + Balance -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
  <div class="stat-card">
    <div class="stat-label">Current Balance</div>
    <div class="stat-value" style="color:<?= $balance > 0 ? 'var(--red)' : ($balance < 0 ? 'var(--green)' : 'var(--text)') ?>">
      <?= money($balance) ?>
    </div>
    <div class="stat-sub"><?= $balance > 0 ? 'Outstanding' : ($balance < 0 ? 'Credit available' : 'Settled') ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Credit Limit</div>
    <div class="stat-value" style="font-size:20px"><?= money($customer['credit_limit']) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Sales</div>
    <div class="stat-value" style="font-size:20px">
      <?php
      $stmtTs = $conn->prepare("SELECT COALESCE(SUM(total),0) t FROM sales WHERE customer_v2_id=? AND status='completed'");
      $stmtTs->bind_param("i", $customerId);
      $stmtTs->execute();
      echo money($stmtTs->get_result()->fetch_assoc()['t']);
      ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Payments</div>
    <div class="stat-value" style="font-size:20px;color:var(--green)">
      <?= money(array_sum(array_column($payments, 'amount'))) ?>
    </div>
  </div>
</div>

<!-- Ledger -->
<div class="table-card">
  <div class="table-toolbar">
    <strong style="font-size:13px">Account Ledger (Khata)</strong>
    <span class="text-muted" style="font-size:12px"><?= count($ledger) ?> entries</span>
  </div>
  <table>
    <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Amount</th><th>Balance</th><th>Note</th><th>User</th></tr></thead>
    <tbody>
    <?php if($ledger): foreach($ledger as $l): ?>
      <tr>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
        <td>
          <?php
          $typeBadge = 'badge-blue';
          switch($l['type']) {
              case 'invoice': $typeBadge = 'badge-red'; break;
              case 'payment': $typeBadge = 'badge-green'; break;
              case 'refund': $typeBadge = 'badge-orange'; break;
              case 'adjustment': $typeBadge = 'badge-blue'; break;
              case 'opening': $typeBadge = 'badge-orange'; break;
          }
          ?>
          <span class="badge <?= $typeBadge ?>"><?= ucfirst($l['type']) ?></span>
        </td>
        <td class="text-mono" style="font-size:12px"><?= e($l['reference_type'] ?: '—') ?></td>
        <td class="text-mono <?= $l['amount'] > 0 ? 'text-red' : 'text-green' ?>" style="font-size:13px">
          <?= $l['amount'] > 0 ? '+' : '' ?><?= money($l['amount']) ?>
        </td>
        <td class="text-mono" style="font-size:13px;font-weight:700"><?= money($l['balance_after']) ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($l['note'] ?: '—') ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($l['user_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="empty-state">No ledger entries yet</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Payment Modal -->
<div class="modal-overlay" id="paymentModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><span class="modal-title">Receive Payment</span><span class="modal-close" onclick="closeModal('paymentModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="payment">
      <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius);padding:10px 12px;margin-bottom:14px">
        <div style="font-size:14px;font-weight:700"><?= e($customer['name']) ?></div>
        <div style="font-size:12px;color:var(--text2);margin-top:3px">
          Outstanding: <span style="color:var(--red);font-weight:600"><?= money($balance) ?></span>
        </div>
      </div>
      <div class="form-group"><label>Amount *</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
      <div class="form-group"><label>Payment Method</label>
        <select name="payment_method" class="form-control">
          <option value="cash">Cash</option>
          <option value="card">Card</option>
          <option value="mobile">Mobile</option>
          <option value="bank_transfer">Bank Transfer</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>
      <div class="form-group"><label>Reference No</label><input name="reference_no" class="form-control" placeholder="Optional"></div>
      <div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('paymentModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Payment</button></div></form>
  </div>
</div>

<?php if(isAdmin()): ?>
<!-- Adjust Modal -->
<div class="modal-overlay" id="adjustModal">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><span class="modal-title">Adjust Balance</span><span class="modal-close" onclick="closeModal('adjustModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="adjust">
      <div style="font-size:12px;color:var(--text2);margin-bottom:12px">
        Use positive amount to increase debt (invoice).<br>Use negative amount to reduce debt (credit/adjustment).
      </div>
      <div class="form-group"><label>Amount (+ or -)</label><input name="amount" type="number" step="0.01" class="form-control" required></div>
      <div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="2" required></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('adjustModal')">Cancel</button><button type="submit" class="btn btn-danger">Adjust</button></div></form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
