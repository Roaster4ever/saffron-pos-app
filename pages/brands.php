<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if ($act === 'add' && $name && isAdmin()) {
        $stmt = $conn->prepare("INSERT INTO brands (name, description) VALUES (?, ?)");
        $desc = trim($_POST['description'] ?? '');
        $stmt->bind_param("ss", $name, $desc);
        $stmt->execute();
        auditLog($conn, 'brand_create', 'brand', $conn->insert_id, ['name' => $name]);
        header('Location: ?msg=Brand+added'); exit;
    }
    if ($act === 'edit' && $name && isAdmin()) {
        $id = intval($_POST['id']);
        $desc = trim($_POST['description'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE brands SET name=?, description=?, is_active=? WHERE id=?");
        $stmt->bind_param("ssii", $name, $desc, $active, $id);
        $stmt->execute();
        header('Location: ?msg=Brand+updated'); exit;
    }
    if ($act === 'delete' && isAdmin()) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM brands WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Brand+deleted'); exit;
    }
}

$pageTitle = 'Brands';
$activePage = 'brands';
$search = trim($_GET['q'] ?? '');

if ($search) {
    $like = "%{$search}%";
    $stmt = $conn->prepare("SELECT b.*, COUNT(p.id) product_count FROM brands b LEFT JOIN products p ON b.id=p.brand_id WHERE b.name LIKE ? GROUP BY b.id ORDER BY b.name");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $brands = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $brands = $conn->query("SELECT b.*, COUNT(p.id) product_count FROM brands b LEFT JOIN products p ON b.id=p.brand_id GROUP BY b.id ORDER BY b.name")->fetch_all(MYSQLI_ASSOC);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Brands</div>
    <div class="page-subtitle"><?= count($brands) ?> brands</div>
  </div>
  <?php if(isAdmin()): ?><button class="btn btn-primary" onclick="openModal('addModal')">+ Add Brand</button><?php endif; ?>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px">
      <input type="text" name="q" class="search-box" placeholder="Search brands..." value="<?= e($search) ?>">
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
      <?php if($search): ?><a href="?" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
    </form>
    <span class="text-muted" style="font-size:12px"><?= count($brands) ?> results</span>
  </div>
  <table>
    <thead><tr><th>#</th><th>Name</th><th>Description</th><th>Products</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if($brands): foreach($brands as $i=>$b): ?>
      <tr>
        <td class="text-muted"><?= $i+1 ?></td>
        <td><strong><?= e($b['name']) ?></strong></td>
        <td class="text-muted" style="font-size:12px"><?= e($b['description'] ?: '—') ?></td>
        <td><span class="badge badge-blue"><?= $b['product_count'] ?></span></td>
        <td><span class="badge <?= $b['is_active']?'badge-green':'badge-red' ?>"><?= $b['is_active']?'Active':'Inactive' ?></span></td>
        <td>
          <?php if(isAdmin()): ?>
          <button class="btn btn-secondary btn-sm" onclick='openEdit(<?= json_encode($b) ?>)'>Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Del</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6" class="empty-state">No brands found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if(isAdmin()): ?>
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><span class="modal-title">Add Brand</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Brand Name *</label><input name="name" class="form-control" required autofocus></div>
      <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div></form>
  </div>
</div>

<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><span class="modal-title">Edit Brand</span><span class="modal-close" onclick="closeModal('editModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="eId">
      <div class="form-group"><label>Brand Name *</label><input name="name" id="eName" class="form-control" required></div>
      <div class="form-group"><label>Description</label><textarea name="description" id="eDesc" class="form-control" rows="2"></textarea></div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" name="is_active" id="eActive" value="1"> Active
        </label>
      </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div></form>
  </div>
</div>
<?php endif; ?>

<script>
function openEdit(b) {
  document.getElementById('eId').value = b.id;
  document.getElementById('eName').value = b.name;
  document.getElementById('eDesc').value = b.description || '';
  document.getElementById('eActive').checked = b.is_active == 1;
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
