<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) exit;

$stmt = $conn->prepare("SELECT qi.*, p.sku, b.name brand_name FROM quotation_items qi LEFT JOIN products p ON qi.product_id=p.id LEFT JOIN brands b ON p.brand_id=b.id WHERE qi.quotation_id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!$items) { echo '<div class="empty-state" style="padding:12px">No items</div>'; exit; }

foreach ($items as $item):
  $lineTotal = $item['price'] * $item['qty'];
  $tax = floatval($item['tax_amount'] ?? 0);
?>
  <div style="display:flex;gap:8px;align-items:center;padding:8px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px;font-size:13px">
    <span style="flex:2;font-weight:600">
      <?= e($item['product_name']) ?>
      <?php if ($item['brand_name']): ?>
        <span style="font-size:11px;color:var(--text2);margin-left:4px"><?= e($item['brand_name']) ?></span>
      <?php endif; ?>
      <?php if ($item['sku']): ?>
        <span style="font-size:10px;color:var(--text3);margin-left:4px;font-family:var(--mono)"><?= e($item['sku']) ?></span>
      <?php endif; ?>
    </span>
    <span style="width:50px;text-align:center"><?= $item['qty'] ?></span>
    <span style="width:80px;text-align:right" class="text-mono"><?= money($item['price']) ?></span>
    <?php if ($tax > 0): ?>
      <span style="width:60px;text-align:right;font-size:11px;color:var(--text2)">+<?= money($tax) ?></span>
    <?php endif; ?>
    <span style="width:80px;text-align:right;font-weight:700" class="text-mono"><?= money($item['total']) ?></span>
  </div>
<?php endforeach; ?>
