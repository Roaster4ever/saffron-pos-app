<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Handle status changes and create
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $projectId  = intval($_POST['project_id'] ?? 0) ?: null;
        $notes      = trim($_POST['notes'] ?? '');
        $terms      = trim($_POST['terms'] ?? QUOTATION_FOOTER);
        $validUntil = $_POST['valid_until'] ?: null;
        $itemsJson  = $_POST['items'] ?? '[]';
        $items = json_decode($itemsJson, true);

        if (empty($items)) { header('Location: ?error=No+items+added'); exit; }

        $quotationNo = generateQuotationNo($conn);

        // Calculate totals (server-side tax resolution)
        $subtotal = 0;
        $taxTotal = 0;
        foreach ($items as &$item) {
            $lineSub = floatval($item['price']) * floatval($item['qty']);
            $item['total'] = $lineSub;
            $subtotal += $lineSub;
            $pid = intval($item['product_id'] ?? $item['id'] ?? 0);
            $taxInfo = $pid ? resolveTaxById($conn, $pid) : ['taxable' => false, 'rate' => 0];
            $tax = $taxInfo['taxable'] ? round($lineSub * $taxInfo['rate'] / 100, 2) : 0;
            $item['tax_amount'] = $tax;
            $item['gst_rate'] = $taxInfo['rate'];
            $item['taxable'] = $taxInfo['taxable'] ? 1 : 0;
            $taxTotal += $tax;
        }
        unset($item);
        $total = round($subtotal + $taxTotal, 2);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO quotations (quotation_no, customer_id, project_id, subtotal, tax, total, notes, terms, valid_until, user_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $uid = $_SESSION['user_id'] ?? null;
            $stmt->bind_param("siidddsssi", $quotationNo, $customerId, $projectId, $subtotal, $taxTotal, $total, $notes, $terms, $validUntil, $uid);
            $stmt->execute();
            $quoId = $conn->insert_id;

            foreach ($items as $item) {
                $pid = intval($item['product_id'] ?? 0) ?: null;
                $name = $item['name'] ?? '';
                $qty = floatval($item['qty']);
                $unit = $item['unit'] ?? 'pc';
                $price = floatval($item['price']);
                $itot = floatval($item['total']);
                $taxAmt = floatval($item['tax_amount']);
                $gstRate = floatval($item['gst_rate'] ?? 0);
                $discount = floatval($item['discount'] ?? 0);

                $stmt2 = $conn->prepare("INSERT INTO quotation_items (quotation_id, product_id, product_name, qty, unit, price, discount, tax_amount, gst_rate, total) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt2->bind_param("iisdsddddd", $quoId, $pid, $name, $qty, $unit, $price, $discount, $taxAmt, $gstRate, $itot);
                $stmt2->execute();
            }

            auditLog($conn, 'quotation_create', 'quotation', $quoId, ['quotation_no' => $quotationNo, 'total' => $total]);
            $conn->commit();
            header('Location: ?msg=Quotation+' . urlencode($quotationNo) . '+created'); exit;
        } catch (Exception $ex) {
            $conn->rollback();
            header('Location: ?error=' . urlencode($ex->getMessage())); exit;
        }
    }

    if ($act === 'status') {
        $id = intval($_POST['id']);
        $status = $_POST['status'] ?? '';
        $validStatuses = ['draft','sent','approved','rejected','converted','cancelled'];
        if (!in_array($status, $validStatuses)) { header('Location: ?error=Invalid+status'); exit; }

        $stmt = $conn->prepare("UPDATE quotations SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        auditLog($conn, 'quotation_status', 'quotation', $id, ['status' => $status]);
        header('Location: ?msg=Status+updated'); exit;
    }

    if ($act === 'convert') {
        // Convert quotation to sale
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("SELECT q.*, GROUP_CONCAT(qi.product_id) product_ids, GROUP_CONCAT(qi.qty) qtys, GROUP_CONCAT(qi.price) prices, GROUP_CONCAT(qi.product_name) names, GROUP_CONCAT(qi.unit) units FROM quotations q JOIN quotation_items qi ON q.id=qi.quotation_id WHERE q.id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $quo = $stmt->get_result()->fetch_assoc();

        if (!$quo || $quo['status'] === 'converted') {
            header('Location: ?error=Cannot+convert'); exit;
        }

        $pids = explode(',', $quo['product_ids']);
        $qtys = explode(',', $quo['qtys']);
        $prices = explode(',', $quo['prices']);
        $names = explode(',', $quo['names']);
        $units = explode(',', $quo['units']);

        // Validate stock availability before conversion
        $stockErrors = [];
        for ($i = 0; $i < count($pids); $i++) {
            $pid = intval($pids[$i]);
            $qty = floatval($qtys[$i]);
            if ($pid && $qty > 0) {
                $stockStmt = $conn->prepare("SELECT stock, reserved_stock, name FROM products WHERE id=?");
                $stockStmt->bind_param("i", $pid);
                $stockStmt->execute();
                $prod = $stockStmt->get_result()->fetch_assoc();
                if ($prod) {
                    $available = $prod['stock'] - ($prod['reserved_stock'] ?? 0);
                    if ($qty > $available) {
                        $stockErrors[] = e($prod['name']) . " (need {$qty}, available {$available})";
                    }
                }
            }
        }
        if (!empty($stockErrors)) {
            header('Location: ?error=Insufficient+stock:+' . urlencode(implode(', ', $stockErrors))); exit;
        }

        $invoice = generateInvoice($conn);
        $uid = $_SESSION['user_id'] ?? null;

        $conn->begin_transaction();
        try {
            $customerId = $quo['customer_id'] ?: null;
            $projectId = $quo['project_id'] ?: null;
            $paid = $quo['total'];
            $method = 'cash';
            $outstanding = 0;
            $stmt2 = $conn->prepare("INSERT INTO sales (invoice_no, customer_v2_id, project_id, quotation_id, user_id, subtotal, discount, tax, total, paid, change_amount, payment_method, outstanding) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt2->bind_param("siiiiddddddsd", $invoice, $customerId, $projectId, $id, $uid, $quo['subtotal'], 0, $quo['tax'], $quo['total'], $paid, 0, $method, $outstanding);
            $stmt2->execute();
            $saleId = $conn->insert_id;

            for ($i = 0; $i < count($pids); $i++) {
                $pid = intval($pids[$i]) ?: null;
                $qty = floatval($qtys[$i]);
                $price = floatval($prices[$i]);
                $name = $names[$i];
                $unit = $units[$i];
                $itot = $price * $qty;

                $stmt3 = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit, price, total) VALUES (?,?,?,?,?,?,?)");
                $stmt3->bind_param("iisdsdd", $saleId, $pid, $name, $qty, $unit, $price, $itot);
                $stmt3->execute();

                if ($pid) {
                    $stmt4 = $conn->prepare("UPDATE products SET stock=stock-? WHERE id=?");
                    $stmt4->bind_param("di", $qty, $pid);
                    $stmt4->execute();
                    logInventoryMovement($conn, $pid, -$qty, 'sale', 'sale', "Quotation $quo[quotation_no] converted");
                }
            }

            // Update quotation status
            $stmt5 = $conn->prepare("UPDATE quotations SET status='converted' WHERE id=?");
            $stmt5->bind_param("i", $id);
            $stmt5->execute();

            auditLog($conn, 'quotation_convert', 'quotation', $id, [
                'quotation_no' => $quo['quotation_no'],
                'invoice_no' => $invoice,
                'total' => $quo['total'],
            ]);

            $conn->commit();
            header('Location: ' . BASE_URL . '/pages/sales.php?msg=Quotation+converted+to+invoice+' . $invoice); exit;
        } catch (Exception $ex) {
            $conn->rollback();
            header('Location: ?error=' . urlencode($ex->getMessage())); exit;
        }
    }
}

$pageTitle = 'Quotations';
$activePage = 'quotations';
$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$where = "WHERE 1=1";
$params = [];
$types = '';
if ($search) {
    $like = "%{$search}%";
    $where .= " AND (q.quotation_no LIKE ? OR COALESCE(cv.name,'') LIKE ?)";
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}
if ($statusFilter && in_array($statusFilter, ['draft','sent','approved','rejected','converted','cancelled'])) {
    $where .= " AND q.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$stmt = $conn->prepare("SELECT q.*, COALESCE(cv.name,'Walk-in') cust_name FROM quotations q LEFT JOIN customers_v2 cv ON q.customer_id=cv.id $where ORDER BY q.created_at DESC");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$quotations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$customers = $conn->query("SELECT id, name, type FROM customers_v2 WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$products = $conn->query("SELECT id, name, sku, brand_id, price, wholesale_price, contractor_price, unit_id, tax_mode, taxable, gst_rate FROM products WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$brands = $conn->query("SELECT id, name FROM brands ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Quotations</div>
    <div class="page-subtitle"><?= count($quotations) ?> quotations</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('createModal')">+ New Quotation</button>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" name="q" class="search-box" placeholder="Search number, customer..." value="<?= e($search) ?>">
      <select name="status" class="form-control" style="width:130px" onchange="this.form.submit()">
        <option value="">All Status</option>
        <?php foreach (['draft','sent','approved','rejected','converted','cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm">Search</button>
    </form>
  </div>
  <table>
    <thead><tr><th>#</th><th>Customer</th><th>Total</th><th>Status</th><th>Valid Until</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($quotations): foreach ($quotations as $i => $q): ?>
      <tr>
        <td class="text-mono text-accent" style="font-size:12px"><?= e($q['quotation_no']) ?></td>
        <td style="font-size:12px"><?= e($q['cust_name']) ?></td>
        <td class="text-mono"><strong><?= money($q['total']) ?></strong></td>
        <td><span class="badge <?= in_array($q['status'], ['approved','converted']) ? 'badge-green' : ($q['status'] === 'sent' ? 'badge-blue' : ($q['status'] === 'rejected' ? 'badge-red' : 'badge-orange')) ?>"><?= ucfirst($q['status']) ?></span></td>
        <td class="text-muted" style="font-size:12px"><?= $q['valid_until'] ? date('d/m/Y', strtotime($q['valid_until'])) : '—' ?></td>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y', strtotime($q['created_at'])) ?></td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick='viewQuo(<?= json_encode($q) ?>)'>View</button>
          <?php if ($q['status'] !== 'converted' && $q['status'] !== 'cancelled' && isAdmin()): ?>
            <?php if (in_array($q['status'], ['draft'])): ?>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $q['id'] ?>">
              <input type="hidden" name="status" value="sent">
              <button class="btn btn-secondary btn-sm">Send</button>
            </form>
            <?php endif; ?>
            <?php if (in_array($q['status'], ['sent'])): ?>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $q['id'] ?>">
              <input type="hidden" name="status" value="approved">
              <button class="btn btn-secondary btn-sm" style="color:var(--green)">Approve</button>
            </form>
            <?php endif; ?>
            <?php if (in_array($q['status'], ['approved'])): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Convert to sale? Stock will be deducted.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?= $q['id'] ?>">
              <button class="btn btn-primary btn-sm">Convert</button>
            </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="empty-state">No quotations found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- View Quotation Modal -->
<div class="modal-overlay" id="viewModal">
  <div class="modal" style="max-width:600px">
    <div class="modal-header"><span class="modal-title">Quotation Details</span><span class="modal-close" onclick="closeModal('viewModal')">&times;</span></div>
    <div class="modal-body" id="quoBody"></div>
    <div class="modal-footer">
      <button onclick="printQuo()" class="btn btn-secondary btn-sm">Print</button>
      <button onclick="closeModal('viewModal')" class="btn btn-primary btn-sm">Close</button>
    </div>
  </div>
</div>

<!-- Create Quotation Modal -->
<div class="modal-overlay" id="createModal">
  <div class="modal" style="max-width:700px;max-height:90vh;overflow-y:auto">
    <div class="modal-header"><span class="modal-title">New Quotation</span><span class="modal-close" onclick="closeModal('createModal')">&times;</span></div>
    <form method="POST" id="quoForm"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <div class="form-grid" style="margin-bottom:14px">
        <div class="form-group" style="grid-column:1/-1">
          <label>Customer</label>
          <select name="customer_id" class="form-control" id="quoCustomer">
            <option value="">— Walk-in —</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= ucfirst($c['type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Valid Until</label><input name="valid_until" type="date" class="form-control" value="<?= date('Y-m-d', strtotime('+15 days')) ?>"></div>
      </div>

      <!-- Product items -->
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:6px">Items</div>
      <div id="quoItems" style="margin-bottom:12px"></div>
      <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:14px">
        <div style="flex:2">
          <select id="quoProdSelect" class="form-control" style="font-size:12px" onchange="onQuoProdChange()">
            <option value="">— Select Product —</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>"
                data-name="<?= e($p['name']) ?>"
                data-price="<?= $p['price'] ?>"
                data-unit="<?= $p['unit_id'] ?? 1 ?>"
                data-taxable="<?= $p['taxable'] ?>"
                data-gst="<?= $p['gst_rate'] ?>"
                data-taxmode="<?= $p['tax_mode'] ?? 'default' ?>"
              ><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="width:70px"><input id="quoQty" type="number" min="1" value="1" class="form-control" style="font-size:12px"></div>
        <div style="width:100px"><input id="quoPrice" type="number" step="0.01" min="0" class="form-control" style="font-size:12px" placeholder="Price"></div>
        <button type="button" class="btn btn-primary btn-sm" onclick="addQuoItem()">Add</button>
      </div>

      <div style="text-align:right;margin-bottom:14px">
        <span style="font-size:11px;color:var(--text2)">Subtotal: <strong id="quoSubtotal"><?= CURRENCY ?>0.00</strong></span>
        <span style="font-size:11px;color:var(--text2);margin-left:12px">Tax: <strong id="quoTax"><?= CURRENCY ?>0.00</strong></span>
        <span style="font-size:11px;color:var(--text2);margin-left:12px">Total: <strong id="quoTotal" style="color:var(--accent)"><?= CURRENCY ?>0.00</strong></span>
      </div>

      <input type="hidden" name="items" id="quoItemsInput" value="[]">
      <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button><button type="submit" class="btn btn-primary">Create Quotation</button></div></form>
  </div>
</div>

<script>
var QUO_PRODUCTS = <?= json_encode($products) ?>;
var QUO_BRANDS = <?= json_encode($brands) ?>;
var CURRENCY_Q = '<?= addslashes(CURRENCY) ?>';
var quoItems = [];

function onQuoProdChange() {
  var sel = document.getElementById('quoProdSelect');
  var opt = sel.options[sel.selectedIndex];
  if (opt.value) {
    document.getElementById('quoPrice').value = opt.dataset.price || 0;
  }
}

function addQuoItem() {
  var sel = document.getElementById('quoProdSelect');
  var opt = sel.options[sel.selectedIndex];
  if (!opt.value) return;
  var qty = parseInt(document.getElementById('quoQty').value) || 1;
  var price = parseFloat(document.getElementById('quoPrice').value) || parseFloat(opt.dataset.price) || 0;
  var taxMode = opt.dataset.taxmode || 'default';
  var gstRate = parseFloat(opt.dataset.gst) || 0;

  quoItems.push({
    product_id: parseInt(opt.value),
    name: opt.dataset.name,
    qty: qty,
    unit: 'pc',
    price: price,
    tax_mode: taxMode,
    gst_rate: gstRate,
    discount: 0
  });
  renderQuoItems();
}

function removeQuoItem(i) {
  quoItems.splice(i, 1);
  renderQuoItems();
}

function renderQuoItems() {
  var defaultRate = <?= getSetting('default_tax_rate', 18) ?>;
  function effRate(item) {
    if (item.tax_mode === 'non_taxable') return 0;
    if (item.tax_mode === 'custom') return item.gst_rate || 0;
    return defaultRate;
  }
  var html = quoItems.map(function(item, i) {
    var lineTotal = item.price * item.qty;
    var rate = effRate(item);
    var tax = lineTotal * rate / 100;
    return '<div style="display:flex;gap:8px;align-items:center;padding:8px;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:6px;font-size:13px">' +
      '<span style="flex:2;font-weight:600">' + item.name + '</span>' +
      '<span style="width:50px;text-align:center">' + item.qty + '</span>' +
      '<span style="width:80px;text-align:right" class="text-mono">' + CURRENCY_Q + item.price.toFixed(2) + '</span>' +
      (tax > 0 ? '<span style="width:60px;text-align:right;font-size:11px;color:var(--text2)">+' + CURRENCY_Q + tax.toFixed(2) + '</span>' : '') +
      '<span style="width:80px;text-align:right;font-weight:700" class="text-mono">' + CURRENCY_Q + (lineTotal + tax).toFixed(2) + '</span>' +
      '<span style="color:var(--red);cursor:pointer" onclick="removeQuoItem(' + i + ')">&times;</span>' +
    '</div>';
  }).join('');
  document.getElementById('quoItems').innerHTML = html || '<div class="empty-state" style="padding:12px">No items yet</div>';

  var sub = quoItems.reduce(function(s, item) { return s + item.price * item.qty; }, 0);
  var tax = quoItems.reduce(function(s, item) {
    return s + (item.price * item.qty * effRate(item) / 100);
  }, 0);
  document.getElementById('quoSubtotal').textContent = CURRENCY_Q + sub.toFixed(2);
  document.getElementById('quoTax').textContent = CURRENCY_Q + tax.toFixed(2);
  document.getElementById('quoTotal').textContent = CURRENCY_Q + (sub + tax).toFixed(2);
  document.getElementById('quoItemsInput').value = JSON.stringify(quoItems);
}

function viewQuo(q) {
  var statusBadges = { draft: 'badge-orange', sent: 'badge-blue', approved: 'badge-green', rejected: 'badge-red', converted: 'badge-green', cancelled: 'badge-red' };
  document.getElementById('quoBody').innerHTML =
    '<div style="margin-bottom:12px">' +
      '<div style="font-size:18px;font-weight:700">' + q.quotation_no + '</div>' +
      '<div style="font-size:12px;color:var(--text2);margin-top:4px">' + q.cust_name + ' &middot; <span class="badge ' + (statusBadges[q.status]||'badge-orange') + '">' + q.status.toUpperCase() + '</span></div>' +
      (q.valid_until ? '<div style="font-size:11px;color:var(--text2);margin-top:2px">Valid until: ' + q.valid_until + '</div>' : '') +
    '</div>' +
    '<div style="font-size:14px;font-weight:700;margin:14px 0 8px">Items</div>' +
    '<div id="quoDetailItems"></div>' +
    '<div style="text-align:right;margin-top:12px;font-size:13px">' +
      '<div>Subtotal: ' + CURRENCY_Q + parseFloat(q.subtotal).toFixed(2) + '</div>' +
      (parseFloat(q.tax) > 0 ? '<div>Tax: ' + CURRENCY_Q + parseFloat(q.tax).toFixed(2) + '</div>' : '') +
      '<div style="font-size:16px;font-weight:800;margin-top:6px;padding-top:6px;border-top:2px solid var(--border)">Total: ' + CURRENCY_Q + parseFloat(q.total).toFixed(2) + '</div>' +
    '</div>' +
    (q.notes ? '<div style="margin-top:12px;padding:10px;background:var(--bg3);border-radius:var(--radius);font-size:12px;color:var(--text2)"><strong>Note:</strong> ' + q.notes + '</div>' : '');
  openModal('viewModal');

  // Fetch items
  fetch('<?= BASE_URL ?>/pages/quotation_detail.php?id=' + q.id)
    .then(function(r) { return r.text(); })
    .then(function(h) { document.getElementById('quoDetailItems').innerHTML = h; });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
