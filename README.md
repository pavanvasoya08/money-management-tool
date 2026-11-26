# Business Finance & E-commerce ERP

A secure, modular ERP for multi-partner e-commerce businesses, built on a lightweight custom PHP MVC framework (no Laravel/WordPress).

**Current status: Phase 7 of 15 — inventory management.**

| | |
|---|---|
| Language | PHP **8.5+** (strict types everywhere) |
| Database | MySQL **8.0+** via PDO (prepared statements only) |
| Frontend | HTML5, CSS3, vanilla JavaScript, Bootstrap 5.3, Lucide icons, Chart.js 4 |
| Tooling | Composer (autoloading + platform checks). **No third-party runtime packages.** |

- 📘 [Installation guide](docs/INSTALLATION.md) — requirements, MySQL, `.env`, migrations, Super Admin, local dev, production
- ✅ [Phase 1 report](docs/PHASE-1.md) — completed features, test results, PHP 8.5 verification
- ✅ [Phase 2 report](docs/PHASE-2.md) — Control Center features, security test results
- ✅ [Phase 3 report](docs/PHASE-3.md) — partners, capital ledger, profit sharing, reports, test results
- ✅ [Phase 4 report](docs/PHASE-4.md) — update steps, GST registrations, GSTIN validation, mappings, documents, approvals, reports, test results
- ✅ [Phase 5 report](docs/PHASE-5.md) — **update steps (incl. `key:generate`)**, bank accounts, ledger, transfers, statements, CSV import, reconciliation, reports, test results
- ✅ [Phase 7 report](docs/PHASE-7.md) — **update steps**, warehouses, locations, stock ledger, opening stock, inward/outward, transfers, adjustments, batches, serials, reservations, FIFO / weighted average, reports, test results
- ✅ [Phase 6 report](docs/PHASE-6.md) — **update steps**, products, variants, SKUs, pricing history, GST/HSN, suppliers, supplier bank details, product-supplier mapping, reports, test results
- 🏛️ [Architecture](docs/ARCHITECTURE.md) — folder layout, request lifecycle, authorization model, how to add a module

## Quick start (local)

```bash
composer install
cp .env.example .env            # set DB_* and APP_ENV=local, APP_DEBUG=true, SESSION_SECURE_COOKIE=false
php bin/console key:generate    # writes APP_KEY (encrypts bank account numbers; back it up, never change it)
php bin/console app:check       # verifies PHP version, extensions, permissions, DB, APP_KEY
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

## What Phase 2 adds

- **Super Admin Control Center** (`/admin`, never delegable): 14 sections covering users, roles, module and action permissions, data and field visibility, resource assignments, approval workflows, templates, access preview, sessions, audit and security settings.
- **Users**: suspend, expiry date, IP and sign-in-hour restrictions, force logout, soft delete and restore, and an 8-tab profile.
- **Roles**: unlimited custom roles; duplicate, copy, and delete safely with user reassignment.
- **Permissions**: 21 modules × module-specific actions in a searchable matrix, plus explicit per-user Allow / Deny / Inherit with expiry.
- **Data access**: 9 record-level scopes applied in SQL; unauthorized IDs return 404.
- **Field access**: hidden / masked / visible / read-only / editable, enforced in pages, CSV export and the JSON API.
- **Approval engine**: multi-level, amount-based, sequential, with self-approval blocked and history kept.
- **Structured audit**: old and new values, affected user and role, IP and device; filterable.

## What Phase 3 adds

- **Partners:** unlimited partners with unique IDs, profiles (11 tabs), PAN validation, suspend/archive, private documents and 1:1 login linking.
- **Capital register:** contributions, withdrawals and adjustments as separate entries, going draft → approval (Phase 2 engine) → posting. Posted entries are corrected only by reversal.
- **Capital ledger:** debit/credit lines with running, opening and closing balances from posted entries only, in exact `DECIMAL` / integer-paise maths. Withdrawals are limited to eligible capital.
- **Profit sharing:** effective-dated, 100%-validated, non-overlapping and history-preserving, and independent of capital.
- **Reports:** 8 partner reports with CSV, Excel and PDF export, plus dashboard widgets. All respect record and field permissions.
- **Accounting foundation:** an idempotent posting queue ready for Phase 12.

## What Phase 4 adds

- **GST registrations:** an unlimited register with server-side GSTIN validation (state code, PAN/TAN segment, entity number, check character), duplicate protection and an official state master.
- **Profile (8 tabs):** details, partner associations (many-to-many with dates and history), business activities, principal and additional places of business, secure documents, per-registration settings and history.
- **Lifecycle:** pending verification, active, inactive, suspended, cancelled; archive instead of delete. Approval workflows for new registrations, key-detail changes and cancellations.
- **Dashboard and 7 reports** with CSV, Excel and PDF export, all limited by record scope and field rules.
- No tax calculation, filing or GST portal connection; a valid format is never presented as an official verification.

## What Phase 5 adds

- **Bank accounts:** unlimited accounts with encrypted, masked account numbers (full number only with its own permission, every reveal logged), duplicate detection per IFSC, statuses from draft to closed, and partner, GST and business-activity mappings with dates and history.
- **Central joint funding account:** one designated account, changed through a controlled process, showing partner contributions received (linked to Phase 3 capital), transfers out and the available balance.
- **Ledger:** deposits, withdrawals, bank charges, interest and adjustments in exact decimals. Balances are calculated from posted entries only, posting is locked and never duplicated, negative balances are refused unless an overdraft is configured, and posted entries are corrected only by reversal.
- **Internal transfers:** both sides posted together under one reference, with approval and two-sided reversal; never counted as income or expense.
- **Statements and reconciliation:** statement upload, CSV import with column mapping, preview and duplicate detection (no entries are created), and reconciliation with suggestions, manual matching, history and approved reopening.
- **Dashboard and 11 reports** with CSV, Excel and PDF export, all limited by record scope and field rules.
- No bank API and no automatic bank synchronisation. Banking passwords, PINs, OTPs and card credentials are never stored.

## What Phase 6 adds

- **Product catalogue:** unlimited products of any type with nested categories, brands, variant attributes and variants. Each product and variant has its own SKU (generated from configurable patterns, unique and never reused), a GS1-checked barcode, images and documents. There is no delete: products are archived and can be restored.
- **Pricing with history:** standard, minimum, maximum, wholesale, marketplace, purchase and supplier prices with effective dates. New prices never overwrite old ones, and any date's price can be looked up.
- **GST / HSN:** dated HSN/SAC, rate, tax category and applicability per product, variant or GST registration. Rate, category and HSN/SAC masters are editable, including the retired 12 % and 28 % slabs kept for history. Codes are checked for format only; there is no tax filing.
- **Suppliers:** GSTIN/PAN format checks with duplicate rules, contacts, addresses, payment terms, documents and statuses. Supplier bank details are encrypted, masked, approved before use and kept separate from company bank accounts.
- **Product–supplier mapping:** many-to-many, optionally per variant, with supplier codes, order quantities, lead times, purchase prices and an explicitly chosen preferred supplier.
- **Payment references:** a supplier can be linked to existing posted bank withdrawals. Nothing is posted.
- **GST-registration and business-activity mappings** for products and suppliers.
- **Two dashboards and 18 reports** with CSV, Excel and PDF export. Everything is limited by record scope and field rules, and 11 optional approval workflows cover sensitive changes.

## What Phase 7 adds

- **Warehouses and storage locations:** unlimited warehouses of editable types with GST-registration and business-activity mappings, assigned users, and safe deactivate, archive and delete rules. Locations nest up to six levels, and circular nesting is refused.
- **Immutable stock ledger:** every quantity and value comes from posted movements.
  - Posting is transactional and row-locked, and duplicates are refused through unique posting keys.
  - Corrections are reversals or adjustments.
  - Balance summaries can be reconciled with the ledger.
- **Stock documents:** opening stock, inward (purchase receipts, customer returns with condition) and outward (dispatches, supplier returns, write-offs, consumption). All go through optional approval and support unit conversion.
- **Transfers:** in-transit stock, partial receipts and loss write-offs, plus location moves.
- **Adjustments:** physical counts with recount protection and condition changes.
- **Batches, expiry and serials:** batches with expiry and FEFO picking, unique serial numbers with full history.
- **Reservations:** stock held for orders or requirements, kept out of available stock.
- **Valuation:** FIFO cost layers or weighted average cost in exact decimals, with revaluation through approval. No journal entries.
- **Monitoring and reports:** low-stock monitoring with suggested reorder quantities and preferred suppliers, a dashboard and 20 reports with export.
- **Access control:** warehouse record scope, cost and contact field rules, and 10 optional approval workflows.

## Help & User Guide

An in-app manual for Phases 1–7 (104 guides, annotated screenshots, search, FAQs) is available to every signed-in user under **Help & User Guide**. Managing it uses the `documentation.*` permissions. See [docs/HELP-GUIDE.md](docs/HELP-GUIDE.md).

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

1. Project setup & authentication ✅
2. Super Admin complete control system ✅
3. **Partner management & capital** ✅ ← current
4. GST registrations · 5. Bank accounts · 6. Products & suppliers · 7. Inventory · 8. E-commerce platforms · 9. Sales & orders · 10. Returns, refunds & settlements · 11. Expenses & purchases · 12. Accounting · 13. GST & tax · 14. Dashboard & financial reports · 15. Automation, audit & deployment
