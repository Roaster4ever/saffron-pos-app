<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle = 'Receivables';
$activePage = 'receivables';

// Fetch customers with outstanding balances
$receivables = $conn->query("
    SELECT cv.*, 
        (SELECT balance_after FROM customer_ledger WHERE customer_id=cv.id ORDER BY id DESC LIMIT 1) as balance,
        (SELECT COUNT(*) FROM sales WHERE customer_v2_id=cv.id AND outstanding > 0 AND status='completed') as credit_sales,
        (SELECT MAX(created_at) FROM sales WHERE customer_v2_id=cv.id) as last_sale
    FROM customers_v2 cv 
    HAVING balance > 0 
    ORDER BY balance DESC
")->fetch_all(MYSQLI_ASSOC);

$totalReceivable = array_sum(array_column($receivables, 'balance'));

// Aging buckets
$aging = ['current' => 0, '30' => 0, '60' => 0, '90' => 0];
foreach ($receivables as $r) {
    $lastSale = $r['last_sale'] ? strtotime($r['last_sale']) : 0;
    $daysSince = $lastSale ? (time() - $lastSale) / 86400 : 999;
    if ($daysSince <= 30) $aging['current'] += $r['balance'];
    elseif ($daysSince <= 60) $aging['30'] += $r['balance'];
    elseif ($daysSince <= 90) $aging['60'] += $r['balance'];
    else $aging['90'] += $r['balance'];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Receivables (Outstanding Balances)</div>
    <div class="page-subtitle"><?= count($receivables) ?> customers &middot; <?= money($totalReceivable) ?> total outstanding</div>
  </div>
</div>

<!-- Aging Summary -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:16px">
  <div class="stat-card"><div class="stat-label">Current (0-30 days)</div><div class="stat-value" style="color:var(--green)"><?= money($aging['current']) ?></div></div>
  <div class="stat-card"><div class="stat-label">31-60 days</div><div class="stat-value" style="color:var(--orange)"><?= money($aging['30']) ?></div></div>
  <div class="stat-card"><div class="stat-label">61-90 days</div><div class="stat-value" style="color:var(--red)"><?= money($aging['60']) ?></div></div>
  <div class="stat-card"><div class="stat-label">90+ days</div><div class="stat-value" style="color:#ff4a4a;font-weight:800"><?= money($aging['90']) ?></div></div>
</div>

<div class="table-card">
  <table>
    <thead><tr><th>Customer</th><th>Type</th><th>Phone</th><th>Credit Limit</th><th>Outstanding</th><th>Credit Sales</th><th>Last Sale</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($receivables): foreach ($receivables as $r): ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong></td>
        <td><span class="badge badge-orange"><?= ucfirst($r['type']) ?></span></td>
        <td class="text-mono" style="font-size:12px"><?= e($r['phone'] ?: '—') ?></td>
        <td class="text-mono" style="font-size:12px"><?= $r['credit_limit'] > 0 ? money($r['credit_limit']) : '—' ?></td>
        <td class="text-mono text-red" style="font-size:14px;font-weight:700"><?= money($r['balance']) ?></td>
        <td class="text-mono"><?= $r['credit_sales'] ?></td>
        <td class="text-muted" style="font-size:12px"><?= $r['last_sale'] ? date('d/m/Y', strtotime($r['last_sale'])) : '—' ?></td>
        <td>
          <a href="customer_ledger.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">Ledger</a>
          <button class="btn btn-primary btn-sm" onclick="quickPayment(<?= $r['id'] ?>,'<?= e($r['name']) ?>',<?= $r['balance'] ?>)">Pay</button>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="8" class="empty-state">No outstanding balances</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Quick Payment Modal -->
<div class="modal-overlay" id="payModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><span class="modal-title">Record Payment</span><span class="modal-close" onclick="closeModal('payModal')">&times;</span></div>
    <form method="POST" action="customer_ledger.php"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="payment">
      <input type="hidden" name="id" id="payCustId">
      <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius);padding:10px 12px;margin-bottom:14px">
        <div style="font-size:14px;font-weight:700" id="payCustName"></div>
        <div style="font-size:12px;color:var(--text2);margin-top:3px">Outstanding: <span style="color:var(--red);font-weight:600" id="payCustBalance"></span></div>
      </div>
      <div class="form-group"><label>Amount *</label><input name="amount" id="payAmount" type="number" step="0.01" min="0.01" class="form-control" required></div>
      <div class="form-group"><label>Payment Method</label>
        <select name="payment_method" class="form-control">
          <option value="cash">Cash</option>
          <option value="card">Card</option>
          <option value="mobile">Mobile</option>
          <option value="bank_transfer">Bank Transfer</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>
      <div class="form-group"><label>Note</label><input name="note" class="form-control" placeholder="Optional"></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('payModal')">Cancel</button><button type="submit" class="btn btn-primary">Save Payment</button></div></form>
  </div>
</div>

<script>
function quickPayment(id, name, balance) {
  document.getElementById('payCustId').value = id;
  document.getElementById('payCustName').textContent = name;
  document.getElementById('payCustBalance').textContent = '<?= CURRENCY ?>' + parseFloat(balance).toFixed(2);
  document.getElementById('payAmount').value = parseFloat(balance).toFixed(2);
  openModal('payModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
