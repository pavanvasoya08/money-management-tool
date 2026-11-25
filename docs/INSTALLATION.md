# Installation guide

## 1. Requirements

### PHP 8.5 or newer

`composer.json` requires `"php": ">=8.5"` and sets `config.platform.php = 8.5.0`, so Composer refuses to install on older PHP and the generated autoloader stops the app at runtime if it runs on an older version. `php bin/console app:check` reports the same.

**Required extensions**

| Extension | Why |
|---|---|
| `pdo`, `pdo_mysql` | Database access (prepared statements) |
| `mbstring` | Multibyte-safe string handling |
| `openssl` | Cryptographically secure randomness (tokens) |
| `json` | Activity-log properties, settings |
| `session` | Login sessions |
| `ctype`, `filter` | Input validation |

**Optional**

| Extension | Why |
|---|---|
| `sodium` (or PHP built with libargon2) | **Argon2id** password hashing. Without it bcrypt (cost 12) is used and passwords are limited to 72 bytes |
| `pdo_sqlite`, `curl` | Only for running the automated test suite |
| `opcache` | Performance in production |

Recommended `php.ini` (production):

```ini
display_errors = Off
log_errors = On
expose_php = Off
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_secure = 1
opcache.enable = 1
```

### MySQL 8.0+

```sql
CREATE DATABASE business_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'erp_user'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON business_erp.* TO 'erp_user'@'localhost';
FLUSH PRIVILEGES;
```

The application uses InnoDB, `utf8mb4_unicode_ci`, foreign keys and strict SQL mode (set per connection). All timestamps are stored in **UTC**; they are converted to the timezone chosen in Settings for display.

> **Audit hardening (optional, recommended):** the application never updates or deletes `activity_logs`. To enforce this in the database too, run migrations with a privileged account and give the runtime account only `SELECT, INSERT` on `activity_logs`.

### Composer 2.x

Composer is used for PSR-4 autoloading and platform checks. There are no third-party runtime packages.

## 2. Install

```bash
git clone https://github.com/pavanvasoya08/money-management-tool.git business-erp
cd business-erp
composer install --no-dev --optimize-autoloader      # production
# composer install                                   # development (adds test autoloading)
cp .env.example .env
```

## 3. Environment configuration (`.env`)

| Key | Example | Notes |
|---|---|---|
| `APP_NAME` | `Business ERP` | Default name (can be changed in Settings) |
| `APP_ENV` | `production` | `local`, `staging` or `production` |
| `APP_DEBUG` | `false` | Stack traces are only ever shown when `true` **and** `APP_ENV=local` |
| `APP_URL` | `https://erp.example.com` | Used to build password-reset links |
| `APP_TIMEZONE` | `Asia/Kolkata` | Default display timezone |
| `APP_KEY` | `base64:…` | Encrypts bank account numbers (Phase 5). Create it once with `php bin/console key:generate`. Back it up with the database and **never change it** once bank accounts exist |
| `TRUSTED_PROXIES` | `10.0.0.5` | Only these proxy IPs may set `X-Forwarded-For/Proto` |
| `DB_HOST` … `DB_PASSWORD` | | MySQL connection |
| `SESSION_NAME` | `erp_session` | Cookie name |
| `SESSION_LIFETIME` | `480` | Absolute session lifetime in minutes |
| `SESSION_SECURE_COOKIE` | `true` | Must be `true` in production (HTTPS). Use `false` only for local HTTP |
| `SESSION_SAME_SITE` | `Lax` | `Lax` or `Strict` |
| `MAIL_DRIVER` | `mail` | `log` writes emails to `storage/logs/mail.log` (development only — contains reset links); `mail` uses the server MTA |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | | Sender of password-reset emails |

Real environment variables always override values in `.env`, so secrets can be injected by the hosting platform instead of being stored in a file. Never commit `.env`.

## 4. Database migrations and seeders

```bash
php bin/console key:generate        # once: writes APP_KEY to .env (skipped if a key exists)
php bin/console migrate --pretend   # optional: print the MySQL DDL first
php bin/console migrate             # create all tables
php bin/console db:seed             # permissions, 5 roles, default settings
php bin/console migrate:status
```

- Seeders are **idempotent** — safe to re-run after upgrades; they never overwrite settings or grants an administrator changed.
- No user accounts or business data are seeded.
- `migrate:rollback [--step=N]` rolls back batches. `migrate:fresh` drops everything and is blocked in production unless `--force` is given.

## 5. Create the first Super Admin

```bash
php bin/console admin:create
# Full name: Rajesh Mehta
# Email: rajesh@yourfirm.com
# Password: (hidden)
# Confirm password: (hidden)
```

Non-interactive (e.g. in a deployment script) — a strong random password is generated, printed **once**, and must be changed at first sign-in:

```bash
php bin/console admin:create --name="Rajesh Mehta" --email=rajesh@yourfirm.com --generate-password
```

Super Admin accounts can only be created by this command (server access) or by another Super Admin in the UI. Ordinary users can never grant the Super Admin role.

## 6. Local development

```bash
# .env: APP_ENV=local, APP_DEBUG=true, SESSION_SECURE_COOKIE=false, MAIL_DRIVER=log
php -S 127.0.0.1:8000 -t public server.php
```

`server.php` is only a router for PHP's built-in server; never use the built-in server in production. Password-reset emails appear in `storage/logs/mail.log`.

Run the automated tests (uses a temporary SQLite database, no MySQL needed):

```bash
composer install          # include dev autoloading
php tests/run.php         # all suites
php tests/run.php Gate    # filter by class name
```

## 7. Production deployment

1. **Document root must be `/public`.** Nothing outside it should be web-accessible. (A root `.htaccess` and per-folder deny rules exist only as a safety net for Apache.)
2. **HTTPS only**, with `SESSION_SECURE_COOKIE=true`. The app sends HSTS when it detects HTTPS.
3. `APP_ENV=production`, `APP_DEBUG=false`.
4. File permissions (web server user `www-data` shown):
   ```bash
   chown -R deploy:www-data .
   find . -type d -exec chmod 750 {} \;
   find . -type f -exec chmod 640 {} \;
   chmod -R 770 storage
   chmod 600 .env && chown www-data .env      # or inject env vars instead
   chmod 750 bin/console
   ```
5. `composer install --no-dev --optimize-autoloader`, then `php bin/console migrate && php bin/console db:seed`.
6. Configure the MTA (`MAIL_DRIVER=mail`) so password-reset emails are delivered.
7. Log rotation for `storage/logs/*.log` (e.g. logrotate, daily, keep 30).
8. Clean expired session files: PHP's session GC handles `storage/sessions`; on Debian/Ubuntu (where GC is disabled by default) add a cron job:
   `find /path/to/storage/sessions -type f -mmin +480 -delete`
9. Bootstrap, Chart.js and Lucide load from jsDelivr/unpkg (whitelisted in the Content-Security-Policy). For stricter environments, download them into `public/assets/vendor/`, update `resources/views/partials/head.php` + `scripts.php`, and remove the CDN hosts from `App\Middleware\SecurityHeaders::CDN`.
10. Run `php bin/console app:check` after every deployment.

### Nginx example

```nginx
server {
    listen 443 ssl http2;
    server_name erp.example.com;
    root /var/www/business-erp/public;
    index index.php;

    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }
    location ~ /\. { deny all; }
    client_max_body_size 10m;
}
```

### Apache

Point `DocumentRoot` at `/public` and enable `mod_rewrite`; `public/.htaccess` routes requests to `index.php`.
