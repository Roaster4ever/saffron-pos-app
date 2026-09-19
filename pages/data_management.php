<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (!isAdmin()) { header('Location: ?error=Admin+access+required'); exit; }

$isVercel = isVercel();

// ════════════════════════════════════════════════════════
// POST HANDLERS
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { header('Location: ?error=Invalid+security+token'); exit; }
    $act = $_POST['action'] ?? '';

    // ── FULL BACKUP (pure PHP — no exec needed) ──
    if ($act === 'backup') {
        $sql = generateSqlDump($conn);
        $gz = @gzencode($sql);
        $filename = 'saffron-pos-backup-' . date('Y-m-d-His') . '.sql.gz';
        auditLog($conn, 'full_backup_created', 'system', null, ['filename' => $filename, 'size' => strlen($gz)]);
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($gz));
        header('Cache-Control: no-cache');
        echo $gz;
        exit;
    }

    // ── RESTORE (pure PHP — no exec needed) ──
    if ($act === 'restore') {
        if (empty($_FILES['restore_file']['tmp_name'])) {
            header('Location: ?error=No+file+uploaded'); exit;
        }
        $file = $_FILES['restore_file'];
        if ($file['size'] > 50 * 1024 * 1024) {
            header('Location: ?error=File+too+large+(max+50MB)'); exit;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['sql', 'gz', 'gzip'])) {
            header('Location: ?error=Invalid+file+type'); exit;
        }
        $confirm = $_POST['confirm_restore'] ?? '';
        if ($confirm !== 'RESTORE') {
            header('Location: ?error=Type+RESTORE+to+confirm'); exit;
        }

        $sqlContent = file_get_contents($file['tmp_name']);
        if (!$sqlContent) {
            header('Location: ?error=Could+not+read+file'); exit;
        }

        $isGzip = ($ext === 'gz' || $ext === 'gzip');
        if ($isGzip) {
            $decompressed = @gzdecode($sqlContent);
            if ($decompressed === false) {
                header('Location: ?error=Could+not+decompress+file'); exit;
            }
            $sqlContent = $decompressed;
        }

        $result = executeSqlRestore($conn, $sqlContent);
        if ($result['success']) {
            auditLog($conn, 'restore_completed', 'system', null, ['file' => $file['name']]);
            header('Location: ?msg=Database+restored+successfully'); exit;
        } else {
            auditLog($conn, 'restore_failed', 'system', null, ['file' => $file['name'], 'error' => implode("\n", $result['errors'])]);
            header('Location: ?error=Restore+failed:+' . urlencode(implode(' ', array_slice($result['errors'], 0, 3)))); exit;
        }
    }

    // ── PRODUCT CSV IMPORT ──
    if ($act === 'product_csv_template') {
        $headers = ['sku','name','brand','model','category','unit','barcode','cost_price','retail_price','wholesale_price','contractor_price','min_price','initial_stock','tax_mode','gst_rate','low_stock_alert','description'];
        $sample = [
            ['MPPR-ELB-25','Master PPR Elbow 25mm','Master','PPR-E25','PPR Fittings','pc','6901010500025','102','140','120','110','110','100','default','18','5','PPR elbow fitting 25mm'],
            ['SON-BM-001','Sonex Basin Mixer Chrome','Sonex','SM-204','Faucets','pc','','5500','8500','7500','6800','6500','20','default','18','5','Single lever basin mixer'],
        ];
        sendCsvDownload('saffron-product-template.csv', generateCsv($headers, $sample));
    }

    if ($act === 'stock_csv_template') {
        $headers = ['sku','quantity','cost_price','supplier','reference','note'];
        $sample = [
            ['MPPR-ELB-25','100','102','ABC Traders','PO-2026001','Regular restock'],
            ['SON-BM-001','5','8300','XYZ Sanitary','PO-2026001','Restock'],
        ];
        sendCsvDownload('saffron-stock-template.csv', generateCsv($headers, $sample));
    }

    if ($act === 'product_import') {
        if (empty($_FILES['csv_file']['tmp_name'])) {
            header('Location: ?error=No+file+uploaded'); exit;
        }
        $file = $_FILES['csv_file'];
        if ($file['size'] > 10 * 1024 * 1024) {
            header('Location: ?error=File+too+large+(max+10MB)'); exit;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            header('Location: ?error=Please+upload+a+CSV+file'); exit;
        }
        $importMode = $_POST['import_mode'] ?? 'create_new';

        $parsed = parseCsvFile(fopen($file['tmp_name'], 'r'), 2000);
        if ($parsed['error']) {
            header('Location: ?error=' . urlencode($parsed['error'])); exit;
        }
        // Store in session for preview step
        $_SESSION['product_import'] = [
            'filename' => $file['name'],
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'mode' => $importMode,
            'timestamp' => time(),
        ];
        header('Location: ?page=product_preview'); exit;
    }

    // ── PRODUCT IMPORT CONFIRM ──
    if ($act === 'product_import_confirm') {
        $data = $_SESSION['product_import'] ?? null;
        if (!$data || time() - $data['timestamp'] > 600) {
            unset($_SESSION['product_import']);
            header('Location: ?error=Import+session+expired.+Please+upload+again.'); exit;
        }
        unset($_SESSION['product_import']);
        $rows = $data['rows'];
        $mode = $data['mode'];
        $results = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => 0, 'errors' => []];

        // Lookup caches
        $catCache = [];
        $brandCache = [];
        $unitCache = [];

        $conn->begin_transaction();
        try {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $sku = trim($row['sku'] ?? '');
                $name = trim($row['name'] ?? '');

                if (!$sku || !$name) {
                    $results['rejected']++;
                    $results['errors'][] = ['row' => $rowNum, 'sku' => $sku, 'reason' => 'Missing SKU or name'];
                    continue;
                }

                // Check existing by SKU
                $stmt = $conn->prepare("SELECT id, stock FROM products WHERE sku=?");
                $stmt->bind_param("s", $sku);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();

                if ($existing) {
                    if ($mode === 'create_new') {
                        $results['skipped']++;
                        continue;
                    }
                    // Update existing
                    // ... (update logic if mode allows)
                    $results['skipped']++;
                    continue;
                }

                // Resolve category
                $catName = trim($row['category'] ?? '');
                if ($catName && !isset($catCache[$catName])) {
                    $s = $conn->prepare("SELECT id FROM categories WHERE name=?");
                    $s->bind_param("s", $catName);
                    $s->execute();
                    $r = $s->get_result()->fetch_assoc();
                    if ($r) { $catCache[$catName] = $r['id']; }
                    else {
                        $s2 = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                        $s2->bind_param("s", $catName);
                        $s2->execute();
                        $catCache[$catName] = $conn->insert_id;
                    }
                }
                $catId = $catCache[$catName] ?? null;

                // Resolve brand
                $brandName = trim($row['brand'] ?? '');
                if ($brandName && !isset($brandCache[$brandName])) {
                    $s = $conn->prepare("SELECT id FROM brands WHERE name=?");
                    $s->bind_param("s", $brandName);
                    $s->execute();
                    $r = $s->get_result()->fetch_assoc();
                    if ($r) { $brandCache[$brandName] = $r['id']; }
                    else {
                        $s2 = $conn->prepare("INSERT INTO brands (name) VALUES (?)");
                        $s2->bind_param("s", $brandName);
                        $s2->execute();
                        $brandCache[$brandName] = $conn->insert_id;
                    }
                }
                $brandId = $brandCache[$brandName] ?? null;

                // Resolve unit
                $unitName = trim($row['unit'] ?? '') ?: 'pc';
                if (!isset($unitCache[$unitName])) {
                    $s = $conn->prepare("SELECT id FROM units WHERE short_name=? OR name=?");
                    $s->bind_param("ss", $unitName, $unitName);
                    $s->execute();
                    $r = $s->get_result()->fetch_assoc();
                    $unitCache[$unitName] = $r ? $r['id'] : null;
                }
                $unitId = $unitCache[$unitName];

                $barcode = trim($row['barcode'] ?? '') ?: null;
                $model = trim($row['model'] ?? '') ?: null;
                $cost = max(0, floatval($row['cost_price'] ?? 0));
                $price = max(0, floatval($row['retail_price'] ?? 0));
                $wholesale = max(0, floatval($row['wholesale_price'] ?? 0));
                $contractor = max(0, floatval($row['contractor_price'] ?? 0));
                $minPrice = max(0, floatval($row['min_price'] ?? 0));
                $initStock = max(0, floatval($row['initial_stock'] ?? $row['current_stock'] ?? 0));
                $taxMode = $row['tax_mode'] ?? 'default';
                if (!in_array($taxMode, ['default','non_taxable','custom'])) $taxMode = 'default';
                $gstRate = max(0, floatval($row['gst_rate'] ?? 18));
                $lowStock = max(0, floatval($row['low_stock_alert'] ?? 5));
                $desc = trim($row['description'] ?? '');
                $sellingMode = trim($row['selling_mode'] ?? 'fixed');
                if (!in_array($sellingMode, ['fixed','measured'])) $sellingMode = 'fixed';
                $standardLengths = trim($row['standard_lengths'] ?? '');
                $defaultQty = max(0, floatval($row['default_qty'] ?? 0));

                // Derive legacy fields
                if ($taxMode === 'non_taxable') { $taxable = 0; $gstRate = 0; }
                elseif ($taxMode === 'custom') { $taxable = 1; }
                else { $taxable = 1; $gstRate = 18; }

                $ins = $conn->prepare("INSERT INTO products (category_id,brand_id,name,sku,barcode,model,price,cost,min_price,wholesale_price,contractor_price,stock,low_stock_alert,taxable,gst_rate,tax_mode,unit_id,selling_mode,standard_lengths,default_qty,description,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->bind_param("iissssdddddddidsissssi", $catId, $brandId, $name, $sku, $barcode, $model, $price, $cost, $minPrice, $wholesale, $contractor, $initStock, $lowStock, $taxable, $gstRate, $taxMode, $unitId, $sellingMode, $standardLengths, $defaultQty, $desc, 1);
                $ins->execute();
                $newId = $conn->insert_id;

                if ($initStock > 0) {
                    logInventoryMovement($conn, $newId, $initStock, 'initial', null, 'CSV import - initial stock');
                }
                auditLog($conn, 'product_create_csv', 'product', $newId, ['name' => $name, 'sku' => $sku, 'source' => 'csv_import']);
                $results['created']++;
            }
            $conn->commit();
            auditLog($conn, 'product_csv_import', 'system', null, [
                'created' => $results['created'], 'skipped' => $results['skipped'],
                'rejected' => $results['rejected'], 'filename' => $data['filename']
            ]);
        } catch (Exception $ex) {
            $conn->rollback();
            header('Location: ?error=Import+failed:+' . urlencode($ex->getMessage())); exit;
        }

        $_SESSION['import_result'] = $results;
        header('Location: ?page=product_result'); exit;
    }

    // ── STOCK CSV IMPORT ──
    if ($act === 'stock_import') {
        if (empty($_FILES['csv_file']['tmp_name'])) {
            header('Location: ?error=No+file+uploaded'); exit;
        }
        $file = $_FILES['csv_file'];
        if ($file['size'] > 10 * 1024 * 1024) {
            header('Location: ?error=File+too+large+(max+10MB)'); exit;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            header('Location: ?error=Please+upload+a+CSV+file'); exit;
        }

        $parsed = parseCsvFile(fopen($file['tmp_name'], 'r'), 2000);
        if ($parsed['error']) {
            header('Location: ?error=' . urlencode($parsed['error'])); exit;
        }
        $_SESSION['stock_import'] = [
            'filename' => $file['name'],
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'timestamp' => time(),
        ];
        header('Location: ?page=stock_preview'); exit;
    }

    // ── STOCK IMPORT CONFIRM ──
    if ($act === 'stock_import_confirm') {
        $data = $_SESSION['stock_import'] ?? null;
        if (!$data || time() - $data['timestamp'] > 600) {
            unset($_SESSION['stock_import']);
            header('Location: ?error=Import+session+expired.+Please+upload+again.'); exit;
        }
        unset($_SESSION['stock_import']);
        $rows = $data['rows'];
        $results = ['restocked' => 0, 'rejected' => 0, 'errors' => [], 'changes' => []];

        $conn->begin_transaction();
        try {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $sku = trim($row['sku'] ?? '');
                $qty = floatval($row['quantity'] ?? 0);

                if (!$sku) {
                    $results['rejected']++;
                    $results['errors'][] = ['row' => $rowNum, 'sku' => '', 'reason' => 'SKU is required'];
                    continue;
                }
                if ($qty <= 0) {
                    $results['rejected']++;
                    $results['errors'][] = ['row' => $rowNum, 'sku' => $sku, 'reason' => 'Quantity must be greater than zero'];
                    continue;
                }

                $stmt = $conn->prepare("SELECT id, name, stock FROM products WHERE sku=? AND is_active=1");
                $stmt->bind_param("s", $sku);
                $stmt->execute();
                $product = $stmt->get_result()->fetch_assoc();

                if (!$product) {
                    $results['rejected']++;
                    $results['errors'][] = ['row' => $rowNum, 'sku' => $sku, 'reason' => 'Product not found'];
                    continue;
                }

                $cost = floatval($row['cost_price'] ?? 0);
                $supplier = trim($row['supplier'] ?? '') ?: null;
                $reference = trim($row['reference'] ?? '') ?: null;
                $note = trim($row['note'] ?? '') ?: null;

                $stmt2 = $conn->prepare("UPDATE products SET stock=stock+? WHERE id=?");
                $stmt2->bind_param("di", $qty, $product['id']);
                $stmt2->execute();

                // Log with supplier/cost info
                $stmt3 = $conn->prepare("INSERT INTO inventory_log (product_id, qty_added, type, reason, supplier, unit_cost, reference, note) VALUES (?,?,'stock_in','stock_in',?,?,?,?)");
                $stmt3->bind_param("idsdss", $product['id'], $qty, $supplier, $cost, $reference, $note);
                $stmt3->execute();

                auditLog($conn, 'stock_csv_import', 'product', $product['id'], [
                    'name' => $product['name'], 'sku' => $sku, 'qty_added' => $qty,
                    'old_stock' => $product['stock'], 'new_stock' => $product['stock'] + $qty,
                    'supplier' => $supplier, 'cost' => $cost, 'reference' => $reference
                ]);

                $results['changes'][] = ['name' => $product['name'], 'sku' => $sku, 'old' => $product['stock'], 'added' => $qty, 'new' => $product['stock'] + $qty];
                $results['restocked']++;
            }
            $conn->commit();
            auditLog($conn, 'stock_csv_import_batch', 'system', null, [
                'restocked' => $results['restocked'], 'rejected' => $results['rejected'], 'filename' => $data['filename']
            ]);
        } catch (Exception $ex) {
            $conn->rollback();
            header('Location: ?error=Import+failed:+' . urlencode($ex->getMessage())); exit;
        }

        $_SESSION['stock_result'] = $results;
        header('Location: ?page=stock_result'); exit;
    }

    // ── CSV EXPORTS ──
    if ($act === 'export_products') {
        $products = $conn->query("SELECT p.*, c.name cat_name, b.name brand_name, u.short_name unit_name FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN brands b ON p.brand_id=b.id LEFT JOIN units u ON p.unit_id=u.id WHERE p.is_active=1 ORDER BY p.name")->fetch_all(MYSQLI_ASSOC);
        $headers = ['sku','name','brand','model','category','unit','barcode','cost_price','retail_price','wholesale_price','contractor_price','min_price','current_stock','tax_mode','gst_rate','low_stock_alert','selling_mode','standard_lengths','default_qty'];
        $rows = [];
        foreach ($products as $p) {
            $tax = resolveTax($p);
            $rows[] = [
                'sku' => $p['sku'], 'name' => $p['name'], 'brand' => $p['brand_name'] ?? '',
                'model' => $p['model'] ?? '', 'category' => $p['cat_name'] ?? '', 'unit' => $p['unit_name'] ?? 'pc',
                'barcode' => $p['barcode'] ?? '', 'cost_price' => $p['cost'], 'retail_price' => $p['price'],
                'wholesale_price' => $p['wholesale_price'], 'contractor_price' => $p['contractor_price'],
                'min_price' => $p['min_price'], 'current_stock' => $p['stock'],
                'tax_mode' => $p['tax_mode'], 'gst_rate' => $tax['rate'], 'low_stock_alert' => $p['low_stock_alert'],
                'selling_mode' => $p['selling_mode'] ?? 'fixed', 'standard_lengths' => $p['standard_lengths'] ?? '',
                'default_qty' => $p['default_qty'] ?? '',
            ];
        }
        auditLog($conn, 'product_csv_export', 'system', null, ['count' => count($rows)]);
        sendCsvDownload('saffron-products-' . date('Y-m-d') . '.csv', generateCsv($headers, array_map(function($r) {
            return array_map('csvEscape', $r);
        }, $rows)));
    }

    if ($act === 'export_sales') {
        $where = '1=1'; $params = []; $types = '';
        if (!empty($_POST['date_from'])) { $where .= ' AND s.created_at >= ?'; $params[] = $_POST['date_from'] . ' 00:00:00'; $types .= 's'; }
        if (!empty($_POST['date_to'])) { $where .= ' AND s.created_at <= ?'; $params[] = $_POST['date_to'] . ' 23:59:59'; $types .= 's'; }
        $sql = "SELECT s.invoice_no, s.created_at, COALESCE(cv.name,'Walk-in') customer, s.subtotal, s.discount, s.tax, s.total, s.paid, s.outstanding, s.payment_method, s.status, u.name cashier FROM sales s LEFT JOIN customers_v2 cv ON s.customer_v2_id=cv.id LEFT JOIN users u ON s.user_id=u.id WHERE {$where} ORDER BY s.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $headers = ['invoice_no','date','customer','subtotal','discount','tax','total','paid','outstanding','payment_method','status','cashier'];
        $rows = array_map(function($s) use ($headers) {
            $r = [];
            foreach ($headers as $h) {
                $r[$h] = $h === 'date' ? date('Y-m-d H:i', strtotime($s['created_at'])) : ($s[$h] ?? '');
            }
            return array_map('csvEscape', $r);
        }, $sales);
        auditLog($conn, 'sales_csv_export', 'system', null, ['count' => count($rows)]);
        sendCsvDownload('saffron-sales-' . date('Y-m-d') . '.csv', generateCsv($headers, $rows));
    }

    if ($act === 'export_sale_items') {
        $where = '1=1'; $params = []; $types = '';
        if (!empty($_POST['date_from'])) { $where .= ' AND s.created_at >= ?'; $params[] = $_POST['date_from'] . ' 00:00:00'; $types .= 's'; }
        if (!empty($_POST['date_to'])) { $where .= ' AND s.created_at <= ?'; $params[] = $_POST['date_to'] . ' 23:59:59'; $types .= 's'; }
        $sql = "SELECT s.invoice_no, s.created_at, si.product_name, si.qty, si.unit, si.price, si.total, si.tax_amount, si.gst_rate FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE {$where} ORDER BY s.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $headers = ['invoice_no','date','product','qty','unit','price','total','tax_amount','gst_rate'];
        $rows = array_map(function($i) {
            return array_map('csvEscape', [
                'invoice_no' => $i['invoice_no'], 'date' => date('Y-m-d H:i', strtotime($i['created_at'])),
                'product' => $i['product_name'], 'qty' => $i['qty'], 'unit' => $i['unit'],
                'price' => $i['price'], 'total' => $i['total'], 'tax_amount' => $i['tax_amount'], 'gst_rate' => $i['gst_rate'],
            ]);
        }, $items);
        auditLog($conn, 'sale_items_csv_export', 'system', null, ['count' => count($rows)]);
        sendCsvDownload('saffron-sale-items-' . date('Y-m-d') . '.csv', generateCsv($headers, $rows));
    }

    if ($act === 'export_inventory') {
        $log = $conn->query("SELECT il.created_at, p.name, p.sku, il.type, il.qty_added, il.reason, il.supplier, il.unit_cost, il.reference, il.note FROM inventory_log il JOIN products p ON il.product_id=p.id ORDER BY il.created_at DESC")->fetch_all(MYSQLI_ASSOC);
        $headers = ['date','product','sku','type','quantity','reason','supplier','unit_cost','reference','note'];
        $rows = array_map(function($l) {
            return array_map('csvEscape', [
                'date' => date('Y-m-d H:i', strtotime($l['created_at'])), 'product' => $l['name'], 'sku' => $l['sku'] ?? '',
                'type' => $l['type'], 'quantity' => $l['qty_added'], 'reason' => $l['reason'] ?? '',
                'supplier' => $l['supplier'] ?? '', 'unit_cost' => $l['unit_cost'] ?? '', 'reference' => $l['reference'] ?? '', 'note' => $l['note'] ?? '',
            ]);
        }, $log);
        auditLog($conn, 'inventory_csv_export', 'system', null, ['count' => count($rows)]);
        sendCsvDownload('saffron-inventory-log-' . date('Y-m-d') . '.csv', generateCsv($headers, $rows));
    }

    if ($act === 'export_customers') {
        $customers = $conn->query("SELECT * FROM customers_v2 WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        $headers = ['name','phone','email','type','city','address','credit_limit','balance'];
        $rows = array_map(function($c) {
            return array_map('csvEscape', [
                'name' => $c['name'], 'phone' => $c['phone'] ?? '', 'email' => $c['email'] ?? '',
                'type' => $c['type'] ?? '', 'city' => $c['city'] ?? '', 'address' => $c['address'] ?? '',
                'credit_limit' => $c['credit_limit'], 'balance' => $c['balance'],
            ]);
        }, $customers);
        auditLog($conn, 'customer_csv_export', 'system', null, ['count' => count($rows)]);
        sendCsvDownload('saffron-customers-' . date('Y-m-d') . '.csv', generateCsv($headers, $rows));
    }

    if ($act === 'export_expenses') {
        $where = '1=1'; $params = []; $types = '';
        if (!empty($_POST['date_from'])) { $where .= ' AND DATE(e.created_at) >= ?'; $params[] = $_POST['date_from']; $types .= 's'; }
        if (!empty($_POST['date_to'])) { $where .= ' AND DATE(e.created_at) <= ?'; $params[] = $_POST['date_to']; $types .= 's'; }
        $sql = "SELECT e.title, e.amount, ec.name category, DATE(e.created_at) AS expense_date, e.note, u.name user FROM expenses e LEFT JOIN expense_categories ec ON e.category_id=ec.id LEFT JOIN users u ON e.user_id=u.id WHERE {$where} ORDER BY e.created_at DESC";
        $stmt = $conn->prepare($sql);
        if ($types) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $headers = ['title','amount','category','date','note','recorded_by'];
        $rows = array_map(function($e) {
            return array_map('csvEscape', [
                'title' => $e['title'], 'amount' => $e['amount'], 'category' => $e['category'] ?? '',
                'date' => $e['expense_date'], 'note' => $e['note'] ?? '', 'recorded_by' => $e['user'] ?? '',
            ]);
        }, $expenses);
        auditLog($conn, 'expense_csv_export', 'system', null, ['count' => count($rows)]);
        sendCsvDownload('saffron-expenses-' . date('Y-m-d') . '.csv', generateCsv($headers, $rows));
    }

    header('Location: ?error=Unknown+action'); exit;
}

// ════════════════════════════════════════════════════════
// PAGE VIEWS
// ════════════════════════════════════════════════════════
$page = $_GET['page'] ?? 'main';
$activePage = 'data_management';
$pageTitle = 'Data & Backup';
include __DIR__ . '/../includes/header.php';

// Database stats for main page
$isPg = $conn->is_pgsql();
if ($isPg) {
    $dbSize = $conn->query("SELECT tablename AS table_name, ROUND(pg_total_relation_size(schemaname||'.'||tablename)/1024.0/1024.0, 2) AS size_mb FROM pg_tables WHERE schemaname='public' ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC")->fetch_all(MYSQLI_ASSOC);
    $totalSize = array_sum(array_column($dbSize, 'size_mb'));
    $tableCount = count($dbSize);
} else {
    $dbSize = $conn->query("SELECT table_name, ROUND(data_length/1024/1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema='pos_db' GROUP BY table_name ORDER BY data_length DESC")->fetch_all(MYSQLI_ASSOC);
    $totalSize = array_sum(array_column($dbSize, 'size_mb'));
    $tableCount = $conn->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema='pos_db'")->fetch_assoc()['c'];
}
$lastSale = $conn->query("SELECT MAX(created_at) d FROM sales")->fetch_assoc()['d'] ?? 'Never';
$productCount = $conn->query("SELECT COUNT(*) c FROM products")->fetch_assoc()['c'];
?>

<div class="page-header">
  <div>
    <div class="page-title">Data & Backup</div>
  </div>
</div>

  <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?= e(str_replace('+', ' ', $_GET['msg'])) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><?= e(str_replace('+', ' ', $_GET['error'])) ?></div>
  <?php endif; ?>

<?php if ($page === 'product_preview'): $data = $_SESSION['product_import'] ?? null; if ($data): ?>
  <div class="card"><div class="card-header"><span class="card-title">Product Import Preview</span></div><div class="card-body">
  <?php
    $rows = $data['rows'];
    $mode = $data['mode'];
    // Validate rows
    $valid = 0; $skipped = 0; $invalid = 0; $errors = []; $skus = []; $barcodes = [];
    foreach ($rows as $i => $row) {
        $r = $i + 2;
        $sku = trim($row['sku'] ?? '');
        $name = trim($row['name'] ?? '');
        if (!$sku || !$name) { $invalid++; $errors[] = ['row'=>$r,'sku'=>$sku,'reason'=>'Missing SKU or name']; continue; }
        if (in_array($sku, $skus)) { $invalid++; $errors[] = ['row'=>$r,'sku'=>$sku,'reason'=>'Duplicate SKU in CSV']; continue; }
        $skus[] = $sku;
        $bc = trim($row['barcode'] ?? '');
        if ($bc && in_array($bc, $barcodes)) { $invalid++; $errors[] = ['row'=>$r,'sku'=>$sku,'reason'=>'Duplicate barcode in CSV']; continue; }
        if ($bc) $barcodes[] = $bc;
        // Check DB duplicates
        $stmt = $conn->prepare("SELECT id FROM products WHERE sku=?");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            if ($mode === 'create_new') { $skipped++; } else { $valid++; }
        } else { $valid++; }
    }
    // Check DB barcode duplicates
    foreach ($barcodes as $bc) {
        $stmt = $conn->prepare("SELECT id, name FROM products WHERE barcode=?");
        $stmt->bind_param("s", $bc);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if ($existing) { $errors[] = ['row'=>'—','sku'=>'','reason'=>"Barcode \"{$bc}\" already exists on \"{$existing['name']}\""]; }
    }
  ?>
    <div class="stats-grid" style="margin-bottom:16px">
      <div class="stat-card"><div class="stat-label">Total Rows</div><div class="stat-value" style="font-size:22px"><?= count($rows) ?></div></div>
      <div class="stat-card" style="border-color:rgba(58,255,138,.3)"><div class="stat-label">New Products</div><div class="stat-value" style="font-size:22px;color:var(--green)"><?= $valid ?></div></div>
      <div class="stat-card" style="border-color:rgba(255,140,74,.3)"><div class="stat-label">Skipped</div><div class="stat-value" style="font-size:22px;color:var(--orange)"><?= $skipped ?></div></div>
      <div class="stat-card" style="border-color:rgba(255,74,74,.3)"><div class="stat-label">Invalid</div><div class="stat-value" style="font-size:22px;color:var(--red)"><?= $invalid ?></div></div>
    </div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:12px">Mode: <strong><?= $mode === 'create_new' ? 'Create new products only' : 'Create + update existing' ?></strong>. Existing products with matching SKU will be <?= $mode === 'create_new' ? 'skipped' : 'updated' ?>. <?= $mode === 'create_new' ? '<code>initial_stock</code> is only used for new products — existing stock will NOT change.' : '' ?></p>
    <?php if (!empty($errors)): ?>
    <div style="margin-bottom:16px">
      <strong style="color:var(--red)">Issues (<?= count($errors) ?>)</strong>
      <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);margin-top:8px">
        <table><thead><tr><th>Row</th><th>SKU</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($errors as $e): ?>
        <tr><td><?= $e['row'] ?></td><td style="font-family:var(--mono)"><?= e($e['sku']) ?></td><td style="color:var(--red)"><?= e($e['reason']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($valid > 0 || ($mode !== 'create_new' && $skipped === 0)): ?>
    <form method="POST" style="display:flex;gap:8px;align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="product_import_confirm">
      <button type="submit" class="btn btn-primary" onclick="return confirm('Import <?= $valid ?> new product(s)? This will create records in the database.')">
        Confirm Import (<?= $valid ?> new<?= $skipped > 0 ? ", {$skipped} skipped" : '' ?>)
      </button>
      <a href="?page=main" class="btn btn-secondary">Cancel</a>
    </form>
    <?php else: ?>
    <p style="color:var(--text2);font-size:13px">No new products to import.</p>
    <a href="?page=main" class="btn btn-secondary">Back</a>
    <?php endif; ?>
  </div></div>
<?php else: header('Location: ?page=main'); exit; endif; ?>

<?php elseif ($page === 'product_result'): $results = $_SESSION['import_result'] ?? null; if ($results): unset($_SESSION['import_result']); ?>
  <div class="card"><div class="card-header"><span class="card-title">Import Complete</span></div><div class="card-body">
    <div class="stats-grid" style="margin-bottom:16px">
      <div class="stat-card" style="border-color:rgba(58,255,138,.3)"><div class="stat-label">Created</div><div class="stat-value" style="font-size:22px;color:var(--green)"><?= $results['created'] ?></div></div>
      <div class="stat-card"><div class="stat-label">Skipped</div><div class="stat-value" style="font-size:22px"><?= $results['skipped'] ?></div></div>
      <div class="stat-card" style="border-color:rgba(255,74,74,.3)"><div class="stat-label">Rejected</div><div class="stat-value" style="font-size:22px;color:var(--red)"><?= $results['rejected'] ?></div></div>
    </div>
    <?php if (!empty($results['errors'])): ?>
    <details style="margin-bottom:12px"><summary style="cursor:pointer;color:var(--red);font-size:13px">View <?= count($results['errors']) ?> error(s)</summary>
      <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);margin-top:8px">
        <table><thead><tr><th>Row</th><th>SKU</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($results['errors'] as $e): ?>
        <tr><td><?= $e['row'] ?></td><td style="font-family:var(--mono)"><?= e($e['sku']) ?></td><td style="color:var(--red)"><?= e($e['reason']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </details>
    <?php endif; ?>
    <a href="?page=main" class="btn btn-secondary">Back to Data Management</a>
  </div></div>
<?php else: header('Location: ?page=main'); exit; endif; ?>

<?php elseif ($page === 'stock_preview'): $data = $_SESSION['stock_import'] ?? null; if ($data): ?>
  <div class="card"><div class="card-header"><span class="card-title">Stock Import Preview</span></div><div class="card-body">
  <?php
    $rows = $data['rows'];
    $valid = 0; $rejected = 0; $errors = []; $changes = [];
    foreach ($rows as $i => $row) {
        $r = $i + 2;
        $sku = trim($row['sku'] ?? '');
        $qty = intval($row['quantity'] ?? 0);
        if (!$sku) { $rejected++; $errors[] = ['row'=>$r,'sku'=>'','reason'=>'SKU required']; continue; }
        if ($qty <= 0) { $rejected++; $errors[] = ['row'=>$r,'sku'=>$sku,'reason'=>'Quantity must be > 0']; continue; }
        $stmt = $conn->prepare("SELECT id, name, stock FROM products WHERE sku=? AND is_active=1");
        $stmt->bind_param("s", $sku);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        if (!$p) { $rejected++; $errors[] = ['row'=>$r,'sku'=>$sku,'reason'=>'Product not found']; continue; }
        $changes[] = ['sku'=>$sku,'name'=>$p['name'],'old'=>$p['stock'],'added'=>$qty,'new'=>$p['stock']+$qty];
        $valid++;
    }
  ?>
    <div class="stats-grid" style="margin-bottom:16px">
      <div class="stat-card" style="border-color:rgba(58,255,138,.3)"><div class="stat-label">Valid Restocks</div><div class="stat-value" style="font-size:22px;color:var(--green)"><?= $valid ?></div></div>
      <div class="stat-card" style="border-color:rgba(255,74,74,.3)"><div class="stat-label">Rejected</div><div class="stat-value" style="font-size:22px;color:var(--red)"><?= $rejected ?></div></div>
    </div>
    <?php if (!empty($changes)): ?>
    <div style="margin-bottom:14px">
      <div style="font-size:11px;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Stock Changes</div>
      <div style="max-height:250px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)">
        <table><thead><tr><th>Product</th><th>SKU</th><th style="text-align:right">Current</th><th style="text-align:right">+ Adding</th><th style="text-align:right">&rarr; New</th></tr></thead><tbody>
        <?php foreach ($changes as $c): ?>
        <tr><td><?= e($c['name']) ?></td><td style="font-family:var(--mono)"><?= e($c['sku']) ?></td><td style="text-align:right"><?= $c['old'] ?></td><td style="text-align:right;color:var(--green)">+<?= $c['added'] ?></td><td style="text-align:right;font-weight:700"><?= $c['new'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div style="margin-bottom:14px">
      <strong style="color:var(--red)">Errors (<?= count($errors) ?>)</strong>
      <?php foreach ($errors as $e): ?>
      <div style="font-size:12px;margin-top:4px"><span style="color:var(--text2)">Row <?= $e['row'] ?></span> &mdash; <strong><?= e($e['sku']) ?></strong> &mdash; <span style="color:var(--red)"><?= e($e['reason']) ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($valid > 0): ?>
    <form method="POST" style="display:flex;gap:8px;align-items:center;margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="stock_import_confirm">
      <button type="submit" class="btn btn-primary" onclick="return confirm('Apply <?= $valid ?> stock change(s)?')">
        Confirm Restock (<?= $valid ?> products)
      </button>
      <a href="?page=main" class="btn btn-secondary">Cancel</a>
    </form>
    <?php else: ?>
    <p style="color:var(--text2);font-size:13px">No valid restocks to apply.</p>
    <a href="?page=main" class="btn btn-secondary">Back</a>
    <?php endif; ?>
  </div></div>
<?php else: header('Location: ?page=main'); exit; endif; ?>

<?php elseif ($page === 'stock_result'): $results = $_SESSION['stock_result'] ?? null; if ($results): unset($_SESSION['stock_result']); ?>
  <div class="card"><div class="card-header"><span class="card-title">Stock Import Complete</span></div><div class="card-body">
    <div class="stats-grid" style="margin-bottom:16px">
      <div class="stat-card" style="border-color:rgba(58,255,138,.3)"><div class="stat-label">Restocked</div><div class="stat-value" style="font-size:22px;color:var(--green)"><?= $results['restocked'] ?></div></div>
      <div class="stat-card" style="border-color:rgba(255,74,74,.3)"><div class="stat-label">Rejected</div><div class="stat-value" style="font-size:22px;color:var(--red)"><?= $results['rejected'] ?></div></div>
    </div>
    <?php if (!empty($results['changes'])): ?>
    <div style="margin-bottom:14px">
      <div style="font-size:11px;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Stock Changes</div>
      <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)">
        <table><thead><tr><th>Product</th><th>SKU</th><th style="text-align:right">Old Stock</th><th style="text-align:right">Added</th><th style="text-align:right">New Stock</th></tr></thead><tbody>
        <?php foreach ($results['changes'] as $c): ?>
        <tr><td><?= e($c['name']) ?></td><td style="font-family:var(--mono)"><?= e($c['sku']) ?></td><td style="text-align:right"><?= $c['old'] ?></td><td style="text-align:right;color:var(--green)">+<?= $c['added'] ?></td><td style="text-align:right;font-weight:700"><?= $c['new'] ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($results['errors'])): ?>
    <details style="margin-bottom:12px"><summary style="cursor:pointer;color:var(--red);font-size:13px">View <?= count($results['errors']) ?> error(s)</summary>
      <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);margin-top:8px">
        <table><thead><tr><th>Row</th><th>SKU</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($results['errors'] as $e): ?>
        <tr><td><?= $e['row'] ?></td><td style="font-family:var(--mono)"><?= e($e['sku']) ?></td><td style="color:var(--red)"><?= e($e['reason']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </details>
    <?php endif; ?>
    <a href="?page=main" class="btn btn-secondary">Back to Data Management</a>
  </div></div>
<?php else: header('Location: ?page=main'); exit; endif; ?>

<?php else: // MAIN PAGE ?>

  <!-- ── Database Overview ── -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Tables</div>
      <div class="stat-value"><?= $tableCount ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Database Size</div>
      <div class="stat-value"><?= number_format($totalSize, 2) ?> <span style="font-size:12px;color:var(--text2)">MB</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Products</div>
      <div class="stat-value"><?= $productCount ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Last Sale</div>
      <div class="stat-value" style="font-size:16px"><?= $lastSale ? date('M j, g:i a', strtotime($lastSale)) : 'Never' ?></div>
    </div>
  </div>

  <!-- ── Backup & Restore ── -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><span class="card-title">Full Backup &amp; Restore</span></div>
    <div class="card-body">
      <?php if ($isVercel): ?>
      <div style="background:rgba(255,140,74,.1);border:1px solid rgba(255,140,74,.3);border-radius:4px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:var(--orange)">
        Running on Vercel (serverless). Backup/restore works via pure PHP (no shell commands). File upload limit may be lower than on a traditional server. For large databases, use your database provider's dashboard instead.
      </div>
      <?php endif; ?>
      <p style="font-size:13px;color:var(--text2);margin-bottom:14px">Creates a complete database dump (<code>.sql.gz</code>) with all tables, data, and structure. Restore will replace the current database — a safety backup is created automatically.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="backup">
          <button type="submit" class="btn btn-primary">Download Full Backup</button>
        </form>
        <div style="border-left:1px solid var(--border);height:32px;margin:0 4px"></div>
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:6px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="restore">
          <label style="font-size:11px;color:var(--text2);text-transform:uppercase;letter-spacing:.05em">Restore from file</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="file" name="restore_file" accept=".sql,.gz,.gzip" class="form-control" style="width:auto;padding:5px 10px" required onchange="document.getElementById('restoreConfirm').style.display='block'">
            <div id="restoreConfirm" style="display:none;align-items:center;gap:6px">
              <span style="font-size:11px;color:var(--red);white-space:nowrap">Type RESTORE:</span>
              <input type="text" name="confirm_restore" placeholder="RESTORE" style="background:var(--bg3);border:1px solid var(--red);color:var(--red);padding:4px 8px;border-radius:2px;font-size:12px;width:110px">
              <button type="submit" class="btn btn-danger btn-sm">Confirm Restore</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── CSV Imports (side by side) ── -->
  <div class="dm-imports" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">

    <!-- Product Import -->
    <div class="card">
      <div class="card-header"><span class="card-title">Import Products</span></div>
      <div class="card-body">
        <p style="font-size:12px;color:var(--text2);margin-bottom:12px">Bulk-create products from CSV. SKU is required and must be unique. <code>initial_stock</code> only applies to <strong>new</strong> products.</p>
        <form method="POST" style="margin-bottom:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="product_csv_template">
          <button type="submit" class="btn btn-secondary btn-sm">Download Template</button>
        </form>
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="product_import">
          <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
            <div class="form-group">
              <label>Import Mode</label>
              <select name="import_mode" class="form-control" style="padding:6px 10px;font-size:12px">
                <option value="create_new">Skip existing SKUs</option>
              </select>
            </div>
            <div class="form-group">
              <label>CSV File</label>
              <input type="file" name="csv_file" accept=".csv,.txt" class="form-control" style="padding:5px 10px;font-size:12px" required>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Upload &amp; Preview</button>
        </form>
      </div>
    </div>

    <!-- Stock Import -->
    <div class="card">
      <div class="card-header"><span class="card-title">Restock (Stock Import)</span></div>
      <div class="card-body">
        <p style="font-size:12px;color:var(--text2);margin-bottom:12px">Add stock to <strong>existing</strong> products only. Unknown SKUs are rejected. Will never create new products.</p>
        <form method="POST" style="margin-bottom:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="stock_csv_template">
          <button type="submit" class="btn btn-secondary btn-sm">Download Template</button>
        </form>
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="stock_import">
          <div class="form-group" style="margin-bottom:10px">
            <label>CSV File</label>
            <input type="file" name="csv_file" accept=".csv,.txt" class="form-control" style="padding:5px 10px;font-size:12px" required>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Upload &amp; Preview</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── CSV Exports ── -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><span class="card-title">Export Data (CSV)</span></div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text2);margin-bottom:14px">Download business data as CSV. Date filters are optional — leave blank to export all records.</p>

      <!-- Quick exports (no date filter) -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="export_products"><button type="submit" class="btn btn-secondary btn-sm">Products</button></form>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="export_customers"><button type="submit" class="btn btn-secondary btn-sm">Customers</button></form>
        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="export_inventory"><button type="submit" class="btn btn-secondary btn-sm">Inventory Log</button></form>
      </div>

      <div style="border-top:1px solid var(--border);padding-top:14px">
        <div style="font-size:11px;color:var(--text2);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Date-Filtered Exports</div>
        <div class="dm-exports" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
          <!-- Sales -->
          <form method="POST" style="display:flex;flex-direction:column;gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export_sales">
            <div style="font-size:12px;font-weight:600;color:var(--text2)">Sales</div>
            <div style="display:flex;gap:4px;align-items:center">
              <input type="date" name="date_from" class="form-control" style="padding:4px 8px;font-size:11px" placeholder="From">
              <span style="color:var(--text3);font-size:11px">to</span>
              <input type="date" name="date_to" class="form-control" style="padding:4px 8px;font-size:11px" placeholder="To">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="align-self:flex-start">Export Sales</button>
          </form>
          <!-- Sale Items -->
          <form method="POST" style="display:flex;flex-direction:column;gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export_sale_items">
            <div style="font-size:12px;font-weight:600;color:var(--text2)">Sale Items</div>
            <div style="display:flex;gap:4px;align-items:center">
              <input type="date" name="date_from" class="form-control" style="padding:4px 8px;font-size:11px">
              <span style="color:var(--text3);font-size:11px">to</span>
              <input type="date" name="date_to" class="form-control" style="padding:4px 8px;font-size:11px">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="align-self:flex-start">Export Items</button>
          </form>
          <!-- Expenses -->
          <form method="POST" style="display:flex;flex-direction:column;gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="export_expenses">
            <div style="font-size:12px;font-weight:600;color:var(--text2)">Expenses</div>
            <div style="display:flex;gap:4px;align-items:center">
              <input type="date" name="date_from" class="form-control" style="padding:4px 8px;font-size:11px">
              <span style="color:var(--text3);font-size:11px">to</span>
              <input type="date" name="date_to" class="form-control" style="padding:4px 8px;font-size:11px">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="align-self:flex-start">Export Expenses</button>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>
<script>
document.querySelectorAll('input[name="restore_file"]').forEach(function(el) {
  el.addEventListener('change', function() {
    var confirm = document.getElementById('restoreConfirm');
    if (confirm) confirm.style.display = this.files.length > 0 ? 'flex' : 'none';
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
