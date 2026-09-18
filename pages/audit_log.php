<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle = 'Audit Log';
$activePage = 'audit_log';

// Filters
$actionFilter = $_GET['action'] ?? '';
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = "WHERE DATE(al.created_at) BETWEEN ? AND ?";
$params = [$from, $to];
$types = 'ss';

if ($actionFilter) {
    $where .= " AND al.action = ?";
    $params[] = $actionFilter;
    $types .= 's';
}

// Count
$countStmt = $conn->prepare("SELECT COUNT(*) cnt FROM audit_log al $where");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = $countStmt->get_result()->fetch_assoc()['cnt'];
$totalPages = max(1, ceil($total / $perPage));

// Fetch
$sql = "SELECT al.*, u.name as user_name FROM audit_log al LEFT JOIN users u ON al.user_id=u.id $where ORDER BY al.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get distinct actions for filter
$actions = $conn->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Audit Log</div>
    <div class="page-subtitle"><?= number_format($total) ?> entries</div>
  </div>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="date" name="from" class="form-control" value="<?= e($from) ?>" style="font-size:12px">
      <span class="text-muted">to</span>
      <input type="date" name="to" class="form-control" value="<?= e($to) ?>" style="font-size:12px">
      <select name="action" class="form-control" style="width:160px;font-size:12px">
        <option value="">All Actions</option>
        <?php foreach($actions as $a): ?>
          <option value="<?= e($a['action']) ?>" <?= $actionFilter===$a['action']?'selected':'' ?>><?= e($a['action']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-secondary btn-sm" type="submit">Filter</button>
      <?php if($actionFilter||$from||$to): ?><a href="?" class="btn btn-secondary btn-sm">Clear</a><?php endif; ?>
    </form>
    <span class="text-muted" style="font-size:12px"><?= number_format($total) ?> entries</span>
  </div>
  <table>
    <thead><tr><th>Date/Time</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php if($logs): foreach($logs as $l): ?>
      <tr>
        <td class="text-mono" style="font-size:11px;white-space:nowrap"><?= e($l['created_at']) ?></td>
        <td><?= e($l['user_name'] ?? 'System') ?></td>
        <td><span class="badge badge-blue" style="font-size:10px"><?= e($l['action']) ?></span></td>
        <td class="text-muted" style="font-size:12px"><?= e($l['table_name'] ?? '—') ?></td>
        <td class="text-mono" style="font-size:12px"><?= e($l['record_id'] ?? '—') ?></td>
        <td style="font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?php
            $details = $l['new_values'] ?? $l['old_values'] ?? '';
            if (is_string($details) && strlen($details) > 80) $details = substr($details, 0, 80) . '...';
            echo e($details ?: '—');
          ?>
        </td>
        <td class="text-muted" style="font-size:11px"><?= e($l['ip_address'] ?? '—') ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="7" class="empty-state">No audit log entries found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if($totalPages > 1): ?>
<div style="display:flex;justify-content:center;gap:4px;margin-top:16px">
  <?php if($page > 1): ?>
    <a href="?page=<?= $page-1 ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&action=<?= e($actionFilter) ?>" class="btn btn-secondary btn-sm">&laquo; Prev</a>
  <?php endif; ?>
  <span class="text-muted" style="padding:6px 12px;font-size:12px">Page <?= $page ?> of <?= $totalPages ?></span>
  <?php if($page < $totalPages): ?>
    <a href="?page=<?= $page+1 ?>&from=<?= e($from) ?>&to=<?= e($to) ?>&action=<?= e($actionFilter) ?>" class="btn btn-secondary btn-sm">Next &raquo;</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
