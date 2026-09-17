<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT o.*,s.name sup_name,s.contact,s.payment_terms FROM orders o LEFT JOIN suppliers s ON o.supplier_id=s.supplier_id WHERE o.id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
if (!$order) { echo '<div class="empty-state">Order not found</div>'; exit; }
$stmt2 = $conn->prepare("SELECT * FROM order_items WHERE order_id=?");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$items = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

function row($l,$v){ return '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px"><span style="color:var(--text2)">'.$l.'</span><span style="font-weight:600">'.$v.'</span></div>'; }
?>
<div style="margin-bottom:14px">
  <div style="font-size:16px;font-weight:700;font-family:var(--mono)"><?= e($order['order_no']) ?></div>
  <div style="margin-top:4px">
    <span class="badge <?= $order['status']==='received'?'badge-green':($order['status']==='cancelled'?'badge-red':'badge-blue') ?>">
      <?= ucfirst($order['status']) ?>
    </span>
  </div>
</div>
<?= row('Supplier',       e($order['sup_name'] ?? '—')) ?>
<?= row('Contact',        e($order['contact']  ?? '—')) ?>
<?= row('Payment Terms',  e($order['payment_terms'] ?? '—')) ?>
<?= row('Ordered',        date('d/m/Y H:i', strtotime($order['ordered_at']))) ?>
<?php if ($order['received_at']): ?>
<?= row('Received', date('d/m/Y H:i', strtotime($order['received_at']))) ?>
<?php endif; ?>
<?php if ($order['note']): ?>
<?= row('Note', e($order['note'])) ?>
<?php endif; ?>

<div style="margin-top:14px;margin-bottom:6px;font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.06em">Items</div>
<table style="width:100%;border-collapse:collapse;font-size:13px">
  <thead><tr style="background:var(--bg3)">
    <th style="padding:7px 8px;text-align:left;border-bottom:1px solid var(--border)">Product</th>
    <th style="padding:7px 8px;text-align:center;border-bottom:1px solid var(--border)">Qty</th>
    <th style="padding:7px 8px;text-align:right;border-bottom:1px solid var(--border)">Cost</th>
    <th style="padding:7px 8px;text-align:right;border-bottom:1px solid var(--border)">Total</th>
  </tr></thead>
  <tbody>
  <?php foreach ($items as $item): ?>
    <tr>
      <td style="padding:7px 8px;border-bottom:1px solid var(--border)"><?= e($item['product_name']) ?></td>
      <td style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:center"><?= $item['qty'] ?></td>
      <td style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:right"><?= money($item['cost']) ?></td>
      <td style="padding:7px 8px;border-bottom:1px solid var(--border);text-align:right;font-weight:700;color:var(--accent)"><?= money($item['total']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<div style="text-align:right;margin-top:10px;font-size:15px;font-weight:800">
  Total: <span style="color:var(--accent)"><?= money($order['total']) ?></span>
</div>
<?php if ($order['status'] !== 'cancelled'): ?>
<div style="margin-top:12px;text-align:left">
  <form method="POST" action="<?= BASE_URL ?>/pages/orders.php" style="display:inline" onsubmit="return confirm('Create a new order duplicated from this one?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="duplicate">
    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
    <button class="btn btn-secondary btn-sm">Duplicate Order</button>
  </form>
</div>
<?php endif; ?>
