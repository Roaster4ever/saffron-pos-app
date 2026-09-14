<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (!isAdmin()) {
    header('Location: ' . BASE_URL . '/?error=Admin+access+required');
    exit;
}

// Handle bulk update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }

    $act = $_POST['action'] ?? '';

    if ($act === 'preview') {
        $taxMode = $_POST['tax_mode'] ?? 'default';
        $customRate = floatval($_POST['custom_rate'] ?? 18);
        $filterType = $_POST['filter_type'] ?? 'all';
        $filterValue = intval($_POST['filter_value'] ?? 0);

        $where = ['is_active=1'];
        $params = [];
        $types = '';

        if ($filterType === 'category' && $filterValue) {
            $where[] = 'category_id=?';
            $params[] = $filterValue;
            $types .= 'i';
        } elseif ($filterType === 'brand' && $filterValue) {
            $where[] = 'brand_id=?';
            $params[] = $filterValue;
            $types .= 'i';
        } elseif ($filterType === 'tax_mode' && $filterValue) {
            $where[] = 'tax_mode=?';
            $params[] = $filterValue;
            $types .= 's';
        }

        $sql = "SELECT COUNT(*) cnt FROM products WHERE " . implode(' AND ', $where);
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'];

        // Store preview in session for confirm step
        $_SESSION['bulk_tax_preview'] = [
            'tax_mode' => $taxMode,
            'custom_rate' => $customRate,
            'filter_type' => $filterType,
            'filter_value' => $filterValue,
            'count' => $count,
        ];

        // Fetch affected products for display
        $sql2 = "SELECT id, name, tax_mode, gst_rate FROM products WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT 50";
        $stmt2 = $conn->prepare($sql2);
        if ($types) $stmt2->bind_param($types, ...$params);
        $stmt2->execute();
        $affected = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        $pageTitle = 'Bulk Tax Management';
        include __DIR__ . '/../includes/header.php';
        ?>
        <main class="main-content">
          <div class="content-header">
            <h1>Bulk Tax Management — Confirm</h1>
            <div style="display:flex;gap:8px">
              <a href="<?= BASE_URL ?>/"/pages/tax_bulk.php" class="btn btn-secondary">Back</a>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title">Confirm Update</span></div>
            <div class="card-body">
              <div style="padding:16px;background:var(--bg3);border:1px solid var(--orange);border-radius:var(--radius);margin-bottom:16px">
                <strong style="color:var(--orange)">⚠ You are about to update <?= $count ?> product(s).</strong><br>
                <span style="font-size:13px;color:var(--text2)">
                  New tax mode: <strong><?= e(ucfirst(str_replace('_', ' ', $taxMode))) ?></strong>
                  <?php if ($taxMode === 'custom'): ?>
                    — Rate: <strong><?= $customRate ?>%</strong>
                  <?php endif; ?>
                </span>
              </div>

              <?php if (!empty($affected)): ?>
              <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)">
                <table style="width:100%;font-size:12px">
                  <thead><tr>
                    <th style="text-align:left;padding:8px;background:var(--bg3)">Product</th>
                    <th style="text-align:left;padding:8px;background:var(--bg3)">Current Mode</th>
                    <th style="text-align:left;padding:8px;background:var(--bg3)">Current Rate</th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ($affected as $p): ?>
                    <tr style="border-top:1px solid var(--border)">
                      <td style="padding:8px"><?= e($p['name']) ?></td>
                      <td style="padding:8px"><?= e($p['tax_mode']) ?></td>
                      <td style="padding:8px"><?= $p['gst_rate'] ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if ($count > 50): ?>
                    <tr><td colspan="3" style="padding:8px;color:var(--text2);text-align:center">… and <?= $count - 50 ?> more</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>

              <form method="POST" style="margin-top:16px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="execute">
                <input type="hidden" name="tax_mode" value="<?= e($taxMode) ?>">
                <input type="hidden" name="custom_rate" value="<?= $customRate ?>">
                <input type="hidden" name="filter_type" value="<?= e($filterType) ?>">
                <input type="hidden" name="filter_value" value="<?= $filterValue ?>">
                <div style="display:flex;gap:8px">
                  <button type="submit" class="btn btn-primary" onclick="return confirm('Apply tax changes to <?= $count ?> product(s)? This cannot be undone.')">
                    Apply to <?= $count ?> Product(s)
                  </button>
                  <a href="<?= BASE_URL ?>/"/pages/tax_bulk.php" class="btn btn-secondary">Cancel</a>
                </div>
              </form>
            </div>
          </div>
        </main>
        <?php
        exit;
    }

    if ($act === 'execute') {
        $taxMode = $_POST['tax_mode'] ?? 'default';
        $customRate = floatval($_POST['custom_rate'] ?? 18);
        $filterType = $_POST['filter_type'] ?? 'all';
        $filterValue = intval($_POST['filter_value'] ?? 0);

        if (!in_array($taxMode, ['default','non_taxable','custom'])) $taxMode = 'default';

        // Derive legacy fields from tax_mode
        if ($taxMode === 'non_taxable') {
            $taxable = 0; $gstRate = 0;
        } elseif ($taxMode === 'custom') {
            $taxable = 1; $gstRate = max(0, $customRate);
        } else {
            $taxable = 1; $gstRate = 18;
        }

        $where = ['is_active=1'];
        $params = [];
        $types = '';

        if ($filterType === 'category' && $filterValue) {
            $where[] = 'category_id=?';
            $params[] = $filterValue;
            $types .= 'i';
        } elseif ($filterType === 'brand' && $filterValue) {
            $where[] = 'brand_id=?';
            $params[] = $filterValue;
            $types .= 'i';
        } elseif ($filterType === 'tax_mode' && $filterValue) {
            $where[] = 'tax_mode=?';
            $params[] = $filterValue;
            $types .= 's';
        }

        $sql = "UPDATE products SET tax_mode=?, taxable=?, gst_rate=? WHERE " . implode(' AND ', $where);
        $stmt = $conn->prepare($sql);
        $allParams = array_merge([$taxMode, $taxable, $gstRate], $params);
        $allTypes = 'sid' . $types;
        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $affected = $stmt->affected_rows;

        auditLog($conn, 'bulk_tax_update', 'product', null, [
            'tax_mode' => $taxMode,
            'gst_rate' => $gstRate,
            'filter_type' => $filterType,
            'filter_value' => $filterValue,
            'affected_rows' => $affected,
        ]);

        header("Location: ?msg=Updated+{$affected}+products"); exit;
    }
    if ($act === 'update_default_rate') {
        $newRate = max(0, min(100, floatval($_POST['default_rate'] ?? 18)));
        setSetting($conn, 'default_tax_rate', $newRate);
        auditLog($conn, 'tax_default_rate_change', 'setting', null, ['new_rate' => $newRate]);
        header("Location: ?msg=Default+tax+rate+updated+to+{$newRate}%"); exit;
    }
}

$pageTitle = 'Bulk Tax Management';
include __DIR__ . '/../includes/header.php';

$categories = $conn->query("SELECT id, name FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$brands = $conn->query("SELECT id, name FROM brands ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Current stats
$stats = $conn->query("SELECT tax_mode, COUNT(*) cnt FROM products WHERE is_active=1 GROUP BY tax_mode")->fetch_all(MYSQLI_ASSOC);
$defaultRate = getSetting('default_tax_rate', 18);
?>

<main class="main-content">
  <div class="content-header">
    <h1>Bulk Tax Management</h1>
    <div style="display:flex;gap:8px">
      <a href="<?= BASE_URL ?>/"/pages/products.php" class="btn btn-secondary">Products</a>
    </div>
  </div>

  <?php if (isset($_GET['msg'])): ?>
    <div style="padding:10px 14px;background:var(--green-bg);border:1px solid var(--green);border-radius:var(--radius);margin-bottom:14px;color:var(--green);font-size:13px">
      <?= e(str_replace('+', ' ', $_GET['msg'])) ?>
    </div>
  <?php endif; ?>

  <!-- Current Tax Status -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><span class="card-title">Current Tax Status</span></div>
    <div class="card-body">
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <div style="padding:10px 16px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius)">
          <div style="font-size:11px;color:var(--text2);text-transform:uppercase">System Default Rate</div>
          <form method="POST" style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_default_rate">
            <input type="number" name="default_rate" value="<?= $defaultRate ?>" min="0" max="100" step="0.01"
              style="width:70px;background:var(--bg);border:1px solid var(--border2);color:var(--green);padding:4px 6px;border-radius:2px;font-size:15px;font-weight:700;text-align:center">
            <span style="color:var(--text2);font-size:14px">%</span>
            <button type="submit" class="btn btn-sm btn-secondary">Update</button>
          </form>
        </div>
        <?php foreach ($stats as $s): ?>
        <div style="padding:10px 16px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius)">
          <div style="font-size:11px;color:var(--text2);text-transform:uppercase"><?= e(ucfirst(str_replace('_', ' ', $s['tax_mode']))) ?></div>
          <div style="font-size:18px;font-weight:700"><?= $s['cnt'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Bulk Update Form -->
  <div class="card">
    <div class="card-header"><span class="card-title">Apply Tax Settings</span></div>
    <div class="card-body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
          <div class="form-group">
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text2)">Filter By</label>
            <select name="filter_type" id="filterType" class="form-control" onchange="onFilterTypeChange()" style="font-size:13px">
              <option value="all">All Active Products</option>
              <option value="category">By Category</option>
              <option value="brand">By Brand</option>
              <option value="tax_mode">By Current Tax Mode</option>
            </select>
          </div>
          <div class="form-group" id="filterValueWrap" style="display:none">
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text2)">Select</label>
            <select name="filter_value" id="filterValue" class="form-control" style="font-size:13px">
              <option value="0">— Select —</option>
            </select>
          </div>

          <div class="form-group">
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text2)">New Tax Mode</label>
            <select name="tax_mode" id="taxModeSelect" class="form-control" onchange="onTaxModeChange()" style="font-size:13px">
              <option value="default">Use System Default (<?= $defaultRate ?>%)</option>
              <option value="taxable">Taxable — Standard Rate</option>
              <option value="non_taxable">Non-Taxable</option>
              <option value="custom">Custom Rate</option>
            </select>
          </div>
          <div class="form-group" id="customRateWrap" style="display:none">
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--text2)">Custom Rate %</label>
            <input type="number" name="custom_rate" id="customRate" min="0" max="100" step="0.01" value="<?= $defaultRate ?>" class="form-control" style="width:120px;font-size:13px">
          </div>
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary">Preview Changes</button>
          <button type="button" class="btn btn-secondary" onclick="window.location=BASE_URL . '/pages/tax_bulk.php'">Reset</button>
        </div>
      </form>
    </div>
  </div>
</main>

<script>
var categories = <?= json_encode($categories) ?>;
var brands = <?= json_encode($brands) ?>;
var taxModes = [
  {value:'default', label:'System Default'},
  {value:'non_taxable', label:'Non-Taxable'},
  {value:'custom', label:'Custom Rate'},
];

function onFilterTypeChange() {
  var type = document.getElementById('filterType').value;
  var wrap = document.getElementById('filterValueWrap');
  var select = document.getElementById('filterValue');
  if (type === 'all') { wrap.style.display = 'none'; return; }
  wrap.style.display = '';
  select.innerHTML = '<option value="0">— Select —</option>';
  var items = type === 'category' ? categories : (type === 'brand' ? brands : taxModes);
  items.forEach(function(item) {
    var opt = document.createElement('option');
    opt.value = item.id || item.value;
    opt.textContent = item.name || item.label;
    select.appendChild(opt);
  });
}

function onTaxModeChange() {
  var mode = document.getElementById('taxModeSelect').value;
  document.getElementById('customRateWrap').style.display = mode === 'custom' ? '' : 'none';
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
