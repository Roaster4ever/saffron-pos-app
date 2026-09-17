<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

$today = date('Y-m-d');
$month = date('Y-m');

// Today's sales
$stmt = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(tax),0) tax FROM sales WHERE DATE(created_at)=? AND status='completed'");
$stmt->bind_param("s", $today);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$todaySales = $r['c']; $todayRevenue = $r['t']; $todayTax = $r['tax'];

// Today's cost (profit calc)
$stmtCost = $conn->prepare("SELECT COALESCE(SUM(p.cost * si.qty),0) c FROM sale_items si JOIN products p ON si.product_id=p.id JOIN sales s ON si.sale_id=s.id WHERE DATE(s.created_at)=? AND s.status='completed'");
$stmtCost->bind_param("s", $today);
$stmtCost->execute();
$todayCost = (float)$stmtCost->get_result()->fetch_assoc()['c'];
$todayProfit = $todayRevenue - $todayTax - $todayCost;

// Monthly
$stmt2 = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM sales WHERE DATE_FORMAT(created_at,'%Y-%m')=? AND status='completed'");
$stmt2->bind_param("s", $month);
$stmt2->execute();
$r2 = $stmt2->get_result()->fetch_assoc();
$monthSales = $r2['c']; $monthRevenue = $r2['t'];

// Inventory stats
$totalProducts = $conn->query("SELECT COUNT(*) c FROM products WHERE is_active=1")->fetch_assoc()['c'];
$lowStock = $conn->query("SELECT COUNT(*) c FROM products WHERE stock <= low_stock_alert AND stock > 0 AND is_active=1")->fetch_assoc()['c'];
$outOfStock = $conn->query("SELECT COUNT(*) c FROM products WHERE stock = 0 AND is_active=1")->fetch_assoc()['c'];
$totalStockValue = $conn->query("SELECT COALESCE(SUM(cost * stock),0) v FROM products WHERE is_active=1")->fetch_assoc()['v'];

// Outstanding receivables
$outstanding = $conn->query("SELECT COALESCE(SUM(outstanding),0) v FROM sales WHERE outstanding > 0 AND status='completed'")->fetch_assoc()['v'];

// Top selling products
$topProducts = $conn->query("SELECT p.name, b.name brand, SUM(si.qty) qty, SUM(si.total) rev FROM sale_items si JOIN products p ON si.product_id=p.id LEFT JOIN brands b ON p.brand_id=b.id JOIN sales s ON si.sale_id=s.id WHERE s.status='completed' AND DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY si.product_id ORDER BY qty DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// Recent sales
$recentSales = $conn->query("SELECT s.*, CASE WHEN s.customer_v2_id IS NOT NULL THEN c.name ELSE 'Walk-in' END cust_name FROM sales s LEFT JOIN customers_v2 c ON s.customer_v2_id=c.id ORDER BY s.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// Top brands
$topBrands = $conn->query("SELECT b.name, SUM(si.total) rev, SUM(si.qty) qty FROM sale_items si JOIN products p ON si.product_id=p.id JOIN brands b ON p.brand_id=b.id JOIN sales s ON si.sale_id=s.id WHERE s.status='completed' AND DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY b.id ORDER BY rev DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Last 7 days chart
$chartData = [];
for($i=6;$i>=0;$i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $stmt3 = $conn->prepare("SELECT COALESCE(SUM(total),0) t FROM sales WHERE DATE(created_at)=? AND status='completed'");
    $stmt3->bind_param("s", $d);
    $stmt3->execute();
    $r3 = $stmt3->get_result()->fetch_assoc();
    $chartData[] = ['date'=>date('D',strtotime($d)), 'total'=>(float)$r3['t']];
}
$maxVal = max(array_column($chartData,'total')) ?: 1;

// AJAX endpoint
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'todaySales' => $todaySales,
        'todayRevenue' => $todayRevenue,
        'todayProfit' => $todayProfit,
        'monthSales' => $monthSales,
        'monthRevenue' => $monthRevenue,
        'totalProducts' => $totalProducts,
        'lowStock' => $lowStock,
        'outOfStock' => $outOfStock,
        'outstanding' => $outstanding,
        'recentSales' => array_map(function($s) {
            return [
                'invoice_no' => $s['invoice_no'],
                'total' => $s['total'],
                'payment_method' => $s['payment_method'],
                'status' => $s['status'],
                'customer' => $s['cust_name'],
                'time' => date('H:i', strtotime($s['created_at']))
            ];
        }, $recentSales),
    ]);
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Dashboard</div>
    <div class="page-subtitle"><?= date('l, F j Y') ?></div>
  </div>
  <div style="display:flex;align-items:center;gap:12px">
    <div class="text-muted" style="font-size:12px">
      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);margin-right:4px;animation:pulse 2s infinite"></span>
      Live
    </div>
    <a href="<?= BASE_URL ?>/"/pages/pos.php" class="btn btn-primary">Open POS</a>
  </div>
</div>

<style>
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
</style>

<!-- Stats Row 1: Revenue + Profit -->
<div class="stats-grid" style="grid-template-columns:repeat(6,1fr);margin-bottom:16px">
  <div class="stat-card">
    <div class="stat-label">Today's Revenue</div>
    <div class="stat-value"><?= money($todayRevenue) ?></div>
    <div class="stat-sub"><?= $todaySales ?> transactions</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Today's Profit</div>
    <div class="stat-value" style="color:<?= $todayProfit>=0?'var(--green)':'var(--red)' ?>"><?= money($todayProfit) ?></div>
    <div class="stat-sub">After COGS + Tax</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Month Revenue</div>
    <div class="stat-value"><?= money($monthRevenue) ?></div>
    <div class="stat-sub"><?= $monthSales ?> transactions</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Outstanding</div>
    <div class="stat-value" style="color:var(--orange)"><?= money($outstanding) ?></div>
    <div class="stat-sub">Receivables</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Stock Value</div>
    <div class="stat-value" style="font-size:20px"><?= money($totalStockValue) ?></div>
    <div class="stat-sub"><?= $totalProducts ?> products</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Stock Alerts</div>
    <div class="stat-value" style="color:var(--orange)"><?= $lowStock ?> low</div>
    <div class="stat-sub"><?= $outOfStock ?> out of stock</div>
  </div>
</div>

<div class="two-col mt-16">
  <!-- Chart -->
  <div class="chart-card">
    <div class="chart-title">Sales — Last 7 Days</div>
    <div class="bar-chart">
      <?php foreach($chartData as $d): $h = max(4, round(($d['total']/$maxVal)*100)); ?>
      <div class="bar-wrap">
        <div class="bar-val"><?= $d['total']>0?money($d['total']):'' ?></div>
        <div class="bar" style="height:<?= $h ?>px"></div>
        <div class="bar-label"><?= $d['date'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Top Products -->
  <div class="chart-card">
    <div class="chart-title">Top Products (30 Days)</div>
    <?php if($topProducts): ?>
    <table style="width:100%">
      <thead><tr><th>Product</th><th>Brand</th><th>Qty</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php foreach($topProducts as $p): ?>
        <tr>
          <td style="font-size:12px"><?= e($p['name']) ?></td>
          <td class="text-muted" style="font-size:11px"><?= e($p['brand'] ?? '—') ?></td>
          <td class="text-mono" style="font-size:12px"><?= $p['qty'] ?></td>
          <td class="text-accent text-mono" style="font-size:12px"><?= money($p['rev']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="empty-state" style="padding:20px">No sales yet</div><?php endif; ?>
  </div>
</div>

<!-- Row 2: Top Brands + Recent Sales -->
<div class="two-col mt-16">
  <!-- Top Brands -->
  <div class="chart-card">
    <div class="chart-title">Top Brands (30 Days)</div>
    <?php if($topBrands): ?>
    <table style="width:100%">
      <thead><tr><th>Brand</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
      <tbody>
      <?php foreach($topBrands as $b): ?>
        <tr>
          <td style="font-size:12px"><strong><?= e($b['name']) ?></strong></td>
          <td class="text-mono" style="font-size:12px"><?= $b['qty'] ?></td>
          <td class="text-accent text-mono" style="font-size:12px"><?= money($b['rev']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="empty-state" style="padding:20px">No data</div><?php endif; ?>
  </div>

  <!-- Recent Sales -->
  <div class="chart-card">
    <div class="chart-title">Recent Sales</div>
    <?php if($recentSales): ?>
    <table style="width:100%">
      <thead><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Status</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach($recentSales as $s): ?>
        <tr>
          <td class="text-mono text-accent" style="font-size:11px"><?= e($s['invoice_no']) ?></td>
          <td style="font-size:12px"><?= e($s['cust_name']) ?></td>
          <td class="text-mono" style="font-size:12px"><?= money($s['total']) ?></td>
          <td><span class="badge <?= $s['status']==='completed'?'badge-green':'badge-red' ?>"><?= $s['status'] ?></span></td>
          <td class="text-muted" style="font-size:11px"><?= date('H:i', strtotime($s['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="empty-state" style="padding:20px">No sales yet</div><?php endif; ?>
  </div>
</div>

<script>
var CURRENCY_DASH = '<?= addslashes(CURRENCY) ?>';
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function fmtMoney(n) { return CURRENCY_DASH + parseFloat(n).toFixed(2); }

function refreshDashboard() {
  fetch('<?= BASE_URL ?>/index.php?ajax=1')
    .then(function(r) { return r.json(); })
    .then(function(d) {
      var cards = document.querySelectorAll('.stats-grid .stat-card');
      if (cards[0]) { cards[0].querySelector('.stat-value').textContent = fmtMoney(d.todayRevenue); cards[0].querySelector('.stat-sub').textContent = d.todaySales + ' transactions'; }
      if (cards[1]) { cards[1].querySelector('.stat-value').textContent = fmtMoney(d.todayProfit); }
      if (cards[2]) { cards[2].querySelector('.stat-value').textContent = fmtMoney(d.monthRevenue); cards[2].querySelector('.stat-sub').textContent = d.monthSales + ' transactions'; }
      if (cards[3]) { cards[3].querySelector('.stat-value').textContent = fmtMoney(d.outstanding); }

      var tbody = document.getElementById('dashRecentSales');
      if (tbody) {
        if (!d.recentSales.length) {
          tbody.innerHTML = '<tr><td colspan="5" class="empty-state">No sales yet today</td></tr>';
        } else {
          tbody.innerHTML = d.recentSales.map(function(s) {
            var badge = s.status === 'completed' ? 'badge-green' : 'badge-red';
            return '<tr>' +
              '<td class="text-mono text-accent" style="font-size:11px">' + esc(s.invoice_no) + '</td>' +
              '<td style="font-size:12px">' + esc(s.customer) + '</td>' +
              '<td class="text-mono" style="font-size:12px">' + fmtMoney(s.total) + '</td>' +
              '<td><span class="badge ' + badge + '">' + s.status + '</span></td>' +
              '<td class="text-muted" style="font-size:11px">' + s.time + '</td>' +
            '</tr>';
          }).join('');
        }
      }
    })
    .catch(function() {});
}

setInterval(refreshDashboard, 15000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
