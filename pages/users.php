<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

if($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';
    if($act === 'add') {
        $name = trim($_POST['name'] ?? '');
        $user = trim($_POST['username'] ?? '');
        $pass = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
        $role = $_POST['role'] === 'admin' ? 'admin' : 'cashier';
        if($name && $user && $_POST['password']) {
            $stmt = $conn->prepare("INSERT INTO users (name,username,password,role) VALUES (?,?,?,?)");
            $stmt->bind_param("ssss", $name, $user, $pass, $role);
            if($stmt->execute()) {
                header('Location: ?msg=User+added'); exit;
            } else {
                header('Location: ?error=Username+already+exists'); exit;
            }
        }
    }
    if($act === 'edit') {
        $id = intval($_POST['id']);
        $name = trim($_POST['name'] ?? '');
        $role = $_POST['role'] === 'admin' ? 'admin' : 'cashier';
        $stmt = $conn->prepare("UPDATE users SET name=?,role=? WHERE id=?");
        $stmt->bind_param("ssi", $name, $role, $id);
        $stmt->execute();
        if(!empty($_POST['password'])) {
            $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt2 = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt2->bind_param("si", $pass, $id);
            $stmt2->execute();
        }
        header('Location: ?msg=User+updated'); exit;
    }
    if($act === 'delete') {
        $id = intval($_POST['id']);
        if($id !== $_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
        }
        header('Location: ?msg=Deleted'); exit;
    }
}
$pageTitle='Users'; $activePage='users';
$users = $conn->query("SELECT * FROM users ORDER BY created_at")->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div><div class="page-title">User Management</div></div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add User</button>
</div>
<div class="table-card">
  <table>
    <thead><tr><th>#</th><th>Name</th><th>Username</th><th>Role</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($users as $i=>$u): ?>
      <tr>
        <td class="text-muted"><?= $i+1 ?></td>
        <td><strong><?= e($u['name']) ?></strong></td>
        <td class="text-mono"><?= e($u['username']) ?></td>
        <td><span class="badge <?= $u['role']==='admin'?'badge-orange':'badge-blue' ?>"><?= $u['role'] ?></span></td>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick='openEdit(<?= json_encode($u) ?>)'>Edit</button>
          <?php if($u['id']!==$_SESSION['user_id']): ?>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Del</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php foreach([['addModal','Add User','add'],['editModal','Edit User','edit']] as [$mid,$mtitle,$mact]): ?>
<div class="modal-overlay" id="<?= $mid ?>">
  <div class="modal" style="max-width:380px">
    <div class="modal-header"><span class="modal-title"><?= $mtitle ?></span><span class="modal-close" onclick="closeModal('<?= $mid ?>')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $mact ?>">
      <?php if($mact==='edit'): ?><input type="hidden" name="id" id="eId"><?php endif; ?>
      <div class="form-group"><label>Full Name *</label><input name="name" id="<?= $mact ?>Name" class="form-control" required></div>
      <?php if($mact==='add'): ?><div class="form-group"><label>Username *</label><input name="username" class="form-control" required></div><?php endif; ?>
      <div class="form-group"><label><?= $mact==='edit'?'New ':'' ?>Password<?= $mact==='edit'?' (leave blank to keep)':' *' ?></label><input name="password" type="password" class="form-control" <?= $mact==='add'?'required':'' ?>></div>
      <div class="form-group"><label>Role</label>
        <select name="role" id="<?= $mact ?>Role" class="form-control">
          <option value="cashier">Cashier</option>
          <option value="admin">Admin</option>
        </select>
      </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('<?= $mid ?>')">Cancel</button><button type="submit" class="btn btn-primary"><?= $mact==='add'?'Add':'Update' ?></button></div></form>
  </div>
</div>
<?php endforeach; ?>
<script>
function openEdit(u){
  document.getElementById('eId').value=u.id;
  document.getElementById('editName').value=u.name;
  document.getElementById('editRole').value=u.role;
  openModal('editModal');
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
