<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'create_order') {
        $sup  = intval($_POST['supplier_id']);
        $note = trim($_POST['note'] ?? '');
        $no   = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $supVal = $sup ?: null;
        $stmt = $conn->prepare("INSERT INTO orders (order_no,supplier_id,note) VALUES (?,?,?)");
        $stmt->bind_param("sis", $no, $supVal, $note);
        $stmt->execute();
        $oid = $conn->insert_id;

        $items = json_decode($_POST['items'] ?? '[]', true);
        $total = 0;
        foreach ($items as $item) {
            $pid   = intval($item['product_id']);
            $pname = $item['product_name'];
            $qty   = floatval($item['qty']);
            $cost  = floatval($item['cost']);
            $itot  = $qty * $cost;
            $total += $itot;
            $stmt2 = $conn->prepare("INSERT INTO order_items (order_id,product_id,product_name,qty,cost,total) VALUES (?,?,?,?,?,?)");
            $stmt2->bind_param("iisidd", $oid, $pid, $pname, $qty, $cost, $itot);
            $stmt2->execute();
        }
        $stmt3 = $conn->prepare("UPDATE orders SET total=? WHERE id=?");
        $stmt3->bind_param("di", $total, $oid);
        $stmt3->execute();
        header('Location: ?msg=Order+created'); exit;
    }

    if ($act === 'receive') {
        $oid = intval($_POST['order_id']);
        $stmt = $conn->prepare("SELECT * FROM orders WHERE id=? AND status='pending'");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $ord = $stmt->get_result()->fetch_assoc();
        if (!$ord) { header('Location: ?error=Order+not+found+or+already+received'); exit; }

        $conn->begin_transaction();
        try {
            $stmt2 = $conn->prepare("SELECT * FROM order_items WHERE order_id=?");
            $stmt2->bind_param("i", $oid);
            $stmt2->execute();
            $items = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($items as $item) {
                if ($item['product_id']) {
                    $stmt3 = $conn->prepare("UPDATE products SET stock=stock+? WHERE id=?");
                    $stmt3->bind_param("di", $item['qty'], $item['product_id']);
                    $stmt3->execute();
                    logInventoryMovement($conn, $item['product_id'], $item['qty'], 'stock_in', 'stock_in', "Order {$ord['order_no']} received");
                }
            }
            $stmt5 = $conn->prepare("UPDATE orders SET status='received', received_at=NOW() WHERE id=?");
            $stmt5->bind_param("i", $oid);
            $stmt5->execute();
            auditLog($conn, 'order_receive', 'order', $oid, ['order_no' => $ord['order_no'], 'total' => $ord['total']]);
            $conn->commit();
            header('Location: ?msg=Order+received+and+stock+updated'); exit;
        } catch (Exception $ex) {
            $conn->rollback();
            error_log('ORDER RECEIVE ERROR: ' . $ex->getMessage());
            header('Location: ?error=Order+receive+failed'); exit;
        }
    }

    if ($act === 'cancel') {
        $oid = intval($_POST['order_id']);
        $stmt = $conn->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='pending'");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        header('Location: ?msg=Order+cancelled'); exit;
    }
}

$pageTitle  = 'Orders';
$activePage = 'orders';

$supFilter  = intval($_GET['supplier_id'] ?? 0);
if ($supFilter) {
    $stmt = $conn->prepare("SELECT o.*,s.name sup_name FROM orders o LEFT JOIN suppliers s ON o.supplier_id=s.supplier_id WHERE o.supplier_id=? ORDER BY o.ordered_at DESC");
    $stmt->bind_param("i", $supFilter);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $orders = $conn->query("SELECT o.*,s.name sup_name FROM orders o LEFT JOIN suppliers s ON o.supplier_id=s.supplier_id ORDER BY o.ordered_at DESC")->fetch_all(MYSQLI_ASSOC);
}
$suppliers  = $conn->query("SELECT * FROM suppliers ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$products   = $conn->query("SELECT id,name,cost FROM products ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div><div class="page-title">Purchase Orders</div></div>
  <button class="btn btn-primary" onclick="openModal('newOrderModal')">+ New Order</button>
</div>

<?php if ($supFilter): ?>
  <div class="alert alert-success" style="margin-bottom:14px">
    Showing orders for: <strong><?= e($suppliers[array_search($supFilter, array_column($suppliers,'supplier_id'))]['name'] ?? '') ?></strong>
    &nbsp;<a href="orders.php" style="color:inherit;text-decoration:underline">Show all</a>
  </div>
<?php endif; ?>

<div class="table-card">
  <table>
    <thead><tr><th>Order #</th><th>Supplier</th><th>Total</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($orders): foreach ($orders as $o): ?>
      <tr>
        <td class="text-mono text-accent" style="font-size:12px"><?= e($o['order_no']) ?></td>
        <td><?= e($o['sup_name'] ?? '—') ?></td>
        <td class="text-mono"><?= money($o['total']) ?></td>
        <td>
          <span class="badge <?= $o['status']==='received'?'badge-green':($o['status']==='cancelled'?'badge-red':'badge-blue') ?>">
            <?= ucfirst($o['status']) ?>
          </span>
        </td>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($o['ordered_at'])) ?></td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick="viewOrder(<?= $o['id'] ?>)">View</button>
          <?php if ($o['status'] === 'pending'): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Mark as received? This will update stock.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="receive">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <button type="submit" class="btn btn-primary btn-sm">Receive</button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirmDelete('Cancel this order?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6" class="empty-state">No orders yet</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- New Order Modal -->
<div class="modal-overlay" id="newOrderModal">
  <div class="modal" style="max-width:600px">
    <div class="modal-header"><span class="modal-title">New Purchase Order</span><span class="modal-close" onclick="closeModal('newOrderModal')">&times;</span></div>
    <form method="POST" onsubmit="return prepareOrder()">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_order">
        <input type="hidden" name="items" id="orderItemsJson">
        <div class="form-grid" style="margin-bottom:14px">
          <div class="form-group">
            <label>Supplier</label>
            <select name="supplier_id" class="form-control">
              <option value="">— Select Supplier —</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= $s['supplier_id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Note</label>
            <input name="note" class="form-control" placeholder="Optional note">
          </div>
        </div>

        <div style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin-bottom:12px">
          <div style="font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Add Items</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="flex:2;min-width:160px">
              <label>Product</label>
              <select id="oProduct" class="form-control" onchange="fillCost()">
                <option value="">— Select —</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-cost="<?= $p['cost'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:1;min-width:80px">
              <label>Qty</label>
              <input type="number" id="oQty" min="1" value="1" class="form-control">
            </div>
            <div class="form-group" style="flex:1;min-width:100px">
              <label>Cost/Unit</label>
              <input type="number" id="oCost" step="0.01" min="0" value="0" class="form-control">
            </div>
            <button type="button" class="btn btn-primary btn-sm" style="margin-bottom:1px" onclick="addOrderItem()">Add</button>
          </div>
        </div>

        <div class="table-card">
          <table>
            <thead><tr><th>Product</th><th>Qty</th><th>Cost</th><th>Total</th><th></th></tr></thead>
            <tbody id="orderItemsBody"><tr><td colspan="5" class="empty-state" style="padding:20px">No items added yet</td></tr></tbody>
          </table>
        </div>
        <div style="text-align:right;margin-top:10px;font-size:15px;font-weight:700">
          Order Total: <span id="orderTotal" class="text-accent text-mono"><?= CURRENCY ?>0.00</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('newOrderModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Order</button>
      </div>
    </form>
  </div>
</div>

<!-- Order Detail Modal -->
<div class="modal-overlay" id="orderDetailModal">
  <div class="modal" style="max-width:540px">
    <div class="modal-header"><span class="modal-title">Order Details</span><span class="modal-close" onclick="closeModal('orderDetailModal')">&times;</span></div>
    <div class="modal-body" id="orderDetailBody" style="padding:20px"></div>
    <div class="modal-footer"><button onclick="closeModal('orderDetailModal')" class="btn btn-secondary">Close</button></div>
  </div>
</div>

<script>
var CURRENCY    = '<?= CURRENCY ?>';
var orderItems  = [];

function fmt(n) { return CURRENCY + parseFloat(n).toFixed(2); }

function fillCost() {
  var sel = document.getElementById('oProduct');
  var opt = sel.options[sel.selectedIndex];
  document.getElementById('oCost').value = opt.dataset.cost || 0;
}

function addOrderItem() {
  var sel  = document.getElementById('oProduct');
  var opt  = sel.options[sel.selectedIndex];
  var pid  = parseInt(sel.value);
  var qty  = parseInt(document.getElementById('oQty').value) || 1;
  var cost = parseFloat(document.getElementById('oCost').value) || 0;
  if (!pid) { alert('Select a product'); return; }
  var existing = orderItems.find(function(i){ return i.product_id === pid; });
  if (existing) { existing.qty += qty; existing.total = existing.qty * existing.cost; }
  else { orderItems.push({ product_id: pid, product_name: opt.dataset.name, qty: qty, cost: cost, total: qty * cost }); }
  renderOrderItems();
  sel.value = ''; document.getElementById('oQty').value = 1; document.getElementById('oCost').value = 0;
}

function removeOrderItem(idx) { orderItems.splice(idx, 1); renderOrderItems(); }

function renderOrderItems() {
  var tbody = document.getElementById('orderItemsBody');
  if (!orderItems.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-state" style="padding:20px">No items added yet</td></tr>';
    document.getElementById('orderTotal').textContent = fmt(0);
    return;
  }
  tbody.innerHTML = orderItems.map(function(item, i) {
    return '<tr><td>' + item.product_name + '</td><td class="text-mono">' + item.qty + '</td><td class="text-mono">' + fmt(item.cost) + '</td><td class="text-mono text-accent">' + fmt(item.total) + '</td><td><span style="cursor:pointer;color:var(--red);font-size:16px" onclick="removeOrderItem(' + i + ')">×</span></td></tr>';
  }).join('');
  document.getElementById('orderTotal').textContent = fmt(orderItems.reduce(function(s,i){ return s + i.total; }, 0));
}

function prepareOrder() {
  if (!orderItems.length) { alert('Add at least one item'); return false; }
  document.getElementById('orderItemsJson').value = JSON.stringify(orderItems);
  return true;
}

function viewOrder(id) {
  document.getElementById('orderDetailBody').innerHTML = '<div class="empty-state">Loading...</div>';
  openModal('orderDetailModal');
  fetch('<?= BASE_URL ?>/pages/order_detail.php?id=' + id).then(function(r){ return r.text(); }).then(function(h){ document.getElementById('orderDetailBody').innerHTML = h; });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
