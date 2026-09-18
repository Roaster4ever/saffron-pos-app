<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }

    $fields = [
        'shop_name'              => ['string', 200],
        'shop_address'           => ['string', 500],
        'shop_phone'             => ['string', 30],
        'shop_whatsapp'          => ['string', 30],
        'shop_email'             => ['string', 100],
        'currency'               => ['string', 20],
        'default_tax_rate'       => ['decimal', 100],
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

$pageTitle = 'Settings';
$activePage = 'settings';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Settings</div>
    <div class="page-subtitle">Business configuration and POS preferences</div>
  </div>
</div>

<form method="POST">
  <?= csrf_field() ?>

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

  <!-- Tax & Invoicing -->
  <div class="table-card" style="margin-bottom:16px">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
      <strong style="font-size:13px">Tax & Invoicing</strong>
    </div>
    <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Default Tax Rate (%)</label>
        <input name="default_tax_rate" type="number" step="0.01" min="0" max="100" class="form-control" value="<?= e(getSetting('default_tax_rate', '18')) ?>">
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:600;color:var(--text2)">Default Tax Treatment</label>
        <select name="default_tax_treatment" class="form-control">
          <option value="taxable" <?= getSetting('default_tax_treatment') === 'taxable' ? 'selected' : '' ?>>Taxable</option>
          <option value="non_taxable" <?= getSetting('default_tax_treatment') === 'non_taxable' ? 'selected' : '' ?>>Non-Taxable</option>
        </select>
      </div>
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

  <div style="text-align:right">
    <button type="submit" class="btn btn-primary" style="padding:10px 28px">Save Settings</button>
  </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
