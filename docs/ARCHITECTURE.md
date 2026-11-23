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

## Authorization model (Phase 2)

There is one decision point per question, and controllers never check role names.

| Question | Service | Notes |
|---|---|---|
| Can this user do `module.action`? | `App::gate()` (`Gate`) | `allows()`, `authorize()`, `explain()` (returns the reason and its source) |
| Which records can they see? | `App::dataAccess()` (`DataAccess`) | `resolve()`, `constrain($user, 'module', 'alias')` → SQL + bindings, `assertCanAccess()` → 404 |
| What can they do with a field? | `App::fields()` (`FieldPolicy`) | `access()`, `present()` / `presentMany()` strip or mask, `assertWritable()` rejects |
| Is the account usable right now? | `AccountState` | inactive, suspended, expired, deleted, IP/CIDR, sign-in hours |
| Who approves? | `App::approvals()` (`ApprovalEngine`) | `submit()`, `approve()`, `reject()`, `cancel()`, `onDecision()` listeners |

The Gate resolves in this order: account not usable → deny; Super Admin → allow; module disabled or super-admin-only → deny; explicit Deny override → deny; explicit Allow override → allow; any active role grant → allow; otherwise deny.

Module catalogue: `config/modules.php` (actions, protectable fields with mask types, data mapping: table, owner column, resource type, scope columns). After changing it, run `php bin/console db:seed`.

Writes to the access configuration go through `App\Services\Admin\*Service`. Each one requires an active Super Admin, refuses changes to your own access, and writes a structured audit entry with old and new values.

```php
// routes
$r->get('/sales', [SalesController::class, 'index'], 'sales.index', ['can:sales.view']);

// list query
[$where, $bind] = App::dataAccess()->constrain($me, 'sales', 's');

// single record (404 when outside the user's scope)
App::dataAccess()->assertCanAccess($me, 'sales', $id);

// output and input
$row = App::fields()->present($me, 'sales', $row);
App::fields()->assertWritable($me, 'sales', $request->all(), $current);

// approvals
$req = App::approvals()->submit('expense', 'expense', $expenseId, $amount, $title, $me);
```

## Money and partner capital (Phase 3)

- Store money as `DECIMAL(15,2)` and percentages as `DECIMAL(7,4)`. In PHP use `App\Support\Money` (integer paise) and `App\Support\Percent` (1% = 10,000 units). Never use floats in business rules.
- `partner_capital_transactions` is append-only. Balances come from `CapitalLedger` over rows in `posted` or `reversed` status, never from a stored balance.
- Approval-driven modules register a listener in `App::approvals()` (see `CapitalService::registerApprovalListener()`).
- Financial postings call `App::postingQueue()->enqueue()` inside their DB transaction. The unique key prevents double counting, and Phase 12 implements `PostingTarget`.
- Partner-level reports for transaction modules use `DataAccess::constrainAs($user, '<module>', 'partners', 'p')`.
- Private files go through `DocumentService` (allow-list, MIME check, random names, outside `public/`) and are streamed only by an authorising controller.
- The CSP forbids inline `style` attributes. Use classes, or `data-width` / `data-height`, which `app.js` applies.

## Activity logging

```php
App::activity()->log('sale.created', "Created order {$no}", 'sale', $id, ['total' => $total]);
```

User, IP address, user agent and timestamp are added automatically; keys that look like secrets (`password`, `token`, `otp`, `pin`, …) are redacted. The model exposes read methods only.

## Adding a module (pattern for Phases 3–15)

1. Migration(s) in `database/migrations/` (timestamps, FKs, indexes; amounts as `DECIMAL` when finance tables arrive).
2. Module, actions, fields and data mapping in `config/modules.php`, default role grants in `config/permissions.php` → `php bin/console db:seed`.
3. Model(s) in `app/Models`, business rules in `app/Services` (use `App::db()->transaction()` for multi-table writes).
4. Controller + routes with `can:` middleware; scope every query with `DataAccess::constrain()` and pass every record through `FieldPolicy::present()`; views under `resources/views/<module>/`.
5. Set the sidebar item's `route` and `permission` in `config/navigation.php`.
6. Log every create/update/delete/approve through `App::activity()`.
7. Tests in `tests/Integration` and `tests/Feature`.
