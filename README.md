# Saffron POS

Full-featured Point of Sale and business management system built for sanitary/plumbing/building-material stores in Pakistan.

## Features

- Real-time POS terminal with barcode scanner support
- Two selling modes: Fixed (pc, set, box) and Measured (ft, m, kg — decimal quantities)
- Multi-price-level pricing: Retail / Wholesale / Contractor
- Centralized tax system with per-product overrides (GST)
- Customer ledger (khata) with payment recording and balance tracking
- Quotation creation with status workflow and convert-to-sale
- Purchase order workflow (create → receive → stock update)
- Delivery tracking with status management
- Expense tracking with categories
- Receivables dashboard with aging analysis
- Multi-user role system (Admin / Cashier)
- Live dashboard with auto-refreshing stats
- Comprehensive reports: P&L, by brand, by category, aging, inventory value
- CSV import/export for products, stock, customers, sales, expenses
- Database backup and restore (pure PHP, no shell commands)
- First-run setup wizard
- Audit log for all critical operations
- Rate limiting on login (5 attempts per 5 minutes)
- Security headers and CSRF protection

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Server | PHP 8.1+ |
| Database | MariaDB 10.5+ / MySQL 8+ / PlanetScale |
| Frontend | Vanilla HTML/CSS/JS (no framework, no build step) |
| PDF | jsPDF 2.5.1 (client-side, loaded locally) |
| Fonts | IBM Plex Sans + Mono (Google Fonts CDN) |

**Zero dependencies** — no Composer, no npm, no frontend framework.

## Quick Start

### Local Development

```bash
# 1. Clone the repo
git clone https://github.com/your-user/saffron-pos.git
cd saffron-pos

# 2. Create database
sudo mariadb -u root << 'SQL'
CREATE DATABASE pos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'saffron_app'@'localhost' IDENTIFIED BY 'your_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON pos_db.* TO 'saffron_app'@'localhost';
FLUSH PRIVILEGES;
SQL

# 3. Import schema
sudo mariadb -u root pos_db < schema.sql

# 4. (Optional) Import sample data
sudo mariadb -u root pos_db < seed_sanitary.sql

# 5. Configure credentials
cp .env.example .env
# Edit .env with your database credentials

# 6. Start PHP dev server
php -S localhost:8000

# 7. Open http://localhost:8000/ and complete the setup wizard
```

### Deploy to Vercel

1. Push this repo to GitHub
2. Connect the repo to Vercel
3. Set environment variables in Vercel dashboard:
   - `POS_DB_HOST` — Your database host (e.g., PlanetScale endpoint)
   - `POS_DB_PORT` — `3306`
   - `POS_DB_USER` — Database username
   - `POS_DB_PASS` — Database password
   - `POS_DB_NAME` — `pos_db`
   - `POS_BASE_URL` — `/pos` (or `/` if deploying at root)
   - `POS_DB_SESSIONS` — `1`
4. Deploy — Vercel will detect the PHP runtime automatically
5. Visit your deployment URL and complete the setup wizard

**Supported database providers for Vercel:**
- PlanetScale (MySQL-compatible, recommended)
- Neon (MySQL-compatible)
- Any MySQL-compatible serverless database

### Traditional Server (Nginx + PHP-FPM + MariaDB)

See [DEPLOYMENT.md](DEPLOYMENT.md) for the full production deployment guide.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `POS_DB_HOST` | `localhost` | Database host |
| `POS_DB_PORT` | `3306` | Database port |
| `POS_DB_USER` | `saffron_app` | Database username |
| `POS_DB_PASS` | *(required)* | Database password |
| `POS_DB_NAME` | `pos_db` | Database name |
| `POS_BASE_URL` | `/pos` | Base URL path |
| `POS_DB_SESSIONS` | *(empty)* | Set to `1` for DB-backed sessions (required on Vercel) |

## Project Structure

```
pos/
├── includes/
│   ├── config.php       — DB connection, settings, all helper functions
│   ├── auth.php         — Session management, requireLogin(), isAdmin()
│   ├── header.php       — HTML head, sidebar navigation
│   └── footer.php       — Closing HTML tags
├── pages/
│   ├── pos.php          — POS terminal (748 lines)
│   ├── checkout.php     — AJAX checkout endpoint
│   ├── products.php     — Product management
│   ├── sales.php        — Sales history + refunds
│   ├── customers.php    — Customer management
│   ├── quotations.php   — Quotation management
│   ├── orders.php       — Purchase orders
│   ├── reports.php      — Business reports
│   ├── data_management.php — Backup/restore, CSV import/export
│   └── ...              — 18 feature modules total
├── css/style.css        — Dark industrial theme
├── js/                  — main.js, pie-chart.js, jspdf.umd.min.js
├── index.php            — Dashboard
├── login.php            — Login with rate limiting
├── setup.php            — First-run setup wizard
├── schema.sql           — Complete database schema (single file)
├── seed_sanitary.sql    — Sample data (68 products, 15 brands)
├── vercel.json          — Vercel deployment config
├── composer.json        — PHP runtime detection for Vercel
├── APPLICATION.md       — Complete application documentation (1679 lines)
└── DEPLOYMENT.md        — Production deployment guide
```

## Documentation

- **[APPLICATION.md](APPLICATION.md)** — Complete application documentation (1679 lines): architecture, database schema, all features, security model, helper functions
- **[DEPLOYMENT.md](DEPLOYMENT.md)** — Production deployment guide for Nginx + PHP-FPM + MariaDB

## License

Private — All rights reserved.
