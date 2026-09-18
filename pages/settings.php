<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }

    $act = $_POST['action'] ?? 'save_settings';

    if ($act === 'save_settings') {
        $fields = [
            'shop_name'              => ['string', 200],
            'shop_address'           => ['string', 500],
            'shop_phone'             => ['string', 30],
            'shop_whatsapp'          => ['string', 30],
            'shop_email'             => ['string', 100],
            'currency'               => ['string', 20],
            'invoice_footer'         => ['string', 500],
            'quotation_footer'       => ['string', 500],
            'low_stock_default'      => ['integer', 99999],
            'quotation_validity_days'=> ['integer', 365],
            'default_tax_treatment'  => ['string', 20],
        ];

        foreach ($fields as $key => $meta) {
            if (isset($_POST[$key])) {
                setSetting($conn, $key, trim($_POST[$key]));
            }
        }

        // Boolean toggles
        $toggles = ['pos_customer_profiles'];
        foreach ($toggles as $key) {
            setSetting($conn, $key, isset($_POST[$key]) ? '1' : '0');
        }

        auditLog($conn, 'settings_update', 'settings', null, ['updated_by' => $_SESSION['user_name'] ?? 'admin']);
        header('Location: ?msg=Settings+saved'); exit;
    }

    if ($act === 'update_default_rate') {
        $newRate = max(0, min(100, floatval($_POST['default_rate'] ?? 18)));
        setSetting($conn, 'default_tax_rate', $newRate);
        auditLog($conn, 'tax_default_rate_change', 'setting', null, ['new_rate' => $newRate]);
        header("Location: ?msg=Default+tax+rate+updated+to+{$newRate}%"); exit;
    }

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

        $sql2 = "SELECT id, name, tax_mode, gst_rate FROM products WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT 50";
        $stmt2 = $conn->prepare($sql2);
        if ($types) $stmt2->bind_param($types, ...$params);
        $stmt2->execute();
        $affected = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        $pageTitle = 'Settings';
        $activePage = 'settings';
        include __DIR__ . '/../includes/header.php';
        ?>
        <div class="page-header">
          <div>
            <div class="page-title">Settings</div>
            <div class="page-subtitle">Bulk Tax — Confirm Update</div>
          </div>
          <div>
            <a href="<?= BASE_URL ?>/pages/settings.php" class="btn btn-secondary">Back to Settings</a>
          </div>
        </div>

        <div class="table-card" style="margin-bottom:16px">
          <div style="padding:16px">
            <div style="padding:14px;background:rgba(255,74,74,.08);border:1px solid rgba(255,74,74,.3);border-radius:var(--radius);margin-bottom:16px">
              <strong style="color:#ff4a4a">⚠ You are about to update <?= $count ?> product(s).</strong><br>
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
                <a href="<?= BASE_URL ?>/pages/settings.php" class="btn btn-secondary">Cancel</a>
              </div>
            </form>
          </div>
        </div>
        <?php
        exit;
    }

    if ($act === 'execute') {
        $taxMode = $_POST['tax_mode'] ?? 'default';
        $customRate = floatval($_POST['custom_rate'] ?? 18);
        $filterType = $_POST['filter_type'] ?? 'all';
        $filterValue = intval($_POST['filter_value'] ?? 0);

        if (!in_array($taxMode, ['default','non_taxable','custom'])) $taxMode = 'default';

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
}

$pageTitle = 'Settings';
$activePage = 'settings';

$categories = $conn->query("SELECT id, name FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$brands = $conn->query("SELECT id, name FROM brands ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$taxStats = $conn->query("SELECT tax_mode, COUNT(*) cnt FROM products WHERE is_active=1 GROUP BY tax_mode")->fetch_all(MYSQLI_ASSOC);
$defaultRate = getSetting('default_tax_rate', 18);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Settings</div>
    <div class="page-subtitle">Business configuration, invoicing, POS and tax management</div>
  </div>
</div>

<form method="POST" id="settingsForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_settings">

  <!-- Business Information -->
  <div class="table-card" style="margin-bottom:16px">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
      <strong style="font-size:13px">Business Information</strong>
    </div>
    <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group" style="grid-column:1/-1">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Business Name</label>
        <input name="shop_name" class="form-control" value="<?= e(getSetting('shop_name', '')) ?>" required>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Address</label>
        <input name="shop_address" class="form-control" value="<?= e(getSetting('shop_address', '')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Phone</label>
        <input name="shop_phone" class="form-control" value="<?= e(getSetting('shop_phone', '')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">WhatsApp</label>
        <input name="shop_whatsapp" class="form-control" value="<?= e(getSetting('shop_whatsapp', '')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Email</label>
        <input name="shop_email" class="form-control" value="<?= e(getSetting('shop_email', '')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Currency</label>
        <input name="currency" class="form-control" value="<?= e(getSetting('currency', 'Rs: ')) ?>">
      </div>
    </div>
  </div>

  <!-- Invoicing -->
  <div class="table-card" style="margin-bottom:16px">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
      <strong style="font-size:13px">Invoicing</strong>
    </div>
    <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Invoice Footer</label>
        <input name="invoice_footer" class="form-control" value="<?= e(getSetting('invoice_footer', '')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Quotation Footer</label>
        <input name="quotation_footer" class="form-control" value="<?= e(getSetting('quotation_footer', '')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Quotation Validity (days)</label>
        <input name="quotation_validity_days" type="number" min="1" class="form-control" value="<?= e(getSetting('quotation_validity_days', '15')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Low Stock Alert Default</label>
        <input name="low_stock_default" type="number" min="0" class="form-control" value="<?= e(getSetting('low_stock_default', '5')) ?>">
      </div>
    </div>
  </div>

  <!-- POS Preferences -->
  <div class="table-card" style="margin-bottom:16px">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
      <strong style="font-size:13px">POS Preferences</strong>
    </div>
    <div style="padding:16px">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 12px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);transition:border-color .15s" onmouseover="this.style.borderColor='var(--accent)'" onmouseout="this.style.borderColor='var(--border)'">
        <input type="checkbox" name="pos_customer_profiles" value="1" <?= getSetting('pos_customer_profiles', true) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--accent)">
        <div>
          <div style="font-size:13px;font-weight:600;color:var(--text)">Customer Profiles in POS</div>
          <div style="font-size:11px;color:var(--text3);margin-top:2px">Show Walk-in / Customer toggle and customer dropdown on the POS page. Saved drafts with customers are not affected.</div>
        </div>
      </label>
    </div>
  </div>

  <div style="text-align:right;margin-bottom:24px">
    <button type="submit" class="btn btn-primary" style="padding:10px 28px">Save Settings</button>
  </div>
</form>

<!-- Tax Management -->
<div class="table-card" style="margin-bottom:16px">
  <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <strong style="font-size:13px">Tax Management</strong>
  </div>
  <div style="padding:16px">
    <!-- Current Tax Status -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
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
      <?php foreach ($taxStats as $s): ?>
      <div style="padding:10px 16px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius)">
        <div style="font-size:11px;color:var(--text2);text-transform:uppercase"><?= e(ucfirst(str_replace('_', ' ', $s['tax_mode']))) ?></div>
        <div style="font-size:18px;font-weight:700"><?= $s['cnt'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Bulk Update Form -->
    <form method="POST" id="bulkTaxForm">
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
        <button type="button" class="btn btn-secondary" onclick="window.location.href='<?= BASE_URL ?>/pages/settings.php'">Reset</button>
      </div>
    </form>
  </div>
</div>

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
