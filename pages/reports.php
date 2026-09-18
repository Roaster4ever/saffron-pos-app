<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Reports';
$activePage = 'reports';
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');

// 1. Profit & Loss
$stmtPL = $conn->prepare("SELECT COALESCE(SUM(s.total),0) revenue, COALESCE(SUM(s.tax),0) tax_collected, COALESCE(SUM(si.qty * p.cost),0) cogs FROM sales s JOIN sale_items si ON s.id=si.sale_id JOIN products p ON si.product_id=p.id WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed'");
$stmtPL->bind_param("ss", $from, $to);
$stmtPL->execute();
$pl = $stmtPL->get_result()->fetch_assoc();
$grossProfit = $pl['revenue'] - $pl['tax_collected'] - $pl['cogs'];
$margin = $pl['revenue'] > 0 ? round(($grossProfit / ($pl['revenue'] - $pl['tax_collected'])) * 100, 1) : 0;

// 2. Sales by Brand
$stmtBrand = $conn->prepare("SELECT b.name, SUM(si.total) revenue, SUM(si.qty) qty, SUM(si.total - (si.qty * p.cost)) profit FROM sale_items si JOIN products p ON si.product_id=p.id JOIN brands b ON p.brand_id=b.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed' GROUP BY b.id ORDER BY revenue DESC");
$stmtBrand->bind_param("ss", $from, $to);
$stmtBrand->execute();
$brandSales = $stmtBrand->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Sales by Category
$stmtCat = $conn->prepare("SELECT c.name, SUM(si.total) revenue, SUM(si.qty) qty FROM sale_items si JOIN products p ON si.product_id=p.id JOIN categories c ON p.category_id=c.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed' GROUP BY c.id ORDER BY revenue DESC");
$stmtCat->bind_param("ss", $from, $to);
$stmtCat->execute();
$catSales = $stmtCat->get_result()->fetch_all(MYSQLI_ASSOC);

// 4. Credit Outstanding
$creditTotal = $conn->query("SELECT COALESCE(SUM(outstanding),0) total FROM sales WHERE outstanding > 0 AND status='completed'")->fetch_assoc()['total'];
$creditCount = $conn->query("SELECT COUNT(*) cnt FROM sales WHERE outstanding > 0 AND status='completed'")->fetch_assoc()['cnt'];

// 5. Low Stock Items
$lowStock = $conn->query("SELECT p.name, p.sku, p.stock, p.low_stock_alert, b.name brand_name FROM products p LEFT JOIN brands b ON p.brand_id=b.id WHERE p.stock <= p.low_stock_alert AND p.is_active=1 ORDER BY p.stock ASC")->fetch_all(MYSQLI_ASSOC);

// 6. Top profit items
$stmtProfit = $conn->prepare("SELECT p.name, b.name brand_name, SUM(si.qty) qty, SUM(si.total) revenue, SUM(si.total - (si.qty * p.cost)) profit FROM sale_items si JOIN products p ON si.product_id=p.id LEFT JOIN brands b ON p.brand_id=b.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='completed' GROUP BY p.id ORDER BY profit DESC LIMIT 10");
$stmtProfit->bind_param("ss", $from, $to);
$stmtProfit->execute();
$topProfit = $stmtProfit->get_result()->fetch_all(MYSQLI_ASSOC);

// 7. Inventory value
$stockValue = $conn->query("SELECT COUNT(*) products, COALESCE(SUM(stock),0) total_units, COALESCE(SUM(cost * stock),0) cost_value, COALESCE(SUM(price * stock),0) retail_value FROM products WHERE is_active=1")->fetch_assoc();

// 8. Refunds/Returns
$stmtRefund = $conn->prepare("SELECT s.invoice_no, COALESCE(cv.name,'Walk-in') cust_name, s.total, s.paid, s.payment_method, s.status, s.created_at FROM sales s LEFT JOIN customers_v2 cv ON s.customer_v2_id=cv.id WHERE DATE(s.created_at) BETWEEN ? AND ? AND s.status='refunded' ORDER BY s.created_at DESC");
$stmtRefund->bind_param("ss", $from, $to);
$stmtRefund->execute();
$refundedSales = $stmtRefund->get_result()->fetch_all(MYSQLI_ASSOC);
$refundTotal = array_sum(array_column($refundedSales, 'total'));
$refundCount = count($refundedSales);

// 9. Partial returns (audit log entries)
$stmtPartial = $conn->prepare("SELECT al.*, u.name as user_name FROM audit_log al LEFT JOIN users u ON al.user_id=u.id WHERE al.action IN ('sale_refund','sale_partial_refund') AND DATE(al.created_at) BETWEEN ? AND ? ORDER BY al.created_at DESC");
$stmtPartial->bind_param("ss", $from, $to);
$stmtPartial->execute();
$refundLog = $stmtPartial->get_result()->fetch_all(MYSQLI_ASSOC);

// 8. Daily trend
$dailyTrend = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $stmtD = $conn->prepare("SELECT COALESCE(SUM(total),0) rev, COUNT(*) cnt FROM sales WHERE DATE(created_at)=? AND status='completed'");
    $stmtD->bind_param("s", $d);
    $stmtD->execute();
    $r = $stmtD->get_result()->fetch_assoc();
    $dailyTrend[] = ['date' => $d, 'revenue' => (float)$r['rev'], 'count' => (int)$r['cnt']];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Business Reports</div>
    <div class="page-subtitle">Period: <?= date('d M Y', strtotime($from)) ?> — <?= date('d M Y', strtotime($to)) ?></div>
  </div>
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="date" name="from" value="<?= e($from) ?>" class="form-control" style="width:140px">
    <input type="date" name="to" value="<?= e($to) ?>" class="form-control" style="width:140px">
    <button class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<!-- P&L Summary -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:16px">
  <div class="stat-card"><div class="stat-label">Revenue</div><div class="stat-value" style="font-size:20px"><?= money($pl['revenue']) ?></div></div>
  <div class="stat-card"><div class="stat-label">COGS</div><div class="stat-value" style="font-size:20px;color:var(--text2)"><?= money($pl['cogs']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Gross Profit</div><div class="stat-value" style="font-size:20px;color:var(--green)"><?= money($grossProfit) ?></div></div>
  <div class="stat-card"><div class="stat-label">Margin</div><div class="stat-value" style="font-size:20px"><?= $margin ?>%</div></div>
  <div class="stat-card"><div class="stat-label">Tax Collected</div><div class="stat-value" style="font-size:20px;color:var(--orange)"><?= money($pl['tax_collected']) ?></div></div>
</div>

<!-- Brand + Category breakdown -->
<div class="two-col" style="margin-bottom:16px">
  <div class="table-card">
    <div class="table-toolbar"><strong style="font-size:13px">Sales by Brand</strong></div>
    <table>
      <thead><tr><th>Brand</th><th>Qty</th><th>Revenue</th><th>Profit</th><th>Margin</th></tr></thead>
      <tbody>
      <?php foreach ($brandSales as $b): $m = $b['revenue'] > 0 ? round(($b['profit'] / $b['revenue']) * 100, 1) : 0; ?>
        <tr>
          <td><strong><?= e($b['name']) ?></strong></td>
          <td class="text-mono"><?= $b['qty'] ?></td>
          <td class="text-accent text-mono"><?= money($b['revenue']) ?></td>
          <td class="text-mono <?= $b['profit'] >= 0 ? 'text-green' : 'text-red' ?>"><?= money($b['profit']) ?></td>
          <td class="text-mono"><?= $m ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="table-card">
    <div class="table-toolbar"><strong style="font-size:13px">Sales by Category</strong></div>
    <table>
      <thead><tr><th>Category</th><th>Qty</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php foreach ($catSales as $c): ?>
        <tr>
          <td><strong><?= e($c['name']) ?></strong></td>
          <td class="text-mono"><?= $c['qty'] ?></td>
          <td class="text-accent text-mono"><?= money($c['revenue']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Top Profit Items + Low Stock + Inventory -->
<div class="two-col" style="margin-bottom:16px">
  <div class="table-card">
    <div class="table-toolbar"><strong style="font-size:13px">Most Profitable Products</strong></div>
    <table>
      <thead><tr><th>Product</th><th>Brand</th><th>Qty</th><th>Revenue</th><th>Profit</th></tr></thead>
      <tbody>
      <?php foreach ($topProfit as $p): ?>
        <tr>
          <td style="font-size:12px"><?= e($p['name']) ?></td>
          <td class="text-muted" style="font-size:12px"><?= e($p['brand_name'] ?? '—') ?></td>
          <td class="text-mono"><?= $p['qty'] ?></td>
          <td class="text-accent text-mono"><?= money($p['revenue']) ?></td>
          <td class="text-mono text-green"><?= money($p['profit']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div>
    <!-- Credit outstanding -->
    <div class="stats-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12px">
      <div class="stat-card"><div class="stat-label">Credit Outstanding</div><div class="stat-value" style="color:var(--red)"><?= money($creditTotal) ?></div><div class="stat-sub"><?= $creditCount ?> invoices</div></div>
      <div class="stat-card">
        <div class="stat-label">Inventory Value</div>
        <div class="stat-value" style="font-size:18px"><?= money($stockValue['cost_value']) ?></div>
        <div class="stat-sub">Retail: <?= money($stockValue['retail_value']) ?></div>
      </div>
    </div>

    <!-- Low stock -->
    <div class="table-card">
      <div class="table-toolbar"><strong style="font-size:13px">Low Stock Alerts</strong></div>
      <?php if ($lowStock): ?>
      <table>
        <thead><tr><th>Product</th><th>Brand</th><th>Stock</th><th>Alert</th></tr></thead>
        <tbody>
        <?php foreach ($lowStock as $ls): ?>
          <tr>
            <td style="font-size:12px"><?= e($ls['name']) ?></td>
            <td class="text-muted" style="font-size:12px"><?= e($ls['brand_name'] ?? '—') ?></td>
            <td class="text-mono <?= $ls['stock'] <= 0 ? 'text-red' : 'text-orange' ?>"><?= $ls['stock'] ?></td>
            <td class="text-mono text-muted"><?= $ls['low_stock_alert'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="empty-state" style="padding:20px">All stock levels healthy</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Returns & Refunds -->
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
  <div class="stat-card"><div class="stat-label">Total Refunds</div><div class="stat-value" style="color:var(--red)"><?= money($refundTotal) ?></div><div class="stat-sub"><?= $refundCount ?> invoice<?= $refundCount !== 1 ? 's' : '' ?> refunded</div></div>
  <div class="stat-card"><div class="stat-label">Net Revenue</div><div class="stat-value" style="color:var(--green)"><?= money($pl['revenue'] - $refundTotal) ?></div><div class="stat-sub">Revenue minus refunds</div></div>
  <div class="stat-card"><div class="stat-label">Refund Rate</div><div class="stat-value"><?= $pl['revenue'] > 0 ? round(($refundTotal / $pl['revenue']) * 100, 1) : 0 ?>%</div><div class="stat-sub">of gross revenue</div></div>
</div>

<?php if ($refundedSales): ?>
<div class="table-card" style="margin-bottom:16px">
  <div class="table-toolbar"><strong style="font-size:13px">Refunded Invoices</strong></div>
  <table>
    <thead><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Payment</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($refundedSales as $rs): ?>
      <tr>
        <td class="text-mono text-accent" style="font-size:12px"><?= e($rs['invoice_no']) ?></td>
        <td style="font-size:12px"><?= e($rs['cust_name']) ?></td>
        <td class="text-mono text-red" style="font-size:12px"><?= money($rs['total']) ?></td>
        <td style="text-transform:capitalize;font-size:12px"><?= e(str_replace('_', ' ', $rs['payment_method'])) ?></td>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($rs['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($refundLog): ?>
<div class="table-card" style="margin-bottom:16px">
  <div class="table-toolbar"><strong style="font-size:13px">Refund Activity Log</strong></div>
  <table>
    <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($refundLog as $rl): ?>
      <tr>
        <td class="text-muted" style="font-size:11px"><?= date('d/m/Y H:i', strtotime($rl['created_at'])) ?></td>
        <td style="font-size:12px"><?= e($rl['user_name'] ?? 'System') ?></td>
        <td><span class="badge badge-orange" style="font-size:10px"><?= e($rl['action']) ?></span></td>
        <td style="font-size:11px"><?= e($rl['new_values'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
