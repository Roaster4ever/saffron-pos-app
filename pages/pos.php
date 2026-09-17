<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle  = 'Point of Sale';
$activePage = 'pos';

$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$products   = $conn->query("SELECT p.*, c.name cat_name, b.name brand_name, u.short_name unit_name, u.allows_decimal FROM products p LEFT JOIN categories c ON p.category_id=c.id LEFT JOIN brands b ON p.brand_id=b.id LEFT JOIN units u ON p.unit_id=u.id WHERE p.is_active=1 ORDER BY p.name")->fetch_all(MYSQLI_ASSOC);

// Fetch customers for dropdown
$customers = $conn->query("SELECT id, name, type, credit_limit FROM customers_v2 WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<!-- INVOICE MODAL -->
<div class="modal-overlay" id="invoiceModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <span class="modal-title">&#10003; Sale Complete &mdash; Invoice</span>
      <span class="modal-close" onclick="closeInvoice()">&times;</span>
    </div>
    <div class="modal-body" id="invoiceBody" style="padding:0;background:#fff"></div>
    <div class="modal-footer" style="gap:8px">
      <button class="btn btn-secondary" onclick="printInvoice()">Print</button>
      <button class="btn btn-primary" onclick="downloadPDF()">Download PDF</button>
      <button class="btn btn-secondary" onclick="closeInvoice()">New Sale</button>
    </div>
  </div>
</div>

<!-- MEASUREMENT PICKER MODAL -->
<div class="modal-overlay" id="measureModal">
  <div class="modal" style="max-width:360px">
    <div class="modal-header"><span class="modal-title" id="measureTitle">Select Quantity</span><span class="modal-close" onclick="closeModal('measureModal')">&times;</span></div>
    <div class="modal-body" id="measureBody"></div>
  </div>
</div>

<!-- POS LAYOUT -->
<div class="pos-layout">

  <!-- LEFT: Products -->
  <div class="pos-panel">
    <div class="pos-search-bar" style="display:flex;gap:8px;align-items:center">
      <input type="text" id="posSearch" class="pos-search" style="flex:1" placeholder="Search product, SKU, brand, barcode + Enter">
    </div>
    <div class="cat-tabs">
      <button class="cat-tab active" data-cat="all">All</button>
      <?php foreach ($categories as $cat): ?>
        <button class="cat-tab" data-cat="<?= $cat['id'] ?>"><?= e($cat['name']) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="products-grid" id="productsGrid">
      <?php foreach ($products as $p): ?>
        <div class="product-card <?= $p['stock'] <= 0 ? 'out-of-stock' : '' ?>"
             data-id="<?= $p['id'] ?>"
             data-name="<?= e($p['name']) ?>"
             data-price="<?= $p['price'] ?>"
             data-cost="<?= $p['cost'] ?>"
             data-minprice="<?= $p['min_price'] ?>"
             data-wholesale="<?= $p['wholesale_price'] ?>"
             data-contractor="<?= $p['contractor_price'] ?>"
             data-stock="<?= $p['stock'] ?>"
             data-reserved="<?= $p['reserved_stock'] ?? 0 ?>"
             data-cat="<?= $p['category_id'] ?>"
             data-barcode="<?= e($p['barcode'] ?? '') ?>"
             data-sku="<?= e($p['sku'] ?? '') ?>"
             data-model="<?= e($p['model'] ?? '') ?>"
             data-brand="<?= e($p['brand_name'] ?? '') ?>"
             data-taxable="<?= $p['taxable'] ?>"
             data-gstrate="<?= $p['gst_rate'] ?>"
             data-taxmode="<?= $p['tax_mode'] ?? 'default' ?>"
             data-unit="<?= e($p['unit_name'] ?? 'pc') ?>"
             data-sellingmode="<?= $p['selling_mode'] ?? 'fixed' ?>"
             data-stdlengths="<?= e($p['standard_lengths'] ?? '') ?>"
             data-defaultqty="<?= $p['default_qty'] ?? '' ?>"
             onclick="addToCart(this)">
          <div class="prod-name"><?= e($p['name']) ?></div>
          <div class="prod-brand"><?= e($p['brand_name'] ?? '') ?></div>
          <div class="prod-price"><?= money($p['price']) ?><?= ($p['selling_mode'] ?? 'fixed') === 'measured' ? ' / ' . e($p['unit_name'] ?? '') : '' ?></div>
          <div class="prod-stock <?= $p['stock'] <= 0 ? 'stock-out' : ($p['stock'] <= $p['low_stock_alert'] ? 'stock-low' : '') ?>">
            Stock: <?= $p['stock'] ?> <?= e($p['unit_name'] ?? 'pc') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RIGHT: Cart -->
  <div class="pos-panel">
    <!-- Sale Type / Customer Selection -->
    <div style="padding:10px 14px;border-bottom:1px solid var(--border)">
      <div style="display:flex;gap:6px;margin-bottom:8px">
        <button class="cat-tab active" id="saleTypeWalkin" onclick="setSaleType('walkin')">Walk-in</button>
        <button class="cat-tab" id="saleTypeCustomer" onclick="setSaleType('customer')">Customer</button>
      </div>
      <div id="customerSelect" style="display:none">
        <select id="customerId" class="form-control" style="font-size:12px;padding:6px" onchange="onCustomerChange()">
          <option value="">— Select Customer —</option>
          <?php foreach($customers as $c): ?>
            <option value="<?= $c['id'] ?>" data-name="<?= e($c['name']) ?>" data-creditlimit="<?= $c['credit_limit'] ?>" data-type="<?= e($c['type']) ?>"><?= e($c['name']) ?> (<?= ucfirst($c['type']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div id="customerInfo" style="display:none;font-size:11px;color:var(--text2);margin-top:4px"></div>
      </div>
      <!-- Price Level -->
      <div style="display:flex;gap:4px;margin-top:6px">
        <button class="cat-tab active" data-prl="price" onclick="setPriceLevel('price',this)">Retail</button>
        <button class="cat-tab" data-prl="wholesale_price" onclick="setPriceLevel('wholesale_price',this)">Wholesale</button>
        <button class="cat-tab" data-prl="contractor_price" onclick="setPriceLevel('contractor_price',this)">Contractor</button>
      </div>
    </div>

    <div class="cart-header">
      <span>Cart</span>
      <span id="cartCount" class="badge badge-blue">0</span>
    </div>
    <div class="cart-items" id="cartItems">
      <div class="empty-state"><div class="empty-icon">&#9723;</div>Cart is empty</div>
    </div>
    <div class="cart-footer">
      <div class="cart-totals">
        <div class="total-row"><span>Subtotal</span><span id="cartSubtotal"><?= CURRENCY ?>0.00</span></div>
        <div class="total-row">
          <span>Discount</span>
          <span><input type="number" id="discountInput" min="0" step="0.01" value="0"
            style="width:70px;background:var(--bg3);border:1px solid var(--border2);color:var(--text);padding:2px 6px;border-radius:2px;font-size:12px"
            oninput="this.value=Math.max(0,this.value||0);updateTotals()"></span>
        </div>
        <div class="total-row"><span>GST/Tax</span><span id="cartTax"><?= CURRENCY ?>0.00</span></div>
        <div class="total-row grand">
          <span>TOTAL</span>
          <span id="cartTotal" class="val"><?= CURRENCY ?>0.00</span>
        </div>
      </div>
      <div style="margin-bottom:8px">
        <select id="paymentMethod" class="form-control" style="font-size:12px;padding:6px" onchange="togglePayment()">
          <option value="cash">Cash</option>
          <option value="card">Card</option>
          <option value="mobile">Mobile</option>
          <option value="credit" id="optCredit" style="display:none">Credit</option>
          <option value="bank_transfer">Bank Transfer</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>
      <div id="cashSection" style="margin-bottom:10px;display:flex;gap:6px;align-items:center">
        <label style="font-size:11px;color:var(--text2);white-space:nowrap">Paid:</label>
        <input type="number" id="paidInput" min="0" step="0.01" value="0"
          class="form-control" style="font-size:12px;padding:6px" oninput="this.value=Math.max(0,this.value||0);updateChange()">
        <span style="font-size:11px;color:var(--text2);white-space:nowrap">
          Change: <strong id="changeAmt" class="text-accent"><?= CURRENCY ?>0.00</strong>
        </span>
      </div>
      <div class="cart-actions">
        <button class="btn-checkout" id="checkoutBtn" onclick="submitSale()">Checkout</button>
        <button class="btn-clear-cart" onclick="clearCart()" title="Clear cart">X</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= BASE_URL ?>/js/jspdf.umd.min.js" defer></script>
<script>
const TAX_RATE  = <?= TAX_RATE ?>;
const CURRENCY  = '<?= CURRENCY ?>';
const SHOP_NAME = '<?= addslashes(SHOP_NAME) ?>';
const CSRF_TOKEN = '<?= csrf_token() ?>';

let cart = {};
let lastSale = null;
let saleType = 'walkin';
let priceLevel = 'price';
let selectedCustomer = null;

/* ── helpers ── */
function fmt(n) { return CURRENCY + parseFloat(n).toFixed(2); }
function $id(id) { return document.getElementById(id); }

/* ── TOAST ── */
function showScanToast(msg, ok) {
  let t = $id('scanToast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'scanToast';
    t.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:10px 22px;border-radius:4px;font-size:13px;font-weight:600;z-index:9999;transition:opacity .4s;pointer-events:none;opacity:0';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.background = ok ? '#3aff8a' : '#ff4a4a';
  t.style.color = ok ? '#000' : '#fff';
  t.style.opacity = '1';
  clearTimeout(t._h);
  t._h = setTimeout(() => { t.style.opacity = '0'; }, 2000);
}

/* ── SALE TYPE ── */
function setSaleType(type) {
  saleType = type;
  document.getElementById('saleTypeWalkin').classList.toggle('active', type === 'walkin');
  document.getElementById('saleTypeCustomer').classList.toggle('active', type === 'customer');
  document.getElementById('customerSelect').style.display = type === 'customer' ? 'block' : 'none';
  if (type === 'walkin') {
    selectedCustomer = null;
    document.getElementById('customerId').value = '';
    document.getElementById('customerInfo').style.display = 'none';
    document.getElementById('optCredit').style.display = 'none';
    if (document.getElementById('paymentMethod').value === 'credit') {
      document.getElementById('paymentMethod').value = 'cash';
    }
    togglePayment();
  }
}

function onCustomerChange() {
  var sel = document.getElementById('customerId');
  var opt = sel.options[sel.selectedIndex];
  if (!sel.value) {
    selectedCustomer = null;
    document.getElementById('customerInfo').style.display = 'none';
    document.getElementById('optCredit').style.display = 'none';
    return;
  }
  selectedCustomer = {
    id: sel.value,
    name: opt.dataset.name,
    creditLimit: parseFloat(opt.dataset.creditlimit) || 0,
    type: opt.dataset.type
  };
  var info = document.getElementById('customerInfo');
  info.style.display = 'block';
  info.innerHTML = '<strong>' + selectedCustomer.name + '</strong> — ' + selectedCustomer.type;
  if (selectedCustomer.creditLimit > 0) {
    info.innerHTML += ' — Credit limit: ' + fmt(selectedCustomer.creditLimit);
  }
  document.getElementById('optCredit').style.display = 'inline';
}

function setPriceLevel(level, btn) {
  priceLevel = level;
  document.querySelectorAll('[data-prl]').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
  // Update cart prices
  Object.keys(cart).forEach(function(id) {
    var card = document.querySelector('.product-card[data-id="' + id + '"]');
    if (card) {
      var newPrice = parseFloat(card.dataset[level]) || parseFloat(card.dataset.price) || 0;
      if (newPrice > 0) cart[id].price = newPrice;
    }
  });
  renderCart();
}

/* ── CART ── */
var _pendingMeasuredCard = null;

function addToCart(el) {
  if (el.classList.contains('out-of-stock')) return;
  var sellingMode = el.dataset.sellingmode || 'fixed';

  if (sellingMode === 'measured') {
    // Open measurement picker for measured products
    _pendingMeasuredCard = el;
    openMeasurePicker(el);
    return;
  }

  // Fixed product — original behavior
  const id = el.dataset.id;
  const stock = parseFloat(el.dataset.stock) - parseFloat(el.dataset.reserved || 0);
  const unitPrice = parseFloat(el.dataset[priceLevel]) || parseFloat(el.dataset.price) || 0;

  if (cart[id]) {
    if (cart[id].qty >= stock) { showScanToast('Max stock reached!', false); return; }
    cart[id].qty++;
  } else {
    cart[id] = {
      id: parseInt(id),
      name: el.dataset.name,
      price: unitPrice,
      cost: parseFloat(el.dataset.cost) || 0,
      minPrice: parseFloat(el.dataset.minprice) || 0,
      qty: 1,
      stock: stock,
      unit: el.dataset.unit || 'pc',
      tax_mode: el.dataset.taxmode || 'default',
      gst_rate: parseFloat(el.dataset.gstrate) || 0,
      taxable: parseInt(el.dataset.taxable) || 0,
      selling_mode: 'fixed'
    };
  }
  renderCart();
}

function openMeasurePicker(el) {
  var name = el.dataset.name;
  var unit = el.dataset.unit || 'ft';
  var stdLengths = (el.dataset.stdlengths || '').split('|').filter(Boolean);
  var defaultQty = el.dataset.defaultqty || '';
  var price = parseFloat(el.dataset[priceLevel]) || parseFloat(el.dataset.price) || 0;

  document.getElementById('measureTitle').textContent = name;

  var html = '<div style="margin-bottom:12px">';
  html += '<div style="font-size:12px;color:var(--text2)">' + CURRENCY + fmt(price) + ' / ' + unit + '</div>';
  html += '</div>';

  if (stdLengths.length > 0) {
    html += '<div style="font-size:11px;font-weight:600;color:var(--text2);margin-bottom:6px">Common Measurements</div>';
    html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">';
    stdLengths.forEach(function(len) {
      html += '<button class="btn btn-secondary btn-sm" onclick="selectMeasure(' + len + ')" style="min-width:60px">' + len + ' ' + unit + '</button>';
    });
    html += '</div>';
  }

  html += '<div style="font-size:11px;font-weight:600;color:var(--text2);margin-bottom:6px">Custom Quantity</div>';
  html += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">';
  html += '<input type="number" id="measureQty" step="any" min="0.001" value="' + (defaultQty || '') + '" class="form-control" style="flex:1" placeholder="Enter quantity">';
  html += '<span style="font-size:13px;color:var(--text2);font-weight:600">' + unit + '</span>';
  html += '</div>';

  html += '<div style="display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid var(--border)">';
  html += '<div style="font-size:12px;color:var(--text2)">Total:</div>';
  html += '<div id="measureTotal" style="font-size:18px;font-weight:700;color:var(--accent)">' + CURRENCY + '0.00</div>';
  html += '</div>';

  html += '<button class="btn btn-primary" style="width:100%;margin-top:12px" onclick="confirmMeasureAdd()">Add to Cart</button>';

  document.getElementById('measureBody').innerHTML = html;

  // Update total on input
  var qtyInput = document.getElementById('measureQty');
  qtyInput.addEventListener('input', function() {
    var q = parseFloat(this.value) || 0;
    document.getElementById('measureTotal').textContent = CURRENCY + fmt(q * price);
  });
  if (defaultQty) {
    qtyInput.dispatchEvent(new Event('input'));
  }

  openModal('measureModal');
  qtyInput.focus();
}

function selectMeasure(qty) {
  document.getElementById('measureQty').value = qty;
  document.getElementById('measureQty').dispatchEvent(new Event('input'));
}

function confirmMeasureAdd() {
  var qty = parseFloat(document.getElementById('measureQty').value) || 0;
  if (qty <= 0) { showScanToast('Enter a valid quantity', false); return; }
  if (!_pendingMeasuredCard) return;

  var el = _pendingMeasuredCard;
  const id = el.dataset.id;
  const stock = parseFloat(el.dataset.stock) - parseFloat(el.dataset.reserved || 0);
  const unitPrice = parseFloat(el.dataset[priceLevel]) || parseFloat(el.dataset.price) || 0;

  if (cart[id]) {
    var newQty = cart[id].qty + qty;
    if (newQty > stock) { showScanToast('Max stock reached! Available: ' + stock + ' ' + el.dataset.unit, false); return; }
    cart[id].qty = parseFloat(newQty.toFixed(3));
  } else {
    if (qty > stock) { showScanToast('Max stock reached! Available: ' + stock + ' ' + el.dataset.unit, false); return; }
    cart[id] = {
      id: parseInt(id),
      name: el.dataset.name,
      price: unitPrice,
      cost: parseFloat(el.dataset.cost) || 0,
      minPrice: parseFloat(el.dataset.minprice) || 0,
      qty: parseFloat(qty.toFixed(3)),
      stock: stock,
      unit: el.dataset.unit || 'ft',
      tax_mode: el.dataset.taxmode || 'default',
      gst_rate: parseFloat(el.dataset.gstrate) || 0,
      taxable: parseInt(el.dataset.taxable) || 0,
      selling_mode: 'measured'
    };
  }

  closeModal('measureModal');
  _pendingMeasuredCard = null;
  showScanToast('Added: ' + el.dataset.name + ' (' + qty + ' ' + (el.dataset.unit || '') + ')', true);
  renderCart();
}

function renderCart() {
  const keys = Object.keys(cart);
  $id('cartCount').textContent = keys.reduce((s, k) => s + cart[k].qty, 0).toFixed(keys.some(k => cart[k].selling_mode === 'measured') ? 1 : 0);
  if (!keys.length) {
    $id('cartItems').innerHTML = '<div class="empty-state"><div class="empty-icon">&#9723;</div>Cart is empty</div>';
    updateTotals(); return;
  }
  $id('cartItems').innerHTML = keys.map(id => {
    const i = cart[id];
    var qtyDisplay = i.selling_mode === 'measured' ? i.qty.toFixed(2).replace(/\.?0+$/, '') : i.qty;
    var delta = i.selling_mode === 'measured' ? 0.5 : 1;
    return '<div class="cart-item">' +
      '<div style="flex:1"><div class="ci-name">' + i.name + '</div><div class="ci-price">' + fmt(i.price) + ' / ' + i.unit + '</div></div>' +
      '<div class="ci-qty">' +
        '<button class="qty-btn" onclick="changeQty(' + id + ',-' + delta + ')">-</button>' +
        '<span style="font-size:13px;min-width:20px;text-align:center">' + qtyDisplay + ' ' + i.unit + '</span>' +
        '<button class="qty-btn" onclick="changeQty(' + id + ',' + delta + ')">+</button>' +
      '</div>' +
      '<div class="ci-total">' + fmt(i.price * i.qty) + '</div>' +
      '<span class="ci-del" onclick="removeItem(' + id + ')">&times;</span>' +
    '</div>';
  }).join('');
  updateTotals();
}

function changeQty(id, delta) {
  if (!cart[id]) return;
  var newQty = parseFloat((cart[id].qty + delta).toFixed(3));
  if (newQty <= 0) { delete cart[id]; }
  else if (newQty > cart[id].stock) { cart[id].qty = cart[id].stock; showScanToast('Max stock!', false); }
  else { cart[id].qty = newQty; }
  renderCart();
}
function removeItem(id) { delete cart[id]; renderCart(); }
function clearCart() { cart = {}; renderCart(); $id('discountInput').value = 0; $id('paidInput').value = 0; updateChange(); }

function updateTotals() {
  const defaultRate = <?= getSetting('default_tax_rate', 18) ?>;
  function effRate(item) {
    if (item.tax_mode === 'non_taxable') return 0;
    if (item.tax_mode === 'custom') return item.gst_rate || 0;
    return defaultRate;
  }
  const sub = Object.values(cart).reduce((s, i) => s + i.price * i.qty, 0);
  const disc = Math.max(0, parseFloat($id('discountInput').value) || 0);
  const tax = Object.values(cart).reduce((s, i) => s + (i.price * i.qty * effRate(i) / 100), 0);
  const tot = Math.max(0, sub + tax - disc);
  $id('cartSubtotal').textContent = fmt(sub);
  var taxEl = $id('cartTax'); if (taxEl) taxEl.textContent = fmt(tax);
  $id('cartTotal').textContent = fmt(tot);
  updateChange();
}

function updateChange() {
  const total = parseFloat($id('cartTotal').textContent.replace(CURRENCY, '')) || 0;
  const paid = parseFloat($id('paidInput').value) || 0;
  $id('changeAmt').textContent = fmt(Math.max(0, paid - total));
}

function togglePayment() {
  var pm = $id('paymentMethod').value;
  $id('cashSection').style.display = (pm === 'cash' || pm === 'credit') ? 'flex' : 'none';
  if (pm === 'credit') {
    $id('paidInput').value = 0;
    updateChange();
  }
}

/* ── SEARCH ── */
$id('posSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.product-card').forEach(function(c) {
    var match = c.dataset.name.toLowerCase().includes(q) ||
                c.dataset.barcode.toLowerCase().includes(q) ||
                (c.dataset.sku||'').toLowerCase().includes(q) ||
                (c.dataset.model||'').toLowerCase().includes(q) ||
                (c.dataset.brand||'').toLowerCase().includes(q);
    c.style.display = match ? '' : 'none';
  });
});

document.querySelectorAll('.cat-tab:not([data-prl])').forEach(function(tab) {
  tab.addEventListener('click', function() {
    document.querySelectorAll('.cat-tab:not([data-prl])').forEach(function(t) { t.classList.remove('active'); });
    tab.classList.add('active');
    const cat = tab.dataset.cat;
    document.querySelectorAll('.product-card').forEach(function(c) {
      c.style.display = (cat === 'all' || c.dataset.cat == cat) ? '' : 'none';
    });
  });
});

/* Keyboard scanner */
var _buf = '', _lastKey = 0;
document.addEventListener('keydown', function(e) {
  var now = Date.now(), gap = now - _lastKey; _lastKey = now;
  if (gap > 300) _buf = '';
  if (e.key === 'Enter') {
    var code = _buf.trim(); _buf = '';
    if (code.length >= 3) handleScannedCode(code);
  } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
    if (gap < 50 || _buf.length === 0) _buf += e.key; else _buf = e.key;
  }
});

$id('posSearch').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    var code = this.value.trim();
    if (code.length >= 3) { handleScannedCode(code); this.value = ''; e.preventDefault(); }
  }
});

/* ── BARCODE MATCHING ── */
function handleScannedCode(code) {
  code = code.trim();
  if (!code) return;
  var cards = Array.from(document.querySelectorAll('.product-card'));
  var matches = cards.filter(function(c) {
    return c.dataset.barcode.trim().toLowerCase() === code.toLowerCase() ||
           (c.dataset.sku||'').toLowerCase() === code.toLowerCase();
  });

  if (matches.length === 0) {
    showScanToast('Not found: ' + code, false);
  } else if (matches.length === 1) {
    addMatchedProduct(matches[0]);
  } else {
    showBarcodePicker(matches, code);
  }
}

function addMatchedProduct(card) {
  if (card.classList.contains('out-of-stock')) {
    showScanToast('Out of stock: ' + card.dataset.name, false);
  } else if (card.dataset.sellingmode === 'measured') {
    // Measured product: open picker instead of adding 1
    _pendingMeasuredCard = card;
    openMeasurePicker(card);
  } else {
    addToCart(card);
    showScanToast('Added: ' + card.dataset.name, true);
    card.style.transition = 'transform .15s,border-color .2s';
    card.style.transform = 'scale(1.06)';
    card.style.borderColor = '#3aff8a';
    setTimeout(function() {
      card.style.transform = ''; card.style.borderColor = '';
    }, 500);
  }
}

function showBarcodePicker(cards, code) {
  var html = cards.map(function(card, i) {
    var inStock = !card.classList.contains('out-of-stock');
    return '<div onclick="' + (inStock ? 'pickProduct(' + i + ')' : '') + '" ' +
      'style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border:1px solid var(--border2);border-radius:4px;margin-bottom:8px;background:var(--bg3);cursor:' + (inStock ? 'pointer' : 'default') + ';opacity:' + (inStock ? '1' : '.4') + '">' +
      '<div><div style="font-weight:600;font-size:13px">' + card.dataset.name + '</div>' +
      '<div style="font-size:11px;color:var(--text2);margin-top:2px">Stock: ' + card.dataset.stock + '</div></div>' +
      '<div style="font-size:15px;font-weight:700;font-family:var(--mono);color:var(--accent)">' + fmt(parseFloat(card.dataset.price)) + '</div></div>';
  }).join('');
  document.getElementById('pickerList').innerHTML = html;
  document.getElementById('pickerBarcode').textContent = code;
  window._pickerCards = cards;
  document.getElementById('barcodePicker').classList.add('open');
}

function pickProduct(i) {
  document.getElementById('barcodePicker').classList.remove('open');
  if (window._pickerCards[i]) addMatchedProduct(window._pickerCards[i]);
}

function closePicker() { document.getElementById('barcodePicker').classList.remove('open'); }

/* ── AJAX CHECKOUT ── */
function submitSale() {
  if (submitSale._processing) return; // Double-submit guard
  if (!Object.keys(cart).length) { alert('Cart is empty!'); return; }

  var pm = $id('paymentMethod').value;
  var total = parseFloat($id('cartTotal').textContent.replace(CURRENCY, '')) || 0;
  var paid = parseFloat($id('paidInput').value) || 0;

  // Validate min price (client-side warning, server blocks)
  for (var id in cart) {
    if (cart[id].minPrice > 0 && cart[id].price < cart[id].minPrice) {
      if (!confirm('Price for "' + cart[id].name + '" (' + fmt(cart[id].price) + ') is below minimum (' + fmt(cart[id].minPrice) + '). Continue?')) {
        return;
      }
    }
  }

  if (pm === 'cash' && paid < total) { alert('Insufficient payment!'); return; }
  if (pm === 'credit' && !selectedCustomer) { alert('Please select a customer for credit sale!'); return; }

  submitSale._processing = true;
  var btn = $id('checkoutBtn');
  btn.textContent = 'Processing...'; btn.disabled = true;

  var fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('cart', JSON.stringify(Object.values(cart)));
  fd.append('payment_method', pm);
  fd.append('discount', $id('discountInput').value || '0');
  fd.append('paid', pm === 'credit' ? 0 : (pm === 'cash' ? paid : total));
  fd.append('customer_id', selectedCustomer ? selectedCustomer.id : '');

  fetch('<?= BASE_URL ?>/pages/checkout.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      submitSale._processing = false;
      btn.textContent = 'Checkout'; btn.disabled = false;
      if (data.success) { lastSale = data; clearCart(); showInvoice(data); }
      else alert('Error: ' + data.error);
    })
    .catch(function() { submitSale._processing = false; btn.textContent = 'Checkout'; btn.disabled = false; alert('Network error. Try again.'); });
}

/* ── INVOICE ── */
function showInvoice(s) {
  var c = s.currency;
  var rows = s.items.map(function(i) {
    var taxVal = parseFloat(i.tax_amount || 0);
    return '<tr>' +
      '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb">' + i.name + '</td>' +
      '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:center">' + i.qty + ' ' + (i.unit||'pc') + '</td>' +
      '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right">' + c + parseFloat(i.price).toFixed(2) + '</td>' +
      '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;color:#888">' + (taxVal > 0 ? c + taxVal.toFixed(2) : '—') + '</td>' +
      '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right">' + c + parseFloat(i.total).toFixed(2) + '</td>' +
    '</tr>';
  }).join('');

  $id('invoiceBody').innerHTML =
  '<div id="invPrint" style="font-family:\'IBM Plex Sans\',sans-serif;padding:24px;background:#fff;color:#111">' +
    '<div style="text-align:center;padding-bottom:14px;border-bottom:2px solid #111;margin-bottom:16px">' +
      '<div style="font-size:22px;font-weight:800">' + s.shop_name + '</div>' +
      '<div style="font-size:11px;color:#666;margin-top:3px">' + (s.shop_address || 'Sales Invoice') + '</div>' +
    '</div>' +
    '<div style="display:flex;justify-content:space-between;font-size:12px;color:#444;margin-bottom:14px">' +
      '<div style="line-height:1.8"><div><strong>Invoice #</strong> ' + s.invoice_no + '</div><div><strong>Date</strong> ' + s.date + '</div>' +
      (s.customer_name ? '<div><strong>Customer</strong> ' + s.customer_name + '</div>' : '') +
      '</div>' +
      '<div style="text-align:right;line-height:1.8"><div><strong>Payment</strong> ' + s.payment_method.charAt(0).toUpperCase() + s.payment_method.slice(1) + '</div>' +
      (s.outstanding > 0 ? '<div style="color:#c00"><strong>Outstanding</strong> ' + c + parseFloat(s.outstanding).toFixed(2) + '</div>' : '') +
      '</div></div>' +
    '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px">' +
      '<thead><tr style="background:#f3f4f6">' +
        '<th style="padding:7px 8px;text-align:left;border-bottom:2px solid #d1d5db;font-size:10px;text-transform:uppercase">Item</th>' +
        '<th style="padding:7px 8px;text-align:center;border-bottom:2px solid #d1d5db;font-size:10px;text-transform:uppercase">Qty</th>' +
        '<th style="padding:7px 8px;text-align:right;border-bottom:2px solid #d1d5db;font-size:10px;text-transform:uppercase">Price</th>' +
        '<th style="padding:7px 8px;text-align:right;border-bottom:2px solid #d1d5db;font-size:10px;text-transform:uppercase">Tax</th>' +
        '<th style="padding:7px 8px;text-align:right;border-bottom:2px solid #d1d5db;font-size:10px;text-transform:uppercase">Total</th>' +
      '</tr></thead><tbody>' + rows + '</tbody>' +
    '</table>' +
    '<div style="margin-left:auto;width:230px;font-size:13px">' +
      '<div style="display:flex;justify-content:space-between;padding:4px 0;color:#555"><span>Subtotal</span><span>' + c + parseFloat(s.subtotal).toFixed(2) + '</span></div>' +
      (s.discount > 0 ? '<div style="display:flex;justify-content:space-between;padding:4px 0;color:#c00"><span>Discount</span><span>-' + c + parseFloat(s.discount).toFixed(2) + '</span></div>' : '') +
      (s.tax > 0 ? '<div style="display:flex;justify-content:space-between;padding:4px 0;color:#555"><span>GST/Tax</span><span>' + c + parseFloat(s.tax).toFixed(2) + '</span></div>' : '') +
      '<div style="display:flex;justify-content:space-between;padding:9px 0;margin-top:4px;border-top:2px solid #111;font-size:16px;font-weight:800"><span>TOTAL</span><span>' + c + parseFloat(s.total).toFixed(2) + '</span></div>' +
      (s.payment_method !== 'credit' ?
        '<div style="display:flex;justify-content:space-between;padding:3px 0;color:#555;font-size:12px"><span>Paid</span><span>' + c + parseFloat(s.paid).toFixed(2) + '</span></div>' +
        '<div style="display:flex;justify-content:space-between;padding:3px 0;color:#555;font-size:12px"><span>Change</span><span>' + c + parseFloat(s.change).toFixed(2) + '</span></div>' : '') +
      (s.outstanding > 0 ? '<div style="display:flex;justify-content:space-between;padding:3px 0;color:#c00;font-size:12px;font-weight:600"><span>Outstanding</span><span>' + c + parseFloat(s.outstanding).toFixed(2) + '</span></div>' : '') +
    '</div>' +
    '<div style="text-align:center;margin-top:22px;padding-top:12px;border-top:1px dashed #ccc;font-size:11px;color:#999"><?= addslashes(INVOICE_FOOTER) ?></div>' +
  '</div>';

  $id('invoiceModal').classList.add('open');
}

function closeInvoice() { $id('invoiceModal').classList.remove('open'); }

function printInvoice() {
  var html = $id('invPrint').innerHTML;
  var w = window.open('', '_blank', 'width=620,height=750');
  w.document.write('<!DOCTYPE html><html><head><title>Invoice</title>' +
    '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">' +
    '<style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:\'IBM Plex Sans\',sans-serif;padding:24px}@media print{body{padding:0}}</style>' +
    '</head><body>' + html + '<script>window.onload=function(){window.print();window.close();};<\/script></body></html>');
  w.document.close();
}

function downloadPDF() {
  if (!lastSale) return;
  var s = lastSale, c = s.currency;
  var doc = new window.jspdf.jsPDF({ unit:'mm', format:'a5' });
  var W = doc.internal.pageSize.getWidth(), y = 16;
  doc.setFont('helvetica','bold'); doc.setFontSize(18); doc.setTextColor(0);
  doc.text(s.shop_name, W/2, y, {align:'center'}); y+=7;
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(100);
  doc.text('SALES INVOICE', W/2, y, {align:'center'}); y+=8;
  doc.setDrawColor(0); doc.setLineWidth(0.6); doc.line(10,y,W-10,y); y+=6;
  doc.setTextColor(60); doc.setFontSize(9);
  doc.text('Invoice: ' + s.invoice_no, 10, y);
  doc.text('Payment: ' + s.payment_method, W-10, y, {align:'right'}); y+=5;
  doc.text('Date: ' + s.date, 10, y);
  if (s.customer_name) doc.text('Customer: ' + s.customer_name, W-10, y, {align:'right'});
  y+=8;
  doc.setFillColor(243,244,246); doc.rect(10,y-4,W-20,7,'F');
  doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(80);
  doc.text('ITEM',12,y); doc.text('QTY',W-62,y,{align:'right'});
  doc.text('PRICE',W-42,y,{align:'right'}); doc.text('TAX',W-22,y,{align:'right'}); doc.text('TOTAL',W-10,y,{align:'right'});
  y+=2; doc.setDrawColor(180); doc.setLineWidth(0.2); doc.line(10,y,W-10,y); y+=5;
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(0);
  s.items.forEach(function(item) {
    var taxVal = parseFloat(item.tax_amount || 0);
    doc.text(String(item.name).substring(0,34),12,y);
    doc.text(String(item.qty)+' '+(item.unit||'pc'),W-62,y,{align:'right'});
    doc.text(c+parseFloat(item.price).toFixed(2),W-42,y,{align:'right'});
    doc.text(taxVal > 0 ? c+taxVal.toFixed(2) : '—',W-22,y,{align:'right'});
    doc.text(c+parseFloat(item.total).toFixed(2),W-10,y,{align:'right'});
    y+=6;
  });
  doc.setDrawColor(30); doc.setLineWidth(0.4); doc.line(10,y,W-10,y); y+=6;
  doc.setFontSize(9); doc.setTextColor(80);
  var tRow = function(l,v){ doc.text(l,W-42,y,{align:'right'}); doc.text(v,W-10,y,{align:'right'}); y+=5; };
  tRow('Subtotal:', c+parseFloat(s.subtotal).toFixed(2));
  if(s.discount>0) tRow('Discount:', '-'+c+parseFloat(s.discount).toFixed(2));
  if(s.tax>0) tRow('GST/Tax:', c+parseFloat(s.tax).toFixed(2));
  doc.setLineWidth(0.5); doc.line(W-60,y,W-10,y); y+=5;
  doc.setFont('helvetica','bold'); doc.setFontSize(12); doc.setTextColor(0);
  doc.text('TOTAL:', W-42,y,{align:'right'});
  doc.text(c+parseFloat(s.total).toFixed(2),W-10,y,{align:'right'}); y+=7;
  if(s.payment_method==='cash'){
    doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(80);
    tRow('Paid:', c+parseFloat(s.paid).toFixed(2));
    tRow('Change:', c+parseFloat(s.change).toFixed(2));
  }
  if(s.outstanding>0){
    doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(200,50,50);
    tRow('Outstanding:', c+parseFloat(s.outstanding).toFixed(2));
  }
  y+=4; doc.setDrawColor(180); doc.setLineWidth(0.2); doc.line(10,y,W-10,y); y+=6;
  doc.setFont('helvetica','italic'); doc.setFontSize(8); doc.setTextColor(140);
  doc.text('Thank you for your purchase! - '+s.shop_name, W/2, y, {align:'center'});
  doc.save(s.invoice_no+'.pdf');
}
</script>

<script>
/* ── Keyboard Shortcuts ── */
document.addEventListener('keydown', function(e) {
  // Don't trigger when typing in inputs
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

  switch(e.key) {
    case '/':
    case 'F2':
      e.preventDefault();
      var sb = document.getElementById('searchBox');
      if (sb) { sb.focus(); sb.select(); }
      break;
    case 'F5':
      e.preventDefault();
      clearCart();
      break;
    case 'F9':
      e.preventDefault();
      submitSale();
      break;
    case 'Escape':
      // Close any open modal
      document.querySelectorAll('.modal-overlay.open').forEach(function(m) { m.classList.remove('open'); });
      break;
  }
});
</script>
<div class="modal-overlay" id="barcodePicker">
  <div class="modal" style="max-width:400px">
    <div class="modal-header">
      <span class="modal-title">Multiple products &mdash; pick one</span>
      <span class="modal-close" onclick="closePicker()">&times;</span>
    </div>
    <div class="modal-body" style="padding:14px">
      <div style="font-size:12px;color:var(--text2);margin-bottom:12px">
        Barcode <strong id="pickerBarcode" style="color:var(--accent);font-family:var(--mono)"></strong>
        matches several products. Tap the correct one:
      </div>
      <div id="pickerList"></div>
    </div>
    <div class="modal-footer">
      <button onclick="closePicker()" class="btn btn-secondary btn-sm">Cancel</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
