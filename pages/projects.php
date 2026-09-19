<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $name = trim($_POST['name'] ?? '');
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active','completed','cancelled'])) $status = 'active';
        if (!$name) { header('Location: ?error=Name+required'); exit; }
        $stmt = $conn->prepare("INSERT INTO projects (name, customer_id, location, description, status) VALUES (?,?,?,?,?)");
        $stmt->bind_param("sisss", $name, $customerId, $location, $description, $status);
        $stmt->execute();
        auditLog($conn, 'project_create', 'project', $conn->insert_id, ['name' => $name]);
        header('Location: ?msg=Project+added'); exit;
    }

    if ($act === 'edit') {
        $id = intval($_POST['id']);
        $name = trim($_POST['name'] ?? '');
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active','completed','cancelled'])) $status = 'active';
        if (!$name) { header('Location: ?error=Name+required'); exit; }
        $stmt = $conn->prepare("UPDATE projects SET name=?, customer_id=?, location=?, description=?, status=? WHERE id=?");
        $stmt->bind_param("sisssi", $name, $customerId, $location, $description, $status, $id);
        $stmt->execute();
        auditLog($conn, 'project_update', 'project', $id, ['name' => $name]);
        header('Location: ?msg=Project+updated'); exit;
    }

    if ($act === 'delete') {
        if (!isAdmin()) { header('Location: ?error=Admin+access+required'); exit; }
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM projects WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Project+deleted'); exit;
    }
}

$pageTitle = 'Projects';
$activePage = 'projects';
$search = trim($_GET['q'] ?? '');

$where = "WHERE 1=1";
$params = [];
$types = '';
if ($search) {
    $like = "%{$search}%";
    $where .= " AND (p.name LIKE ? OR p.location LIKE ?)";
    $params[] = $like; $params[] = $like;
    $types .= 'ss';
}

$stmt = $conn->prepare("SELECT p.*, cv.name customer_name FROM projects p LEFT JOIN customers_v2 cv ON p.customer_id=cv.id $where ORDER BY p.created_at DESC");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$projects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$customers = $conn->query("SELECT id, name FROM customers_v2 WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Projects</div>
    <div class="page-subtitle"><?= count($projects) ?> projects</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Project</button>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px;align-items:center">
      <input type="text" name="q" class="search-box" placeholder="Search projects..." value="<?= e($search) ?>" style="width:200px">
      <button class="btn btn-secondary btn-sm">Search</button>
    </form>
  </div>
  <table>
    <thead><tr><th>Name</th><th>Customer</th><th>Location</th><th>Status</th><th>Created</th><th style="width:120px"></th></tr></thead>
    <tbody>
    <?php if ($projects): foreach ($projects as $p): ?>
      <tr>
        <td><strong><?= e($p['name']) ?></strong></td>
        <td style="font-size:12px"><?= e($p['customer_name'] ?? '—') ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($p['location'] ?? '—') ?></td>
        <td>
          <?php
          $badge = $p['status'] === 'active' ? 'badge-green' : ($p['status'] === 'completed' ? 'badge-blue' : 'badge-red');
          ?>
          <span class="badge <?= $badge ?>"><?= ucfirst($p['status']) ?></span>
        </td>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y', strtotime($p['created_at'])) ?></td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick='editProject(<?= json_encode($p) ?>)'>Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete('Delete <?= e(addslashes($p['name'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-danger btn-sm">Del</button>
          </form>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6" class="empty-state">No projects found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><span class="modal-title">Add Project</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Project Name *</label><input name="name" class="form-control" required></div>
      <div class="form-group"><label>Customer</label>
        <select name="customer_id" class="form-control">
          <option value="">— None —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Location</label><input name="location" class="form-control" placeholder="Project location"></div>
      <div class="form-group"><label>Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      <div class="form-group"><label>Status</label>
        <select name="status" class="form-control">
          <option value="active">Active</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div></form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><span class="modal-title">Edit Project</span><span class="modal-close" onclick="closeModal('editModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editId">
      <div class="form-group"><label>Project Name *</label><input name="name" id="editName" class="form-control" required></div>
      <div class="form-group"><label>Customer</label>
        <select name="customer_id" id="editCustomer" class="form-control">
          <option value="">— None —</option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Location</label><input name="location" id="editLocation" class="form-control"></div>
      <div class="form-group"><label>Description</label><textarea name="description" id="editDescription" class="form-control" rows="2"></textarea></div>
      <div class="form-group"><label>Status</label>
        <select name="status" id="editStatus" class="form-control">
          <option value="active">Active</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div></form>
  </div>
</div>

<script>
function editProject(p) {
  document.getElementById('editId').value = p.id;
  document.getElementById('editName').value = p.name;
  document.getElementById('editCustomer').value = p.customer_id || '';
  document.getElementById('editLocation').value = p.location || '';
  document.getElementById('editDescription').value = p.description || '';
  document.getElementById('editStatus').value = p.status;
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
