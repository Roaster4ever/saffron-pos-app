<?php
// pages/pos_search.php — AJAX product search for POS
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

$like = "%{$q}%";
$stmt = $conn->prepare("SELECT p.id, p.name, p.price, p.cost, p.min_price, p.wholesale_price, p.contractor_price,
    p.stock, p.reserved_stock, p.barcode, p.sku, p.model, p.taxable, p.gst_rate, p.tax_mode, p.selling_mode,
    p.standard_lengths, p.default_qty, p.low_stock_alert,
    b.name brand_name, u.short_name unit_name, u.allows_decimal
    FROM products p
    LEFT JOIN brands b ON p.brand_id=b.id
    LEFT JOIN units u ON p.unit_id=u.id
    WHERE p.is_active=1 AND (p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ? OR p.model LIKE ? OR b.name LIKE ?)
    ORDER BY p.name
    LIMIT 30");
$stmt->bind_param("sssss", $like, $like, $like, $like, $like);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode($results);
