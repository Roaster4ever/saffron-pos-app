# Saffron POS — Production Deployment Guide

## Prerequisites

- Ubuntu/Debian Linux with root or sudo access
- Nginx
- PHP 8.1+ with PHP-FPM and `mysqli` extension
- MariaDB 10.5+ or MySQL 8+

## Step 1: Install Dependencies

```bash
# Ubuntu/Debian
sudo apt update
sudo apt install -y nginx php8.1-fpm php8.1-mysql mariadb-server

# Enable services
sudo systemctl enable nginx php8.1-fpm mariadb
```

## Step 2: Create Database and User

```bash
sudo mariadb -u root << 'SQL'
CREATE DATABASE pos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'saffron_app'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON pos_db.* TO 'saffron_app'@'localhost';
FLUSH PRIVILEGES;
SQL
```

> **IMPORTANT:** Replace `CHANGE_THIS_PASSWORD` with a strong random password.

## Step 3: Deploy Application Files

```bash
# Copy application to /opt/pos (or your preferred location)
sudo mkdir -p /opt/pos
sudo cp -r /path/to/saffron-pos/* /opt/pos/
sudo chown -R www-data:www-data /opt/pos
sudo chmod -R 755 /opt/pos

# Ensure config.php is not world-readable
sudo chmod 640 /opt/pos/includes/config.php
```

## Step 4: Configure Database Credentials

Edit `/opt/pos/includes/config.php` — update the credential section:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'saffron_app');
define('DB_PASS', 'CHANGE_THIS_PASSWORD');
define('DB_NAME', 'pos_db');
```

Or use environment variables:
```bash
export POS_DB_HOST=localhost
export POS_DB_USER=saffron_app
export POS_DB_PASS=CHANGE_THIS_PASSWORD
export POS_DB_NAME=pos_db
```

## Step 5: Import Schema

```bash
sudo mariadb -u root pos_db < /opt/pos/schema.sql
```

> If you have existing migration files, run them in order:
> ```bash
> sudo mariadb -u root pos_db < /opt/pos/migration_phase1.sql
> sudo mariadb -u root pos_db < /opt/pos/seed_sanitary.sql  # optional
> ```

## Step 6: Configure Nginx

Create `/etc/nginx/conf.d/pos.conf`:

```nginx
server {
    listen 80;
    server_name your-domain.com;

    root /opt/pos;
    index index.php index.html;

    # Security headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Block hidden files
    location ~ /\. { deny all; access_log off; log_not_found off; }

    # Block backup files
    location ~* \.(sql|gz|bak|old|orig|save)$ { deny all; }

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

### HTTPS (Recommended for Production)

For HTTPS, add a certificate (Let's Encrypt or your own):

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

Then add to the server block:
```nginx
listen 443 ssl http2;
ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

## Step 7: Configure PHP-FPM

Ensure PHP-FPM has appropriate settings in `/etc/php/8.1/fpm/php.ini`:

```ini
display_errors = Off
log_errors = On
error_log = /var/log/pos_errors.log
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.1-fpm
```

## Step 8: Set Up Log Rotation

```bash
sudo tee /etc/logrotate.d/pos << 'EOF'
/var/log/pos_errors.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
}
EOF
```

## Step 9: Initialize the Application

1. Open `http://your-domain.com/pos/` in a browser
2. You will be redirected to the **First-Time Setup** page
3. Enter your shop details and create an admin account
4. The default admin account will be removed during setup

## Step 10: Create Backup Schedule

```bash
# Add to crontab for daily backups at 2 AM
sudo crontab -e
```

Add:
```
0 2 * * * mariadb-dump -u root pos_db | gzip > /opt/backups/pos-$(date +\%Y-\%m-\%d).sql.gz
find /opt/backups/ -name "*.sql.gz" -mtime +30 -delete
```

## Step 11: Verify Installation

```bash
# Test database connection
mariadb -u saffron_app -p pos_db -e "SELECT COUNT(*) FROM products"

# Test Nginx
curl -I http://localhost/pos/login.php

# Check for errors
sudo tail -20 /var/log/pos_errors.log
```

## Restore Procedure

If you need to restore from backup:

```bash
# 1. Create safety backup of current state
mariadb-dump -u root pos_db | gzip > /tmp/pre-restore-$(date +%s).sql.gz

# 2. Restore
gunzip -c /path/to/backup.sql.gz | mariadb -u root pos_db

# 3. Verify
mariadb -u root pos_db -e "SELECT COUNT(*) FROM products"
```

Or use the **Data & Backup** page in the admin panel (Admin only).

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Blank page | Check `/var/log/pos_errors.log` |
| Database connection failed | Verify MariaDB is running and credentials are correct |
| 503 errors | Check PHP-FPM is running: `systemctl status php8.1-fpm` |
| Permission errors | `chown -R www-data:www-data /opt/pos` |
| Session not persisting | Check PHP session config and `session.cookie_httponly` |
