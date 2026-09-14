<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';
    $name  = trim($_POST['name'] ?? '');
    $cont  = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $terms = trim($_POST['payment_terms'] ?? '');
    $limit = floatval($_POST['credit_limit'] ?? 0);

    if ($act === 'add' && $name) {
        $stmt = $conn->prepare("INSERT INTO suppliers (name,contact,email,payment_terms,credit_limit) VALUES (?,?,?,?,?)");
        $stmt->bind_param("ssssd", $name, $cont, $email, $terms, $limit);
        $stmt->execute();
        header('Location: ?msg=Supplier+added'); exit;
    }
    if ($act === 'edit' && $name) {
        $id = intval($_POST['supplier_id']);
        $stmt = $conn->prepare("UPDATE suppliers SET name=?,contact=?,email=?,payment_terms=?,credit_limit=? WHERE supplier_id=?");
        $stmt->bind_param("ssssdi", $name, $cont, $email, $terms, $limit, $id);
        $stmt->execute();
        header('Location: ?msg=Supplier+updated'); exit;
    }
    if ($act === 'delete') {
        $id = intval($_POST['supplier_id']);
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE supplier_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Supplier+deleted'); exit;
    }
}

$pageTitle  = 'Suppliers';
$activePage = 'suppliers';
$suppliers  = $conn->query("SELECT s.*, COUNT(o.id) order_count, COALESCE(SUM(o.total),0) total_orders FROM suppliers s LEFT JOIN orders o ON s.supplier_id=o.supplier_id GROUP BY s.supplier_id ORDER BY s.name")->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div><div class="page-title">Suppliers</div></div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Supplier</button>
</div>

<div class="table-card">
  <table>
    <thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Payment Terms</th><th>Credit Limit</th><th>Orders</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($suppliers): foreach ($suppliers as $s): ?>
      <tr>
        <td><strong><?= e($s['name']) ?></strong></td>
        <td><?= e($s['contact']) ?: '—' ?></td>
        <td><?= e($s['email']) ?: '—' ?></td>
        <td><?= e($s['payment_terms']) ?: '—' ?></td>
        <td class="text-mono text-accent"><?= money($s['credit_limit']) ?></td>
        <td>
          <span class="badge badge-blue"><?= $s['order_count'] ?></span>
          <?php if ($s['order_count'] > 0): ?>
            <a href="orders.php?supplier_id=<?= $s['supplier_id'] ?>" class="btn btn-secondary btn-sm" style="margin-left:4px">View</a>
          <?php endif; ?>
        </td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick='openEdit(<?= json_encode($s) ?>)'>Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="supplier_id" value="<?= $s['supplier_id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Del</button>
          </form>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="empty-state">No suppliers yet</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header"><span class="modal-title">Add Supplier</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1"><label>Name *</label><input name="name" class="form-control" required></div>
        <div class="form-group"><label>Contact</label><input name="contact" class="form-control"></div>
        <div class="form-group"><label>Email</label><input name="email" type="email" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Payment Terms</label><input name="payment_terms" class="form-control" placeholder="e.g. Net 30, Cash on delivery"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Credit Limit</label><input name="credit_limit" type="number" step="0.01" min="0" value="0" class="form-control"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:440px">
    <div class="modal-header"><span class="modal-title">Edit Supplier</span><span class="modal-close" onclick="closeModal('editModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="supplier_id" id="eId">
      <div class="form-grid">
        <div class="form-group" style="grid-column:1/-1"><label>Name *</label><input name="name" id="eName" class="form-control" required></div>
        <div class="form-group"><label>Contact</label><input name="contact" id="eCont" class="form-control"></div>
        <div class="form-group"><label>Email</label><input name="email" id="eEmail" type="email" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Payment Terms</label><input name="payment_terms" id="eTerms" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label>Credit Limit</label><input name="credit_limit" id="eLimit" type="number" step="0.01" min="0" class="form-control"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div>
    </form>
  </div>
</div>

<script>
function openEdit(s) {
  document.getElementById('eId').value    = s.supplier_id;
  document.getElementById('eName').value  = s.name;
  document.getElementById('eCont').value  = s.contact  || '';
  document.getElementById('eEmail').value = s.email    || '';
  document.getElementById('eTerms').value = s.payment_terms || '';
  document.getElementById('eLimit').value = s.credit_limit || 0;
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
