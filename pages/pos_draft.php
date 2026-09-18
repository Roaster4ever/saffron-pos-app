<?php
// pages/pos_draft.php — AJAX draft save/load/delete for POS
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');
$userId = $_SESSION['user_id'];

// Load draft by ID
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM pos_drafts WHERE id=? AND user_id=?");
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $draft = $stmt->get_result()->fetch_assoc();
    if (!$draft) { echo json_encode(['success' => false, 'error' => 'Draft not found']); exit; }
    echo json_encode([
        'success' => true,
        'draft_no' => $draft['draft_no'],
        'customer_id' => $draft['customer_id'],
        'customer_name' => $draft['customer_name'],
        'subtotal' => $draft['subtotal'],
        'discount' => $draft['discount'],
        'tax' => $draft['tax'],
        'total' => $draft['total'],
        'payment_method' => $draft['payment_method'],
        'items' => json_decode($draft['items_json'] ?? '[]', true),
    ]);
    exit;
}

// List all drafts for current user
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['action']) && $_GET['action'] === 'list')) {
    $stmt = $conn->prepare("SELECT id, draft_no, customer_name, total, item_count, items_json, DATE_FORMAT(created_at, '%d/%m %H:%i') as created_at FROM pos_drafts WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $drafts = array_map(function($d) {
        $items = json_decode($d['items_json'] ?? '[]', true);
        $itemNames = array_map(function($i) { return $i['product_name'] ?? $i['name'] ?? ''; }, $items ?? []);
        unset($d['items_json']);
        $d['items_text'] = implode(' ', $itemNames);
        return $d;
    }, $rows);
    echo json_encode($drafts);
    exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { echo json_encode(['success' => false, 'error' => 'Invalid security token']); exit; }

    $act = $_POST['action'] ?? '';

    if ($act === 'save_draft') {
        $cart = json_decode($_POST['cart'] ?? '[]', true);
        if (empty($cart)) { echo json_encode(['success' => false, 'error' => 'Cart is empty']); exit; }

        $discount = max(0, floatval($_POST['discount'] ?? 0));
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $paymentMethod = $_POST['payment_method'] ?? 'cash';

        // Get customer name
        $customerName = '';
        if ($customerId) {
            $cstmt = $conn->prepare("SELECT name FROM customers_v2 WHERE id=?");
            $cstmt->bind_param("i", $customerId);
            $cstmt->execute();
            $customerName = $cstmt->get_result()->fetch_assoc()['name'] ?? '';
        }

        // Calculate totals
        $subtotal = 0;
        $taxTotal = 0;
        $defaultRate = getSetting('default_tax_rate', 18);
        $itemCount = 0;
        foreach ($cart as $item) {
            $lineTotal = floatval($item['price']) * floatval($item['qty']);
            $subtotal += $lineTotal;
            $taxMode = $item['tax_mode'] ?? 'default';
            $rate = $taxMode === 'non_taxable' ? 0 : ($taxMode === 'custom' ? floatval($item['gst_rate'] ?? 0) : $defaultRate);
            $taxTotal += $lineTotal * $rate / 100;
            $itemCount++;
        }
        $total = max(0, $subtotal + $taxTotal - $discount);

        // Generate draft number
        $draftNo = 'DFT-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO pos_drafts (draft_no, user_id, customer_id, customer_name, subtotal, discount, tax, total, payment_method, item_count, items_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $itemsJson = json_encode(array_values($cart));
        $stmt->bind_param("siisddddsi", $draftNo, $userId, $customerId, $customerName, $subtotal, $discount, $taxTotal, $total, $paymentMethod, $itemCount, $itemsJson);
        $stmt->execute();

        echo json_encode(['success' => true, 'draft_no' => $draftNo, 'id' => $conn->insert_id]);
        exit;
    }

    if ($act === 'update_draft') {
        $draftId = intval($_POST['draft_id']);
        $cart = json_decode($_POST['cart'] ?? '[]', true);
        if (empty($cart)) { echo json_encode(['success' => false, 'error' => 'Cart is empty']); exit; }

        $discount = max(0, floatval($_POST['discount'] ?? 0));
        $customerId = intval($_POST['customer_id'] ?? 0) ?: null;
        $paymentMethod = $_POST['payment_method'] ?? 'cash';

        $customerName = '';
        if ($customerId) {
            $cstmt = $conn->prepare("SELECT name FROM customers_v2 WHERE id=?");
            $cstmt->bind_param("i", $customerId);
            $cstmt->execute();
            $customerName = $cstmt->get_result()->fetch_assoc()['name'] ?? '';
        }

        $subtotal = 0;
        $taxTotal = 0;
        $defaultRate = getSetting('default_tax_rate', 18);
        $itemCount = 0;
        foreach ($cart as $item) {
            $lineTotal = floatval($item['price']) * floatval($item['qty']);
            $subtotal += $lineTotal;
            $taxMode = $item['tax_mode'] ?? 'default';
            $rate = $taxMode === 'non_taxable' ? 0 : ($taxMode === 'custom' ? floatval($item['gst_rate'] ?? 0) : $defaultRate);
            $taxTotal += $lineTotal * $rate / 100;
            $itemCount++;
        }
        $total = max(0, $subtotal + $taxTotal - $discount);

        $itemsJson = json_encode(array_values($cart));
        $stmt = $conn->prepare("UPDATE pos_drafts SET customer_id=?, customer_name=?, subtotal=?, discount=?, tax=?, total=?, payment_method=?, item_count=?, items_json=? WHERE id=? AND user_id=?");
        $stmt->bind_param("isddddsiiii", $customerId, $customerName, $subtotal, $discount, $taxTotal, $total, $paymentMethod, $itemCount, $itemsJson, $draftId, $userId);
        $stmt->execute();

        echo json_encode(['success' => true, 'id' => $draftId]);
        exit;
    }

    if ($act === 'delete_draft') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM pos_drafts WHERE id=? AND user_id=?");
        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
