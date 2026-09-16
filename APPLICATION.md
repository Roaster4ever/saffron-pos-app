# Saffron POS — Application Documentation

## Table of Contents

1. [Overview](#overview)
2. [Technology Stack](#technology-stack)
3. [Architecture](#architecture)
4. [File Structure](#file-structure)
5. [Database Schema](#database-schema)
6. [Features & Modules](#features--modules)
7. [Tax System](#tax-system)
8. [Security Model](#security-model)
9. [Helper Functions](#helper-functions)
10. [Environment & Configuration](#environment--configuration)

---

## Overview

**Saffron POS** is a full-featured Point of Sale and business management web application built for sanitary/plumbing/building-material stores in Pakistan. It handles the entire retail and wholesale cycle: product management with brands/units/specs, multi-price-level pricing, customer ledger (khata), quotation creation, delivery tracking, supplier purchase orders, expense tracking, inventory management, and comprehensive business reporting with P&L analysis.

It runs on two deployment targets:

- **Local** — LEMP stack (Nginx + MariaDB + PHP 8.5) at `localhost/pos`
- **Vercel** — Serverless PHP with Neon (Postgres) via the `vercel-php` runtime

---

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.5 |
| Database | MariaDB 10.x (local) / Neon Postgres (Vercel) |
| Web Server | Nginx + PHP-FPM (local) / Vercel Edge Network |
| Frontend | Vanilla HTML/CSS/JS, no framework |
| Fonts | IBM Plex Sans + IBM Plex Mono (Google Fonts or local) |
| PDF Generation | jsPDF (client-side, A5 format) |
| CSS Theme | Custom dark industrial theme (`css/style.css`) |
| Icons | Unicode characters (no icon library) |

---

## Architecture

### Request Flow

```
Browser → Nginx (port 80) → PHP-FPM → config.php → .env loaded → DB connection
                                                              → Settings loaded
                                                              → Session started
                                                           → auth.php (session check)
                                                           → Page PHP file
                                                           → header.php + page content + footer.php
                                                           → Response
```

### Core Includes

| File | Purpose |
|------|---------|
| `includes/config.php` | Bootstrap: loads .env, connects DB, starts session, loads settings, defines helpers |
| `includes/db.php` | PDO wrapper providing mysqli-compatible API (`Db`, `DbStmt`, `DbResult` classes) with SQL dialect adapter for MySQL/PostgreSQL |
| `includes/auth.php` | `requireLogin()`, `requireAdmin()`, `isAdmin()`, `trackSession()` |
| `includes/header.php` | HTML head, sidebar navigation, alert display |
| `includes/footer.php` | Closes HTML shell, loads `js/main.js` |

### Authentication Flow

1. `login.php` checks rate limiting (5 attempts per 5 minutes via `login_attempts` table)
2. Verifies username/password with `password_verify()` against bcrypt hash
3. On success: `session_regenerate_id(true)`, stores `user_id`, `user_name`, `user_role` in `$_SESSION`
4. Records sign-in in `user_sessions` table
5. `auth.php::requireLogin()` checks `$_SESSION['user_id']` exists and session hasn't expired (8h timeout)
6. `auth.php::requireAdmin()` additionally checks `$_SESSION['user_role'] === 'admin'`

### Role-Based Access

| Role | Access |
|------|--------|
| `admin` | Full access: all pages, reports, tax settings, data management, user management, session logs |
| `cashier` | Dashboard, POS, Sales, Quotations, Deliveries, Products, Brands, Categories, Customers, Suppliers, Purchase Orders, Receivables, Expenses |

---

## File Structure

```
pos/
├── index.php                  # Dashboard (home page after login)
├── login.php                  # Login page with rate limiting
├── logout.php                 # Session destroy + cookie cleanup
├── setup.php                  # First-run wizard (shop info, tax rate, admin password)
├── api/index.php              # Vercel single entry point router
├── config.php                 # (unused, config is in includes/config.php)
│
├── includes/
│   ├── config.php             # Bootstrap, DB connection, helpers, session, settings
│   ├── db.php                 # PDO wrapper with mysqli-compatible API
│   ├── auth.php               # Authentication & authorization functions
│   ├── header.php             # HTML head + sidebar navigation
│   └── footer.php             # HTML close + JS include
│
├── pages/
│   ├── pos.php                # Point of Sale terminal (748 lines)
│   ├── checkout.php           # AJAX checkout endpoint (JSON response)
│   ├── sales.php              # Sales history, refund processing (full + partial)
│   ├── quotations.php         # Quotation CRUD, status workflow, convert-to-sale
│   ├── deliveries.php         # Delivery tracking, status management
│   ├── products.php           # Product CRUD with specs, images, selling modes
│   ├── brands.php             # Brand management
│   ├── categories.php         # Category management
│   ├── customers.php          # Customer CRUD (v2 with types and credit limits)
│   ├── customer_ledger.php    # Individual customer khata/ledger with payment recording
│   ├── suppliers.php          # Supplier management
│   ├── orders.php             # Purchase order workflow (create → receive → stock update)
│   ├── receivables.php        # Outstanding balances dashboard with aging analysis
│   ├── expenses.php           # Expense tracking with categories
│   ├── reports.php            # Business reports (admin only)
│   ├── tax_bulk.php           # Bulk tax settings (admin only)
│   ├── data_management.php    # Backup/restore, CSV import/export (admin only)
│   ├── users.php              # User management (admin only)
│   └── sessions.php           # User session logs (admin only)
│
├── js/
│   ├── main.js                # Global UI interactions
│   └── jspdf.umd.min.js      # PDF generation library
│
├── css/
│   ├── style.css              # Complete dark industrial theme
│   └── fonts-local.css        # Local font fallback (for Bukhari Desktop mode)
│
├── schema.sql                 # MySQL database schema (32 tables + seed data)
├── schema_postgres.sql        # PostgreSQL schema for Neon/Vercel
├── schema_planetscale.sql     # PlanetScale-compatible schema (no foreign keys)
├── .env.example               # Environment variable template
├── .env                       # Local credentials (gitignored)
├── vercel.json                # Vercel deployment config
├── composer.json              # PHP extensions for Vercel detection
├── README.md                  # Repository documentation
└── APPLICATION.md             # This file
```

---

## Database Schema

32 tables organized into functional groups:

### Product & Inventory

| Table | Purpose |
|-------|---------|
| `categories` | Product categories (e.g., PPR Fittings, Faucets, Valves) |
| `brands` | Product brands (e.g., Master, Sonex, Argent) |
| `units` | Measurement units with `allows_decimal` flag (pc, ft, m, kg) |
| `products` | Core product catalog: SKU, barcode, model, multi-price, stock, tax settings, selling mode |
| `product_prices` | Per-product per-price-list pricing (extensible) |
| `price_lists` | Named price lists (Retail, Wholesale, Contractor) |
| `spec_definitions` | Category-level specification templates (e.g., "Material", "Size", "Color") |
| `product_specs` | Per-product specification values |
| `inventory_log` | Full movement history: stock_in, adjustment, initial, sale, refund, stock_out |

### Customers & Ledger

| Table | Purpose |
|-------|---------|
| `customers` | Legacy customer table (kept for backward compat) |
| `customers_v2` | Current customer model: type (individual/contractor/builder/architect/dealer/company), credit limit, balance, city, WhatsApp |
| `customer_ledger` | Khata entries: invoice, payment, credit, adjustment, refund, opening — with running `balance_after` |
| `customer_payments` | Payment records: amount, method, reference |

### Sales

| Table | Purpose |
|-------|---------|
| `sales` | Invoice header: customer, subtotal, discount, tax, total, paid, change, outstanding, payment method, status |
| `sale_items` | Line items: product, qty, unit, price, discount, total, tax_amount, gst_rate |
| `quotations` | Quotation header: status workflow (draft → sent → approved/rejected → converted) |
| `quotation_items` | Quotation line items |
| `stock_reservations` | Reserved stock for quotations/pending orders (active, fulfilled, cancelled) |

### Deliveries

| Table | Purpose |
|-------|---------|
| `deliveries` | Delivery records: linked to sale/quotation/customer/project, status (pending → partially_delivered → delivered) |
| `delivery_items` | Per-delivery item tracking: qty_ordered vs qty_delivered |

### Purchasing

| Table | Purpose |
|-------|---------|
| `suppliers` | Supplier directory: contact, email, payment terms, credit limit |
| `orders` | Purchase orders: supplier, status (pending → received → cancelled) |
| `order_items` | Purchase order line items: product, qty, cost |

### Expenses

| Table | Purpose |
|-------|---------|
| `expense_categories` | Expense categories (e.g., Rent, Utilities, Transport) |
| `expenses` | Expense records: title, amount, category, note, user |

### Projects

| Table | Purpose |
|-------|---------|
| `projects` | Customer projects: name, location, status (active, completed, cancelled) |

### System

| Table | Purpose |
|-------|---------|
| `users` | User accounts: name, username, bcrypt password, role (admin/cashier) |
| `settings` | Key-value store for all app configuration (shop info, tax rate, currency, etc.) |
| `app_sessions` | DB-backed sessions for Vercel/serverless (8h TTL) |
| `login_attempts` | Rate limiting: IP-based, 5 attempts per 5 minutes |
| `user_sessions` | Sign-in/sign-out tracking with duration |
| `audit_log` | Audit trail: user, action, entity type/id, JSON details, IP address |

---

## Features & Modules

### Dashboard (`index.php`)

Live dashboard with auto-refreshing statistics:

- **Today's Stats**: transaction count, revenue, tax collected, profit
- **Monthly Stats**: total transactions and revenue
- **Inventory**: total products, low stock count, out of stock count, total stock value at cost
- **Outstanding Receivables**: total amount customers owe
- **Top Selling Products**: last 30 days, ranked by quantity sold
- **Top Brands**: last 30 days, ranked by revenue
- **Recent Sales**: last 8 transactions with customer name
- **Revenue Chart**: last 7 days bar chart
- Auto-refreshes every 15 seconds via AJAX (`index.php?ajax=1`)

### Point of Sale (`pages/pos.php`)

Full-featured POS terminal with split-panel layout:

**Left Panel — Product Grid:**
- Product cards showing name, brand, price, stock level
- Category tab filtering (All, PPR Fittings, Faucets, etc.)
- Search by name, SKU, barcode, model, or brand
- Out-of-stock items visually dimmed
- Color-coded stock levels (green = healthy, orange = low, red = out)

**Right Panel — Cart:**
- **Sale Type Toggle**: Walk-in (no customer) or Customer (select from dropdown)
- **Price Level Switcher**: Retail / Wholesale / Contractor — updates all cart prices instantly
- **Cart Items**: quantity +/- buttons, unit display, line total, remove button
- **Measured Products**: opens a measurement picker modal with common preset lengths + custom input
- **Totals**: subtotal, discount input, tax/GST, grand total
- **Payment Methods**: Cash, Card, Mobile, Credit (requires customer), Bank Transfer, Cheque
- **Cash Section**: paid amount input, automatic change calculation
- **Checkout Button**: double-submit guard, processes via AJAX

**Barcode Scanner Support:**
- USB keyboard-mode barcode scanner support (buffered keystroke detection)
- Matches scanned code against product barcode and SKU
- Single match: auto-adds to cart
- Multiple matches: shows picker modal to select the correct product
- Toast notifications for success/failure

**Cart Features:**
- Measured product mode: decimal quantities (e.g., 2.5 ft), step by 0.5
- Fixed product mode: integer quantities, step by 1
- Stock validation: prevents adding more than available
- Minimum price enforcement: warns if price is below product's minimum
- Discount field: subtracted from total

**Invoice Modal (post-sale):**
- Clean printable invoice with shop name, address, invoice number, date
- Line items with qty, unit, price, tax, total
- Subtotal, discount, tax, total breakdown
- Payment info: method, paid amount, change, outstanding
- **Print** button: opens print-friendly window
- **Download PDF** button: generates A5 PDF via jsPDF
- **New Sale** button: closes modal, resets cart

### AJAX Checkout (`pages/checkout.php`)

Server-side checkout endpoint (returns JSON):
- CSRF token verification
- Server-side stock validation (prevents overselling)
- Server-side price floor enforcement (min_price check)
- Server-side tax resolution per product (tax_mode: default/non_taxable/custom)
- Credit sale validation (requires selected customer)
- Creates sale record + sale_items in a transaction
- Updates product stock
- For credit sales: updates customer balance via `customer_ledger`
- Returns complete invoice data for client-side rendering

### Sales History (`pages/sales.php`)

- Paginated list of all sales with invoice number, date, customer, total, payment method, status
- Date range filtering
- **Full Refund**: marks sale as refunded, restores all stock, updates customer ledger
- **Partial Refund**: select individual line items and quantities to return, partial stock restoration
- Refund entries logged in audit trail

### Quotations (`pages/quotations.php`)

Quotation lifecycle management:
- **Create**: select customer, project (optional), add items with prices, set notes/terms/validity
- **Status Workflow**: Draft → Sent → Approved/Rejected/Converted/Cancelled
- **Convert to Sale**: transforms approved quotation into a completed sale, deducts stock
- **Stock Reservations**: creating a quotation reserves stock (shown in POS as reserved)
- Quotation number auto-generated: `QUO-YYYYMM-NNNN`
- Server-side tax calculation for each line item
- Print and PDF download (same invoice format)

### Deliveries (`pages/deliveries.php`)

Delivery tracking linked to sales/quotations:
- **Create Delivery**: select sale or quotation, customer, project, delivery address, date, items
- **Status Management**: Pending → Partially Delivered → Delivered / Cancelled
- Per-item quantity tracking (ordered vs delivered)
- Delivery number auto-generated: `DEL-YYYYMMDD-NNNN`
- Linked to projects for multi-delivery order tracking

### Products (`pages/products.php`)

Comprehensive product management:
- **CRUD Operations**: add, edit, soft-delete (is_active flag)
- **Multi-Price Support**: retail price, wholesale price, contractor price, minimum price, cost price
- **Stock Management**: current stock, reserved stock, low stock alert threshold
- **Identification**: SKU (unique), barcode, model number
- **Tax Settings**: tax_mode (default/non_taxable/custom), per-product GST rate
- **Selling Modes**: `fixed` (integer qty, pc/set/box) or `measured` (decimal qty, ft/m/kg)
- **Measured Product Config**: standard lengths (pipe presets like 1, 2, 3, 4, 6 ft), default quantity
- **Category & Brand**: linked with foreign keys
- **Unit**: linked to units table (determines decimal support)
- **Specifications**: dynamic per-category spec fields (e.g., Material: Brass, Size: 1/2")
- **Search & Filter**: by name, SKU, category, brand

### Brands (`pages/brands.php`)

- CRUD for product brands
- Unique name constraint
- Active/inactive toggle

### Categories (`pages/categories.php`)

- CRUD for product categories
- Used for POS filtering and reporting

### Customers (`pages/customers.php`)

Customer management (v2 model):
- **Types**: individual, contractor, builder, architect, dealer, company
- **Fields**: name, phone, WhatsApp, email, address, city
- **Credit Management**: credit limit, current balance, opening balance
- **Notes**: free-text notes field
- **Soft Delete**: `is_active` flag
- Click-through to customer ledger (`customer_ledger.php?id=X`)

### Customer Ledger (`pages/customer_ledger.php`)

Full khata (account book) for each customer:
- **Transaction History**: every invoice, payment, credit entry, adjustment, refund
- **Running Balance**: `balance_after` on each entry
- **Record Payment**: amount, payment method (cash/card/mobile/bank_transfer/cheque), reference number, note
- **Balance Updates**: automatically adjusts customer balance on payment/invoice/refund
- Date range filtering

### Suppliers (`pages/suppliers.php`)

- CRUD for supplier directory
- Fields: name, contact, email, payment terms, credit limit
- Used in purchase order workflow

### Purchase Orders (`pages/orders.php`)

Full procurement workflow:
- **Create Order**: select supplier, add items (product, qty, cost), add notes
- Order number auto-generated: `ORD-YYYYMMDD-NNNN`
- **Receive Order**: marks order as received, increases product stock, logs inventory movement
- **Cancel Order**: cancels without stock changes
- **Status Tracking**: pending → received/cancelled
- Supplier and total cost tracking

### Receivables (`pages/receivables.php`)

Outstanding balances dashboard:
- **Customer List**: all customers with positive balance (they owe money)
- **Aging Analysis**: Current, 30 days, 60 days, 90+ days buckets
- **Total Outstanding**: sum of all receivables
- Sorted by balance (highest first)
- Click-through to individual customer ledger

### Expenses (`pages/expenses.php`)

Expense tracking:
- **Record Expense**: title, amount, category, note
- **Categories**: linked to `expense_categories` table
- **Delete**: admin only
- Date range filtering
- Audit logged on create/delete

### Reports (`pages/reports.php`) — Admin Only

Comprehensive business intelligence:

**Profit & Loss:**
- Revenue (gross sales)
- Cost of Goods Sold (COGS)
- Gross Profit (Revenue - Tax - COGS)
- Profit Margin %
- Tax Collected

**Sales Analysis:**
- Sales by Brand: revenue, quantity, profit, margin per brand
- Sales by Category: revenue, quantity per category
- Most Profitable Products: top 10 by profit with brand and quantity
- 30-Day Daily Trend: revenue and transaction count per day

**Credit & Inventory:**
- Credit Outstanding: total and invoice count
- Low Stock Alerts: products below threshold with brand
- Inventory Value: total units, cost value, retail value

**Date Range**: configurable from/to date filter (default: last 30 days)

### Tax Settings (`pages/tax_bulk.php`) — Admin Only

Bulk tax management for all products:
- **Preview**: shows all affected products before applying changes
- **Filter Options**: all products, by category, by brand, by individual product
- **Tax Modes**: set to default (use shop rate), non_taxable, or custom rate
- **Custom Rate**: per-product custom GST percentage
- **Bulk Apply**: updates all filtered products in one operation
- Audit logged with before/after values

### Data Management (`pages/data_management.php`) — Admin Only

**Database Backup & Restore:**
- **Full Backup**: generates SQL dump in pure PHP (no exec/shell needed), gzip compressed, auto-download
- **Restore**: upload .sql or .sql.gz file, type "RESTORE" to confirm, replaces all data
- Compatible with both MySQL and PostgreSQL schemas

**CSV Product Import:**
- Download template CSV with sample data
- Import mode: create new only, or update existing
- Preview step: shows validation results before confirming
- Auto-creates categories, brands, and units if they don't exist
- Validates: SKU required, name required, duplicate detection
- Batch insert with transaction rollback on failure

**CSV Stock Import:**
- Download stock template CSV
- Add stock to existing products by SKU
- Records supplier, cost, reference for each entry
- Updates product stock and logs inventory movement

**CSV Export (5 types):**
- Products: all product data with brand, category, unit, prices, stock, tax settings
- Sales: invoice-level data with customer, totals, payment, status, cashier
- Sale Items: line-item detail with product, qty, price, tax
- Inventory Log: full movement history with product, type, reason, cost
- Customers: all customer data with type, contact, credit info
- Expenses: title, amount, category, date, recorded by

**Database Stats:**
- Table count and sizes
- Total database size
- Product count
- Last sale timestamp

### User Management (`pages/users.php`) — Admin Only

- **Add User**: name, username, password (bcrypt hashed), role
- **Edit User**: name, role
- **Reset Password**: set new password for any user
- **Delete User**: removes user account
- Unique username constraint

### Session Logs (`pages/sessions.php`) — Admin Only

User activity monitoring:
- **Sign-in/Sign-out Tracking**: timestamp, duration, IP address
- **Filters**: date range, user, role, status (online/offline)
- **Online Indicator**: green dot for currently active sessions
- **Duration Calculation**: auto-calculated on sign-out (hours and minutes)
- Sorted by most recent first

---

## Tax System

The tax system is a three-tier architecture:

### Configuration Levels

1. **Shop Default Rate** (`settings.default_tax_rate`): global rate, default 18% GST. Set via Tax Settings page.

2. **Product Tax Mode** (`products.tax_mode`):
   - `default` — uses shop's default rate
   - `non_taxable` — no tax applied (rate = 0)
   - `custom` — uses product's own `gst_rate` field

3. **Tax Resolution** (`resolveTax()` in config.php):
   ```
   non_taxable → rate = 0
   custom      → rate = product.gst_rate
   default     → rate = settings.default_tax_rate
   ```

### Tax Calculation Flow

```
For each cart/quotation item:
  1. Resolve tax mode → effective rate
  2. line_subtotal = price × qty
  3. line_tax = line_subtotal × rate / 100  (rounded to 2 decimals)
  4. total_tax = sum of all line taxes
  grand_total = subtotal + total_tax - discount
```

Tax is calculated server-side at checkout and during quotation/sale creation. The POS client-side calculation mirrors this for live totals display.

---

## Security Model

### Authentication
- Bcrypt password hashing (`PASSWORD_DEFAULT`)
- Session-based authentication with `session_regenerate_id(true)` on login
- 8-hour session timeout with automatic redirect to login
- Cookie cleanup on logout

### Rate Limiting
- Login attempts tracked by IP address in `login_attempts` table
- 5 attempts per 5 minutes per IP
- Counter reset on successful login
- Works with both MySQL file-based and PostgreSQL DB sessions

### CSRF Protection
- Token generated per session (`random_bytes(32)`)
- Embedded in all forms via `csrf_field()` helper
- Verified on all POST requests via `csrf_verify()`
- Timing-safe comparison via `hash_equals()`

### Input Handling
- All user output escaped via `e()` function (`htmlspecialchars` with `ENT_QUOTES`)
- Prepared statements for all database queries (prevents SQL injection)
- JSON inputs validated before processing

### Server-Side Validation
- Stock availability verified at checkout (prevents overselling)
- Minimum price enforcement (prevents selling below floor)
- Credit sale requires customer selection
- Payment method validation against allowed list

### Security Headers
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`

### Access Control
- `requireLogin()` — redirects unauthenticated users to login
- `requireAdmin()` — additionally checks admin role
- Role checks in sidebar navigation (admin-only links hidden for cashiers)
- Nginx blocks direct access to config/auth PHP files, backup files, hidden files, temp/upload directories

### Error Handling
- Production-safe: no stack traces shown to users
- Exceptions logged to `/var/log/pos_errors.log` (local) or `php://stderr` (Vercel)
- Custom error handler catches all PHP errors/warnings
- AJAX endpoints return JSON error messages
- HTML pages show user-friendly error div

---

## Helper Functions

### `config.php` Helpers

| Function | Purpose |
|----------|---------|
| `loadDotEnv($path)` | Parses .env file into `putenv()` for local dev |
| `isVercel()` | Detects Vercel runtime environment |
| `e($str)` | HTML entity encoding (XSS prevention) |
| `money($n)` | Formats number as currency: `Rs: 1,234.56` |
| `moneyRaw($n)` | Formats number without currency prefix: `1,234.56` |
| `csrf_token()` | Generates/retrieves per-session CSRF token |
| `csrf_field()` | Returns hidden input HTML for CSRF token |
| `csrf_verify()` | Validates CSRF token on POST requests |
| `calculateTax($subtotal, $taxable, $gstRate)` | Calculates tax amount for a line item |
| `getCustomerBalance($conn, $id)` | Gets current customer balance from ledger |
| `updateCustomerBalance($conn, $id, $type, $amount, ...)` | Writes ledger entry and returns new balance |
| `logInventoryMovement($conn, $productId, $qty, $type, ...)` | Records stock movement in inventory_log |
| `auditLog($conn, $action, $entityType, $entityId, $details, $ip)` | Writes to audit_log with JSON details |
| `getSetting($key, $default)` | Reads from cached settings array |
| `setSetting($conn, $key, $value)` | Writes to settings table and updates cache |
| `availableStock($product)` | Returns stock minus reserved_stock |
| `getBrandName($conn, $brandId)` | Cached brand name lookup |
| `getUnitShort($conn, $unitId)` | Cached unit short_name lookup |
| `resolveTax($product)` | Resolves effective tax rate from product tax_mode |
| `resolveTaxById($conn, $productId)` | Same but fetches product first |
| `getEffectiveTaxRate($conn, $productId)` | Returns numeric effective tax rate |
| `generateInvoice($conn)` | Generates next invoice number: `INV-YYYYMMDD-NNNNN` |
| `generateQuotationNo($conn)` | Generates next quotation number: `QUO-YYYYMM-NNNN` |
| `generateOrderNo($conn)` | Generates next order number: `ORD-YYYYMMDD-NNNN` |
| `generateDeliveryNo($conn)` | Generates next delivery number: `DEL-YYYYMMDD-NNNN` |

### CSV Helpers

| Function | Purpose |
|----------|---------|
| `parseCsvFile($handle, $maxRows)` | Parses CSV with BOM detection, header normalization, row limit |
| `generateCsv($headers, $rows)` | Generates UTF-8 BOM CSV content |
| `sendCsvDownload($filename, $csvContent)` | Sends CSV as browser download |
| `csvEscape($value)` | Prefixes dangerous characters (=, +, -, @, tabs, newlines) to prevent CSV injection |

### Backup Helpers

| Function | Purpose |
|----------|---------|
| `generateSqlDump($conn)` | Pure-PHP SQL dump generator (MySQL + PostgreSQL) |
| `executeSqlRestore($conn, $sqlContent)` | Executes SQL statements with error collection |

### `auth.php` Helpers

| Function | Purpose |
|----------|---------|
| `requireLogin()` | Enforces authentication, redirects if not logged in or session expired |
| `requireAdmin()` | Enforces admin role |
| `isAdmin()` | Returns true if current user is admin |
| `canOverridePrice()` | Returns true if user can override prices (admin only) |
| `trackSession($conn)` | Records sign-in, updates previous session sign-out with duration |

### `db.php` Classes

| Class | Purpose |
|-------|---------|
| `Db` | Extends PDO with mysqli-compatible API: `query()`, `prepare()`, `real_escape_string()`, `insert_id`, `error`, `num_rows`, `fetch_assoc()`, `fetch_all(MYSQLI_ASSOC)`, `begin_transaction()`, `commit()`, `rollback()`, `is_pgsql()` |
| `DbStmt` | Prepared statement wrapper: `bind_param()`, `execute()`, `get_result()` |
| `DbResult` | Result set wrapper: `fetch_assoc()`, `fetch_all()`, `fetch_row()`, `num_rows` |

The DB layer auto-detects MySQL vs PostgreSQL and translates SQL dialect at runtime:
- `REPLACE INTO` → `INSERT ... ON CONFLICT DO UPDATE` (PostgreSQL)
- `DATE_FORMAT()` → `TO_CHAR()` (PostgreSQL)
- `GROUP_CONCAT()` → `STRING_AGG()` (PostgreSQL)
- `TIMESTAMPDIFF()` → `EXTRACT()` (PostgreSQL)
- `SHOW TABLES` → `information_schema` query (PostgreSQL)
- Backtick quoting → double-quote quoting (PostgreSQL)
- `LIMIT offset, count` → `LIMIT count OFFSET offset` (PostgreSQL)

---

## Environment & Configuration

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `POS_DB_HOST` | Yes | `localhost` | Database host |
| `POS_DB_PORT` | No | `3306` (MySQL) / `5432` (PG) | Database port |
| `POS_DB_USER` | Yes | — | Database username |
| `POS_DB_PASS` | Yes | — | Database password |
| `POS_DB_NAME` | Yes | `pos_db` (MySQL) / `saffron` (PG) | Database name |
| `POS_BASE_URL` | No | `/pos` (local) / `` (Vercel) | URL path prefix |
| `POS_DB_SESSIONS` | No | — | Set to `1` for DB-backed sessions on non-Vercel |
| `POSTGRES_URL` | Vercel only | — | Neon/Postgres connection string (auto-injected by Vercel Marketplace) |
| `BUKHARI_DESKTOP` | No | — | Set to `1` for local fonts instead of Google Fonts |

### Settings Table

All shop configuration is stored in the `settings` database table and cached in `$SETTINGS` on every page load:

| Key | Default | Description |
|-----|---------|-------------|
| `shop_name` | Saffron Sanitary | Shop name on invoices/reports |
| `shop_address` | Main Market, Lahore | Shop address |
| `shop_phone` | 0321-1234567 | Contact number |
| `shop_whatsapp` | 0321-1234567 | WhatsApp number |
| `shop_email` | info@saffronpos.com | Email |
| `currency` | Rs: | Currency prefix |
| `default_tax_rate` | 18 | Default GST percentage |
| `invoice_footer` | Thank you for your business! | Invoice footer text |
| `quotation_footer` | This quotation is valid for 15 days. | Quotation footer text |
| `low_stock_default` | 5 | Default low stock alert threshold |
| `quotation_validity_days` | 15 | Default validity period |
| `app_initialized` | 0 | First-run wizard completion flag |

### Deployment

**Local (LEMP):**
- Nginx config: `root /mnt/sda2/POS;` with PHP-FPM at unix socket
- `GET /` redirects to `/pos/`
- Static files served by Nginx with 7-day cache
- PHP files routed through `try_files` fallback to `/pos/index.php`

**Vercel:**
- Single entry point: `api/index.php` routes all requests to appropriate PHP files
- `vercel.json`: `vercel-php@0.9.0` runtime, `handle: filesystem` for static files
- DB sessions via `app_sessions` table (8h TTL)
- PostgreSQL via Neon `POSTGRES_URL` connection string
