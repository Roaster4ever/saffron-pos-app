<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $title    = validateString($_POST['title'] ?? '', 200);
        $amount   = validateMoney($_POST['amount'] ?? 0);
        $date     = date('Y-m-d H:i:s');
        $catId    = validateInt($_POST['category_id'] ?? 0, 0, 999999) ?: null;
        $note     = validateString($_POST['note'] ?? '', 1000);

        if (!$title || $amount === false || $amount <= 0) { header('Location: ?error=Title+and+valid+amount+required'); exit; }

        $stmt = $conn->prepare("INSERT INTO expenses (title, amount, category_id, note, user_id) VALUES (?,?,?,?,?)");
        $uid = $_SESSION['user_id'] ?? null;
        $stmt->bind_param("sdssi", $title, $amount, $catId, $note, $uid);
        $stmt->execute();
        auditLog($conn, 'expense_create', 'expense', $conn->insert_id, ['title' => $title, 'amount' => $amount]);
        header('Location: ?msg=Expense+added'); exit;
    }

    if ($act === 'edit') {
        $id      = validateInt($_POST['id'] ?? 0, 1);
        $title   = validateString($_POST['title'] ?? '', 200);
        $amount  = validateMoney($_POST['amount'] ?? 0);
        $catId   = validateInt($_POST['category_id'] ?? 0, 0, 999999) ?: null;
        $note    = validateString($_POST['note'] ?? '', 1000);

        if (!$id || !$title || $amount === false || $amount <= 0) { header('Location: ?error=Invalid+input'); exit; }

        $stmt = $conn->prepare("UPDATE expenses SET title=?, amount=?, category_id=?, note=? WHERE id=?");
        $stmt->bind_param("sdssi", $title, $amount, $catId, $note, $id);
        $stmt->execute();
        auditLog($conn, 'expense_update', 'expense', $id, ['title' => $title, 'amount' => $amount]);
        header('Location: ?msg=Expense+updated'); exit;
    }

    if ($act === 'delete') {
        if (!isAdmin()) { header('Location: ?error=Admin+access+required'); exit; }
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM expenses WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Expense+deleted'); exit;
    }

    if ($act === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { header('Location: ?error=Category+name+required'); exit; }
        $stmt = $conn->prepare("INSERT INTO expense_categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        header('Location: ?msg=Category+added'); exit;
    }

    if ($act === 'delete_category') {
        $id = intval($_POST['id']);
        // Check if category is in use
        $check = $conn->prepare("SELECT COUNT(*) cnt FROM expenses WHERE category_id=?");
        $check->bind_param("i", $id);
        $check->execute();
        if ($check->get_result()->fetch_assoc()['cnt'] > 0) {
            header('Location: ?error=Cannot+delete+category+in+use'); exit;
        }
        $stmt = $conn->prepare("DELETE FROM expense_categories WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: ?msg=Category+deleted'); exit;
    }
}

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to   = $_GET['to']   ?? date('Y-m-d');
    $fromE = date('Y-m-d', strtotime($_GET['from'] ?? '-30 days'));
    $toE   = date('Y-m-d', strtotime($_GET['to'] ?? 'now'));
    $stmtExp = $conn->prepare("SELECT e.title, e.amount, ec.name as category, e.note, e.created_at, u.name as user
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id=ec.id
        LEFT JOIN users u ON e.user_id=u.id
        WHERE DATE(e.created_at) BETWEEN ? AND ?
        ORDER BY e.created_at DESC");
    $stmtExp->bind_param("ss", $fromE, $toE);
    $stmtExp->execute();
    $expenses = $stmtExp->get_result()->fetch_all(MYSQLI_ASSOC);
    $headers = ['title','amount','category','note','date','user'];
    $rows = array_map(function($e) {
        return array_map('csvEscape', [
            'title' => $e['title'], 'amount' => $e['amount'], 'category' => $e['category'] ?? '',
            'note' => $e['note'] ?? '', 'date' => $e['created_at'], 'user' => $e['user'] ?? '',
        ]);
    }, $expenses);
    auditLog($conn, 'expense_csv_export', 'system', null, ['count' => count($rows)]);
    sendCsvDownload('saffron-expenses-' . date('Y-m-d') . '.csv', generateCsv($headers, $rows));
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
$expSql = "SELECT e.*, ec.name cat_name, u.name user_name FROM expenses e LEFT JOIN expense_categories ec ON e.category_id=ec.id LEFT JOIN users u ON e.user_id=u.id $where ORDER BY e.created_at DESC LIMIT 20 OFFSET " . intval($offset);
$stmtE = $conn->prepare($expSql);
$stmtE->bind_param($types, ...$params);
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
  <div style="display:flex;gap:8px">
    <a href="?export=csv&from=<?= e($from) ?>&to=<?= e($to) ?>" class="btn btn-secondary btn-sm">Export CSV</a>
    <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Expense</button>
  </div>
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
<div class="table-card">
  <table>
    <thead><tr><th>Date</th><th>Title</th><th>Category</th><th>Amount</th><th>Note</th><th>User</th><th style="width:120px"></th></tr></thead>
    <tbody>
    <?php if ($expenses): foreach ($expenses as $exp): ?>
      <tr>
        <td style="font-size:12px;white-space:nowrap"><?= date('d/m/Y', strtotime($exp['created_at'])) ?></td>
        <td><strong><?= e($exp['title']) ?></strong></td>
        <td><span class="badge badge-orange"><?= e($exp['cat_name'] ?? '—') ?></span></td>
        <td class="text-mono text-red"><?= money($exp['amount']) ?></td>
        <td class="text-muted" style="font-size:12px"><?= e($exp['note'] ?? '—') ?></td>
        <td class="text-muted" style="font-size:12px;white-space:nowrap"><?= e($exp['user_name'] ?? '—') ?></td>
        <td style="text-align:right">
          <button class="btn btn-secondary btn-sm" onclick='editExpense(<?= json_encode($exp) ?>)'>Edit</button>
          <form method="POST" style="display:inline" onsubmit="return confirmDelete('Delete <?= e(addslashes($exp['title'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $exp['id'] ?>">
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

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><span class="modal-title">Edit Expense</span><span class="modal-close" onclick="closeModal('editModal')">&times;</span></div>
    <form method="POST"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editExpId">
      <div class="form-group"><label>Title *</label><input name="title" id="editExpTitle" class="form-control" required></div>
      <div class="form-group"><label>Amount *</label><input name="amount" id="editExpAmount" type="number" step="0.01" min="0.01" class="form-control" required></div>
      <div class="form-group"><label>Category</label>
        <select name="category_id" id="editExpCat" class="form-control">
          <option value="">— Select —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Note</label><textarea name="note" id="editExpNote" class="form-control" rows="2"></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div></form>
  </div>
</div>

<script>
function editExpense(exp) {
  document.getElementById('editExpId').value = exp.id;
  document.getElementById('editExpTitle').value = exp.title;
  document.getElementById('editExpAmount').value = exp.amount;
  document.getElementById('editExpCat').value = exp.category_id || '';
  document.getElementById('editExpNote').value = exp.note || '';
  openModal('editModal');
}
</script>

<!-- Expense Categories Management -->
<?php if (isAdmin()): ?>
<div class="table-card" style="margin-top:16px">
  <div class="table-toolbar">
    <strong style="font-size:13px">Expense Categories</strong>
    <form method="POST" style="display:flex;gap:6px;align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_category">
      <input name="name" class="form-control" placeholder="New category name" style="width:180px;font-size:12px" required>
      <button class="btn btn-primary btn-sm">Add</button>
    </form>
  </div>
  <table>
    <thead><tr><th>Category</th><th>Expenses</th><th style="width:80px"></th></tr></thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
      <tr>
        <td><strong><?= e($c['name']) ?></strong></td>
        <td class="text-mono text-muted" style="font-size:12px"><?= $c['id'] ? count(array_filter($expenses, function($e) use ($c) { return ($e['category_id'] ?? null) == $c['id']; })) : 0 ?></td>
        <td>
          <form method="POST" style="margin:0" onsubmit="return confirmDelete('Delete <?= e(addslashes($c['name'])) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-danger btn-sm">Del</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
