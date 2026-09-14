<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add' || $act === 'edit') {
        $name    = trim($_POST['name'] ?? '');
        $cat     = intval($_POST['category_id'] ?? 0);
        $brand   = intval($_POST['brand_id'] ?? 0);
        $price   = floatval($_POST['price'] ?? 0);
        $cost    = floatval($_POST['cost'] ?? 0);
        $stock   = floatval($_POST['stock'] ?? 0);
        $alert   = floatval($_POST['low_stock_alert'] ?? 5);
        $barcode = trim($_POST['barcode'] ?? '');
        $sku     = trim($_POST['sku'] ?? '');
        $model   = trim($_POST['model'] ?? '');
        $unitId  = intval($_POST['unit_id'] ?? 0);
        $taxable = isset($_POST['taxable']) ? 1 : 0;
        $gstRate = floatval($_POST['gst_rate'] ?? 18);
        $taxMode = $_POST['tax_mode'] ?? 'default';
        if (!in_array($taxMode, ['default','non_taxable','custom'])) $taxMode = 'default';
        // Derive legacy taxable/gst_rate from tax_mode for backward compat
        if ($taxMode === 'non_taxable') {
            $taxable = 0;
            $gstRate = 0;
        } elseif ($taxMode === 'custom') {
            $taxable = 1;
        } else { // default
            $taxable = 1;
            $gstRate = 18;
        }
        $minPrice    = floatval($_POST['min_price'] ?? 0);
        $wholesale   = floatval($_POST['wholesale_price'] ?? 0);
        $contractor  = floatval($_POST['contractor_price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $isActive    = isset($_POST['is_active']) ? 1 : 1;
        // Measured product fields
        $sellingMode = $_POST['selling_mode'] ?? 'fixed';
        if (!in_array($sellingMode, ['fixed','measured'])) $sellingMode = 'fixed';
        $standardLengths = trim($_POST['standard_lengths'] ?? '');
        $defaultQty = strlen($_POST['default_qty'] ?? '') > 0 ? floatval($_POST['default_qty']) : null;

        // Validate measured product requires a unit with allows_decimal
        if ($sellingMode === 'measured' && $unitId) {
            $unitCheck = $conn->prepare("SELECT allows_decimal FROM units WHERE id=?");
            $unitCheck->bind_param("i", $unitId);
            $unitCheck->execute();
            $uRow = $unitCheck->get_result()->fetch_assoc();
            if ($uRow && !$uRow['allows_decimal']) {
                $sellingMode = 'fixed'; // Force fixed if unit doesn't allow decimal
            }
        }

        if (!$name) { header('Location: ?error=Name+required'); exit; }

        // ── Duplicate detection ──
        $editId = $act === 'edit' ? intval($_POST['id'] ?? 0) : 0;
        if ($sku) {
            $check = $conn->prepare("SELECT id, name, stock FROM products WHERE sku=? AND id != ?");
            $check->bind_param("si", $sku, $editId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            if ($existing) {
                header('Location: ?error=' . urlencode("SKU \"{$sku}\" already exists on product \"{$existing['name']}\" (Stock: {$existing['stock']})"));
                exit;
            }
        }
        if ($barcode) {
            $check = $conn->prepare("SELECT id, name, stock FROM products WHERE barcode=? AND id != ?");
            $check->bind_param("si", $barcode, $editId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            if ($existing) {
                header('Location: ?error=' . urlencode("Barcode \"{$barcode}\" already exists on product \"{$existing['name']}\" (Stock: {$existing['stock']})"));
                exit;
            }
        }

        $catVal = $cat ?: null;
        $brandVal = $brand ?: null;
        $unitVal = $unitId ?: null;

        if ($act === 'add') {
            // Types: cat(i),brand(i),name(s),sku(s),barcode(s),model(s),price(d),cost(d),minP(d),wholesale(d),contractor(d),stock(d),alert(d),taxable(i),gstRate(d),taxMode(s),unit(i),sellMode(s),stdLen(s),defQty(s),desc(s),active(i)
            $stmt = $conn->prepare("INSERT INTO products (category_id,brand_id,name,sku,barcode,model,price,cost,min_price,wholesale_price,contractor_price,stock,low_stock_alert,taxable,gst_rate,tax_mode,unit_id,selling_mode,standard_lengths,default_qty,description,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iissssdddddddidsissssi", $catVal, $brandVal, $name, $sku, $barcode, $model, $price, $cost, $minPrice, $wholesale, $contractor, $stock, $alert, $taxable, $gstRate, $taxMode, $unitVal, $sellingMode, $standardLengths, $defaultQty, $description, $isActive);
            $stmt->execute();
            $newId = $conn->insert_id;
            if ($stock > 0) {
                logInventoryMovement($conn, $newId, $stock, 'initial', null, 'Initial stock');
            }
            auditLog($conn, 'product_create', 'product', $newId, ['name' => $name]);
            header('Location: ?msg=Product+added'); exit;
        } else {
            $id = intval($_POST['id']);
            // Same types as insert + trailing i for WHERE id=?
            $stmt = $conn->prepare("UPDATE products SET category_id=?,brand_id=?,name=?,sku=?,barcode=?,model=?,price=?,cost=?,min_price=?,wholesale_price=?,contractor_price=?,stock=?,low_stock_alert=?,taxable=?,gst_rate=?,tax_mode=?,unit_id=?,selling_mode=?,standard_lengths=?,default_qty=?,description=?,is_active=? WHERE id=?");
            $stmt->bind_param("iissssdddddddidsissssii", $catVal, $brandVal, $name, $sku, $barcode, $model, $price, $cost, $minPrice, $wholesale, $contractor, $stock, $alert, $taxable, $gstRate, $taxMode, $unitVal, $sellingMode, $standardLengths, $defaultQty, $description, $isActive, $id);
            $stmt->execute();
            header('Location: ?msg=Product+updated'); exit;
        }
    }

    if ($act === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Product+deleted'); exit;
    }

    if ($act === 'stock_in') {
        $pid = intval($_POST['product_id']);
        $qty = floatval($_POST['qty_added']);
        if ($pid && $qty > 0) {
            $stmt = $conn->prepare("UPDATE products SET stock=stock+? WHERE id=?");
            $stmt->bind_param("di", $qty, $pid);
            $stmt->execute();
            logInventoryMovement($conn, $pid, $qty, 'stock_in', null, 'Stock in');
            header('Location: ?msg=Stock+updated'); exit;
        }
        header('Location: ?error=Invalid+data'); exit;
    }

    if ($act === 'adjust_stock') {
        $pid    = intval($_POST['product_id']);
        $qty    = floatval($_POST['qty']);
        $reason = trim($_POST['reason'] ?? '');
        $note   = trim($_POST['note'] ?? '');
        if ($pid && $qty > 0) {
            $stmt = $conn->prepare("SELECT stock FROM products WHERE id=?");
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $cur = $stmt->get_result()->fetch_assoc();
            if ($cur && $qty <= $cur['stock']) {
                $stmt2 = $conn->prepare("UPDATE products SET stock=stock-? WHERE id=?");
                $stmt2->bind_param("di", $qty, $pid);
                $stmt2->execute();
                logInventoryMovement($conn, $pid, -$qty, 'adjustment', $reason, $note);
                header('Location: ?msg=Stock+adjusted'); exit;
            }
        }
        header('Location: ?error=Invalid+adjustment'); exit;
    }
}

$pageTitle = 'Products';
$activePage = 'products';
$search = trim($_GET['q'] ?? '');
$catFilter = intval($_GET['cat'] ?? 0);
$brandFilter = intval($_GET['brand'] ?? 0);

$where = "WHERE p.is_active = 1";
$params = [];
$types = '';

if ($search) {
    $where .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ? OR p.model LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
if ($catFilter) {
    $where .= " AND p.category_id = ?";
    $params[] = $catFilter;
    $types .= 'i';
}
if ($brandFilter) {
    $where .= " AND p.brand_id = ?";
    $params[] = $brandFilter;
    $types .= 'i';
}

$stmt = $conn->prepare("SELECT p.*, c.name cat_name, b.name brand_name, u.short_name unit_name FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN brands b ON p.brand_id=b.id LEFT JOIN units u ON p.unit_id=u.id $where ORDER BY p.name");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$brands = $conn->query("SELECT * FROM brands WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$units = $conn->query("SELECT * FROM units WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Products</div>
    <div class="page-subtitle"><?= count($products) ?> products</div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn btn-primary" onclick="openInvScanner()">Add / Restock</button>
    <?php if(isAdmin()): ?><button class="btn btn-secondary" onclick="openModal('addModal')">+ New Product</button><?php endif; ?>
  </div>
</div>

<!-- Filters -->
<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" name="q" class="search-box" placeholder="Search name, SKU, barcode, model..." value="<?= e($search) ?>" style="min-width:250px">
      <select name="cat" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="">All Categories</option>
        <?php foreach($categories as $c): ?>
          <option value="<?=$c['id']?>" <?= $catFilter==$c['id']?'selected':'' ?>><?=e($c['name'])?></option>
        <?php endforeach; ?>
      </select>
      <select name="brand" class="form-control" style="width:150px" onchange="this.form.submit()">
        <option value="">All Brands</option>
        <?php foreach($brands as $b): ?>
          <option value="<?=$b['id']?>" <?= $brandFilter==$b['id']?'selected':'' ?>><?=e($b['name'])?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
      <?php if($search||$catFilter||$brandFilter): ?><a href="?" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
    </form>
    <span class="text-muted" style="font-size:12px"><?= count($products) ?> results</span>
  </div>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Product</th><th>Brand</th><th>SKU</th><th>Category</th><th>Cost</th><th>Retail</th><th>Stock</th><th>Unit</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if($products): foreach($products as $p): ?>
      <tr style="cursor:pointer" onclick='showDetail(<?= json_encode($p) ?>)'>
        <td>
          <strong><?= e($p['name']) ?></strong>
          <?php if($p['model']): ?><div style="font-size:11px;color:var(--text3)"><?= e($p['model']) ?></div><?php endif; ?>
          <?php if($p['stock'] <= 0): ?>
            <span class="badge badge-red" style="font-size:9px;margin-left:4px">OUT</span>
          <?php elseif($p['stock'] <= $p['low_stock_alert']): ?>
            <span class="badge badge-orange" style="font-size:9px;margin-left:4px">LOW</span>
          <?php endif; ?>
        </td>
        <td class="text-muted" style="font-size:12px"><?= e($p['brand_name'] ?? '—') ?></td>
        <td class="text-mono" style="font-size:12px"><?= e($p['sku'] ?: '—') ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($p['cat_name'] ?? '—') ?></td>
        <td class="text-mono text-muted"><?= money($p['cost']) ?></td>
        <td class="text-accent text-mono"><?= money($p['price']) ?></td>
        <td class="text-mono <?= $p['stock'] <= 0 ? 'stock-out' : ($p['stock'] <= $p['low_stock_alert'] ? 'stock-low' : '') ?>">
          <?= $p['stock'] ?> <?= e($p['unit_name'] ?? 'pc') ?>
        </td>
        <td style="font-size:12px"><?= e($p['unit_name'] ?? 'pc') ?></td>
        <td onclick="event.stopPropagation()">
          <?php if(isAdmin()): ?>
          <button class="btn btn-secondary btn-sm" onclick='openEdit(<?= json_encode($p) ?>)'>Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Del</button>
          </form>
          <?php else: ?><span class="text-muted" style="font-size:12px">View</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="9" class="empty-state">No products found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if(isAdmin()): ?>
<!-- Add/Edit Product Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:650px">
    <div class="modal-header"><span class="modal-title" id="prodModalTitle">Add Product</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST" id="productForm"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="prodAction" value="add">
      <input type="hidden" name="id" id="prodId">

      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:8px">Basic Info</div>
      <div class="form-grid" style="margin-bottom:16px">
        <div class="form-group" style="grid-column:1/-1"><label>Product Name *</label><input name="name" id="prodName" class="form-control" required></div>
        <div class="form-group"><label>Brand</label>
          <select name="brand_id" id="prodBrand" class="form-control">
            <option value="">— None —</option>
            <?php foreach($brands as $b): ?><option value="<?=$b['id']?>"><?=e($b['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Category</label>
          <select name="category_id" id="prodCat" class="form-control">
            <option value="">— Select —</option>
            <?php foreach($categories as $c): ?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>SKU</label><input name="sku" id="prodSku" class="form-control" placeholder="Internal code"></div>
        <div class="form-group"><label>Barcode</label><input name="barcode" id="prodBarcode" class="form-control"></div>
        <div class="form-group"><label>Model</label><input name="model" id="prodModel" class="form-control" placeholder="e.g. Porta One"></div>
      </div>

      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:8px">Pricing</div>
      <div class="form-grid" style="margin-bottom:16px">
        <div class="form-group"><label>Cost Price</label><input name="cost" id="prodCost" type="number" step="0.01" min="0" value="0" class="form-control"></div>
        <div class="form-group"><label>Retail Price *</label><input name="price" id="prodPrice" type="number" step="0.01" min="0" value="0" class="form-control" required></div>
        <div class="form-group"><label>Wholesale Price</label><input name="wholesale_price" id="prodWholesale" type="number" step="0.01" min="0" value="0" class="form-control"></div>
        <div class="form-group"><label>Contractor Price</label><input name="contractor_price" id="prodContractor" type="number" step="0.01" min="0" value="0" class="form-control"></div>
        <div class="form-group"><label>Min Price</label><input name="min_price" id="prodMin" type="number" step="0.01" min="0" value="0" class="form-control" title="Minimum allowed selling price"></div>
      </div>

      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:8px">Inventory</div>
      <div class="form-grid" style="margin-bottom:16px">
        <div class="form-group"><label>Unit</label>
          <select name="unit_id" id="prodUnit" class="form-control">
            <?php foreach($units as $u): ?><option value="<?=$u['id']?>" data-decimal="<?=$u['allows_decimal']?>"><?=e($u['name'])?> (<?=e($u['short_name'])?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Stock</label><input name="stock" id="prodStock" type="number" step="any" min="0" value="0" class="form-control"></div>
        <div class="form-group"><label>Low Stock Alert</label><input name="low_stock_alert" id="prodAlert" type="number" step="any" min="0" value="5" class="form-control"></div>
      </div>

      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:8px">Selling Mode</div>
      <div class="form-grid" style="margin-bottom:16px">
        <div class="form-group" style="grid-column:1/-1">
          <div style="display:flex;gap:16px;align-items:center">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
              <input type="radio" name="selling_mode" id="prodSellingModeFixed" value="fixed" checked onchange="onSellingModeChange()"> Fixed Quantity
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
              <input type="radio" name="selling_mode" id="prodSellingModeMeasured" value="measured" onchange="onSellingModeChange()"> Measured (decimal qty)
            </label>
          </div>
        </div>
        <div class="form-group" id="prodStdLengthsWrap" style="display:none;grid-column:1/-1">
          <label>Common Measurements (pipe-separated)</label>
          <input name="standard_lengths" id="prodStdLengths" class="form-control" placeholder="e.g. 3|6|9|12">
          <div style="font-size:11px;color:var(--text3);margin-top:3px">Enter values separated by | — shown as quick-select buttons in POS</div>
        </div>
        <div class="form-group" id="prodDefaultQtyWrap" style="display:none">
          <label>Default Quick Quantity</label>
          <input name="default_qty" id="prodDefaultQty" type="number" step="any" min="0" class="form-control" placeholder="Optional">
        </div>
      </div>

      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:8px">Tax & Status</div>
      <div class="form-grid">
        <div class="form-group">
          <label>Tax Mode</label>
          <select name="tax_mode" id="prodTaxMode" class="form-control" onchange="onTaxModeChange()">
            <option value="default">Use System Default (<?= getSetting('default_tax_rate', 18) ?>%)</option>
            <option value="taxable">Taxable — Standard Rate</option>
            <option value="non_taxable">Non-Taxable</option>
            <option value="custom">Custom Rate</option>
          </select>
        </div>
        <div class="form-group">
          <label>Custom GST Rate %</label>
          <input name="gst_rate" id="prodGstRate" type="number" step="0.01" min="0" max="100" value="18" class="form-control" style="width:100px" disabled>
        </div>
        <div class="form-group">
          <label>Effective Rate</label>
          <div id="prodEffRate" style="padding:8px 10px;background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius);font-size:13px;font-weight:600;color:var(--green)"><?= getSetting('default_tax_rate', 18) ?>%</div>
        </div>
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" name="is_active" id="prodActive" value="1" checked> Active
          </label>
        </div>
        <div class="form-group" style="grid-column:1/-1"><label>Description</label><textarea name="description" id="prodDesc" class="form-control" rows="2"></textarea></div>
      </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div></form>
  </div>
</div>
<?php endif; ?>

<!-- Stock-In Modal -->
<div class="modal-overlay" id="stockInModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><span class="modal-title">Add Stock</span><span class="modal-close" onclick="closeModal('stockInModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stock_in">
      <input type="hidden" name="product_id" id="siProductId">
      <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius);padding:10px 12px;margin-bottom:14px">
        <div style="font-size:14px;font-weight:700" id="siProductName"></div>
        <div style="font-size:11px;color:var(--text2);margin-top:3px">Current stock: <span id="siCurrentStock" class="text-accent text-mono"></span></div>
      </div>
      <div class="form-group"><label>Quantity to Add *</label><input name="qty_added" id="siQty" type="number" step="any" min="0.001" value="1" class="form-control" required></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('stockInModal')">Cancel</button><button type="submit" class="btn btn-primary">Add Stock</button></div></form>
  </div>
</div>

<!-- Product Detail Modal -->
<div class="modal-overlay" id="detailModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header"><span class="modal-title">Product Details</span><span class="modal-close" onclick="closeModal('detailModal')">&times;</span></div>
    <div class="modal-body" id="detailBody"></div>
    <div class="modal-footer"><button onclick="closeModal('detailModal')" class="btn btn-secondary">Close</button></div>
  </div>
</div>

<script>
var INV_PRODUCTS = <?= json_encode(array_values($products)) ?>;
var CURRENCY = '<?= addslashes(CURRENCY) ?>';

function openEdit(p) {
  document.getElementById('prodModalTitle').textContent = 'Edit Product';
  document.getElementById('prodAction').value = 'edit';
  document.getElementById('prodId').value = p.id;
  document.getElementById('prodName').value = p.name;
  document.getElementById('prodBrand').value = p.brand_id || '';
  document.getElementById('prodCat').value = p.category_id || '';
  document.getElementById('prodSku').value = p.sku || '';
  document.getElementById('prodBarcode').value = p.barcode || '';
  document.getElementById('prodModel').value = p.model || '';
  document.getElementById('prodCost').value = p.cost;
  document.getElementById('prodPrice').value = p.price;
  document.getElementById('prodMin').value = p.min_price || 0;
  document.getElementById('prodWholesale').value = p.wholesale_price || 0;
  document.getElementById('prodContractor').value = p.contractor_price || 0;
  document.getElementById('prodUnit').value = p.unit_id || 1;
  document.getElementById('prodStock').value = p.stock;
  document.getElementById('prodAlert').value = p.low_stock_alert;
  document.getElementById('prodTaxMode').value = p.tax_mode || 'default';
  onTaxModeChange(p.gst_rate || 18);
  document.getElementById('prodActive').checked = p.is_active == 1;
  document.getElementById('prodDesc').value = p.description || '';
  // Selling mode
  var isMeasured = p.selling_mode === 'measured';
  document.getElementById('prodSellingModeFixed').checked = !isMeasured;
  document.getElementById('prodSellingModeMeasured').checked = isMeasured;
  document.getElementById('prodStdLengths').value = p.standard_lengths || '';
  document.getElementById('prodDefaultQty').value = p.default_qty || '';
  onSellingModeChange();
  openModal('addModal');
}

function openNew() {
  document.getElementById('prodModalTitle').textContent = 'Add Product';
  document.getElementById('prodAction').value = 'add';
  document.getElementById('prodId').value = '';
  document.getElementById('productForm').reset();
  document.getElementById('prodTaxMode').value = 'default';
  onTaxModeChange();
  document.getElementById('prodActive').checked = true;
  document.getElementById('prodSellingModeFixed').checked = true;
  document.getElementById('prodStdLengths').value = '';
  document.getElementById('prodDefaultQty').value = '';
  onSellingModeChange();
  openModal('addModal');
}

function onSellingModeChange() {
  var isMeasured = document.getElementById('prodSellingModeMeasured').checked;
  document.getElementById('prodStdLengthsWrap').style.display = isMeasured ? '' : 'none';
  document.getElementById('prodDefaultQtyWrap').style.display = isMeasured ? '' : 'none';
  // Update stock input step based on mode
  var stockInput = document.getElementById('prodStock');
  var alertInput = document.getElementById('prodAlert');
  stockInput.step = isMeasured ? 'any' : '1';
  alertInput.step = isMeasured ? 'any' : '1';
}

function onTaxModeChange(customRate) {
  var mode = document.getElementById('prodTaxMode').value;
  var rateInput = document.getElementById('prodGstRate');
  var effDisplay = document.getElementById('prodEffRate');
  var defaultRate = <?= getSetting('default_tax_rate', 18) ?>;
  if (customRate === undefined) customRate = parseFloat(rateInput.value) || defaultRate;
  rateInput.disabled = (mode !== 'custom');
  var effRate;
  switch(mode) {
    case 'non_taxable': effRate = 0; break;
    case 'custom': effRate = customRate; break;
    case 'taxable': effRate = defaultRate; break;
    default: effRate = defaultRate; break;
  }
  effDisplay.textContent = effRate + '%';
  effDisplay.style.color = effRate > 0 ? 'var(--green)' : 'var(--text2)';
}

function showDetail(p) {
  var stockBadge = p.stock<=0 ? ' <span class="badge badge-red" style="font-size:9px">OUT</span>'
    : (p.stock<=p.low_stock_alert ? ' <span class="badge badge-orange" style="font-size:9px">LOW</span>' : '');
  function row(l,v){ return '<div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px"><span style="color:var(--text2)">'+l+'</span><span style="font-weight:600">'+v+'</span></div>'; }
  var margin = p.cost > 0 ? (((p.price - p.cost) / p.cost) * 100).toFixed(1) + '%' : '—';
  document.getElementById('detailBody').innerHTML =
    '<div style="margin-bottom:12px"><div style="font-size:18px;font-weight:700">'+p.name+'</div>'+
    '<div style="font-size:12px;color:var(--text2);margin-top:3px">'+(p.brand_name||'No brand')+' &middot; '+(p.cat_name||'No category')+'</div></div>'+
    (p.model ? row('Model', p.model) : '')+
    (p.sku ? row('SKU', p.sku) : '')+
    (p.barcode ? row('Barcode', p.barcode) : '')+
    row('Cost', CURRENCY+parseFloat(p.cost).toFixed(2))+
    row('Retail', CURRENCY+parseFloat(p.price).toFixed(2))+
    row('Wholesale', p.wholesale_price > 0 ? CURRENCY+parseFloat(p.wholesale_price).toFixed(2) : '—')+
    row('Contractor', p.contractor_price > 0 ? CURRENCY+parseFloat(p.contractor_price).toFixed(2) : '—')+
    row('Min Price', p.min_price > 0 ? CURRENCY+parseFloat(p.min_price).toFixed(2) : '—')+
    row('Margin', margin)+
    row('Stock', p.stock + ' ' + (p.unit_name||'pc') + stockBadge)+
    var taxLabels = { default: 'System Default', non_taxable: 'Non-Taxable', custom: 'Custom Rate' };
    var tm = p.tax_mode || 'default';
    var dr = <?= getSetting('default_tax_rate', 18) ?>;
    var er = tm === 'non_taxable' ? 0 : (tm === 'custom' ? (p.gst_rate || 0) : dr);
    row('GST', (taxLabels[tm] || tm) + (er > 0 ? ' (' + er + '%)' : ''));
  openModal('detailModal');
}

function openInvScanner() { openStockIn(INV_PRODUCTS[0]); }
function openStockIn(product) {
  if (!product) return;
  document.getElementById('siProductId').value = product.id;
  document.getElementById('siProductName').textContent = product.name;
  document.getElementById('siCurrentStock').textContent = product.stock + ' ' + (product.unit_name||'pc');
  document.getElementById('siQty').value = 1;
  openModal('stockInModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
