<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $title    = trim($_POST['title'] ?? '');
        $amount   = floatval($_POST['amount'] ?? 0);
        $date     = date('Y-m-d H:i:s');
        $catId    = intval($_POST['category_id'] ?? 0) ?: null;
        $note     = trim($_POST['note'] ?? '');

        if (!$title || $amount <= 0) { header('Location: ?error=Title+and+amount+required'); exit; }

        $stmt = $conn->prepare("INSERT INTO expenses (title, amount, category_id, note, user_id) VALUES (?,?,?,?,?)");
        $uid = $_SESSION['user_id'] ?? null;
        $stmt->bind_param("sdssi", $title, $amount, $catId, $note, $uid);
        $stmt->execute();
        auditLog($conn, 'expense_create', 'expense', $conn->insert_id, ['title' => $title, 'amount' => $amount]);
        header('Location: ?msg=Expense+added'); exit;
    }

    if ($act === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM expenses WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Expense+deleted'); exit;
    }
}

$pageTitle = 'Expenses';
$activePage = 'expenses';

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');
$catFilter = intval($_GET['cat'] ?? 0);

$where = "WHERE DATE(e.created_at) BETWEEN ? AND ?";
$params = [$from, $to];
$types = 'ss';

if ($catFilter) {
    $where .= " AND e.category_id = ?";
    $params[] = $catFilter;
    $types .= 'i';
}

// Total expenses
$stmtT = $conn->prepare("SELECT COALESCE(SUM(amount),0) total FROM expenses e $where");
$stmtT->bind_param($types, ...$params);
$stmtT->execute();
$totalExpenses = $stmtT->get_result()->fetch_assoc()['total'];

// By category
$stmtC = $conn->prepare("SELECT COALESCE(ec.name, 'Uncategorized') cat_name, COALESCE(SUM(e.amount),0) total, COUNT(*) cnt FROM expenses e LEFT JOIN expense_categories ec ON e.category_id=ec.id $where GROUP BY e.category_id ORDER BY total DESC");
$stmtC->bind_param($types, ...$params);
$stmtC->execute();
$byCategory = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);

// Expenses list
$offset = max(0, (intval($_GET['page'] ?? 1) - 1)) * 20;
$expSql = "SELECT e.*, ec.name cat_name, u.name user_name FROM expenses e LEFT JOIN expense_categories ec ON e.category_id=ec.id LEFT JOIN users u ON e.user_id=u.id $where ORDER BY e.created_at DESC LIMIT 20 OFFSET ?";
$expParams = $params;
$expParams[] = $offset;
$expTypes = $types . 'i';
$stmtE = $conn->prepare($expSql);
$stmtE->bind_param($expTypes, ...$expParams);
$stmtE->execute();
$expenses = $stmtE->get_result()->fetch_all(MYSQLI_ASSOC);

$categories = $conn->query("SELECT * FROM expense_categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Expenses</div>
    <div class="page-subtitle"><?= money($totalExpenses) ?> total in period</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Expense</button>
</div>

<!-- Filters -->
<div style="display:flex;gap:8px;margin-bottom:16px;align-items:center">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="date" name="from" value="<?= e($from) ?>" class="form-control" style="width:140px">
    <input type="date" name="to" value="<?= e($to) ?>" class="form-control" style="width:140px">
    <select name="cat" class="form-control" style="width:160px" onchange="this.form.submit()">
      <option value="">All Categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $catFilter === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary btn-sm">Apply</button>
  </form>
</div>

<!-- Category breakdown -->
<?php if ($byCategory): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:16px">
  <?php foreach ($byCategory as $cat): ?>
    <div class="stat-card">
      <div class="stat-label"><?= e($cat['cat_name']) ?></div>
      <div class="stat-value" style="font-size:18px"><?= money($cat['total']) ?></div>
      <div class="stat-sub"><?= $cat['cnt'] ?> expenses</div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Expenses table -->
<div class="table-card" style="overflow-x:auto">
  <table style="min-width:700px">
    <thead><tr><th>Date</th><th>Title</th><th>Category</th><th>Amount</th><th>Note</th><th>User</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($expenses): foreach ($expenses as $e): ?>
      <tr>
        <td class="text-muted" style="font-size:12px"><?= date('d/m/Y', strtotime($e['created_at'])) ?></td>
        <td><strong><?= e($e['title']) ?></strong></td>
        <td><span class="badge badge-orange"><?= e($e['cat_name'] ?? '—') ?></span></td>
        <td class="text-mono text-red"><?= money($e['amount']) ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($e['note'] ?? '—') ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($e['user_name'] ?? '—') ?></td>
        <td>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete('Delete expense <?= e(addslashes($e['title'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="empty-state">No expenses found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><span class="modal-title">Add Expense</span><span class="modal-close" onclick="closeModal('addModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="form-group"><label>Title *</label><input name="title" class="form-control" required></div>
      <div class="form-group"><label>Amount *</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control" required></div>
      <div class="form-group"><label>Category</label>
        <select name="category_id" class="form-control">
          <option value="">— Select —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div></form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
