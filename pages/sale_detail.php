<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT s.*, COALESCE(cv.name, 'Walk-in') cust_name FROM sales s LEFT JOIN customers_v2 cv ON s.customer_v2_id=cv.id WHERE s.id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
if (!$sale) { echo '<div class="empty-state">Not found</div>'; exit; }
$stmt2 = $conn->prepare("SELECT si.*, p.sku, b.name brand_name FROM sale_items si LEFT JOIN products p ON si.product_id=p.id LEFT JOIN brands b ON p.brand_id=b.id WHERE si.sale_id=?");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$items = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<div class="receipt">
  <div class="receipt-title"><?= SHOP_NAME ?></div>
  <div class="receipt-sub">SALES INVOICE</div>
  <hr class="receipt-divider">
  <div class="receipt-row"><span>Invoice:</span><span style="font-weight:700"><?= e($sale['invoice_no']) ?></span></div>
  <div class="receipt-row"><span>Date:</span><span><?= date('d/m/Y H:i', strtotime($sale['created_at'])) ?></span></div>
  <div class="receipt-row"><span>Customer:</span><span><?= e($sale['cust_name']) ?></span></div>
  <div class="receipt-row"><span>Payment:</span><span style="text-transform:capitalize"><?= e(str_replace('_', ' ', $sale['payment_method'])) ?></span></div>
  <?php if ($sale['quotation_id']): ?>
    <div class="receipt-row"><span>Quotation:</span><span class="text-accent">#<?= $sale['quotation_id'] ?></span></div>
  <?php endif; ?>
  <hr class="receipt-divider">
  <?php foreach ($items as $it): ?>
    <div class="receipt-row" data-item-id="<?= $it['id'] ?>">
      <span><?= e($it['product_name']) ?> &times;<?= $it['qty'] ?> <?= e($it['unit'] ?? 'pc') ?></span>
      <span><?= money($it['total']) ?></span>
    </div>
    <div class="receipt-row" style="font-size:11px;color:#888">
      <span>&nbsp;&nbsp;@ <?= money($it['price']) ?><?= $it['brand_name'] ? ' (' . e($it['brand_name']) . ')' : '' ?></span>
      <span><?php if (floatval($it['tax_amount'] ?? 0) > 0): ?>GST <?= $it['gst_rate'] ?>%: <?= money($it['tax_amount']) ?><?php endif; ?></span>
    </div>
  <?php endforeach; ?>
  <hr class="receipt-divider">
  <div class="receipt-row"><span>Subtotal:</span><span><?= money($sale['subtotal']) ?></span></div>
  <?php if ($sale['discount'] > 0): ?><div class="receipt-row"><span>Discount:</span><span style="color:var(--red)">-<?= money($sale['discount']) ?></span></div><?php endif; ?>
  <?php if ($sale['tax'] > 0): ?><div class="receipt-row"><span>GST/Tax:</span><span><?= money($sale['tax']) ?></span></div><?php endif; ?>
  <div class="receipt-row receipt-total"><span>TOTAL:</span><span><?= money($sale['total']) ?></span></div>
  <div class="receipt-row"><span>Paid:</span><span><?= money($sale['paid']) ?></span></div>
  <?php if ($sale['payment_method'] === 'cash'): ?>
    <div class="receipt-row"><span>Change:</span><span><?= money($sale['change_amount']) ?></span></div>
  <?php endif; ?>
  <?php if (floatval($sale['outstanding'] ?? 0) > 0): ?>
    <div class="receipt-row" style="font-weight:700;color:var(--red)"><span>Outstanding:</span><span><?= money($sale['outstanding']) ?></span></div>
  <?php endif; ?>
  <hr class="receipt-divider">
  <div class="receipt-footer">Status: <?= strtoupper($sale['status']) ?><br><?= INVOICE_FOOTER ?></div>
</div>
