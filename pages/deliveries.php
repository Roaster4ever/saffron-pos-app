<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $saleId     = intval($_POST['sale_id'] ?? 0) ?: null;
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $projectId  = intval($_POST['project_id'] ?? 0) ?: null;
        $address    = trim($_POST['delivery_address'] ?? '');
        $date       = $_POST['delivery_date'] ?? date('Y-m-d');
        $notes      = trim($_POST['notes'] ?? '');
        $itemsJson  = $_POST['items'] ?? '[]';
        $items = json_decode($itemsJson, true);

        if (empty($items)) { header('Location: ?error=No+items'); exit; }

        $delNo = generateDeliveryNo($conn);
        $uid = $_SESSION['user_id'] ?? null;

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO deliveries (delivery_no, sale_id, customer_id, project_id, delivery_address, delivery_date, notes, user_id) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param("siiisssi", $delNo, $saleId, $customerId, $projectId, $address, $date, $notes, $uid);
            $stmt->execute();
            $delId = $conn->insert_id;

            foreach ($items as $item) {
                $pid = intval($item['product_id'] ?? 0) ?: null;
                $name = $item['name'] ?? '';
                $qtyOrdered = floatval($item['qty_ordered'] ?? $item['qty'] ?? 0);

                $qtyDelivered = floatval($item['qty_delivered'] ?? 0);
                $stmt2 = $conn->prepare("INSERT INTO delivery_items (delivery_id, product_id, product_name, qty_ordered, qty_delivered) VALUES (?,?,?,?,?)");
                $stmt2->bind_param("iisdd", $delId, $pid, $name, $qtyOrdered, $qtyDelivered);
                $stmt2->execute();
            }

            // Update delivery status
            $totalOrdered = array_sum(array_column($items, 'qty_ordered'));
            $totalDelivered = array_sum(array_column($items, 'qty_delivered'));
            $status = 'pending';
            if ($totalDelivered >= $totalOrdered) $status = 'delivered';
            elseif ($totalDelivered > 0) $status = 'partially_delivered';

            $stmt3 = $conn->prepare("UPDATE deliveries SET status=? WHERE id=?");
            $stmt3->bind_param("si", $status, $delId);
            $stmt3->execute();

            auditLog($conn, 'delivery_create', 'delivery', $delId, ['delivery_no' => $delNo]);
            $conn->commit();
            header('Location: ?msg=Delivery+' . urlencode($delNo) . '+created'); exit;
        } catch (Exception $ex) {
            $conn->rollback();
            header('Location: ?error=' . urlencode($ex->getMessage())); exit;
        }
    }

    if ($act === 'update_status') {
        $id = intval($_POST['id']);
        $status = $_POST['status'] ?? '';
        $validStatuses = ['pending','partially_delivered','delivered','cancelled'];
        if (!in_array($status, $validStatuses)) { header('Location: ?error=Invalid+status'); exit; }

        $stmt = $conn->prepare("UPDATE deliveries SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        header('Location: ?msg=Status+updated'); exit;
    }
}

$pageTitle = 'Deliveries';
$activePage = 'deliveries';
$statusFilter = $_GET['status'] ?? '';

$where = "WHERE 1=1";
$params = [];
$types = '';
if ($statusFilter && in_array($statusFilter, ['pending','partially_delivered','delivered','cancelled'])) {
    $where .= " AND d.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$stmt = $conn->prepare("SELECT d.*, COALESCE(cv.name, 'Walk-in') cust_name, s.invoice_no FROM deliveries d LEFT JOIN customers_v2 cv ON d.customer_id=cv.id LEFT JOIN sales s ON d.sale_id=s.id $where ORDER BY d.created_at DESC");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Deliveries</div>
    <div class="page-subtitle"><?= count($deliveries) ?> deliveries</div>
  </div>
</div>

<div class="table-card">
  <div class="table-toolbar">
    <form method="GET" style="display:flex;gap:8px">
      <select name="status" class="form-control" style="width:160px" onchange="this.form.submit()">
        <option value="">All Status</option>
        <?php foreach (['pending','partially_delivered','delivered','cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <table>
    <thead><tr><th>Delivery #</th><th>Customer</th><th>Invoice</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($deliveries): foreach ($deliveries as $d): ?>
      <tr>
        <td class="text-mono text-accent" style="font-size:12px"><?= e($d['delivery_no']) ?></td>
        <td style="font-size:12px"><?= e($d['cust_name']) ?></td>
        <td class="text-mono" style="font-size:12px"><?= $d['invoice_no'] ? e($d['invoice_no']) : '—' ?></td>
        <td class="text-muted" style="font-size:12px"><?= $d['delivery_date'] ? date('d/m/Y', strtotime($d['delivery_date'])) : '—' ?></td>
        <td>
          <?php
          $stBadge = 'badge-orange';
          if ($d['status'] === 'delivered') $stBadge = 'badge-green';
          elseif ($d['status'] === 'cancelled') $stBadge = 'badge-red';
          elseif ($d['status'] === 'partially_delivered') $stBadge = 'badge-blue';
          ?>
          <span class="badge <?= $stBadge ?>"><?= ucfirst(str_replace('_', ' ', $d['status'])) ?></span>
        </td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick='viewDelivery(<?= json_encode($d) ?>)'>View</button>
          <?php if ($d['status'] !== 'delivered' && $d['status'] !== 'cancelled' && isAdmin()): ?>
          <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= $d['id'] ?>">
            <input type="hidden" name="status" value="delivered">
            <button class="btn btn-primary btn-sm">Mark Delivered</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="6" class="empty-state">No deliveries found</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function viewDelivery(d) {
  alert('Delivery ' + d.delivery_no + '\nCustomer: ' + d.cust_name + '\nAddress: ' + (d.delivery_address || 'N/A') + '\nStatus: ' + d.status);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
