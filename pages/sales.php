<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Refund handler (full or partial)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("SELECT * FROM sales WHERE id=? AND status='completed'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    if (!$sale) { header('Location: ?error=Sale+not+found'); exit; }

    // Partial returns support
    $returnItems = json_decode($_POST['return_items'] ?? '[]', true); // [{sale_item_id, qty}]

    $conn->begin_transaction();
    try {
        if (!empty($returnItems)) {
            // Partial return
            $totalRefund = 0;
            foreach ($returnItems as $ri) {
                $saleItemId = intval($ri['sale_item_id']);
                $returnQty = floatval($ri['qty']);
                if ($saleItemId <= 0 || $returnQty <= 0) continue;

                // Get sale item
                $siStmt = $conn->prepare("SELECT * FROM sale_items WHERE id=? AND sale_id=?");
                $siStmt->bind_param("ii", $saleItemId, $id);
                $siStmt->execute();
                $saleItem = $siStmt->get_result()->fetch_assoc();
                if (!$saleItem) continue;

                // Prevent returning more than sold
                $returnQty = min($returnQty, $saleItem['qty']);

                // Restore stock if product still exists
                if ($saleItem['product_id']) {
                    $stmt4 = $conn->prepare("UPDATE products SET stock=stock+? WHERE id=?");
                    $stmt4->bind_param("di", $returnQty, $saleItem['product_id']);
                    $stmt4->execute();
                    logInventoryMovement($conn, $saleItem['product_id'], $returnQty, 'refund', 'refund', "Partial refund - Invoice {$sale['invoice_no']}");
                }

                $totalRefund += round($saleItem['price'] * $returnQty, 2);
            }

            // Update sale status
            $newStatus = $totalRefund >= floatval($sale['total']) ? 'refunded' : 'completed';
            $stmt5 = $conn->prepare("UPDATE sales SET status=? WHERE id=?");
            $stmt5->bind_param("si", $newStatus, $id);
            $stmt5->execute();

            // Customer ledger: reduce outstanding by refund amount for credit sales
            if ($sale['customer_v2_id'] && floatval($sale['outstanding'] ?? 0) > 0 && $totalRefund > 0) {
                $adjustAmt = min($totalRefund, floatval($sale['outstanding']));
                updateCustomerBalance($conn, $sale['customer_v2_id'], 'refund', $adjustAmt, 'sale', $id, "Partial refund - Invoice {$sale['invoice_no']}");
            }

            auditLog($conn, 'sale_partial_refund', 'sale', $id, [
                'invoice_no' => $sale['invoice_no'],
                'refund_amount' => $totalRefund,
                'items_returned' => count($returnItems),
            ]);
        } else {
            // Full return (original behavior)
            $stmt2 = $conn->prepare("UPDATE sales SET status='refunded' WHERE id=?");
            $stmt2->bind_param("i", $id);
            $stmt2->execute();

            $stmt3 = $conn->prepare("SELECT * FROM sale_items WHERE sale_id=?");
            $stmt3->bind_param("i", $id);
            $stmt3->execute();
            $items = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($items as $item) {
                if ($item['product_id']) {
                    $stmt4 = $conn->prepare("UPDATE products SET stock=stock+? WHERE id=?");
                    $stmt4->bind_param("di", $item['qty'], $item['product_id']);
                    $stmt4->execute();
                    logInventoryMovement($conn, $item['product_id'], $item['qty'], 'refund', 'refund', "Full refund - Invoice {$sale['invoice_no']}");
                }
            }

            // Reverse ledger entry if credit sale
            if ($sale['customer_v2_id'] && floatval($sale['outstanding'] ?? 0) > 0) {
                updateCustomerBalance($conn, $sale['customer_v2_id'], 'refund', floatval($sale['outstanding']), 'sale', $id, "Full refund - Invoice {$sale['invoice_no']}");
            }

            auditLog($conn, 'sale_refund', 'sale', $id, ['invoice_no' => $sale['invoice_no']]);
        }

        $conn->commit();
        // Detect AJAX request (partial refund modal)
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false || !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
        // Check if the request came via fetch with FormData (partial refund)
        $hasReturnItems = !empty($_POST['return_items']);
        if ($hasReturnItems) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Return processed']);
            exit;
        }
        header('Location: ?msg=Sale+refunded'); exit;
    } catch (Exception $ex) {
        $conn->rollback();
        error_log('REFUND ERROR: ' . $ex->getMessage());
        header('Location: ?error=Refund+failed'); exit;
    }
}

$pageTitle = 'Sales';
$activePage = 'sales';

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');

$q        = trim($_GET['q'] ?? '');
$method   = $_GET['method'] ?? '';
$invPage  = max(1, intval($_GET['inv_page'] ?? 1));
$perPage  = 18;

// Stats queries
$whereStats = "WHERE DATE(s.created_at) BETWEEN ? AND ?";
$paramsStats = [$from, $to];
$typesStats  = "ss";

$stmt2 = $conn->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) rev, COALESCE(SUM(tax),0) tax_total, COALESCE(SUM(outstanding),0) total_outstanding FROM sales s $whereStats AND s.status='completed'");
$stmt2->bind_param($typesStats, ...$paramsStats);
$stmt2->execute();
$totals = $stmt2->get_result()->fetch_assoc();

$paymentStats = [];
$stmt3 = $conn->prepare("SELECT payment_method, COUNT(*) cnt, COALESCE(SUM(total),0) total FROM sales s $whereStats AND s.status='completed' GROUP BY payment_method");
$stmt3->bind_param($typesStats, ...$paramsStats);
$stmt3->execute();
$psResult = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($psResult as $ps) $paymentStats[$ps['payment_method']] = $ps;

$avgSale = $totals['cnt'] > 0 ? $totals['rev'] / $totals['cnt'] : 0;

// Invoice filters
$whereInv = "WHERE 1=1";
$paramsInv = [];
$typesInv  = "";

if ($q) {
    $like = "%{$q}%";
    $whereInv .= " AND (s.invoice_no LIKE ? OR COALESCE(cv.name, '') LIKE ?)";
    $paramsInv[] = $like;
    $paramsInv[] = $like;
    $typesInv .= "ss";
}
if ($method && in_array($method, ['cash','card','mobile','credit','bank_transfer','cheque'])) {
    $whereInv .= " AND s.payment_method = ?";
    $paramsInv[] = $method;
    $typesInv .= "s";
}

$countSql = "SELECT COUNT(*) cnt FROM sales s LEFT JOIN customers_v2 cv ON s.customer_v2_id=cv.id $whereInv";
$stmtCnt = $conn->prepare($countSql);
if ($typesInv) $stmtCnt->bind_param($typesInv, ...$paramsInv);
$stmtCnt->execute();
$totalInvoices = $stmtCnt->get_result()->fetch_assoc()['cnt'];
$totalPages = max(1, ceil($totalInvoices / $perPage));
if ($invPage > $totalPages) $invPage = $totalPages;
$offset = ($invPage - 1) * $perPage;

$invSql = "SELECT s.*, COALESCE(cv.name, 'Walk-in') cust_name FROM sales s LEFT JOIN customers_v2 cv ON s.customer_v2_id=cv.id $whereInv ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
$invTypes = $typesInv . "ii";
$paramsInv[] = $perPage;
$paramsInv[] = $offset;
$stmt = $conn->prepare($invSql);
$stmt->bind_param($invTypes, ...$paramsInv);
$stmt->execute();
$sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Sales History</div>
    <div class="page-subtitle"><?= $totals['cnt'] ?> transactions &middot; <?= money($totals['rev']) ?> revenue &middot; <?= money($totals['total_outstanding']) ?> outstanding</div>
  </div>
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="date" name="from" value="<?= e($from) ?>" class="form-control" style="width:140px">
    <input type="date" name="to" value="<?= e($to) ?>" class="form-control" style="width:140px">
    <button class="btn btn-primary btn-sm">Apply</button>
  </form>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:16px">
  <div class="stat-card"><div class="stat-label">Transactions</div><div class="stat-value"><?= $totals['cnt'] ?></div></div>
  <div class="stat-card"><div class="stat-label">Revenue</div><div class="stat-value"><?= money($totals['rev']) ?></div></div>
  <div class="stat-card"><div class="stat-label">GST/Tax</div><div class="stat-value" style="color:var(--orange)"><?= money($totals['tax_total']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Avg Sale</div><div class="stat-value"><?= money($avgSale) ?></div></div>
  <div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-value" style="color:var(--orange)"><?= money($totals['total_outstanding']) ?></div></div>
</div>

<!-- Payment breakdown -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="chart-card-half">
    <div class="chart-title">Payment Breakdown</div>
    <div class="pie-chart-wrap" id="paymentPieChart"></div>
  </div>
  <div class="table-card">
    <div class="table-toolbar"><strong style="font-size:13px">By Payment Method</strong></div>
    <table>
      <thead><tr><th>Method</th><th>Count</th><th>Amount</th></tr></thead>
      <tbody>
      <?php foreach ($psResult as $pm): ?>
        <tr>
          <td style="text-transform:capitalize"><?= e($pm['payment_method']) ?></td>
          <td><?= $pm['cnt'] ?></td>
          <td class="text-accent text-mono"><?= money($pm['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Invoice table -->
<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="text" name="q" class="search-box" placeholder="Search invoice or customer..." value="<?= e($q) ?>" style="width:200px">
      <select name="method" class="form-control" style="width:140px" onchange="this.form.submit()">
        <option value="">All Methods</option>
        <?php foreach (['cash','card','mobile','credit','bank_transfer','cheque'] as $m): ?>
          <option value="<?= $m ?>" <?= $method === $m ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$m)) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm">Search</button>
    </form>
    <span class="text-muted" style="font-size:12px"><?= $totalInvoices ?> invoices &middot; Page <?= $invPage ?>/<?= $totalPages ?></span>
  </div>
  <table>
    <thead><tr><th>Invoice</th><th>Customer</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Method</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($sales): foreach ($sales as $s): ?>
      <tr>
        <td class="text-mono text-accent" style="font-size:12px"><?= e($s['invoice_no']) ?></td>
        <td style="font-size:12px">
          <?= e($s['cust_name']) ?>
          <?php if (floatval($s['outstanding'] ?? 0) > 0): ?>
            <span class="badge badge-red" style="font-size:9px;margin-left:4px">Credit</span>
          <?php endif; ?>
        </td>
        <td class="text-mono"><strong><?= money($s['total']) ?></strong></td>
        <td class="text-mono" style="font-size:12px"><?= money($s['paid']) ?></td>
        <td class="text-mono <?= floatval($s['outstanding'] ?? 0) > 0 ? 'text-red' : '' ?>" style="font-size:12px">
          <?= floatval($s['outstanding'] ?? 0) > 0 ? money($s['outstanding']) : '—' ?>
        </td>
        <td style="text-transform:capitalize;font-size:12px"><?= e(str_replace('_', ' ', $s['payment_method'])) ?></td>
        <td><span class="badge <?= $s['status'] === 'completed' ? 'badge-green' : ($s['status'] === 'refunded' ? 'badge-orange' : 'badge-red') ?>"><?= $s['status'] ?></span></td>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($s['created_at'])) ?></td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick="viewSale(<?= $s['id'] ?>)">View</button>
          <?php if ($s['status'] === 'completed' && isAdmin()): ?>
          <button class="btn btn-secondary btn-sm" onclick="openPartialRefund(<?= $s['id'] ?>)" style="font-size:11px">Partial</button>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete('Refund this entire sale?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refund"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Full Refund</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="9" class="empty-state">No invoices found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;justify-content:center;align-items:center;gap:8px;padding:14px;border-top:1px solid var(--border)">
    <?php
    $pgParams = [];
    if ($q) $pgParams['q'] = $q;
    if ($method) $pgParams['method'] = $method;
    $pgParams['from'] = $from;
    $pgParams['to'] = $to;
    ?>
    <a href="?<?= http_build_query(array_merge($pgParams, ['inv_page' => $invPage - 1])) ?>"
       class="btn btn-secondary btn-sm <?= $invPage <= 1 ? 'disabled' : '' ?>"
       style="<?= $invPage <= 1 ? 'opacity:.3;pointer-events:none' : '' ?>">&#9664; Prev</a>
    <span class="text-muted" style="font-size:12px;padding:0 8px"><?= $invPage ?> / <?= $totalPages ?></span>
    <a href="?<?= http_build_query(array_merge($pgParams, ['inv_page' => $invPage + 1])) ?>"
       class="btn btn-secondary btn-sm <?= $invPage >= $totalPages ? 'disabled' : '' ?>"
       style="<?= $invPage >= $totalPages ? 'opacity:.3;pointer-events:none' : '' ?>">Next &#9654;</a>
  </div>
  <?php endif; ?>
</div>

<!-- Sale Detail Modal -->
<div class="modal-overlay" id="saleModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header"><span class="modal-title">Sale Details</span><span class="modal-close" onclick="closeModal('saleModal')">&times;</span></div>
    <div class="modal-body" id="saleDetail"><div class="empty-state">Loading...</div></div>
    <div class="modal-footer"><button onclick="closeModal('saleModal')" class="btn btn-primary btn-sm">Close</button></div>
  </div>
</div>

<!-- Partial Refund Modal -->
<div class="modal-overlay" id="partialRefundModal">
  <div class="modal" style="max-width:550px">
    <div class="modal-header"><span class="modal-title">Partial Return</span><span class="modal-close" onclick="closeModal('partialRefundModal')">&times;</span></div>
    <div class="modal-body" id="partialRefundBody"><div class="empty-state">Loading...</div></div>
    <div class="modal-footer">
      <button onclick="closeModal('partialRefundModal')" class="btn btn-secondary btn-sm">Cancel</button>
      <button onclick="submitPartialRefund()" class="btn btn-danger btn-sm" id="partialRefundBtn">Process Return</button>
    </div>
  </div>
</div>

<script>
function viewSale(id) {
  document.getElementById('saleDetail').innerHTML = '<div class="empty-state">Loading...</div>';
  openModal('saleModal');
  fetch('<?= BASE_URL ?>/pages/sale_detail.php?id=' + id)
    .then(function(r) { return r.text(); })
    .then(function(h) { document.getElementById('saleDetail').innerHTML = h; });
}

/* ── Partial Refund ── */
var _currentSaleId = 0;
var _csrfToken = '<?= csrf_token() ?>';

function openPartialRefund(saleId) {
  _currentSaleId = saleId;
  document.getElementById('partialRefundBody').innerHTML = '<div class="empty-state">Loading...</div>';
  openModal('partialRefundModal');
  fetch('<?= BASE_URL ?>/pages/sale_detail.php?id=' + saleId)
    .then(function(r) { return r.text(); })
    .then(function(html) {
      // Parse the detail HTML to extract items
      var parser = new DOMParser();
      var doc = parser.parseFromString(html, 'text/html');
      var rows = doc.querySelectorAll('tbody tr');
      var items = [];
      rows.forEach(function(row) {
        var cells = row.querySelectorAll('td');
        if (cells.length >= 3) {
          var name = cells[0].textContent.trim();
          var qtyText = cells[1].textContent.trim();
          var qty = parseInt(qtyText) || 0;
          // Try to extract item id from data attribute or onclick
          var itemId = row.getAttribute('data-item-id') || row.dataset.itemId;
          if (qty > 0 && name) {
            items.push({ name: name, qty: qty, soldQty: qty, itemId: itemId });
          }
        }
      });
      // If we couldn't parse items from detail view, show a message
      if (items.length === 0) {
        document.getElementById('partialRefundBody').innerHTML = '<div class="empty-state">Could not load sale items. Use Full Refund instead.</div>';
        return;
      }
      var html2 = '<div style="margin-bottom:12px;font-size:12px;color:var(--text2)">Select items and quantities to return:</div>';
      html2 += '<div style="max-height:300px;overflow-y:auto">';
      items.forEach(function(item, idx) {
        html2 += '<div style="display:flex;align-items:center;gap:10px;padding:8px;border-bottom:1px solid var(--border)">';
        html2 += '<input type="checkbox" id="ri_check_' + idx + '" checked style="accent-color:var(--accent)">';
        html2 += '<div style="flex:1;font-size:13px">' + item.name + '</div>';
        html2 += '<div style="font-size:12px;color:var(--text2)">Return:</div>';
        html2 += '<input type="number" id="ri_qty_' + idx + '" value="' + item.soldQty + '" min="0" max="' + item.soldQty + '" style="width:60px;background:var(--bg3);border:1px solid var(--border2);color:var(--text);padding:4px 6px;border-radius:2px;font-size:12px;text-align:center">';
        html2 += '<div style="font-size:11px;color:var(--text3)">/ ' + item.soldQty + '</div>';
        html2 += '</div>';
      });
      html2 += '</div>';
      html2 += '<div style="margin-top:10px;font-size:11px;color:var(--text3)">Items not checked will not be returned. Stock is only restored for returned items.</div>';
      document.getElementById('partialRefundBody').innerHTML = html2;
      document.getElementById('partialRefundBody')._items = items;
    });
}

function submitPartialRefund() {
  var items = document.getElementById('partialRefundBody')._items || [];
  var returnItems = [];
  items.forEach(function(item, idx) {
    var checked = document.getElementById('ri_check_' + idx);
    var qtyInput = document.getElementById('ri_qty_' + idx);
    if (checked && checked.checked && qtyInput) {
      var qty = parseInt(qtyInput.value) || 0;
      if (qty > 0) {
        returnItems.push({ sale_item_id: item.itemId ? parseInt(item.itemId) : 0, qty: qty });
      }
    }
  });
  if (returnItems.length === 0) {
    alert('No items selected for return.');
    return;
  }
  if (!confirm('Return ' + returnItems.length + ' item(s)? Stock will be restored.')) return;

  var fd = new FormData();
  fd.append('action', 'refund');
  fd.append('id', _currentSaleId);
  fd.append('return_items', JSON.stringify(returnItems));
  fd.append('csrf_token', _csrfToken);

  fetch('<?= BASE_URL ?>/pages/sales.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json().catch(function() { return { redirect: true }; }); })
    .then(function(d) {
      if (d.redirect !== false) window.location.reload();
    })
    .catch(function() { window.location.reload(); });
}
</script>

<script src="<?= BASE_URL ?>/&#34;/js/pie-chart.js"></script>
<script>
var CURRENCY_SALES = '<?= addslashes(CURRENCY) ?>';
renderPieChart('paymentPieChart', [
  { label: 'Cash', value: <?= floatval($paymentStats['cash']['total'] ?? 0) ?> },
  { label: 'Card', value: <?= floatval($paymentStats['card']['total'] ?? 0) ?> },
  { label: 'Mobile', value: <?= floatval($paymentStats['mobile']['total'] ?? 0) ?> },
  { label: 'Credit', value: <?= floatval($paymentStats['credit']['total'] ?? 0) ?> },
  { label: 'Bank', value: <?= floatval($paymentStats['bank_transfer']['total'] ?? 0) ?> }
], ['#3aff8a', '#4ab4ff', '#ff8c4a', '#ff4a6a', '#c084fc'], CURRENCY_SALES);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
