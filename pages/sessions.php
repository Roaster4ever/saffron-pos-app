<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Sessions';
$activePage = 'sessions';

// Check if user_sessions table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'user_sessions'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    include __DIR__ . '/../includes/header.php';
    echo '<div class="page-header"><div><div class="page-title">User Sessions</div></div></div>';
    echo '<div class="alert alert-danger">The <code>user_sessions</code> table does not exist. Please run <code>migrate.sql</code> in phpMyAdmin first.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$from        = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to          = $_GET['to']   ?? date('Y-m-d');
$userFilter  = intval($_GET['user_id'] ?? 0);
$roleFilter  = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$where = "WHERE DATE(us.sign_in) BETWEEN ? AND ?";
$params = [$from, $to];
$types  = "ss";

if ($userFilter) {
    $where .= " AND us.user_id = ?";
    $params[] = $userFilter;
    $types  .= "i";
}

if ($roleFilter && in_array($roleFilter, ['admin','cashier'])) {
    $where .= " AND u.role = ?";
    $params[] = $roleFilter;
    $types  .= "s";
}

if ($statusFilter === 'active') {
    $where .= " AND us.sign_out IS NULL";
} elseif ($statusFilter === 'ended') {
    $where .= " AND us.sign_out IS NOT NULL";
}

$stmt = $conn->prepare("SELECT us.*, u.name user_name, u.role user_role FROM user_sessions us LEFT JOIN users u ON us.user_id = u.id $where ORDER BY us.sign_in DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// AJAX endpoint: return sessions as JSON
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(['sessions' => $sessions]);
    exit;
}

$users = $conn->query("SELECT id, name, role FROM users ORDER BY name")->fetch_all(MYSQLI_ASSOC);
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">User Sessions</div>
    <div class="page-subtitle">Login history and active sessions</div>
  </div>
  <div class="text-muted" style="font-size:12px" id="liveIndicator">
    <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);margin-right:4px;animation:pulse 2s infinite"></span>
    Live — updates every 5s
  </div>
</div>

<style>
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
</style>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" id="filterForm" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="date" name="from" value="<?= e($from) ?>" class="form-control" style="width:140px" onchange="this.form.submit()">
      <input type="date" name="to" value="<?= e($to) ?>" class="form-control" style="width:140px" onchange="this.form.submit()">
      <select name="user_id" class="form-control" style="width:160px" onchange="this.form.submit()">
        <option value="">All Users</option>
        <?php foreach($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $userFilter == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <select name="role" class="form-control" style="width:130px" onchange="this.form.submit()">
        <option value="">All Roles</option>
        <option value="admin" <?= $roleFilter==='admin'?'selected':'' ?>>Admin</option>
        <option value="cashier" <?= $roleFilter==='cashier'?'selected':'' ?>>Cashier</option>
      </select>
      <select name="status" class="form-control" style="width:130px" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
        <option value="ended" <?= $statusFilter==='ended'?'selected':'' ?>>Ended</option>
      </select>
    </form>
    <span class="text-muted" style="font-size:12px" id="resultCount"><?= count($sessions) ?> results</span>
  </div>
  <div style="overflow-x:auto">
  <table id="sessionsTable">
    <thead><tr><th>#</th><th>User</th><th>Role</th><th>Sign In</th><th>Sign Out</th><th>Duration</th><th>IP</th><th>Status</th></tr></thead>
    <tbody>
    <?php if($sessions): foreach($sessions as $i=>$s): ?>
      <tr>
        <td class="text-muted"><?= $i+1 ?></td>
        <td><strong><?= e($s['user_name'] ?? 'Unknown') ?></strong></td>
        <td><span class="badge <?= ($s['user_role']??'')==='admin'?'badge-orange':'badge-blue' ?>"><?= e($s['user_role'] ?? '—') ?></span></td>
        <td class="text-mono" style="font-size:12px"><?= date('d/m/Y H:i', strtotime($s['sign_in'])) ?></td>
        <td class="text-mono" style="font-size:12px"><?= $s['sign_out'] ? date('d/m/Y H:i', strtotime($s['sign_out'])) : '—' ?></td>
        <td class="text-mono"><?= e($s['duration'] ?: '—') ?></td>
        <td class="text-mono text-muted" style="font-size:12px"><?= e($s['ip_address'] ?: '—') ?></td>
        <td>
          <?php if(!$s['sign_out']): ?>
            <span class="session-active badge badge-green">Active</span>
          <?php else: ?>
            <span class="session-ended badge badge-blue">Ended</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="8" class="empty-state">No sessions found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
var SESSIONS_FROM   = <?= json_encode($from) ?>;
var SESSIONS_TO     = <?= json_encode($to) ?>;
var SESSIONS_UID    = <?= $userFilter ?>;
var SESSIONS_ROLE   = <?= json_encode($roleFilter) ?>;
var SESSIONS_STATUS = <?= json_encode($statusFilter) ?>;

function buildSessionRow(s, i) {
  var roleBadge = s.user_role === 'admin' ? 'badge-orange' : 'badge-blue';
  var statusHtml = s.sign_out
    ? '<span class="session-ended badge badge-blue">Ended</span>'
    : '<span class="session-active badge badge-green">Active</span>';
  var signIn = new Date(s.sign_in);
  var signOut = s.sign_out ? new Date(s.sign_out) : null;
  function fmtDate(d) {
    var dd = String(d.getDate()).padStart(2,'0');
    var mm = String(d.getMonth()+1).padStart(2,'0');
    var yyyy = d.getFullYear();
    var hh = String(d.getHours()).padStart(2,'0');
    var mi = String(d.getMinutes()).padStart(2,'0');
    return dd+'/'+mm+'/'+yyyy+' '+hh+':'+mi;
  }
  return '<tr>' +
    '<td class="text-muted">'+(i+1)+'</td>' +
    '<td><strong>'+esc(s.user_name||'Unknown')+'</strong></td>' +
    '<td><span class="badge '+roleBadge+'">'+esc(s.user_role||'—')+'</span></td>' +
    '<td class="text-mono" style="font-size:12px">'+fmtDate(signIn)+'</td>' +
    '<td class="text-mono" style="font-size:12px">'+(s.sign_out?fmtDate(signOut):'—')+'</td>' +
    '<td class="text-mono">'+esc(s.duration||'—')+'</td>' +
    '<td class="text-mono text-muted" style="font-size:12px">'+esc(s.ip_address||'—')+'</td>' +
    '<td>'+statusHtml+'</td>' +
  '</tr>';
}

function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function refreshSessions() {
  var params = new URLSearchParams({
    from: SESSIONS_FROM, to: SESSIONS_TO,
    user_id: SESSIONS_UID, role: SESSIONS_ROLE, status: SESSIONS_STATUS,
    ajax: 1
  });
  fetch('<?= BASE_URL ?>/pages/sessions.php?' + params.toString())
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.sessions) return;
      var tbody = document.querySelector('#sessionsTable tbody');
      if (!data.sessions.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="empty-state">No sessions found</td></tr>';
      } else {
        tbody.innerHTML = data.sessions.map(function(s, i) { return buildSessionRow(s, i); }).join('');
      }
      document.getElementById('resultCount').textContent = data.sessions.length + ' results';
    })
    .catch(function() {});
}

setInterval(refreshSessions, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
