<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// AJAX view endpoint
if (isset($_GET['ajax_view'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['ajax_view']);
    $stmt = $conn->prepare("SELECT d.*, COALESCE(cv.name, 'Walk-in') cust_name, s.invoice_no FROM deliveries d LEFT JOIN customers_v2 cv ON d.customer_id=cv.id LEFT JOIN sales s ON d.sale_id=s.id WHERE d.id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $d = $stmt->get_result()->fetch_assoc();
    if (!$d) { echo json_encode(['error' => 'Not found']); exit; }
    $items = $conn->prepare("SELECT * FROM delivery_items WHERE delivery_id=? ORDER BY id");
    $items->bind_param("i", $id);
    $items->execute();
    echo json_encode(['delivery' => $d, 'items' => $items->get_result()->fetch_all(MYSQLI_ASSOC)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $saleId     = intval($_POST['sale_id'] ?? 0) ?: null;
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $projectId  = intval($_POST['project_id'] ?? 0) ?: null;
        $address    = trim($_POST['delivery_address'] ?? '');
        $date       = $_POST['delivery_date'] ?? date('Y-m-d');
        $notes      = trim($_POST['notes'] ?? '');
        $itemsJson  = $_POST['items'] ?? '[]';
        $items = json_decode($itemsJson, true);

        if (empty($items)) { header('Location: ?error=No+items'); exit; }

        $delNo = generateDeliveryNo($conn);
        $uid = $_SESSION['user_id'] ?? null;

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO deliveries (delivery_no, sale_id, customer_id, project_id, delivery_address, delivery_date, notes, user_id) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param("siiisssi", $delNo, $saleId, $customerId, $projectId, $address, $date, $notes, $uid);
            $stmt->execute();
            $delId = $conn->insert_id;

            foreach ($items as $item) {
                $pid = intval($item['product_id'] ?? 0) ?: null;
                $name = $item['name'] ?? '';
                $qtyOrdered = floatval($item['qty_ordered'] ?? $item['qty'] ?? 0);
                $qtyDelivered = floatval($item['qty_delivered'] ?? 0);
                $stmt2 = $conn->prepare("INSERT INTO delivery_items (delivery_id, product_id, product_name, qty_ordered, qty_delivered) VALUES (?,?,?,?,?)");
                $stmt2->bind_param("iisdd", $delId, $pid, $name, $qtyOrdered, $qtyDelivered);
                $stmt2->execute();
            }

            $totalOrdered = array_sum(array_column($items, 'qty_ordered'));
            $totalDelivered = array_sum(array_column($items, 'qty_delivered'));
            $status = 'pending';
            if ($totalDelivered >= $totalOrdered && $totalOrdered > 0) $status = 'delivered';
            elseif ($totalDelivered > 0) $status = 'partially_delivered';

            $stmt3 = $conn->prepare("UPDATE deliveries SET status=? WHERE id=?");
            $stmt3->bind_param("si", $status, $delId);
            $stmt3->execute();

            auditLog($conn, 'delivery_create', 'delivery', $delId, ['delivery_no' => $delNo]);
            $conn->commit();
            header('Location: ?msg=Delivery+' . urlencode($delNo) . '+created'); exit;
        } catch (Exception $ex) {
            $conn->rollback();
            header('Location: ?error=' . urlencode($ex->getMessage())); exit;
        }
    }

    if ($act === 'update_status') {
        $id = intval($_POST['id']);
        $status = $_POST['status'] ?? '';
        $validStatuses = ['pending','partially_delivered','delivered','cancelled'];
        if (!in_array($status, $validStatuses)) { header('Location: ?error=Invalid+status'); exit; }

        $stmt = $conn->prepare("UPDATE deliveries SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        header('Location: ?msg=Status+updated'); exit;
    }
}

$pageTitle = 'Deliveries';
$activePage = 'deliveries';
$statusFilter = $_GET['status'] ?? '';

$where = "WHERE 1=1";
$params = [];
$types = '';
if ($statusFilter && in_array($statusFilter, ['pending','partially_delivered','delivered','cancelled'])) {
    $where .= " AND d.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$stmt = $conn->prepare("SELECT d.*, COALESCE(cv.name, 'Walk-in') cust_name, s.invoice_no FROM deliveries d LEFT JOIN customers_v2 cv ON d.customer_id=cv.id LEFT JOIN sales s ON d.sale_id=s.id $where ORDER BY d.created_at DESC");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch data for create form
$sales = $conn->query("SELECT s.id, s.invoice_no, COALESCE(cv.name,'Walk-in') cust_name FROM sales s LEFT JOIN customers_v2 cv ON s.customer_v2_id=cv.id WHERE s.status='completed' ORDER BY s.created_at DESC")->fetch_all(MYSQLI_ASSOC);
$customers = $conn->query("SELECT id, name, type FROM customers_v2 WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Deliveries</div>
    <div class="page-subtitle"><?= count($deliveries) ?> deliveries</div>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('open')">+ New Delivery</button>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px">
      <select name="status" class="form-control" style="width:160px" onchange="this.form.submit()">
        <option value="">All Status</option>
        <?php foreach (['pending','partially_delivered','delivered','cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <table>
    <thead><tr><th>Delivery #</th><th>Customer</th><th>Invoice</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($deliveries): foreach ($deliveries as $d): ?>
      <tr>
        <td class="text-mono text-accent" style="font-size:12px"><?= e($d['delivery_no']) ?></td>
        <td style="font-size:12px"><?= e($d['cust_name']) ?></td>
        <td class="text-mono" style="font-size:12px"><?= $d['invoice_no'] ? e($d['invoice_no']) : '—' ?></td>
        <td class="text-muted" style="font-size:12px"><?= $d['delivery_date'] ? date('d/m/Y', strtotime($d['delivery_date'])) : '—' ?></td>
        <td>
          <?php
          $stBadge = 'badge-orange';
          if ($d['status'] === 'delivered') $stBadge = 'badge-green';
          elseif ($d['status'] === 'cancelled') $stBadge = 'badge-red';
          elseif ($d['status'] === 'partially_delivered') $stBadge = 'badge-blue';
          ?>
          <span class="badge <?= $stBadge ?>"><?= ucfirst(str_replace('_', ' ', $d['status'])) ?></span>
        </td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick="viewDelivery(<?= $d['id'] ?>)">View</button>
          <?php if ($d['status'] !== 'delivered' && $d['status'] !== 'cancelled' && isAdmin()): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Mark as delivered?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= $d['id'] ?>">
            <input type="hidden" name="status" value="delivered">
            <button class="btn btn-primary btn-sm">Delivered</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6" class="empty-state">No deliveries found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- CREATE DELIVERY MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal" style="max-width:600px">
    <div class="modal-header">
      <span class="modal-title">New Delivery</span>
      <span class="modal-close" onclick="document.getElementById('createModal').classList.remove('open')">&times;</span>
    </div>
    <form method="POST" id="createDeliveryForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="items" id="delItems" value="[]">
      <div class="modal-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
          <div>
            <label style="font-size:11px;color:var(--text2);display:block;margin-bottom:4px">Sale (optional)</label>
            <select name="sale_id" class="form-control" style="font-size:12px" onchange="onSaleSelect(this)">
              <option value="">No linked sale</option>
              <?php foreach ($sales as $s): ?>
                <option value="<?= $s['id'] ?>" data-cust="<?= e($s['cust_name']) ?>"><?= e($s['invoice_no']) ?> — <?= e($s['cust_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="font-size:11px;color:var(--text2);display:block;margin-bottom:4px">Customer</label>
            <select name="customer_id" id="delCustomer" class="form-control" style="font-size:12px">
              <option value="">— Select —</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= ucfirst($c['type']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
          <div>
            <label style="font-size:11px;color:var(--text2);display:block;margin-bottom:4px">Delivery Date</label>
            <input type="date" name="delivery_date" class="form-control" style="font-size:12px" value="<?= date('Y-m-d') ?>">
          </div>
          <div>
            <label style="font-size:11px;color:var(--text2);display:block;margin-bottom:4px">Address</label>
            <input type="text" name="delivery_address" class="form-control" style="font-size:12px" placeholder="Delivery address">
          </div>
        </div>
        <div style="margin-bottom:12px">
          <label style="font-size:11px;color:var(--text2);display:block;margin-bottom:4px">Notes</label>
          <input type="text" name="notes" class="form-control" style="font-size:12px" placeholder="Optional notes">
        </div>
        <div style="border-top:1px solid var(--border);padding-top:12px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong style="font-size:13px">Delivery Items</strong>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addDeliveryItem()">+ Add Item</button>
          </div>
          <div id="delItemsList" style="font-size:12px"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('createModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary" onclick="return submitDelivery()">Create Delivery</button>
      </div>
    </form>
  </div>
</div>

<!-- VIEW DELIVERY MODAL -->
<div class="modal-overlay" id="viewModal">
  <div class="modal" style="max-width:550px">
    <div class="modal-header">
      <span class="modal-title">Delivery Details</span>
      <span class="modal-close" onclick="document.getElementById('viewModal').classList.remove('open')">&times;</span>
    </div>
    <div class="modal-body" id="viewBody"></div>
  </div>
</div>

<script>
var PRODUCTS = [
  <?php
  $allProducts = $conn->query("SELECT id, name, sku, stock, unit_id FROM products WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
  foreach ($allProducts as $i => $p):
    if ($i > 0) echo ',';
    echo '{id:'.$p['id'].',name:"'.addslashes($p['name']).'",sku:"'.addslashes($p['sku'] ?? '').'",stock:'.$p['stock'].'}';
  endforeach;
  ?>
];

var delItems = [];

function addDeliveryItem() {
  var idx = delItems.length;
  delItems.push({product_id: 0, name: '', qty_ordered: 1, qty_delivered: 0});
  renderDelItems();
}

function removeDelItem(i) {
  delItems.splice(i, 1);
  renderDelItems();
}

function renderDelItems() {
  var html = '';
  if (!delItems.length) {
    html = '<div style="color:var(--text2);padding:10px;text-align:center">No items added yet</div>';
  }
  delItems.forEach(function(item, i) {
    html += '<div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;padding:6px;background:var(--bg3);border-radius:4px">';
    html += '<select onchange="onDelItemSelect('+i+',this)" class="form-control" style="flex:1;font-size:11px;padding:4px">';
    html += '<option value="">Select product</option>';
    PRODUCTS.forEach(function(p) {
      var sel = p.id == item.product_id ? ' selected' : '';
      html += '<option value="'+p.id+'"'+sel+'>'+p.name+' (Stock: '+p.stock+')</option>';
    });
    html += '</select>';
    html += '<input type="number" min="0" step="any" value="'+item.qty_ordered+'" onchange="delItems['+i+'].qty_ordered=parseFloat(this.value)||0" class="form-control" style="width:60px;font-size:11px;padding:4px" title="Qty Ordered">';
    html += '<input type="number" min="0" step="any" value="'+item.qty_delivered+'" onchange="delItems['+i+'].qty_delivered=parseFloat(this.value)||0" class="form-control" style="width:60px;font-size:11px;padding:4px" title="Qty Delivered">';
    html += '<button type="button" onclick="removeDelItem('+i+')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:16px">&times;</button>';
    html += '</div>';
  });
  document.getElementById('delItemsList').innerHTML = html;
}

function onDelItemSelect(i, sel) {
  var pid = parseInt(sel.value) || 0;
  var prod = PRODUCTS.find(function(p) { return p.id === pid; });
  delItems[i].product_id = pid;
  delItems[i].name = prod ? prod.name : '';
}

function submitDelivery() {
  if (!delItems.length) { alert('Add at least one item'); return false; }
  document.getElementById('delItems').value = JSON.stringify(delItems);
  return true;
}

function onSaleSelect(sel) {
  var opt = sel.options[sel.selectedIndex];
  if (opt && opt.value) {
    var custName = opt.dataset.cust || '';
    var custSel = document.getElementById('delCustomer');
    for (var i = 0; i < custSel.options.length; i++) {
      if (custSel.options[i].text.indexOf(custName) !== -1) {
        custSel.selectedIndex = i;
        break;
      }
    }
  }
}

function viewDelivery(id) {
  fetch('<?= BASE_URL ?>/pages/deliveries.php?ajax_view=' + id)
    .then(function(r) { return r.json(); })
    .then(function(d) {
      var html = '<div style="font-size:13px">';
      html += '<div style="display:flex;justify-content:space-between;margin-bottom:10px"><strong style="font-size:15px;color:var(--accent)">' + d.delivery.delivery_no + '</strong><span class="badge badge-' + (d.delivery.status==='delivered'?'green':d.delivery.status==='cancelled'?'red':d.delivery.status==='partially_delivered'?'blue':'orange') + '">' + d.delivery.status.replace(/_/g,' ') + '</span></div>';
      html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:12px">';
      html += '<div><span style="color:var(--text2)">Customer:</span> ' + (d.delivery.cust_name || 'Walk-in') + '</div>';
      html += '<div><span style="color:var(--text2)">Date:</span> ' + (d.delivery.delivery_date || 'N/A') + '</div>';
      html += '<div style="grid-column:span 2"><span style="color:var(--text2)">Address:</span> ' + (d.delivery.delivery_address || 'N/A') + '</div>';
      if (d.delivery.notes) html += '<div style="grid-column:span 2"><span style="color:var(--text2)">Notes:</span> ' + d.delivery.notes + '</div>';
      html += '</div>';
      html += '<table style="width:100%;font-size:12px"><thead><tr><th style="text-align:left;padding:4px">Item</th><th style="text-align:center;padding:4px">Ordered</th><th style="text-align:center;padding:4px">Delivered</th></tr></thead><tbody>';
      d.items.forEach(function(it) {
        html += '<tr><td style="padding:4px;border-top:1px solid var(--border)">' + it.product_name + '</td><td style="text-align:center;padding:4px;border-top:1px solid var(--border)">' + it.qty_ordered + '</td><td style="text-align:center;padding:4px;border-top:1px solid var(--border)">' + it.qty_delivered + '</td></tr>';
      });
      html += '</tbody></table></div>';
      document.getElementById('viewBody').innerHTML = html;
      document.getElementById('viewModal').classList.add('open');
    })
    .catch(function() { alert('Failed to load delivery details'); });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
