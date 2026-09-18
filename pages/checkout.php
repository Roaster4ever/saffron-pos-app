<?php
// pages/checkout.php — AJAX endpoint, returns JSON only
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

if (!csrf_verify()) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$cart        = json_decode($_POST['cart'] ?? '[]', true);
$payment     = $_POST['payment_method'] ?? 'cash';
$discount    = max(0, floatval($_POST['discount'] ?? 0));
$paid        = floatval($_POST['paid'] ?? 0);
$customerId  = intval($_POST['customer_id'] ?? 0) ?: null;

// Validate payment method
$validMethods = ['cash','card','mobile','credit','bank_transfer','cheque'];
if (!in_array($payment, $validMethods)) $payment = 'cash';

// Credit sales require a customer
if ($payment === 'credit' && !$customerId) {
    echo json_encode(['success' => false, 'error' => 'Credit sales require a customer']);
    exit;
}

if (empty($cart)) {
    echo json_encode(['success' => false, 'error' => 'Cart is empty']);
    exit;
}

// ── Fetch customer info if selected ──
$customer = null;
$customerName = 'Walk-in Customer';
if ($customerId) {
    $stmt = $conn->prepare("SELECT * FROM customers_v2 WHERE id=? AND is_active=1");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    if (!$customer) {
        echo json_encode(['success' => false, 'error' => 'Customer not found or inactive']);
        exit;
    }
    $customerName = $customer['name'];
}

    // ── Server-side stock validation + fetch tax info + price floor ──
    foreach ($cart as &$item) {
        $pid = intval($item['id']);
        $qty = floatval($item['qty']);
        if ($pid <= 0 || $qty <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid product or quantity']);
            exit;
        }
        $stmt = $conn->prepare("SELECT stock, reserved_stock, tax_mode, taxable, gst_rate, name, min_price, price, wholesale_price, contractor_price, selling_mode FROM products WHERE id=?");
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        if (!$product) {
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            exit;
        }
        $available = $product['stock'] - ($product['reserved_stock'] ?? 0);
        if ($available < $qty) {
            echo json_encode(['success' => false, 'error' => 'Insufficient stock for ' . $product['name'] . ' (available: ' . $available . ', requested: ' . $qty . ')']);
            exit;
        }

        // Price floor enforcement (server-side)
        $minPrice = floatval($product['min_price'] ?? 0);
        $sentPrice = floatval($item['price'] ?? 0);
        if ($minPrice > 0 && $sentPrice < $minPrice) {
            echo json_encode(['success' => false, 'error' => 'Price for ' . $product['name'] . ' is below minimum (' . CURRENCY . number_format($minPrice, 2) . ')']);
            exit;
        }

        $taxInfo = resolveTax($product);
        $item['_taxable'] = $taxInfo['taxable'] ? 1 : 0;
        $item['_gst_rate'] = $taxInfo['rate'];
        $item['_server_name'] = $product['name'];
    }

// ── Calculate totals ──
$subtotal = 0;
$totalTax = 0;
foreach ($cart as &$item) {
    $lineSub = floatval($item['price']) * floatval($item['qty']);
    $subtotal += $lineSub;
    if ($item['_taxable']) {
        $totalTax += round($lineSub * $item['_gst_rate'] / 100, 2);
    }
}
$totalTax = round($totalTax, 2);
$total = round($subtotal + $totalTax - $discount, 2);

// Credit sale: outstanding = total (customer owes us)
if ($payment === 'credit') {
    $paid = 0;
    $outstanding = $total;
    $change = 0;
} else {
    $paid = min($paid, $total); // Don't allow paid > total
    $outstanding = 0;
    $change = round(max(0, $paid - $total), 2);
}

$invoice = generateInvoice($conn);
$uid = $_SESSION['user_id'] ?? null;

$conn->begin_transaction();
try {
    // ── Insert sale record ──
    $stmt = $conn->prepare("INSERT INTO sales (invoice_no, customer_id, customer_v2_id, user_id, subtotal, discount, tax, total, paid, change_amount, payment_method, outstanding) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siiddddddsd", $invoice, $customerId, $uid, $subtotal, $discount, $totalTax, $total, $paid, $change, $payment, $outstanding);
    $stmt->execute();
    $saleId = $conn->insert_id;

    // ── Insert sale items + deduct stock ──
    $itemsOut = [];
    foreach ($cart as &$item) {
        $pid   = intval($item['id']);
        $name  = $item['_server_name'] ?? $item['name'];
        $qty   = floatval($item['qty']);
        $price = floatval($item['price']);
        $itot  = round($price * $qty, 2);
        $itemTax = $item['_taxable'] ? round($itot * $item['_gst_rate'] / 100, 2) : 0;
        $itemGstRate = $item['_taxable'] ? $item['_gst_rate'] : 0;
        $unit = $item['unit'] ?? 'pc';

        $stmt2 = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, qty, unit, price, total, tax_amount, gst_rate) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt2->bind_param("iisdsdddd", $saleId, $pid, $name, $qty, $unit, $price, $itot, $itemTax, $itemGstRate);
        $stmt2->execute();

        // Deduct stock
        $stmt3 = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $stmt3->bind_param("di", $qty, $pid);
        $stmt3->execute();

        // Log inventory movement
        logInventoryMovement($conn, $pid, -$qty, 'sale', 'sale', "Invoice $invoice");

        $itemsOut[] = [
            'name'      => $name,
            'qty'       => $qty,
            'unit'      => $unit,
            'price'     => $price,
            'total'     => $itot,
            'tax_amount'=> $itemTax,
            'gst_rate'  => $itemGstRate,
        ];
    }

    // ── Customer ledger entry for credit sales ──
    if ($payment === 'credit' && $customerId && $outstanding > 0) {
        updateCustomerBalance($conn, $customerId, 'invoice', $outstanding, 'sale', $saleId, "Invoice $invoice");
    }

    // ── Audit log ──
    auditLog($conn, 'sale_create', 'sale', $saleId, [
        'invoice_no' => $invoice,
        'customer'   => $customerName,
        'total'      => $total,
        'payment'    => $payment,
    ]);

    $conn->commit();

    echo json_encode([
        'success'        => true,
        'sale_id'        => $saleId,
        'invoice_no'     => $invoice,
        'customer_name'  => $customerName,
        'payment_method' => $payment,
        'subtotal'       => $subtotal,
        'discount'       => $discount,
        'tax'            => $totalTax,
        'total'          => $total,
        'paid'           => $paid,
        'change'         => $change,
        'outstanding'    => $outstanding,
        'date'           => date('d/m/Y H:i'),
        'shop_name'      => SHOP_NAME,
        'shop_address'   => SHOP_ADDRESS,
        'shop_phone'     => SHOP_PHONE,
        'shop_email'     => SHOP_EMAIL,
        'currency'       => CURRENCY,
        'items'          => $itemsOut,
    ]);
} catch (Exception $ex) {
    $conn->rollback();
    error_log('CHECKOUT ERROR: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    echo json_encode(['success' => false, 'error' => 'Checkout failed. Please try again.']);
} catch (Error $ex) {
    $conn->rollback();
    error_log('CHECKOUT FATAL: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred. Please try again.']);
}
