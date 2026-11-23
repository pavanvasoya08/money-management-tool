# Business Finance & E-commerce ERP

A secure, modular ERP for multi-partner e-commerce businesses, built on a lightweight custom PHP MVC framework (no Laravel/WordPress).

**Current status: Phase 1 of 15 — Project foundation & authentication.**

| | |
|---|---|
| Language | PHP **8.5+** (strict types everywhere) |
| Database | MySQL **8.0+** via PDO (prepared statements only) |
| Frontend | HTML5, CSS3, vanilla JavaScript, Bootstrap 5.3, Lucide icons, Chart.js 4 |
| Tooling | Composer (autoloading + platform checks). **No third-party runtime packages.** |

- 📘 [Installation guide](docs/INSTALLATION.md) — requirements, MySQL, `.env`, migrations, Super Admin, local dev, production
- ✅ [Phase 1 report](docs/PHASE-1.md) — completed features, test results, PHP 8.5 verification, pending Phase 2 work
- 🏛️ [Architecture](docs/ARCHITECTURE.md) — folder layout, request lifecycle, authorization model, how to add a module

## Quick start (local)

```bash
composer install
cp .env.example .env            # set DB_* and APP_ENV=local, APP_DEBUG=true, SESSION_SECURE_COOKIE=false
php bin/console app:check       # verifies PHP version, extensions, permissions, DB
php bin/console migrate
php bin/console db:seed
php bin/console admin:create    # creates the first Super Admin (password typed hidden)
php -S 127.0.0.1:8000 -t public server.php
```

Open http://127.0.0.1:8000 and sign in.

## What Phase 1 delivers

- **Authentication** — login, logout, forgot/reset password (single-use, expiring, hashed tokens), change password, secure remember-me (rotating split tokens), idle & absolute session timeouts, failed-login tracking, temporary account lockout, per-IP throttling, password strength policy.
- **Users & roles** — create, edit, activate/deactivate, assign multiple roles, admin password reset (forces change at next sign-in), login history. Five initial roles (Super Admin, Partner, Accountant, Manager, Viewer); the schema supports unlimited custom roles.
- **Centralized authorization** — one `Gate` decides every permission (`module.action`), with role grants, per-user allow/deny overrides (optionally expiring), record-level data scopes and GST/bank/platform/partner constraints ready for Phase 2. Controllers never check role names.
- **Audit trail** — append-only activity log of sign-ins, failures, lockouts, user/role/status/password changes, settings changes and denied access, with IP and user agent.
- **Admin dashboard** — responsive ERP shell with sidebar, breadcrumbs, notifications, profile menu, and real system data only (users, sign-ins, activity). Future modules appear as disabled “Soon” items.
- **Settings** — app name, company, timezone, date format, page size, lockout, password length, idle timeout, remember-me and reset-link durations.
- **Security** — CSRF on every state-changing request, output escaping, strict CSP, secure cookies, session fixation protection, production-safe errors with reference IDs, secrets redacted from logs.

## Commands

```bash
php bin/console help
php bin/console migrate [--pretend]     # --pretend prints the MySQL DDL without running it
php bin/console migrate:status
php bin/console migrate:rollback [--step=N]
php bin/console db:seed
php bin/console admin:create [--name="…" --email=… --generate-password]
php bin/console app:check
php tests/run.php [filter]              # automated tests (needs pdo_sqlite + curl, no MySQL)
```

## Roadmap

1. **Project setup & authentication** ← current
2. Super Admin complete control system (role/permission UI, overrides, data & field-level access)
3. Partner management · 4. GST registrations · 5. Bank accounts · 6. Products & suppliers · 7. Inventory · 8. E-commerce platforms · 9. Sales & orders · 10. Returns, refunds & settlements · 11. Expenses & purchases · 12. Accounting · 13. GST & tax · 14. Dashboard & financial reports · 15. Automation, audit & deployment
