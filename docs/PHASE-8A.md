# Phase 8A — E-commerce Platform Management

Phase 8A adds the **platform master**: the sales channels the business uses — marketplaces, online stores, social commerce, B2B portals, the business's own website and offline channels. It includes:
- an e-commerce dashboard built from the platform master only (no sales figures);
- unlimited platforms with code, logo, category, website, country, currencies, languages and display order;
- editable platform categories;
- configuration: currencies, languages, time zone, date and number formats, reference prefix;
- a capability register (product listing, SKU mapping, inventory / order / settlement / return sync, API, webhooks);
- platform-specific key–value settings (credentials refused);
- dated GST, business-activity, partner and warehouse mappings and a default business currency;
- controlled activation, deactivation, archiving and restoring with status history;
- private documents, notes with visibility, and an insert-only history;
- CSV / Excel / PDF export, 4 navigation pages, a 9-tab platform page and 10 help guides with screenshots.

It builds on Phases 1–7 and replaces nothing. Permissions, record scope, field rules, resource assignments and the audit log are the existing Phase 2 systems; no second permission engine was added. GST registrations (Phase 4), business activities, partners (Phase 3) and warehouses (Phase 7) are reused through the existing dated-mapping service, never duplicated.

**Scope boundary.** Phase 8A does **not** include seller accounts, API credentials, marketplace integrations, product sync, inventory sync, order sync, webhooks, fees, sales, returns or settlements. No marketplace password or API key is stored anywhere — platform settings refuse keys that look like credentials. Phase 8B is not started.

## Updating from Phase 7

```bash
git pull
composer install                 # refreshes the autoloader (new classes)
php bin/console migrate          # 1 new migration: 000034 (11 e-commerce platform tables)
php bin/console db:seed          # platform permissions, 7 categories, role defaults, 10 help guides
php bin/console migrate:status   # every migration should show "Ran"
```

**XAMPP:** run the same commands in the XAMPP Shell from the project folder. `php bin/console migrate --pretend` prints the MySQL SQL first. The migration has:
- 11 tables;
- 36 foreign keys (all `RESTRICT` or `SET NULL` — nothing cascades a delete);
- identifiers of at most 60 characters;
- rollback support.

**What the seeder does on an existing install**
- **Module.** The Phase 2 placeholder module key `platforms` is kept (records and the *Sales manager* template already reference it). Its placeholder actions and fields are replaced by 21 actions:
  - the 17 from the specification: `view_dashboard, view, create, edit, activate, deactivate, archive, manage_categories, manage_configurations, manage_gst_mappings, manage_business_mappings, manage_warehouse_mappings, manage_documents, download_documents, manage_notes, view_history, export`;
  - `view_all`, `view_own`, `view_assigned` (record scope) and `print`.
- **Field rules.** `internal_notes` and `partner_mappings` (sensitive, hidden by default) and `website_url`.
- **Masters.** 7 editable categories: Marketplace, E-commerce store, Social commerce, B2B platform, Own website, Offline channel, Other.
- **Default grants**, applied once (`ecommerce_defaults_released`); later changes by a Super Admin are kept.
  - **Accountant:** dashboard, view all platforms, download documents, history, export, print. Read-only.
  - **Manager:** create and edit platforms, configuration, warehouse mappings, documents, notes, history, export. Cannot activate, deactivate, archive, or manage GST, business or partner mappings.
  - **Super Admin** has everything; **Viewer** and **Partner** get nothing.
- **Data.** Creates **no** platforms.

## The platform master

| Field | Notes |
|---|---|
| Platform ID / UUID | Auto-increment id plus a UUID for later seller accounts, integrations, orders and settlements |
| Name | Required, unique (case-insensitive) |
| Code | Optional; generated from the name (`AMAZONINDIA`, `AMAZONINDIA-2` …). `[A-Z0-9][A-Z0-9_-]{1,29}`, unique. Locked once product prices use it as their sales channel |
| Logo | PNG / JPG / WebP / GIF up to 2 MB, content-checked, stored privately, served through the platform scope |
| Category | From the editable category list (must be active) |
| Website | `http(s)://` only (no `javascript:` etc.) |
| Description, internal notes | Internal notes are a sensitive field |
| Country | ISO code, plus *Global* |
| Supported currencies / default currency | Default must be supported |
| Languages, time zone, date format, number format | Configuration tab |
| Reference prefix | Up to 12 characters, unique |
| Default business currency | Business mappings tab |
| Status | `under_configuration` → `active` ↔ `inactive`; any → `archived`; archived → restore as `inactive` |
| Connection status | `not_connected` until a real integration (Phase 8C) writes it |
| Display order, audit columns | `created_by / updated_by / archived_by` and timestamps |

**Activation** requires the configuration checklist's required items (category, country, a default currency that is supported, at least one language). Recommended items (website, GST registration, warehouse, reference prefix) feed the configuration percentage on the list and dashboard.

**Deactivation** needs a reason and deletes nothing. Inactive platforms can still be configured.

**Archiving** needs a reason. Active GST / business / partner / warehouse mappings, documents and product prices that use the platform code as their channel are listed as warnings and must be confirmed. A hard-blocker hook is ready for seller accounts (8B) and transactions (9+). An archived platform is read-only (edits, configuration, mappings, documents and notes are refused) and is hidden from the list unless *Archived* is chosen. **Restore** returns it as *Inactive*. Platforms are **never deleted**.

## Configuration and capabilities

Each platform gets 8 capability rows: product listing, SKU mapping, inventory sync, order sync, settlement sync, return sync, API integration, webhooks. For each, record whether the **platform offers it** (not known / offered / not offered) and its **ERP status** (not available / planned / operational).

`operational` is accepted only for keys in `PlatformService::IMPLEMENTED`, which is **empty** in Phase 8A — nothing is claimed that does not exist. The phase that will implement each capability is shown.

Platform-specific settings are free key–value pairs (`[a-z][a-z0-9_.]{1,59}`, value up to 500 characters). Keys containing *secret, password, token, api_key, private_key* are refused. Saving an empty value removes a setting.

## Mappings

| Tab | Target (existing records) | Permission |
|---|---|---|
| GST mappings | Phase 4 GST registrations you can see | `manage_gst_mappings` |
| Business mappings | Business activities; partners (Phase 3, sensitive field); default business currency | `manage_business_mappings` |
| Warehouse mappings | Phase 7 warehouses you can see (needs Inventory access) | `manage_warehouse_mappings` |

All mappings are dated (from / to), ended with a reason, kept as history, and logged. Several registrations or warehouses can be active for one platform, and one registration or warehouse can serve several platforms. They are **defaults or permitted scopes, not ownership** — no profit-sharing percentage or partner right is derived from them.

## Documents, notes and history

- **Documents:** types agreement, seller policy, commission, fee schedule, integration documentation, other. Title, type and file required; description optional. Stored under `storage/uploads/platform-documents` with random names and SHA-256, validated by content and size (10 MB). Served only after the permission check **and** the platform scope; a document id requested through another platform returns 404. Uploads, views, downloads and archiving are written to the platform history and the activity log.
- **Notes:** title, text and visibility — *everyone* who can see the platform, *note managers* (`manage_notes`), or *private* (author and Super Admins). Edited or archived by the author. The history stores a restricted note's visibility, not its text.
- **History:** `ecommerce_platform_history` is insert-only: created, updated, configuration / capability / setting changes, logo, status changes (with from / to), archive / restore, mapping added / ended, business currency, document uploaded / viewed / downloaded / archived, notes. The platform page shows it with the audit-log entries; **E-commerce → Platform History** lists it across visible platforms. Partner-mapping details are hidden from users without that field.

## Record scope

The existing Phase 2 data scope of module `platforms` applies everywhere: list, dashboard, export, history, the platform page and every action. `view_all` sees everything; `view_own` sees platforms they created; `view_assigned` sees platforms assigned to them through **resource assignments** (resource type `platform`) or through their assigned GST registrations. A platform outside the scope returns **404**.

## Pages

| Page | Route |
|---|---|
| E-commerce dashboard | `/ecommerce` |
| Platform list (filters, CSV / Excel / PDF export) | `/ecommerce/platforms` |
| Add / edit platform | `/ecommerce/platforms/create`, `/ecommerce/platforms/{id}/edit` |
| Platform page — 9 tabs: Overview, Configuration, GST mappings, Business mappings, Warehouse mappings, Seller accounts (Phase 8B placeholder), Documents, Notes, History | `/ecommerce/platforms/{id}?tab=…` |
| Platform categories (and the capability register) | `/ecommerce/categories` |
| Platform history | `/ecommerce/history` |

The sidebar has a new **E-commerce** section (Dashboard, Platforms, Platform Categories, Platform History); the "E-commerce Platforms" upcoming-module placeholder was removed. `released_phase` is 8 and the version `1.8.0-phase8a`.

## Help & User Guide

New category **Phase 8A – E-commerce Platform Management** with 10 guides, each with real annotated screenshots from demo data:
E-commerce dashboard · The platform master · Adding and editing platforms · Managing platform categories · Platform configuration and capabilities · GST mappings · Business mappings · Warehouse mappings · Platform documents and notes · Platform history.

Guides are readable by every signed-in user regardless of module permissions (checked with a viewer account that has no platform access); only `documentation.*` holders can manage them.

## Integration readiness (Phase 8B onward)

- Seller accounts (8B) reference `ecommerce_platforms.id` / `uuid`; their encrypted credentials will use the existing Phase 5 `APP_KEY` encryption. The **Seller accounts** tab is a placeholder.
- `archiveCheck()` has a `blockers` list ready for live seller accounts and transactions.
- `connection_status` / `connection_checked_at` are written only by a future integration.
- `IMPLEMENTED` capabilities are added as the matching features ship and are tested.
- `reference_prefix` is ready for order and settlement references.

## Testing

`php tests/run.php` → **216 passed, 0 failed, 4,000 assertions** (SQLite, PHP 8.3 sandbox).

New tests (mapped to the specification's test list):
- **`tests/Integration/EcommercePlatformTest.php`** (9 tests):
  1. multiple platforms, code generation, uniqueness, URL / country / code validation, capabilities, audit and history;
  2. custom categories, renaming, protection while in use, inactive categories refused;
  3. configuration (currencies, default currency, languages, time zone, prefix uniqueness), capabilities (operational refused), settings (credentials refused), no-op edits write no history;
  4. activation (checklist enforced), deactivation (reason), status history from / to;
  5–6. archive with warnings and confirmation, reason required, already archived, read-only after archiving, hidden from the list, restore;
  7–9. GST (several active, non-exclusive, end with reason), warehouse, business-activity and partner mappings, no profit sharing, business currency, history events;
  10–11. document validation (content, extension, type, title), private storage, download logging, archiving; logo images only;
  12. notes visibility (everyone / managers / private), author-only edits, no private text in history;
  14. record scope (assigned platforms only, 404 outside, viewer sees none).
- **`tests/Feature/EcommerceSecurityTest.php`** (3 HTTP tests):
  - every page and every tab renders for a Super Admin without PHP notices or inline styles; CSV and Excel export with audit; add, configure, activate and upload through the forms (15);
  - viewer gets 403 everywhere; accountant is read-only (403 on create / status / archive / mappings / categories), cannot see internal notes or private notes, also in exports; manager cannot activate or map GST / partners; CSRF enforced (13);
  - an assigned-platform user sees only their platform in list, dashboard, export and history; the other platform, its logo, documents and edits return 404; a document requested through another platform is 404; files are not in `public/` (11, 14).
- Existing tests updated: migration table lists and the new release flag, help categories (8) and guide counts (≥ 112, 10 in Phase 8A, all with screenshots) (16).

A crawl of all e-commerce pages on demo data as five users found no server errors and no PHP notices: admin opened 144 pages, an accountant 124, a manager 134; the viewer and the partner login got 403 on every e-commerce page.

## Known limits (honest list)

- **MySQL:** tests run on SQLite here. The MySQL DDL was generated and checked (`migrate --pretend`: 11 tables, 36 foreign keys, identifiers ≤ 60 characters) but not executed against a MySQL server in this sandbox.
- **No integrations:** nothing connects to a marketplace; connection status is always *Not connected* and no capability can be operational.
- **Existing field-rule changes:** on installs where a Super Admin already changed the Phase 2 placeholder fields of the `platforms` module, those placeholder fields are removed (they belonged to seller accounts, which arrive in 8B).
- **Charts:** the dashboard uses lists and bars, no Chart.js charts.
