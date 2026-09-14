<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $name    = trim($_POST['name'] ?? '');
        $type    = $_POST['type'] ?? 'individual';
        $phone   = trim($_POST['phone'] ?? '');
        $whatsapp= trim($_POST['whatsapp'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $creditLimit = floatval($_POST['credit_limit'] ?? 0);
        $openingBalance = floatval($_POST['opening_balance'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');

        if (!$name) { header('Location: ?error=Name+required'); exit; }

        $stmt = $conn->prepare("INSERT INTO customers_v2 (name,type,phone,whatsapp,email,address,city,credit_limit,opening_balance,notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssssdds", $name, $type, $phone, $whatsapp, $email, $address, $city, $creditLimit, $openingBalance, $notes);
        $stmt->execute();
        $cid = $conn->insert_id;

        // Create opening balance ledger entry if any
        if ($openingBalance != 0) {
            updateCustomerBalance($conn, $cid, 'opening', $openingBalance, null, null, 'Opening balance');
        }

        auditLog($conn, 'customer_create', 'customer', $cid, ['name' => $name, 'type' => $type]);
        header('Location: ?msg=Customer+added'); exit;
    }

    if ($act === 'edit') {
        $id = intval($_POST['id']);
        $name    = trim($_POST['name'] ?? '');
        $type    = $_POST['type'] ?? 'individual';
        $phone   = trim($_POST['phone'] ?? '');
        $whatsapp= trim($_POST['whatsapp'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $creditLimit = floatval($_POST['credit_limit'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');
        $active  = isset($_POST['is_active']) ? 1 : 0;

        if (!$name) { header('Location: ?error=Name+required'); exit; }

        $stmt = $conn->prepare("UPDATE customers_v2 SET name=?,type=?,phone=?,whatsapp=?,email=?,address=?,city=?,credit_limit=?,notes=?,is_active=? WHERE id=?");
        $stmt->bind_param("sssssssdssi", $name, $type, $phone, $whatsapp, $email, $address, $city, $creditLimit, $notes, $active, $id);
        $stmt->execute();
        header('Location: ?msg=Customer+updated'); exit;
    }

    if ($act === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM customers_v2 WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Customer+deleted'); exit;
    }
}

$pageTitle = 'Customers';
$activePage = 'customers';
$search = trim($_GET['q'] ?? '');
$typeFilter = $_GET['type'] ?? '';

$where = "WHERE 1=1";
$params = [];
$types = '';

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}
if ($typeFilter && in_array($typeFilter, ['individual','contractor','builder','architect','dealer','company'])) {
    $where .= " AND c.type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}

$sql = "SELECT c.*,
    (SELECT COALESCE(balance_after, 0) FROM customer_ledger WHERE customer_id = c.id ORDER BY id DESC LIMIT 1) as balance,
    (SELECT COUNT(*) FROM sales WHERE customer_v2_id = c.id) as sale_count,
    (SELECT COALESCE(SUM(total),0) FROM sales WHERE customer_v2_id = c.id AND status='completed') as total_sales
    FROM customers_v2 c $where ORDER BY c.name";

$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Customers</div>
    <div class="page-subtitle"><?= count($customers) ?> customers</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Customer</button>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" name="q" class="search-box" placeholder="Search name, phone..." value="<?= e($search) ?>">
      <select name="type" class="form-control" style="width:140px" onchange="this.form.submit()">
        <option value="">All Types</option>
        <?php foreach(['individual'=>'Individual','contractor'=>'Contractor','builder'=>'Builder','architect'=>'Architect','dealer'=>'Dealer','company'=>'Company'] as $k=>$v): ?>
          <option value="<?=$k?>" <?= $typeFilter===$k?'selected':'' ?>><?=$v?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
      <?php if($search||$typeFilter): ?><a href="?" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
    </form>
    <span class="text-muted" style="font-size:12px"><?= count($customers) ?> results</span>
  </div>
  <table>
    <thead><tr><th>Name</th><th>Type</th><th>Phone</th><th>City</th><th>Sales</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if($customers): foreach($customers as $c): ?>
      <tr>
        <td>
          <strong><?= e($c['name']) ?></strong>
          <?php if($c['credit_limit']>0): ?><span class="badge badge-blue" style="font-size:9px;margin-left:4px">Credit</span><?php endif; ?>
        </td>
        <td><span class="badge badge-orange"><?= ucfirst($c['type']) ?></span></td>
        <td class="text-mono" style="font-size:12px"><?= e($c['phone'] ?: '—') ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($c['city'] ?: '—') ?></td>
        <td class="text-mono"><?= $c['sale_count'] ?></td>
        <td class="text-mono <?= ($c['balance'] ?? 0) > 0 ? 'text-red' : 'text-green' ?>">
          <?= money($c['balance'] ?? 0) ?>
        </td>
        <td><span class="badge <?= $c['is_active']?'badge-green':'badge-red' ?>"><?= $c['is_active']?'Active':'Inactive' ?></span></td>
        <td>
          <a href="customer_ledger.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Ledger</a>
          <button class="btn btn-secondary btn-sm" onclick='openEdit(<?= json_encode($c) ?>)'>Edit</button>
          <?php if(isAdmin()): ?>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Del</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="8" class="empty-state">No customers found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><span class="modal-title">Add Customer</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1"><label>Name *</label><input name="name" class="form-control" required></div>
        <div class="form-group"><label>Type</label>
          <select name="type" class="form-control">
            <option value="individual">Individual</option>
            <option value="contractor">Contractor</option>
            <option value="builder">Builder</option>
            <option value="architect">Architect</option>
            <option value="dealer">Dealer</option>
            <option value="company">Company</option>
          </select>
        </div>
        <div class="form-group"><label>Phone</label><input name="phone" class="form-control"></div>
        <div class="form-group"><label>WhatsApp</label><input name="whatsapp" class="form-control"></div>
        <div class="form-group"><label>Email</label><input name="email" type="email" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
        <div class="form-group"><label>City</label><input name="city" class="form-control"></div>
        <div class="form-group"><label>Credit Limit</label><input name="credit_limit" type="number" step="0.01" min="0" value="0" class="form-control"></div>
        <div class="form-group"><label>Opening Balance</label><input name="opening_balance" type="number" step="0.01" value="0" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
      </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div></form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><span class="modal-title">Edit Customer</span><span class="modal-close" onclick="closeModal('editModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="eId">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1"><label>Name *</label><input name="name" id="eName" class="form-control" required></div>
        <div class="form-group"><label>Type</label>
          <select name="type" id="eType" class="form-control">
            <option value="individual">Individual</option>
            <option value="contractor">Contractor</option>
            <option value="builder">Builder</option>
            <option value="architect">Architect</option>
            <option value="dealer">Dealer</option>
            <option value="company">Company</option>
          </select>
        </div>
        <div class="form-group"><label>Phone</label><input name="phone" id="ePhone" class="form-control"></div>
        <div class="form-group"><label>WhatsApp</label><input name="whatsapp" id="eWhatsapp" class="form-control"></div>
        <div class="form-group"><label>Email</label><input name="email" id="eEmail" type="email" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Address</label><textarea name="address" id="eAddress" class="form-control" rows="2"></textarea></div>
        <div class="form-group"><label>City</label><input name="city" id="eCity" class="form-control"></div>
        <div class="form-group"><label>Credit Limit</label><input name="credit_limit" id="eCreditLimit" type="number" step="0.01" min="0" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Notes</label><textarea name="notes" id="eNotes" class="form-control" rows="2"></textarea></div>
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
            <input type="checkbox" name="is_active" id="eActive" value="1"> Active
          </label>
        </div>
      </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div></form>
  </div>
</div>

<script>
function openEdit(c) {
  document.getElementById('eId').value = c.id;
  document.getElementById('eName').value = c.name;
  document.getElementById('eType').value = c.type;
  document.getElementById('ePhone').value = c.phone || '';
  document.getElementById('eWhatsapp').value = c.whatsapp || '';
  document.getElementById('eEmail').value = c.email || '';
  document.getElementById('eAddress').value = c.address || '';
  document.getElementById('eCity').value = c.city || '';
  document.getElementById('eCreditLimit').value = c.credit_limit || 0;
  document.getElementById('eNotes').value = c.notes || '';
  document.getElementById('eActive').checked = c.is_active == 1;
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
