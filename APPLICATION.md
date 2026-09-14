# Saffron POS — Complete Application Documentation

## Table of Contents

1. [Overview](#1-overview)
2. [Technology Stack](#2-technology-stack)
3. [System Architecture](#3-system-architecture)
4. [File Structure](#4-file-structure)
5. [Code Architecture — How Everything Connects](#5-code-architecture--how-everything-connects)
6. [Database Schema (30 Tables)](#6-database-schema-30-tables)
7. [Tax System](#7-tax-system)
8. [Measured Products (Variable-Quantity)](#8-measured-products-variable-quantity)
9. [Authentication & Authorization](#9-authentication--authorization)
10. [Feature Modules — Page by Page](#10-feature-modules--page-by-page)
11. [Frontend Architecture](#11-frontend-architecture)
12. [Security Model](#12-security-model)
13. [Helper Functions Reference](#13-helper-functions-reference)
14. [Deployment & Server Configuration](#14-deployment--server-configuration)

---

## 1. Overview

**Saffron POS** is a full-featured Point of Sale and business management web application built for sanitary/plumbing/building-material stores in Pakistan. It handles the entire retail and wholesale cycle: product management with brands/units/specs, multi-price-level pricing, customer ledger (khata), quotation creation, delivery tracking, supplier purchase orders, expense tracking, inventory management, and comprehensive business reporting with P&L analysis.

**Key capabilities:**

- Real-time POS terminal with barcode scanner support (USB keyboard mode)
- Two product selling modes: **Fixed** (pc, set, box) and **Measured** (ft, m, kg — decimal quantities)
- Centralized tax system with per-product overrides (default / non_taxable / custom)
- Multi-price-level pricing: Retail, Wholesale, Contractor
- Customer selection with credit sales and outstanding tracking
- Invoice generation with print and PDF download (jsPDF)
- Quotation creation with status workflow and convert-to-sale
- Customer ledger (khata) with payment recording and balance tracking
- Receivables dashboard with aging analysis (30/60/90+ days)
- Delivery tracking with status management
- Product management with brands, units, SKUs, specifications
- Purchase order workflow (create → receive → stock update)
- Inventory management with stock-in, adjustments, movement log
- Expense tracking with categories and date filtering
- Multi-user role system (Admin / Cashier)
- Live dashboard with auto-refreshing stats
- Comprehensive reports: P&L, by brand, by category, aging, inventory value
- Data Management: CSV import/export, database backup/restore
- First-run setup wizard for initial configuration
- Audit log for all critical operations
- Rate limiting on login (5 attempts per 5 minutes)
- Security headers (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy)
- Production error handling (no stack traces shown to users)
- Dedicated database user with least-privilege access

**Currency:** Pakistani Rupee (Rs: )
**Setup:** First-run wizard creates admin account and shop details

---

## 2. Technology Stack

| Layer | Technology | Details |
|-------|-----------|---------|
| **Server** | Nginx 1.30 | Reverse proxy + static file serving, security headers, `server_tokens off` |
| **Runtime** | PHP 8.5 + PHP-FPM | FastCGI via Unix socket (`/run/php-fpm/php-fpm.sock`) |
| **Database** | MariaDB 12.3 | MySQL-compatible, InnoDB engine, 30 tables, utf8mb4 charset |
| **Frontend** | Vanilla HTML/CSS/JS | No framework, no build step, no npm |
| **Fonts** | IBM Plex Sans + Mono | Loaded from Google Fonts CDN (non-blocking) |
| **PDF (Client)** | jsPDF 2.5.1 | Loaded locally from `js/jspdf.umd.min.js` (no CDN dependency) |
| **Design System** | Custom CSS variables | Dark industrial theme in `css/style.css` |

**No Composer, no npm, no build tools, no frontend framework.** Zero-dependency PHP application on any LEMP stack.

### Production Database User

The application uses a dedicated `saffron_app` user with only `SELECT, INSERT, UPDATE, DELETE` privileges — no DDL, no DROP, no ALTER. This prevents application bugs from modifying schema. Root is never used by the application.

---

## 3. System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     BROWSER (Client)                         │
│  ┌───────────┐  ┌───────────┐  ┌────────────────────────┐  │
│  │  POS Page  │  │  Reports  │  │  jsPDF (PDF gen)       │  │
│  │ (JS cart,  │  │ (CSV exp) │  │  Client-side only      │  │
│  │  measured  │  │           │  │                        │  │
│  │  picker)   │  │           │  │                        │  │
│  └─────┬──────┘  └─────┬─────┘  └────────────────────────┘  │
│        │ AJAX          │ AJAX/Download                        │
└────────┼───────────────┼─────────────────────────────────────┘
         │               │
┌────────▼───────────────▼─────────────────────────────────────┐
│                   NGINX (Port 80)                              │
│  • Static files: CSS/JS/ICO → served directly (7d cache)     │
│  • Security headers on every response                         │
│  • PHP files → fastcgi_pass to PHP-FPM                        │
│  • Root: /mnt/sda2/POS (serves /pos/* paths)                  │
│  • Blocks: hidden files, .sql/.gz backups, config.php direct  │
└────────┬──────────────────────────────────────────────────────┘
         │ Unix socket
┌────────▼──────────────────────────────────────────────────────┐
│                 PHP-FPM (8.5)                                   │
│  • Session: PHPSESSID cookie (8h timeout, httponly, samesite) │
│  • DB: mysqli extension → MariaDB (dedicated user)            │
│  • Custom exception/error handlers (hide traces in prod)      │
│  • All business logic: auth, tax resolution, sales,           │
│    inventory, ledger, audit, stock validation                 │
└────────┬──────────────────────────────────────────────────────┘
         │ TCP (localhost)
┌────────▼──────────────────────────────────────────────────────┐
│               MariaDB (localhost)                               │
│  Database: pos_db                                              │
│  Tables: 30 (InnoDB, utf8mb4)                                  │
│  User: saffron_app (SELECT/INSERT/UPDATE/DELETE only)          │
└───────────────────────────────────────────────────────────────┘
```

### Request Flow — POS Checkout Example

1. User navigates to `http://localhost/pos/login.php`
2. Nginx maps `/pos/` → `/mnt/sda2/POS/pos/login.php`
3. PHP-FPM processes the file, creates session, serves HTML
4. On login POST: validates credentials against `users` table, starts session, redirects to dashboard
5. POS page loads all products into HTML with `data-*` attributes (including `tax_mode`, `gst_rate`, `selling_mode`, `standard_lengths`)
6. Cart operations happen entirely in JavaScript (no server round-trips until checkout)
7. For **measured products**: clicking opens a measurement picker modal (predefined lengths + custom input)
8. Cart displays quantity with unit label (e.g., "7.5 ft") and decimal +/- buttons
9. Checkout sends AJAX POST to `checkout.php` with cart JSON
10. `checkout.php` uses a MySQL transaction:
    - Validates stock availability for each item
    - Resolves tax from DB (server-authoritative)
    - Creates `sales` record
    - Creates `sale_items` with **server-calculated** tax amounts and decimal quantities
    - Decrements stock (using DECIMAL arithmetic)
    - Logs inventory movement
    - Creates audit log entry
    - For credit sales: updates customer balance and ledger
11. Returns JSON with full sale details (invoice_no, totals, change amount)
12. Invoice modal renders in HTML; PDF is generated client-side via jsPDF

### Request Flow — First-Run Setup

1. User visits `/pos/` for the first time
2. `config.php` checks `app_initialized` setting — if not set, redirects to `setup.php`
3. `setup.php` shows a form: shop name, address, phone, admin username, password
4. On POST: creates admin user, updates shop settings, sets `app_initialized = 1`
5. Redirects to dashboard — no default credentials remain

---

## 4. File Structure

```
pos/                                    (root: /mnt/sda2/POS/pos/)
├── includes/
│   ├── config.php          (360 lines) DB connection, settings loader, ALL helpers
│   │                                 (money, invoice, ledger, audit, tax, CSV)
│   ├── auth.php            (59 lines)  Session management (8h timeout),
│   │                                 requireLogin(), isAdmin(), trackSession()
│   ├── header.php          (106 lines) HTML <head>, sidebar navigation (18 pages),
│   │                                 CSRF token injection, active page highlighting
│   └── footer.php          (5 lines)   Closing HTML tags
│
├── pages/
│   ├── pos.php             (748 lines) POS terminal: product grid, cart, measurement
│   │                                 picker modal, payment, barcode scanning
│   ├── checkout.php        (203 lines) AJAX endpoint: transactional sale with tax
│   │                                 resolution, stock validation, credit handling
│   ├── products.php        (513 lines) Product CRUD: selling mode (fixed/measured),
│   │                                 standard lengths, stock-in, adjust_stock
│   ├── sales.php           (425 lines) Sales history: refund (full/partial), inventory
│   │                                 restoration, ledger reversal
│   ├── sale_detail.php     (52 lines)  Sale receipt detail (AJAX modal)
│   ├── brands.php          (141 lines) Brand CRUD with product count
│   ├── categories.php      (85 lines)  Category CRUD with product count
│   ├── customers.php       (248 lines) Customer CRUD: types, credit limits, balance
│   ├── customer_ledger.php (204 lines) Customer ledger (khata): payments, adjustments
│   ├── receivables.php     (113 lines) Outstanding dashboard with aging analysis
│   ├── quotations.php      (428 lines) Quotation CRUD, status workflow, convert-to-sale
│   ├── quotation_detail.php(37 lines)  Quotation detail (AJAX modal)
│   ├── deliveries.php      (159 lines) Delivery tracking with status management
│   ├── expenses.php        (169 lines) Expense tracking with categories, date filtering
│   ├── suppliers.php       (135 lines) Supplier CRUD
│   ├── orders.php          (283 lines) Purchase order management (create → receive)
│   ├── order_detail.php    (59 lines)  Order detail (AJAX modal)
│   ├── tax_bulk.php        (322 lines) Bulk tax management (admin only)
│   ├── users.php           (113 lines) User management (admin only)
│   ├── sessions.php        (191 lines) Login session tracking (admin only)
│   ├── data_management.php (868 lines) Backup/restore, CSV import/export (admin only)
│   └── reports.php         (165 lines) Business reports: P&L, brands, categories,
│                                        inventory value, low stock alerts
│
├── css/
│   └── style.css           (292 lines) Dark industrial theme, POS layout, responsive,
│                                        modal system, badges, tables, forms
│
├── js/
│   ├── main.js             (24 lines)  Modal helpers (openModal, closeModal),
│   │                                 confirmDelete dialog
│   ├── pie-chart.js        (72 lines)  SVG donut pie chart renderer
│   └── jspdf.umd.min.js   (398 lines) jsPDF library (local copy, non-blocking)
│
├── index.php               (263 lines) Dashboard: revenue, profit, stock alerts,
│                                        top products/brands, 7-day chart, recent sales
│
├── login.php               (122 lines) Login with rate limiting (5 attempts/5min),
│                                        CSRF, session regeneration, setup redirect
│
├── logout.php              (12 lines)  Session cleanup + redirect
│
├── setup.php               (149 lines) First-run setup wizard: shop details + admin
│                                        account creation
│
├── database.sql            (175 lines) Original base schema
├── migration_phase1.sql    (446 lines) Extended schema: new tables, altered columns,
│                                        indexes, measured product support
├── migrate.sql             (87 lines)  Production migration patches
├── seed_sanitary.sql       (354 lines) 68 products, 15 brands, 20 categories,
│                                        15 units, settings, expense categories
├── suppliers_migrate.sql   (41 lines)  Supplier table migration
│
├── APPLICATION.md          This file
├── DEPLOYMENT.md           (236 lines) Production deployment guide
└── favicon.ico             (4,286 bytes)
```

### File Sizes and Line Counts

| File | Lines | Bytes | Purpose |
|------|-------|-------|---------|
| `includes/config.php` | 360 | 14,165 | Core configuration + all helper functions |
| `includes/auth.php` | 59 | 2,000 | Session management + authorization |
| `includes/header.php` | 106 | 5,508 | HTML head + sidebar + CSRF |
| `includes/footer.php` | 5 | 73 | Closing HTML |
| `pages/pos.php` | 748 | 35,846 | POS terminal (largest page) |
| `pages/data_management.php` | 868 | 50,526 | Data management (backup/import/export) |
| `pages/products.php` | 513 | 28,772 | Product management |
| `pages/quotations.php` | 428 | 21,624 | Quotation management |
| `pages/sales.php` | 425 | 20,772 | Sales history + refunds |
| `pages/checkout.php` | 203 | 7,780 | AJAX checkout endpoint |
| `pages/orders.php` | 283 | 13,479 | Purchase orders |
| `pages/customers.php` | 248 | 12,799 | Customer management |
| `pages/tax_bulk.php` | 322 | 14,515 | Bulk tax management |
| `pages/reports.php` | 165 | 8,875 | Business reports |
| `pages/customer_ledger.php` | 204 | 10,019 | Customer ledger (khata) |
| `pages/deliveries.php` | 159 | 6,991 | Delivery tracking |
| `pages/expenses.php` | 169 | 7,365 | Expense tracking |
| `pages/sessions.php` | 191 | 8,141 | Session tracking |
| `pages/suppliers.php` | 135 | 6,871 | Supplier management |
| `pages/users.php` | 113 | 5,458 | User management |
| `pages/brands.php` | 141 | 6,569 | Brand management |
| `pages/categories.php` | 85 | 4,256 | Category management |
| `pages/receivables.php` | 113 | 5,935 | Receivables dashboard |
| `pages/sale_detail.php` | 52 | 3,438 | Sale detail modal |
| `pages/quotation_detail.php` | 37 | 1,764 | Quotation detail modal |
| `pages/order_detail.php` | 59 | 3,221 | Order detail modal |
| `index.php` | 263 | 11,808 | Dashboard |
| `login.php` | 122 | 4,881 | Login page |
| `setup.php` | 149 | 6,980 | First-run setup wizard |
| `logout.php` | 12 | 506 | Session cleanup |
| `css/style.css` | 292 | 18,198 | Complete stylesheet |
| `js/main.js` | 24 | 729 | Modal helpers |
| `js/pie-chart.js` | 72 | 3,037 | SVG pie chart |

---

## 5. Code Architecture — How Everything Connects

### 5.1 Bootstrap Chain

Every page in the application follows the same bootstrap pattern:

```
Page File (e.g., products.php)
    │
    ├── include 'includes/config.php'
    │       ├── Defines DB_HOST, DB_USER, DB_PASS, DB_NAME
    │       ├── Sets error_reporting, display_errors=Off, log_errors=On
    │       ├── Sets custom exception_handler (hides traces from users)
    │       ├── Sets custom error_handler (logs but doesn't display)
    │       ├── Creates $conn = new mysqli(...)
    │       ├── Calls loadSettings($conn) → populates $SETTINGS global
    │       └── Defines ALL helper functions (see Section 13)
    │
    ├── include 'includes/auth.php'
    │       ├── Configures session security (httponly, samesite=Lax, strict_mode)
    │       ├── Starts session if not already started
    │       └── Defines: requireLogin(), requireAdmin(), isAdmin(), trackSession()
    │
    ├── requireLogin() or requireAdmin()
    │       └── Redirects to login.php if not authenticated or session expired
    │
    ├── include 'includes/header.php'
    │       ├── Outputs HTML <head> with CSS, fonts, meta tags
    │       ├── Outputs sidebar navigation (active page highlighted)
    │       └── Outputs main content opening tags
    │
    ├── [Page Content — PHP logic + HTML]
    │
    └── include 'includes/footer.php'
            └── Outputs closing HTML tags, loads js/main.js
```

### 5.2 Request/Response Patterns

The application uses three distinct patterns:

**Pattern A: Full Page Load (most pages)**
```
Browser → GET /pos/pages/products.php → PHP processes → Returns full HTML
```
Used by: products, sales, customers, brands, categories, quotations, orders, deliveries, expenses, reports, tax_bulk, users, sessions, data_management, receivables, customer_ledger

**Pattern B: AJAX JSON Endpoint**
```
Browser → POST /pos/pages/checkout.php → PHP processes → Returns JSON
Browser → GET  /pos/pages/sale_detail.php?id=123 → Returns HTML fragment
```
Used by: checkout (JSON), sale_detail (HTML), quotation_detail (HTML), order_detail (HTML), index.php?ajax=1 (JSON for dashboard refresh), sessions.php?ajax=1 (JSON for live updates)

**Pattern C: File Download**
```
Browser → POST /pos/pages/data_management.php → Returns CSV file or .sql.gz file
```
Used by: data_management export/import actions

### 5.3 Data Flow Patterns

**Sale Creation (checkout.php):**
```
Client JS cart → JSON POST → Server validates stock → Resolves tax from DB
→ Opens transaction → INSERT sales → INSERT sale_items (with server tax)
→ UPDATE products SET stock = stock - ? → INSERT inventory_log
→ INSERT audit_log → UPDATE customer_ledger (if credit)
→ COMMIT → Returns JSON response
```

**Product Stock Change:**
```
Product form POST → Validates inputs → Checks unique SKU/barcode
→ INSERT or UPDATE products → If stock > 0: INSERT inventory_log
→ INSERT audit_log → Redirect with success message
```

**Customer Balance Update:**
```
Any balance-affecting action → Calls updateCustomerBalance()
→ Calculates new balance (current + amount based on type)
→ INSERT customer_ledger with running balance_after
→ Balance is always derived from latest ledger entry
```

### 5.4 Client-Side Architecture (POS Page)

The POS page is the most complex client-side module. It maintains state entirely in JavaScript:

```javascript
// Global state (no framework, no state management library)
let cart = [];          // Array of { id, name, price, qty, stock, unit, tax_mode, gst_rate, selling_mode }
let totalPrice = 0;     // Current cart total
let discount = 0;       // Manual discount
let selectedCustomer = null;

// Product grid → click → addToCart() or openMeasurePicker()
// Cart → +/- buttons → changeQty() → recalcTotal()
// Payment → select method → calculate change → checkout button
// Checkout → AJAX POST to checkout.php → receive JSON → show invoice modal
```

For **measured products**, clicking opens a measurement picker modal with:
- Predefined length buttons (from `data-std-lengths` attribute, pipe-separated)
- Custom quantity input with `step="any"` for decimal values
- Live price preview as quantity changes
- Stock validation before adding to cart

### 5.5 Numbering Systems

All entity numbers are generated server-side with date-prefixed sequential formatting:

| Entity | Format | Example | Generator |
|--------|--------|---------|-----------|
| Invoice | `INV-YYYYMMDD-XXXXX` | INV-20260913-00001 | `generateInvoice()` |
| Quotation | `QUO-YYYYMM-XXXX` | QUO-202609-0001 | `generateQuotationNo()` |
| Purchase Order | `ORD-YYYYMMDD-XXXX` | ORD-20260913-0001 | `generateOrderNo()` |
| Delivery | `DEL-YYYYMMDD-XXXX` | DEL-20260913-0001 | `generateDeliveryNo()` |

Each generator queries the database for the last number with the same prefix and increments by 1.

---

## 6. Database Schema (30 Tables)

### Database: `pos_db`

Character set: `utf8mb4` (full Unicode support)
Engine: `InnoDB` (transactions, foreign keys)

### 6.1 `categories`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) NOT NULL | |
| created_at | TIMESTAMP DEFAULT NOW | |

### 6.2 `brands`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) NOT NULL UNIQUE | |
| description | TEXT NULL | |
| created_at | TIMESTAMP DEFAULT NOW | |

### 6.3 `units`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(50) NOT NULL | Full name (e.g., "Foot") |
| short_name | VARCHAR(20) NOT NULL | Abbreviation (e.g., "ft") |
| allows_decimal | TINYINT(1) DEFAULT 0 | Whether this unit supports decimal quantities |

**Pre-seeded units (15):**

| ID | Name | Short | Decimal? |
|----|------|-------|----------|
| 1 | Piece | pc | No |
| 2 | Set | set | No |
| 3 | Pair | pair | No |
| 4 | Box | box | No |
| 5 | Dozen | dz | No |
| 6 | Meter | m | **Yes** |
| 7 | Foot | ft | **Yes** |
| 8 | Roll | roll | No |
| 9 | Kilogram | kg | **Yes** |
| 10 | Bag | bag | No |
| 11 | Sheet | sheet | No |
| 12 | Carton | ctn | No |
| 13 | Gallon | gal | **Yes** |
| 14 | Liter | L | **Yes** |
| 15 | Pack | pack | No |

### 6.4 `products`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| category_id | INT FK → categories(id) | |
| brand_id | INT FK → brands(id) | |
| name | VARCHAR(200) NOT NULL | Descriptive name |
| barcode | VARCHAR(100) | Secondary identifier (can be shared) |
| sku | VARCHAR(100) UNIQUE | Primary identifier (must be unique) |
| model | VARCHAR(100) | Manufacturer model |
| price | DECIMAL(10,2) | Retail price |
| cost | DECIMAL(10,2) | Purchase cost (admin only) |
| min_price | DECIMAL(12,2) | Minimum allowed selling price (admin only) |
| wholesale_price | DECIMAL(12,2) | Wholesale tier price |
| contractor_price | DECIMAL(12,2) | Contractor tier price |
| stock | DECIMAL(12,3) | Current inventory (supports decimal for measured products) |
| reserved_stock | DECIMAL(12,3) | Reserved by pending quotations |
| low_stock_alert | DECIMAL(12,3) | Threshold for low-stock warnings |
| taxable | TINYINT(1) DEFAULT 1 | Legacy: whether GST applies |
| gst_rate | DECIMAL(5,2) DEFAULT 18.00 | Legacy: per-product rate |
| tax_mode | ENUM('default','non_taxable','custom') DEFAULT 'default' | Tax resolution mode |
| unit_id | INT FK → units(id) | Unit of measure |
| selling_mode | ENUM('fixed','measured') DEFAULT 'fixed' | Fixed=whole units, Measured=decimal qty |
| standard_lengths | TEXT NULL | Pipe-separated predefined lengths (e.g., "3\|6\|9\|12") |
| default_qty | DECIMAL(10,2) NULL | Pre-filled quantity in POS picker |
| image | VARCHAR(255) | Reserved (not displayed) |
| description | TEXT | Product description |
| is_active | TINYINT(1) DEFAULT 1 | Soft-delete flag |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | Auto-updates |

**Product identity system:** SKU = primary key (UNIQUE constraint), barcode = secondary identifier, name = descriptive only. Duplicates are blocked at both database and application levels.

**Current data:** 68 active products, 5 measured

### 6.5 `customers_v2`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(200) NOT NULL | |
| phone | VARCHAR(20) | |
| email | VARCHAR(200) | |
| type | ENUM('individual','contractor','builder','architect','dealer','company') | |
| city | VARCHAR(100) | |
| address | TEXT | |
| credit_limit | DECIMAL(12,2) DEFAULT 0 | Max allowed credit |
| balance | DECIMAL(12,2) DEFAULT 0 | Current outstanding (cached, updated via ledger) |
| is_active | TINYINT(1) DEFAULT 1 | |
| created_at | TIMESTAMP | |

### 6.6 `customer_ledger`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| customer_id | INT FK → customers_v2(id) | |
| type | ENUM('invoice','payment','credit','adjustment','refund','opening') | Transaction type |
| reference_type | VARCHAR(50) NULL | 'sale', 'quotation', etc. |
| reference_id | INT NULL | ID of related record |
| amount | DECIMAL(12,2) | Positive = increases balance |
| balance_after | DECIMAL(12,2) | Running balance snapshot |
| note | TEXT NULL | Description |
| user_id | INT NULL FK → users(id) | Who recorded it |
| created_at | TIMESTAMP | |

### 6.7 `customer_payments`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| customer_id | INT FK → customers_v2(id) | |
| amount | DECIMAL(12,2) | Payment amount |
| payment_method | ENUM('cash','card','mobile','bank_transfer','cheque') DEFAULT 'cash' | |
| reference | VARCHAR(100) NULL | Cheque #, transfer ref, etc. |
| note | TEXT NULL | |
| user_id | INT NULL FK → users(id) | |
| created_at | TIMESTAMP | |

### 6.8 `sales`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| invoice_no | VARCHAR(50) UNIQUE | Format: `INV-YYYYMMDD-XXXXX` |
| customer_id | INT FK → customers(id) | Legacy (NULL) |
| customer_v2_id | INT FK → customers_v2(id) | Current customer |
| project_id | INT FK → projects(id) | Optional project link |
| quotation_id | INT FK → quotations(id) | If converted from quotation |
| user_id | INT NULL FK → users(id) | Cashier |
| delivery_address | TEXT | Delivery location |
| subtotal | DECIMAL(10,2) | Sum of item totals |
| discount | DECIMAL(10,2) | Manual discount |
| tax | DECIMAL(10,2) | Total GST collected |
| total | DECIMAL(10,2) | subtotal + tax - discount |
| paid | DECIMAL(10,2) | Amount paid |
| change_amount | DECIMAL(10,2) | Change given |
| outstanding | DECIMAL(12,2) | Amount still owed |
| payment_method | ENUM('cash','card','mobile','credit','bank_transfer','cheque') | |
| status | ENUM('completed','refunded') | |
| created_at | TIMESTAMP | |

### 6.9 `sale_items`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| sale_id | INT FK → sales(id) ON DELETE CASCADE | |
| product_id | INT FK → products(id) | |
| product_name | VARCHAR(200) | Snapshot at time of sale |
| qty | DECIMAL(12,3) | Supports decimal for measured products |
| unit | VARCHAR(20) DEFAULT 'pc' | Unit of measure (e.g., "ft", "pc") |
| price | DECIMAL(10,2) | Unit price at time of sale |
| discount | DECIMAL(10,2) DEFAULT 0 | Per-item discount |
| total | DECIMAL(10,2) | price × qty |
| tax_amount | DECIMAL(10,2) | Tax for this line item |
| gst_rate | DECIMAL(5,2) | **Snapshotted** rate at time of sale |

### 6.10 `quotations`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| quotation_no | VARCHAR(50) UNIQUE | Format: `QUO-YYYYMM-XXXX` |
| customer_id | INT FK → customers_v2(id) | |
| project_id | INT FK → projects(id) | |
| subtotal | DECIMAL(10,2) | |
| tax | DECIMAL(10,2) | |
| total | DECIMAL(10,2) | |
| status | ENUM('draft','sent','approved','rejected','converted','cancelled') | |
| notes | TEXT | |
| terms | TEXT | |
| valid_until | DATE | |
| user_id | INT NULL FK → users(id) | |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### 6.11 `quotation_items`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| quotation_id | INT FK → quotations(id) ON DELETE CASCADE | |
| product_id | INT FK → products(id) | |
| product_name | VARCHAR(200) | |
| qty | DECIMAL(12,3) | Supports decimal quantities |
| unit | VARCHAR(20) DEFAULT 'pc' | |
| price | DECIMAL(10,2) | |
| discount | DECIMAL(10,2) DEFAULT 0 | |
| tax_amount | DECIMAL(10,2) DEFAULT 0 | Server-calculated |
| gst_rate | DECIMAL(5,2) DEFAULT 0 | Resolved at creation |
| total | DECIMAL(10,2) | |

### 6.12 `deliveries`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| delivery_no | VARCHAR(50) UNIQUE | Format: `DEL-YYYYMMDD-XXXX` |
| sale_id | INT FK → sales(id) | |
| customer_id | INT FK → customers_v2(id) | |
| status | ENUM('pending','partially_delivered','delivered','cancelled') | |
| delivery_date | DATE | |
| notes | TEXT | |
| user_id | INT NULL FK → users(id) | |
| created_at | TIMESTAMP | |

### 6.13 `delivery_items`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| delivery_id | INT FK → deliveries(id) ON DELETE CASCADE | |
| product_name | VARCHAR(200) | |
| qty_ordered | DECIMAL(12,3) | Original ordered quantity |
| qty_delivered | DECIMAL(12,3) DEFAULT 0 | Delivered quantity |

### 6.14 `expenses`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| title | VARCHAR(200) NOT NULL | |
| amount | DECIMAL(10,2) | |
| category_id | INT FK → expense_categories(id) | |
| user_id | INT NULL FK → users(id) | Who recorded it |
| note | TEXT | |
| expense_date | DATE | |
| created_at | TIMESTAMP | |

### 6.15 `expense_categories`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) NOT NULL | |

**Pre-seeded (9):** Rent, Salaries, Utilities, Transport, Maintenance, Marketing, Office Supplies, Miscellaneous, Other

### 6.16 `suppliers`
| Column | Type | Notes |
|--------|------|-------|
| supplier_id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(200) NOT NULL | |
| contact | VARCHAR(100) | Phone |
| email | VARCHAR(200) | |
| payment_terms | VARCHAR(100) | e.g., "Net 30" |
| credit_limit | DECIMAL(10,2) DEFAULT 0 | |
| created_at | TIMESTAMP | |

### 6.17 `orders` (Purchase Orders)
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| order_no | VARCHAR(50) UNIQUE | Format: `ORD-YYYYMMDD-XXXX` |
| supplier_id | INT FK → suppliers(supplier_id) | |
| total | DECIMAL(10,2) DEFAULT 0 | |
| status | ENUM('pending','received','cancelled') | |
| note | TEXT | |
| ordered_at | DATETIME DEFAULT NOW | |
| received_at | DATETIME NULL | |

### 6.18 `order_items`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| order_id | INT FK → orders(id) ON DELETE CASCADE | |
| product_id | INT FK → products(id) | |
| product_name | VARCHAR(200) | |
| qty | DECIMAL(12,3) DEFAULT 1 | Supports decimal quantities |
| cost | DECIMAL(10,2) DEFAULT 0 | Unit cost |
| total | DECIMAL(10,2) DEFAULT 0 | qty × cost |

### 6.19 `users`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(200) NOT NULL | Display name |
| username | VARCHAR(100) UNIQUE | Login username |
| password | VARCHAR(255) | bcrypt hash |
| role | ENUM('admin','cashier') | |
| created_at | TIMESTAMP | |

### 6.20 `user_sessions`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT FK → users(id) | |
| username | VARCHAR(100) | Denormalized |
| sign_in | DATETIME | |
| sign_out | DATETIME NULL | NULL = still active |
| duration | VARCHAR(20) | Calculated (e.g., "2h 15m") |
| ip_address | VARCHAR(45) | Supports IPv6 |

### 6.21 `inventory_log`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| product_id | INT FK → products(id) ON DELETE CASCADE | |
| qty_added | DECIMAL(12,3) | Positive = stock-in, negative = adjustment/sale |
| type | ENUM('stock_in','adjustment','initial','sale','refund','stock_out') | |
| reason | VARCHAR(255) NULL | |
| supplier | VARCHAR(255) NULL | Supplier name (for stock imports) |
| unit_cost | DECIMAL(10,2) NULL | Per-unit cost (for stock imports) |
| reference | VARCHAR(255) NULL | Reference number (for stock imports) |
| note | VARCHAR(255) | |
| created_at | TIMESTAMP | |

### 6.22 `audit_log`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| user_id | INT NULL FK → users(id) | |
| action | VARCHAR(100) NOT NULL | e.g., 'sale_create', 'stock_adjust', 'product_create' |
| entity_type | VARCHAR(50) | e.g., 'sale', 'product', 'customer' |
| entity_id | INT NULL | ID of affected record |
| details | LONGTEXT | JSON payload |
| ip_address | VARCHAR(45) | |
| created_at | TIMESTAMP | |

### 6.23 `settings`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| setting_key | VARCHAR(100) UNIQUE | |
| setting_value | TEXT | |
| setting_type | ENUM('string','integer','decimal','boolean','json') | |
| description | TEXT | |
| updated_at | TIMESTAMP | |

**Current settings (15):**

| Key | Value | Purpose |
|-----|-------|---------|
| `app_initialized` | 1 | First-run setup completed flag |
| `currency` | Rs: | Currency prefix for display |
| `default_measurement_unit` | 7 | Default unit for measured products (ft) |
| `default_tax_rate` | 18 | System-wide default GST rate (%) |
| `default_tax_treatment` | taxable | Default for new products |
| `invoice_footer` | Thank you for your business! | Invoice footer text |
| `low_stock_default` | 5 | Default alert threshold |
| `quotation_footer` | This quotation is valid for 15 days. | Quotation footer text |
| `quotation_validity_days` | 15 | Default validity period |
| `shop_address` | Main Market, Lahore | Address on invoices |
| `shop_email` | info@saffronpos.com | Contact email |
| `shop_name` | Saffron Sanitary | Shop name on invoices |
| `shop_phone` | 0321-1234567 | Contact phone |
| `shop_whatsapp` | 0321-1234567 | WhatsApp number |

### 6.24 `price_lists`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) NOT NULL | e.g., "Retail", "Wholesale", "Contractor" |
| description | TEXT | |

### 6.25 `product_prices`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| product_id | INT FK → products(id) | |
| price_list_id | INT FK → price_lists(id) | |
| price | DECIMAL(12,2) | |

### 6.26 `spec_definitions`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(100) NOT NULL | e.g., "Color", "Material", "Size" |
| category_id | INT FK → categories(id) | Which category this spec applies to |

### 6.27 `product_specs`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| product_id | INT FK → products(id) | |
| spec_def_id | INT FK → spec_definitions(id) | |
| value | VARCHAR(200) | Spec value |

### 6.28 `projects`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(200) NOT NULL | |
| customer_id | INT FK → customers_v2(id) | |
| description | TEXT | |
| status | ENUM('active','completed','cancelled') | |
| created_at | TIMESTAMP | |

### 6.29 `stock_reservations`
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| product_id | INT FK → products(id) | |
| quotation_id | INT FK → quotations(id) | |
| qty | DECIMAL(12,3) | |
| status | ENUM('active','fulfilled','cancelled') | |
| created_at | TIMESTAMP | |

### 6.30 `customers` (Legacy)
| Column | Type | Notes |
|--------|------|-------|
| id | INT AUTO_INCREMENT PK | |
| name | VARCHAR(200) NOT NULL | |
| phone | VARCHAR(20) | |
| email | VARCHAR(200) | |
| address | TEXT | |
| created_at | TIMESTAMP | |

> **Note:** Legacy table. All current customer functionality uses `customers_v2`.

### Entity Relationship Summary

```
categories ──1:N── products ──1:N── sale_items ──N:1── sales
     │                │                                    │
     │                ├──1:N── inventory_log                │
     │                ├──1:N── product_prices ──N:1── price_lists
     │                ├──1:N── product_specs ──N:1── spec_definitions
     │                └──1:N── order_items ──N:1── orders ──N:1── suppliers
     │
     └──1:N── spec_definitions

customers_v2 ──1:N── customer_ledger
customers_v2 ──1:N── sales (via customer_v2_id)
customers_v2 ──1:N── quotations
customers_v2 ──1:N── deliveries
customers_v2 ──1:N── customer_payments

sales ──1:N── sale_items
sales ──1:N── deliveries ──1:N── delivery_items
quotations ──1:N── quotation_items
quotations ──1:N── stock_reservations

users ──1:N── sales
users ──1:N── user_sessions
users ──1:N── expenses
users ──1:N── audit_log
```

---

## 7. Tax System

### Architecture

The tax system uses a **centralized default + per-product override** model.

```
System Default Rate (settings.default_tax_rate = 18%)
      │
      ▼
Product.tax_mode determines resolution:
  ┌──────────────────────────────────────────────────┐
  │ default     → use system default rate (18%)      │
  │ non_taxable → rate = 0%                          │
  │ custom      → rate = product.gst_rate            │
  └──────────────────────────────────────────────────┘
```

### Tax Resolution Flow (Server-Side Only)

At checkout/quotation creation, the server:
1. Receives product IDs and quantities from the client
2. Fetches `tax_mode` and `gst_rate` from the `products` table
3. Calls `resolveTax($product)` which returns `['taxable' => bool, 'rate' => float]`
4. Calculates tax: `lineTotal × rate / 100`
5. Stores the **resolved** `gst_rate` and `tax_amount` in `sale_items` (or `quotation_items`)
6. The client-supplied tax values are **ignored** — server is always authoritative

### Historical Accuracy

`sale_items.gst_rate` and `sale_items.tax_amount` are **snapshotted** at the time of sale. Changing a product's current tax mode or rate does **not** retroactively modify historical invoices.

### Bulk Tax Management

Admin-only page (`pages/tax_bulk.php`) allows:
- Viewing current distribution (how many products in each tax mode)
- Editing the system default rate
- Bulk updating tax mode for all products, by category, by brand, or by current tax mode
- Preview step showing affected product count and sample list before confirmation
- Confirmation dialog before applying changes

---

## 8. Measured Products (Variable-Quantity)

### Concept

Some products are sold by measurement rather than by piece — pipes measured in feet, wire in meters, fabric in meters. These products require **decimal quantities** (e.g., 7.5 ft of pipe) and have different POS UX (a measurement picker instead of a simple quantity input).

### Schema Changes

Three new columns were added to `products`:

| Column | Type | Purpose |
|--------|------|---------|
| `selling_mode` | ENUM('fixed','measured') DEFAULT 'fixed' | Fixed = whole units, Measured = decimal qty |
| `standard_lengths` | TEXT NULL | Pipe-separated predefined lengths (e.g., "3\|6\|9\|12") |
| `default_qty` | DECIMAL(10,2) NULL | Pre-filled quantity in the measurement picker |

All quantity fields across the schema were migrated to `DECIMAL(12,3)`:
- `products.stock`, `products.reserved_stock`, `products.low_stock_alert`
- `sale_items.qty`, `order_items.qty`, `inventory_log.qty_added`
- `quotation_items.qty`, `stock_reservations.qty`, `delivery_items.qty_ordered`, `delivery_items.qty_delivered`

### How It Works End-to-End

**Product Creation (products.php):**
1. Admin selects "Measured (decimal qty)" radio button
2. Standard lengths input appears (pipe-separated, e.g., "3|6|9|12")
3. Default quantity input appears
4. System validates that the selected unit allows decimal (`units.allows_decimal = 1`)
5. If unit doesn't allow decimal, selling mode is forced to 'fixed'
6. Product is saved with `selling_mode='measured'`

**POS Terminal (pos.php):**
1. Product cards display selling mode: measured products show "Rs 180 / ft" instead of just "Rs 180"
2. Clicking a **fixed** product → adds 1 to cart (or opens picker if >0 already in cart)
3. Clicking a **measured** product → opens the **measurement picker modal**:
   - Shows predefined length buttons (e.g., 3 ft, 6 ft, 9 ft, 12 ft)
   - Shows a custom quantity input with `step="any"` and `min="0.001"`
   - Shows live price preview as quantity changes
   - Shows available stock
   - User selects or enters quantity → clicks "Add to Cart"
4. Cart displays measured items with: quantity + unit label (e.g., "7.5 ft")
5. Measured items get decimal +/- buttons (step = 0.5 instead of 1)

**Barcode Scanning:**
- Fixed products: scanned → added with qty 1
- Measured products: scanned → opens the measurement picker (cannot auto-add a fixed quantity)

**Cart Calculations:**
- All calculations use `parseFloat()` (not `parseInt()`)
- Quantities are rounded to 3 decimal places
- Totals: `price × qty` (full DECIMAL precision)

**Checkout (checkout.php):**
- Stock validation: `$available < $qty` — decimal comparison
- Stock deduction: `UPDATE products SET stock = stock - ? WHERE id = ?` with `bind_param("di", ...)` — double for qty, integer for id
- Sale items: qty stored as DECIMAL(12,3)
- Inventory log: qty_added stored as DECIMAL(12,3)

**Invoices:**
- Display: `qty + ' ' + unit` (e.g., "7.5 ft")
- PDF: same format

**Quotations:**
- Create: decimal qty preserved through insert
- Convert to sale: decimal qty extracted from CSV, stock deducted with DECIMAL arithmetic

**Purchase Orders:**
- Create: decimal qty supported in order items
- Receive: stock incremented with DECIMAL precision

**Partial Returns (sales.php):**
- Select items and specify return quantity (decimal for measured products)
- Stock restored: `UPDATE products SET stock = stock + ?` with `bind_param("di", ...)`
- Customer ledger adjusted
- Audit logged

**CSV Import/Export (data_management.php):**
- Export: includes `selling_mode`, `standard_lengths`, `default_qty` columns
- Import: reads these columns, creates measured products with correct fields
- Stock import: `floatval()` for quantity, `bind_param("di", ...)` for update

### Pre-seeded Measured Products (5)

| SKU | Name | Unit | Standard Lengths | Default Qty | Stock |
|-----|------|------|-----------------|-------------|-------|
| PVC-GRN-3 | Green PVC Pipe 3" | ft | 3\|6\|9\|12 | 6 | 133.5 |
| PVC-WHT-2 | White PVC Pipe 2" | ft | 3\|6\|9\|12 | — | 200 |
| CWR-25 | Copper Wire 2.5mm | ft | — | — | 500 |
| HOS-GRN-12 | Green Hose 1/2" | m | 1\|2\|3\|5\|10 | — | 300 |

---

## 9. Authentication & Authorization

### Login Flow

1. `login.php` renders a form with CSRF token
2. Rate limiting: max 5 attempts per 5 minutes per IP (file-based, `/tmp/pos_ratelimit_*`)
3. POST → validates username/password with `password_verify()` against bcrypt hash
4. On success: `session_regenerate_id(true)` → stores `user_id`, `user_name`, `user_role`, `csrf_token` in `$_SESSION`
5. Creates a `user_sessions` record (tracks sign-in time and IP)
6. Redirects to `/pos/index.php`

### Session Management

- PHP native sessions via `PHPSESSID` cookie
- **8-hour session timeout** — auto-logout after inactivity (`$_SESSION['last_activity']`)
- Session security: httponly cookie, SameSite=Lax, strict mode, cookies-only
- On logout (`logout.php`): updates `user_sessions.sign_out` with duration, destroys session
- On new login: closes any previous active sessions for that user

### Role System

| Page | Cashier | Admin |
|------|---------|-------|
| Dashboard | ✅ | ✅ |
| POS Terminal | ✅ | ✅ |
| Sales History | ✅ (view) | ✅ (view + refund) |
| Quotations | ✅ | ✅ |
| Deliveries | ✅ | ✅ |
| Products | ✅ (view) | ✅ (full CRUD + stock) |
| Brands | ✅ (view) | ✅ (full CRUD) |
| Categories | ✅ (view) | ✅ (full CRUD) |
| Customers | ✅ | ✅ |
| Receivables | ✅ | ✅ |
| Customer Ledger | ✅ | ✅ |
| Expenses | ✅ (add) | ✅ (add + delete) |
| Suppliers | ✅ | ✅ |
| Orders | ✅ | ✅ |
| Reports | ❌ | ✅ |
| Tax Settings | ❌ | ✅ |
| Users | ❌ | ✅ |
| Sessions | ❌ | ✅ |
| Data Management | ❌ | ✅ |

### Access Control Functions (in `includes/auth.php`)

| Function | Purpose |
|----------|---------|
| `requireLogin()` | Redirects to login if no session or session expired (8h) |
| `requireAdmin()` | Calls requireLogin() + checks role === 'admin' |
| `isAdmin()` | Returns boolean, used in templates to show/hide UI |
| `canOverridePrice()` | Returns boolean (admin only) — controls below-min-price selling |
| `trackSession($conn)` | Creates user_sessions record on login, closes previous active session |

---

## 10. Feature Modules — Page by Page

### 10.1 Dashboard (`index.php` — 263 lines)

**Stats displayed (6 cards):**
- Today's revenue, today's profit
- Monthly revenue
- Customer outstanding
- Stock value (retail)
- Low stock alerts

**Data sections:**
- Top 5 products (30 days) with revenue and quantity
- Top 5 brands (30 days) with revenue and profit
- Recent sales (last 8 transactions)
- 7-day revenue bar chart (SVG, rendered by `pie-chart.js`)

**Live update:** AJAX polls `?ajax=1` every 10 seconds.

**Code pattern:** Standard full page load. Stats are calculated with SQL aggregations in-page. The chart is rendered client-side using SVG `<rect>` elements computed from daily revenue totals.

---

### 10.2 POS Terminal (`pages/pos.php` — 748 lines)

The most complex page in the application. Contains ~500 lines of JavaScript for cart management and measurement picker.

**Product browsing:**
- Grid of product cards with name, price, brand, stock level, selling mode indicator
- Category filter tabs
- Search by name, barcode, SKU, model, or brand
- Price level switching: Retail / Wholesale / Contractor
- Products loaded as HTML with `data-*` attributes: `data-id`, `data-name`, `data-price`, `data-stock`, `data-tax-mode`, `data-gst-rate`, `data-unit`, `data-brand`, `data-selling-mode`, `data-std-lengths`, `data-default-qty`

**Customer selection:**
- Walk-in Customer (default)
- Select from customer list (shows phone and type)

**Cart (client-side JavaScript):**
- Products stored in `cart` array of objects
- Each item: `{ id, name, price, qty, stock, tax_mode, gst_rate, unit, selling_mode }`
- Quantity +/- buttons: fixed products use step=1, measured products use step=0.5
- Remove button, clear cart button
- Manual discount input (non-negative enforced)
- Total = subtotal + tax - discount
- Tax calculated client-side using `tax_mode` + system default rate (for display only; server recalculates)

**Measurement picker modal:**
- Opened when clicking a measured product
- Shows predefined length buttons from `standard_lengths` (pipe-separated)
- Custom quantity input with `step="any"`, `min="0.001"`
- Live total updates as quantity changes
- Stock validation before adding

**Payment methods:** Cash, Card, Mobile, Credit, Bank Transfer, Cheque

**Barcode scanner support:**
- USB scanners that emulate keyboard input work automatically
- Rapid keystrokes + Enter → lookup by barcode/SKU → auto-add (fixed) or open picker (measured)

**Checkout (AJAX to `checkout.php`):**
1. Client-side validation (cart not empty, min price check, credit requires customer)
2. Sends `cart` JSON array with: `{ id, qty, price, unit, tax_mode }`
3. Server-side: validates stock, resolves tax from DB, opens MySQL transaction
4. Inserts `sales` record with customer, payment method, outstanding
5. Inserts `sale_items` with **server-calculated** tax amounts and correct types
6. Decrements stock with DECIMAL arithmetic
7. Logs inventory movement, audit log
8. For credit sales: updates customer balance and ledger
9. Returns JSON: `{ success, sale_id, invoice_no, subtotal, discount, tax, total, paid, change, ... }`

**Double-submit prevention:** `submitSale._processing` flag prevents multiple concurrent AJAX requests.

**Invoice modal:** Print (new window) and PDF download (jsPDF, local copy)

---

### 10.3 Products Management (`pages/products.php` — 513 lines)

**Features:**
- Searchable product list with category/brand/unit filters
- Table: Name, Brand, SKU, Category, Cost, Prices (retail/wholesale/contractor), Stock, Tax, Status, Actions
- Badges: OUT (red), LOW (orange)

**Product form (add/edit):**
- Basic fields: Name, SKU, Barcode, Model, Category, Brand, Unit, Description
- Prices: Cost, Retail, Wholesale, Contractor
- Tax mode selector: System Default / Non-Taxable / Custom Rate
- Effective rate display (live update)
- **Selling mode section:**
  - Fixed (whole units) / Measured (decimal qty) radio buttons
  - Standard lengths input (visible only for measured mode)
  - Default quantity input (visible only for measured mode)
  - Validation: measured mode requires a unit with `allows_decimal = 1`

**bind_param format (INSERT):** `iissssdddddddidsissssi` (22 params)
- Types: `i`=int, `s`=string, `d`=double
- Order: catId, brandId, name, sku, barcode, model, price, cost, minPrice, wholesale, contractor, stock, alert, taxable, gstRate, taxMode, unitId, sellingMode, standardLengths, defaultQty, description, isActive

**Stock-in modal:** Decimal quantity input (`step="any"`, `min="0.001"`), uses `floatval()` and `bind_param("di", ...)`

**Stock adjustment (admin):** Reduce stock with reason and note, same decimal handling

---

### 10.4 Sales History (`pages/sales.php` — 425 lines)

**Stats (date-filtered):**
- Transaction count, Revenue, Tax collected, Average sale, Top payment method

**Invoice list:**
- Searchable by invoice number
- Filterable by payment method
- Columns: Invoice #, Customer, Subtotal, Discount, Tax, Total, Method, Status, Date
- Credit sales highlighted with badge
- Outstanding amount displayed

**Refund system (admin only):**

*Full refund:*
- Restores stock: `UPDATE products SET stock = stock + ?` with `bind_param("di", ...)`
- Adjusts customer ledger (if credit sale)
- Marks sale as 'refunded'
- Audit logged

*Partial refund:*
- Modal with item selection (checkbox per item)
- Editable return quantity per item (validated against original)
- Stock restored per item
- Ledger adjusted for the refund amount
- Audit logged

---

### 10.5 Brands (`pages/brands.php` — 141 lines)

- CRUD with product count per brand
- Search filter
- Add/Edit via modal: name + description
- Admin-only add/edit/delete
- Delete blocked if brand has associated products

---

### 10.6 Categories (`pages/categories.php` — 85 lines)

- CRUD with product count per category
- Add/Edit via modal (name field only)
- Admin-only for add/edit/delete

---

### 10.7 Customer Management (`pages/customers.php` — 248 lines)

- CRUD with types: Individual, Contractor, Builder, Architect, Dealer, Company
- Fields: Name, Phone, Email, Type, City, Address, Credit Limit
- Balance display with link to ledger
- Active/inactive toggle

---

### 10.8 Customer Ledger (`pages/customer_ledger.php` — 204 lines)

**Purpose:** Traditional Pakistani khata system.

- Full transaction history (invoices, payments, refunds, adjustments)
- Running balance after each transaction (`balance_after`)
- Record payment modal (amount, method, reference, note)
- Balance adjustment (admin only)
- Date-filtered view

---

### 10.9 Receivables Dashboard (`pages/receivables.php` — 113 lines)

- Total outstanding amount
- Aging buckets: Current (0-30 days), 31-60 days, 61-90 days, 90+ days
- Per-customer breakdown with contact info and type
- Quick payment modal (record payment from this page)
- Customers sorted by outstanding amount (highest first)

---

### 10.10 Quotations (`pages/quotations.php` — 428 lines)

**Status workflow:** Draft → Sent → Approved → Converted to Sale

**Create quotation:**
- Select customer, optional project
- Add products with prices (fixed or measured)
- Server-side tax resolution
- Notes and terms (auto-populated from settings)
- Validity period

**Convert to sale:**
- Deducts stock (with decimal quantity support)
- Creates sale record linked to quotation
- Creates customer ledger entry (if customer selected)
- Audit log entry

---

### 10.11 Deliveries (`pages/deliveries.php` — 159 lines)

**Statuses:** Pending → Partial → Delivered | Cancelled

- Linked to sales
- Delivery items with ordered and delivered quantities (decimal)
- Status tracking
- Notes

---

### 10.12 Expenses (`pages/expenses.php` — 169 lines)

- Category support (9 seeded categories)
- Date-range filtering
- Add expense: Title, Category, Amount, Note, Date
- Running total display
- Delete (admin only)

---

### 10.13 Purchase Orders (`pages/orders.php` — 283 lines)

**Order lifecycle:** Created (pending) → Received → Stock Updated | Cancelled

- Select supplier
- Add items: product, quantity (decimal), cost per unit
- Receive order → increments stock + logs inventory movement
- Transactional receive (rollback on failure)
- Order number format: `ORD-YYYYMMDD-XXXX`

---

### 10.14 Reports (`pages/reports.php` — 165 lines) — Admin Only

**Filters:** Date range + payment method

**Report sections:**
1. **P&L Summary:** Revenue, COGS, Gross Profit, Expenses, Net Profit, Margin
2. **Sales by Brand:** Revenue, quantity, profit, margin per brand
3. **Sales by Category:** Revenue, quantity, profit per category
4. **Most Profitable Products:** Top products by absolute profit
5. **Credit Outstanding:** Customer-wise aging
6. **Inventory Value:** Cost value vs retail value
7. **Low Stock Alerts:** Products below threshold

---

### 10.15 Tax Settings (`pages/tax_bulk.php` — 322 lines) — Admin Only

- Current distribution display (count per tax mode)
- Editable system default rate
- Bulk update tool with:
  - Filter: All active products / By category / By brand / By current tax mode
  - Target: Default / Taxable-Standard / Non-Taxable / Custom Rate
  - Preview step: affected product count + sample list
  - Confirmation dialog before applying
- Audit log entry for all bulk changes

---

### 10.16 User Management (`pages/users.php` — 113 lines) — Admin Only

- List all users with role badges
- Add user: Name, Username, Password (bcrypt), Role
- Edit user: Name, Role, optional password change
- Delete user (cannot delete yourself)

---

### 10.17 Session Tracking (`pages/sessions.php` — 191 lines) — Admin Only

- Date range filter
- Filter by user, role, status (active/ended)
- Table: User, Role, Sign In, Sign Out, Duration, IP, Status
- Live updates: AJAX polls every 5 seconds

---

### 10.18 Data Management (`pages/data_management.php` — 868 lines) — Admin Only

The second-largest page. Handles all data import/export and backup operations.

**Backup & Restore:**
- **Create Backup:** `mysqldump` → gzip → download as `.sql.gz` file
- **Restore from Backup:** Upload `.sql.gz` → gunzip → import via `mysql` command
- Safety: current state is backed up before restore

**Product CSV Import:**
- Upload CSV file with product data
- Auto-creates categories, brands, and units if they don't exist
- Validates SKU uniqueness
- Supports measured product fields: `selling_mode`, `standard_lengths`, `default_qty`
- Stock uses `floatval()` for decimal quantities
- Transactional (rollback on failure)

**Stock CSV Import:**
- Upload CSV with columns: `sku`, `quantity`, `cost_price`, `supplier`, `reference`, `note`
- Adds quantity to existing stock (not replace)
- Validates SKU exists and is active
- Uses `floatval()` and `bind_param("di", ...)` for decimal quantities
- Logs each stock movement with supplier/cost info

**CSV Export:**
- Products: includes all fields including `selling_mode`, `standard_lengths`, `default_qty`
- Customers
- Inventory
- Sales
- Expenses

**Product CSV Export format:** 19 columns:
`sku, name, brand, model, category, unit, barcode, cost_price, retail_price, wholesale_price, contractor_price, min_price, current_stock, tax_mode, gst_rate, low_stock_alert, selling_mode, standard_lengths, default_qty`

---

### 10.19 Setup Wizard (`setup.php` — 149 lines)

**First-run setup flow:**
1. `config.php` checks `getSetting('app_initialized')` — if falsy, redirects to `setup.php`
2. `setup.php` shows a form: shop name, address, phone, admin username, password
3. On POST:
   - Creates the admin user with bcrypt-hashed password
   - Updates shop settings (name, address, phone)
   - Sets `app_initialized = 1`
4. Redirects to dashboard
5. Default admin account is no longer usable

---

## 11. Frontend Architecture

### Layout

Fixed sidebar (220px) + scrollable main content:

```
┌──────────┬──────────────────────────────┐
│ SIDEBAR  │        MAIN CONTENT          │
│ (220px)  │    (flex: 1, scrollable)     │
│          │                              │
│ Brand    │  [Page Header]               │
│ Nav      │  [Content]                   │
│ (sections│                              │
│  with    │                              │
│  active  │                              │
│  states) │                              │
│          │                              │
│ User     │                              │
│ Logout   │                              │
└──────────┴──────────────────────────────┘
```

### Sidebar Navigation Sections

- **Top:** Dashboard, POS
- **Sales:** Sales, Quotations, Deliveries
- **Inventory:** Products, Brands, Categories
- **Business:** Customers, Suppliers, Purchase Orders, Receivables, Expenses
- **Admin (admin only):** Reports, Tax Settings, Users, Sessions, Data Management

Active page highlighting uses `$activePage` variable set in each page file and compared in `header.php`.

### POS Terminal Layout

```
┌─────────────────────────────────┬──────────────┐
│     PRODUCTS (flex: 1)          │  CART (340px)│
│                                 │              │
│ [Search] [Category Tabs]        │ [Customer]   │
│ [Price Level: R/W/C]           │ [Cart Items] │
│                                 │              │
│ ┌───┐ ┌───┐ ┌───┐ ┌───┐       │  measured:   │
│ │ P │ │ P │ │ P │ │ P │       │  "7.5 ft"    │
│ └───┘ └───┘ └───┘ └───┘       │              │
│                                 │ [Subtotal]   │
│                                 │ [Discount]   │
│                                 │ [Tax]        │
│                                 │ [TOTAL]      │
│                                 │ [Payment]    │
│                                 │ [Checkout]   │
└─────────────────────────────────┴──────────────┘
```

### CSS Theme

Dark industrial aesthetic with CSS custom properties:

```css
--bg: #0f0f0f;        /* Page background */
--bg2: #161616;        /* Card background */
--bg3: #1e1e1e;        /* Input/hover background */
--border: #2a2a2a;     /* Standard border */
--accent: #e8ff3a;     /* Primary accent (yellow-green) */
--green: #3aff8a;      /* Success/positive */
--red: #ff4a4a;        /* Error/danger */
--blue: #4ab4ff;       /* Info/secondary */
--orange: #ff8c4a;     /* Warning */
--font: 'IBM Plex Sans';
--mono: 'IBM Plex Mono';
```

### Modal System

Standardized modal pattern used throughout the application:
- Opened by adding class `open` to `.modal-overlay`
- Closed by removing `open` class or clicking overlay backdrop
- Managed by `openModal(id)` / `closeModal(id)` in `main.js`
- Confirmation dialogs use `confirmDelete(msg)` for destructive actions

### JavaScript Architecture

No framework. All state management is vanilla JS:

- `js/main.js` (24 lines): `openModal()`, `closeModal()`, `confirmDelete()`
- `js/pie-chart.js` (72 lines): `renderPieChart(containerId, data, colors, currency)` — renders SVG donut charts
- `js/jspdf.umd.min.js` (398 lines): jsPDF library for client-side PDF generation
- Inline `<script>` in each page for page-specific logic (POS cart, checkout, product form, etc.)

---

## 12. Security Model

| Measure | Implementation |
|---------|---------------|
| **Password hashing** | `password_hash()` with `PASSWORD_DEFAULT` (bcrypt) |
| **CSRF protection** | 64-char hex token generated per session, verified on every POST via `csrf_verify()` |
| **SQL injection prevention** | All queries use `mysqli` prepared statements with bound parameters |
| **XSS prevention** | `e()` helper (`htmlspecialchars` with `ENT_QUOTES`) applied to all user output |
| **Session fixation** | `session_regenerate_id(true)` on login |
| **Session timeout** | 8-hour idle timeout via `$_SESSION['last_activity']` |
| **Session security** | httponly cookie, SameSite=Lax, strict_mode, cookies-only |
| **Role-based access** | `isAdmin()` for admin-only pages and UI elements |
| **Stock validation** | Server-side stock check in `checkout.php` before sale |
| **Price floor** | Server-side min_price enforcement in `checkout.php` |
| **Tax calculation** | Server-side `resolveTax()` — client values ignored at checkout |
| **Rate limiting** | 5 login attempts per 5 minutes per IP (file-based `/tmp/pos_ratelimit_*`) |
| **Security headers** | X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, X-XSS-Protection, Referrer-Policy |
| **Server signature** | `server_tokens off` in Nginx |
| **Hidden files** | Nginx blocks `location ~ /\.` |
| **Backup files** | Nginx blocks `.(sql|gz|bak|old|orig|save)$` |
| **Config protection** | Nginx blocks direct access to `includes/config.php` |
| **Error handling** | `display_errors = Off`, custom exception handler hides traces from users |
| **Production error handler** | Logs to `/var/log/pos_errors.log`, shows generic error page |
| **Self-delete prevention** | Users cannot delete their own account |
| **Input validation** | `trim()`, `intval()`, `floatval()`, type checks on all POST data |
| **Audit trail** | Critical operations logged to `audit_log` with user, action, entity, details JSON |
| **Double-submit prevention** | POS checkout uses `submitSale._processing` flag |
| **Unique SKU constraint** | Database UNIQUE index + application-level duplicate check |
| **Least privilege DB** | Dedicated `saffron_app` user with SELECT/INSERT/UPDATE/DELETE only |

---

## 13. Helper Functions Reference

### Core Helpers (`includes/config.php`)

| Function | Signature | Purpose |
|----------|-----------|---------|
| `loadSettings($conn)` | `($conn) → array` | Load all settings from DB into associative array |
| `e($str)` | `($str) → string` | Escape HTML output (`htmlspecialchars` with `ENT_QUOTES`) |
| `money($n)` | `($n) → string` | Format as currency: "Rs: 1,234.56" |
| `moneyRaw($n)` | `($n) → string` | Format number: "1,234.56" (no currency prefix) |

### CSRF Protection

| Function | Signature | Purpose |
|----------|-----------|---------|
| `csrf_token()` | `() → string` | Generate or retrieve 64-char hex CSRF token |
| `csrf_field()` | `() → string` | Output hidden input: `<input type="hidden" name="csrf_token" ...>` |
| `csrf_verify()` | `() → bool` | Validate POST CSRF token using `hash_equals()` |

### Numbering Generators

| Function | Signature | Format | Example |
|----------|-----------|--------|---------|
| `generateInvoice($conn)` | `($conn) → string` | INV-YYYYMMDD-XXXXX | INV-20260913-00001 |
| `generateQuotationNo($conn)` | `($conn) → string` | QUO-YYYYMM-XXXX | QUO-202609-0001 |
| `generateOrderNo($conn)` | `($conn) → string` | ORD-YYYYMMDD-XXXX | ORD-20260913-0001 |
| `generateDeliveryNo($conn)` | `($conn) → string` | DEL-YYYYMMDD-XXXX | DEL-20260913-0001 |

Each queries the DB for the last number with the same date prefix and increments by 1.

### Tax Resolution

| Function | Signature | Purpose |
|----------|-----------|---------|
| `calculateTax($sub, $taxable, $rate)` | `(float, bool, float) → float` | Calculate tax amount: `sub × rate / 100` |
| `resolveTax($product)` | `(array) → ['taxable'=>bool, 'rate'=>float]` | Resolve effective tax from product row |
| `resolveTaxById($conn, $id)` | `($conn, int) → array` | Fetch product from DB + resolve tax |
| `getEffectiveTaxRate($conn, $id)` | `($conn, int) → float` | Get numeric rate for display |

### Customer Ledger

| Function | Signature | Purpose |
|----------|-----------|---------|
| `getCustomerBalance($conn, $id)` | `($conn, int) → float` | Get current outstanding from ledger |
| `updateCustomerBalance($conn, $id, $type, $amount, ...)` | `($conn, int, string, float, ...) → float` | Create ledger entry + return new balance |

Balance types: `invoice` (+amount), `payment` (-amount), `refund` (-amount), `credit` (+amount), `adjustment` (+amount)

### Inventory & Audit

| Function | Signature | Purpose |
|----------|-----------|---------|
| `logInventoryMovement($conn, $pid, $qty, $type, $reason, $note)` | `($conn, int, float, string, ...) → void` | Log to inventory_log |
| `auditLog($conn, $action, $entityType, $entityId, $details, $ip)` | `($conn, string, string, int, array, string) → void` | Log to audit_log with JSON details |

### Settings

| Function | Signature | Purpose |
|----------|-----------|---------|
| `getSetting($key, $default)` | `(string, mixed) → mixed` | Read from $SETTINGS global (loaded from DB) |
| `setSetting($conn, $key, $value)` | `($conn, string, string) → void` | Write to settings table + update cache |

### Product Helpers

| Function | Signature | Purpose |
|----------|-----------|---------|
| `availableStock($product)` | `(array) → int` | Calculate stock - reserved_stock |
| `getBrandName($conn, $id)` | `($conn, int) → string` | Get brand name (cached per request) |
| `getUnitShort($conn, $id)` | `($conn, int) → string` | Get unit abbreviation (cached per request, default "pc") |

### CSV Helpers

| Function | Signature | Purpose |
|----------|-----------|---------|
| `parseCsvFile($handle, $maxRows)` | `(resource, int) → ['headers'=>[], 'rows'=>[], 'error'=>null\|string]` | Parse CSV file with BOM detection, header cleaning |
| `generateCsv($headers, $rows)` | `(array, array) → string` | Generate CSV content with BOM for Excel |
| `sendCsvDownload($filename, $csv)` | `(string, string) → void` | Send CSV as HTTP download |
| `csvEscape($value)` | `(mixed) → string` | Escape value to prevent spreadsheet formula injection |

### Authentication (`includes/auth.php`)

| Function | Signature | Purpose |
|----------|-----------|---------|
| `requireLogin()` | `() → void` | Redirect to login if not authenticated or session expired |
| `requireAdmin()` | `() → void` | Call requireLogin() + check admin role |
| `isAdmin()` | `() → bool` | Check if current session is admin |
| `canOverridePrice()` | `() → bool` | Check if user can sell below min price (admin only) |
| `trackSession($conn)` | `($conn) → void` | Create user_sessions record, close previous active session |

---

## 14. Deployment & Server Configuration

### Services Enabled on Boot

```bash
systemctl enable nginx php-fpm mariadb
```

### Nginx Configuration (`/etc/nginx/conf.d/pos.conf`)

```nginx
server {
    listen 80;
    server_name localhost;
    root /mnt/sda2/POS;
    index index.php index.html;

    # Security headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Block hidden files (.env, .git, etc.)
    location ~ /\. { deny all; access_log off; log_not_found off; }

    # Block backup files
    location ~* \.(sql|gz|bak|old|orig|save)$ { deny all; }

    # Block temp/upload directories
    location ~ ^/pos/(tmp|uploads|backups)/ { deny all; }

    location / {
        try_files $uri $uri/ /pos/index.php?$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php-fpm/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(css|js|ico|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    server_tokens off;
}
```

### Database Connection (`includes/config.php`)

```php
// Credentials support environment variable override
define('DB_HOST', getenv('POS_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('POS_DB_USER') ?: 'saffron_app');
define('DB_PASS', getenv('POS_DB_PASS') ?: 'Bukh@r1P0s_2026!xK9');
define('DB_NAME', getenv('POS_DB_NAME') ?: 'pos_db');
```

### Production Database User

```sql
CREATE USER 'saffron_app'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON pos_db.* TO 'saffron_app'@'localhost';
```

No DDL, no DROP, no ALTER — prevents application bugs from modifying schema.

### PHP Extension Required

`mysqli` — enabled via `/etc/php/conf.d/mysqli.ini`

### Services to Restart After Code Changes

```bash
# After changing any PHP file:
sudo systemctl restart php-fpm

# After changing Nginx config:
sudo systemctl restart nginx
```

### To Deploy on a New Server

1. Install: `nginx`, `php-fpm`, `php-mysqli`, `mariadb`
2. Initialize MariaDB, create `pos_db` database
3. Create `saffron_app` user with least-privilege grants
4. Import `database.sql` + `migration_phase1.sql` + `migrate.sql`
5. Optionally run `seed_sanitary.sql` for sample data
6. Copy project files to web root
7. Configure Nginx virtual host (see above)
8. Set `includes/config.php` permissions to 640
9. Start/restart services
10. Visit `http://your-server/pos/` — redirected to first-run setup
11. Complete setup wizard (creates admin account, shop details)

### Backup Schedule

```bash
# Daily backup at 2 AM (add to crontab)
0 2 * * * mariadb-dump -u root pos_db | gzip > /backups/pos-$(date +\%Y-\%m-\%d).sql.gz
find /backups/ -name "*.sql.gz" -mtime +30 -delete
```

Or use the **Data Management** page in the admin panel (one-click backup/restore).

---

*Document covers 30 database tables, 18 page modules, every user-facing feature, all helper functions, complete security model, and deployment instructions. Last updated: September 2026.*
