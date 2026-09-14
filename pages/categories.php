<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    if($act === 'add' && $name) {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        header('Location: ?msg=Category+added'); exit;
    }
    if($act === 'edit' && $name) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("UPDATE categories SET name=? WHERE id=?");
        $stmt->bind_param("si", $name, $id);
        $stmt->execute();
        header('Location: ?msg=Updated'); exit;
    }
    if($act === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Deleted'); exit;
    }
}
$pageTitle = 'Categories'; $activePage = 'categories';
$categories = $conn->query("SELECT c.*,COUNT(p.id) pcount FROM categories c LEFT JOIN products p ON c.id=p.category_id GROUP BY c.id ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><div class="page-title">Categories</div></div>
  <?php if(isAdmin()): ?><button class="btn btn-primary" onclick="openModal('addModal')">+ Add Category</button><?php endif; ?>
</div>
<div class="table-card">
  <table>
    <thead><tr><th>#</th><th>Name</th><th>Products</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($categories as $i=>$c): ?>
      <tr>
        <td class="text-muted"><?= $i+1 ?></td>
        <td><strong><?= e($c['name']) ?></strong></td>
        <td><span class="badge badge-blue"><?= $c['pcount'] ?></span></td>
        <td>
          <?php if(isAdmin()): ?>
          <button class="btn btn-secondary btn-sm" onclick="openEdit(<?= $c['id'] ?>,'<?= e($c['name']) ?>')">Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Del</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:340px">
    <div class="modal-header"><span class="modal-title">Add Category</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Category Name</label><input name="name" class="form-control" required autofocus></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div></form>
  </div>
</div>
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:340px">
    <div class="modal-header"><span class="modal-title">Edit Category</span><span class="modal-close" onclick="closeModal('editModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="editId">
      <div class="form-group"><label>Category Name</label><input name="name" id="editName" class="form-control" required></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div></form>
  </div>
</div>
<script>function openEdit(id,name){document.getElementById('editId').value=id;document.getElementById('editName').value=name;openModal('editModal');}</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
