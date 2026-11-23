# Architecture

## Folder layout

```
app/
  Core/          Framework kernel: App (service registry), Router, Request/Response, Session,
                 View, Validator, Database (PDO), Schema builder, Migrator, Seeder, ErrorHandler, Logger
  Controllers/   Thin HTTP controllers (validate → authorize → call service → respond)
  Models/        Data access per table (no ORM magic; prepared statements only)
  Services/      Business rules: AuthService, UserService, PasswordResetService, RememberMeService,
                 PasswordPolicy/Hasher, SettingsService, ActivityLogger, Mailer
    Authorization/  Gate, Grant, DataScope — the single place where access is decided
  Middleware/    SecurityHeaders, StartSession, VerifyCsrfToken, Authenticate, Authorize, …
  Helpers/       functions.php (e(), route(), can(), csrf_field(), format_datetime(), …)
  Exceptions/    HttpException, AuthorizationException, ValidationException, DomainRuleException, …
  Console/       CLI kernel (bin/console)
config/          app, auth, database, session, mail, middleware, navigation, permissions
database/
  migrations/    One file per table (Schema builder → MySQL 8 DDL)
  seeders/       Permissions, roles, default settings (idempotent)
public/          index.php (front controller), assets/, .htaccess — the ONLY web root
resources/views/ Plain-PHP templates (layouts, partials, pages)
routes/web.php   Route table with middleware
storage/         logs/, sessions/, cache/, uploads/ (writable, never web-served)
tests/           Dependency-free runner: Unit, Integration (SQLite), Feature (real HTTP)
```

## Request lifecycle

```
public/index.php
  → App::boot()           .env + config, UTC clock, error handler
  → Router::dispatch()
      global middleware:  SecurityHeaders → StartSession → VerifyCsrfToken
      route middleware:   guest | auth → can:<permission>
      controller action:  validate() → authorize() → Service → view()/redirect
  → ErrorHandler          ValidationException → redirect back with errors/old input
                          AuthorizationException → 403 (logged to audit)
                          DomainRuleException → redirect back with message
                          anything else → logged with reference ID, generic 500
```

## Authorization model (built for Phase 2)

Permissions are `module.action` strings stored in `permissions` (e.g. `users.create`, later `sales.export`).

Effective access for a user is resolved by `App\Services\Authorization\Gate`:

1. Inactive user → nothing.
2. Any active role with `is_super_admin = 1` → everything.
3. Union of `role_permissions` from all active roles. When several roles grant the same permission, the **widest** `data_scope` wins (`all` > `selected` > `assigned` > `own`) and `constraints` are merged.
4. `user_permission_overrides`: `deny` removes a permission, `allow` adds or replaces it; expired overrides are ignored.
5. Optional resource **policy** for record-level rules (`Gate::define('users.edit', fn ($user, $record, $grant) => …)`).

The four levels requested for Phase 2 map onto this design:

| Level | Where it lives |
|---|---|
| Module access | permission `module.view` (sidebar items declare their permission in `config/navigation.php`) |
| Action access | `module.create/edit/delete/approve/export` |
| Data access | `role_permissions.data_scope` / `user_permission_overrides.data_scope` → `Gate::dataScope()` |
| GST / bank / platform / partner restrictions | `constraints` JSON, e.g. `{"gst_registration_ids":[1,2],"bank_account_ids":[3]}` → `Gate::constraints()` |
| Field access | Phase 2 adds a `field_permissions` table and `Gate::fieldAccess()` (visible / hidden / masked / read-only / editable) |

Usage everywhere:

```php
// routes/web.php
$r->get('/sales', [SalesController::class, 'index'], 'sales.index', ['can:sales.view']);

// controller
$this->authorize('sales.edit', $order);

// views
<?php if (can('sales.export')): ?> … <?php endif; ?>

// queries (Phase 2+)
$scope = App::gate()->dataScope($user, 'sales.view');        // DataScope::Own, …
$limits = App::gate()->constraints($user, 'sales.view');     // ['gst_registration_ids' => [...]]
```

## Activity logging

```php
App::activity()->log('sale.created', "Created order {$no}", 'sale', $id, ['total' => $total]);
```

User, IP address, user agent and timestamp are added automatically; keys that look like secrets (`password`, `token`, `otp`, `pin`, …) are redacted. The model exposes read methods only.

## Adding a module (pattern for Phases 3–15)

1. Migration(s) in `database/migrations/` (timestamps, FKs, indexes; amounts as `DECIMAL` when finance tables arrive).
2. Permissions in `config/permissions.php` → `php bin/console db:seed`.
3. Model(s) in `app/Models`, business rules in `app/Services` (use `App::db()->transaction()` for multi-table writes).
4. Controller + routes with `can:` middleware; views under `resources/views/<module>/`.
5. Set the sidebar item's `route` and `permission` in `config/navigation.php`.
6. Log every create/update/delete/approve through `App::activity()`.
7. Tests in `tests/Integration` and `tests/Feature`.
