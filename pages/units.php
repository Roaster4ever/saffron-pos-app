<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $name = trim($_POST['name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $allowsDecimal = isset($_POST['allows_decimal']) ? 1 : 0;
        if (!$name || !$shortName) { header('Location: ?error=Name+and+short+name+required'); exit; }
        $stmt = $conn->prepare("INSERT INTO units (name, short_name, allows_decimal) VALUES (?,?,?)");
        $stmt->bind_param("ssi", $name, $shortName, $allowsDecimal);
        $stmt->execute();
        auditLog($conn, 'unit_create', 'unit', $conn->insert_id, ['name' => $name]);
        header('Location: ?msg=Unit+added'); exit;
    }

    if ($act === 'edit') {
        $id = intval($_POST['id']);
        $name = trim($_POST['name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $allowsDecimal = isset($_POST['allows_decimal']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if (!$name || !$shortName) { header('Location: ?error=Name+and+short+name+required'); exit; }
        $stmt = $conn->prepare("UPDATE units SET name=?, short_name=?, allows_decimal=?, is_active=? WHERE id=?");
        $stmt->bind_param("ssii", $name, $shortName, $allowsDecimal, $isActive, $id);
        $stmt->execute();
        auditLog($conn, 'unit_update', 'unit', $id, ['name' => $name]);
        header('Location: ?msg=Unit+updated'); exit;
    }

    if ($act === 'delete') {
        $id = intval($_POST['id']);
        // Check if unit is used by any products
        $check = $conn->prepare("SELECT COUNT(*) cnt FROM products WHERE unit_id=?");
        $check->bind_param("i", $id);
        $check->execute();
        if ($check->get_result()->fetch_assoc()['cnt'] > 0) {
            header('Location: ?error=Cannot+delete+unit+in+use'); exit;
        }
        $stmt = $conn->prepare("DELETE FROM units WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Unit+deleted'); exit;
    }
}

$pageTitle = 'Units';
$activePage = 'units';

$units = $conn->query("SELECT u.*, (SELECT COUNT(*) FROM products WHERE unit_id=u.id) product_count FROM units u ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Units of Measurement</div>
    <div class="page-subtitle"><?= count($units) ?> units</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Unit</button>
</div>

<div class="table-card">
  <table>
    <thead><tr><th>Name</th><th>Short Name</th><th>Decimal</th><th>Products</th><th>Status</th><th style="width:120px"></th></tr></thead>
    <tbody>
    <?php if ($units): foreach ($units as $u): ?>
      <tr>
        <td><strong><?= e($u['name']) ?></strong></td>
        <td class="text-mono"><?= e($u['short_name']) ?></td>
        <td><?= $u['allows_decimal'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-orange">No</span>' ?></td>
        <td class="text-mono"><?= $u['product_count'] ?></td>
        <td><?= $u['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Inactive</span>' ?></td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick='editUnit(<?= json_encode($u) ?>)'>Edit</button>
          <?php if ($u['product_count'] == 0): ?>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete('Delete <?= e(addslashes($u['name'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button class="btn btn-danger btn-sm">Del</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6" class="empty-state">No units found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><span class="modal-title">Add Unit</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Full Name *</label><input name="name" class="form-control" placeholder="e.g. Kilogram" required></div>
      <div class="form-group"><label>Short Name *</label><input name="short_name" class="form-control" placeholder="e.g. kg" required></div>
      <div class="form-group"><label><input type="checkbox" name="allows_decimal" value="1" checked> Allow decimal quantities</label></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div></form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><span class="modal-title">Edit Unit</span><span class="modal-close" onclick="closeModal('editModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editId">
      <div class="form-group"><label>Full Name *</label><input name="name" id="editName" class="form-control" required></div>
      <div class="form-group"><label>Short Name *</label><input name="short_name" id="editShort" class="form-control" required></div>
      <div class="form-group"><label><input type="checkbox" name="allows_decimal" id="editDecimal" value="1"> Allow decimal quantities</label></div>
      <div class="form-group"><label><input type="checkbox" name="is_active" id="editActive" value="1"> Active</label></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div></form>
  </div>
</div>

<script>
function editUnit(u) {
  document.getElementById('editId').value = u.id;
  document.getElementById('editName').value = u.name;
  document.getElementById('editShort').value = u.short_name;
  document.getElementById('editDecimal').checked = u.allows_decimal == 1;
  document.getElementById('editActive').checked = u.is_active == 1;
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
