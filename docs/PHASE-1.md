# Phase 1 report — Project foundation & authentication

## Completed features

### Foundation
- [x] Custom MVC framework (router with groups, named routes and middleware; request/response; plain-PHP views; validator; service registry)
- [x] Environment-based configuration (`.env` + `config/*.php`; real env vars take precedence)
- [x] Reusable PDO service with prepared statements, identifier validation, nested transactions, LIKE escaping
- [x] Migration system (batches, rollback, status, `--pretend` MySQL DDL preview)
- [x] Seeder system (idempotent)
- [x] CLI: `migrate`, `migrate:status`, `migrate:rollback`, `migrate:fresh`, `db:seed`, `admin:create`, `app:check`
- [x] Composer PSR-4 autoloading and PHP 8.5 platform requirements

### Database (utf8mb4, InnoDB, foreign keys, indexes, UTC timestamps)
`users`, `roles`, `permissions`, `user_roles`, `role_permissions`, `user_permission_overrides`, `login_attempts`, `password_reset_tokens`, `activity_logs`, `system_settings` — plus `remember_tokens` (secure remember-me, one row per device) and `migrations`.

### Authentication
- [x] Login / logout
- [x] Forgot password + reset password (256-bit tokens, only SHA-256 stored, expiry, single use with atomic claim, newer link invalidates older ones, 3 requests/hour limit, no account enumeration)
- [x] Change password (requires current password, signs out other remembered devices)
- [x] Remember me (selector/validator split token, hashed validator, rotation on every use, theft detection revokes all devices)
- [x] Session management: strict mode, HttpOnly/Secure/SameSite cookies, ID regeneration on login, idle timeout, absolute timeout, browser fingerprint check, auto sign-out when the account is deactivated or the password changes elsewhere
- [x] Failed login tracking (`login_attempts`) and temporary account lockout (configurable threshold/duration) + per-IP throttling
- [x] Password strength policy (length, character classes, repetition, common passwords, no name/email) enforced server-side everywhere
- [x] Argon2id hashing (bcrypt fallback), transparent rehash on login, constant-time dummy verification for unknown emails

### Users & roles
- [x] Create (temporary password, must change at first sign-in), edit, activate/deactivate, assign multiple roles, admin password reset, list with search/filter/pagination, user detail with login history and audit trail, global login history
- [x] Roles: Super Admin, Partner, Accountant, Manager, Viewer (schema supports unlimited custom roles)
- [x] New users receive no access beyond explicitly assigned roles
- [x] Rules enforced in `UserService` (not just the UI): no self role/status changes; only Super Admins grant the Super Admin role or manage Super Admin accounts; the last active Super Admin cannot be deactivated or demoted

### Authorization foundation
- [x] `Gate` with role grants, per-user allow/deny overrides (optional expiry), data scopes, constraint merging, resource policies, ownership helper
- [x] Middleware: `auth`, `guest`, `can:<permission>`, `super-admin`, CSRF, security headers
- [x] No role-name checks in controllers or views

### Activity logging
- [x] `ActivityLogger` service usable by every module; captures user, action, description, subject, properties (secrets redacted), IP, user agent, timestamp
- [x] Logged: login, logout, failed login, lockout, throttling, remember-me theft, password reset request/reset/change, user created/updated/activated/deactivated, role changes, admin password reset, settings changes, denied access, Super Admin creation
- [x] Read-only activity log page with search/filter/pagination; no update/delete code paths

### UI
- [x] Responsive Bootstrap 5 shell: navy sidebar (collapsible on mobile), top bar, breadcrumbs, notifications dropdown, profile dropdown
- [x] Dashboard with real data only: welcome, signed-in user details, total/active/inactive/locked users, 14-day sign-in chart, recent logins, recent activity, quick navigation
- [x] Future modules shown as disabled “Soon” sidebar items (no fake pages or figures)
- [x] Reusable components: toasts, confirmation modal, loading buttons, empty states, pagination, role picker, status badges, password strength meter + generator, stacked tables on phones
- [x] Settings page (general + security) with validation and audit logging

### Security checklist
| Control | Implementation |
|---|---|
| SQL injection | PDO prepared statements, `ATTR_EMULATE_PREPARES=false`, validated identifiers |
| XSS | `e()` on all output, strict CSP (no inline scripts/styles), `nosniff` |
| CSRF | Per-session token on every POST, `hash_equals` comparison, SameSite cookies |
| Sessions | Strict mode, regeneration, HttpOnly/Secure/SameSite, idle + absolute timeouts |
| Clickjacking | `X-Frame-Options: DENY`, `frame-ancestors 'none'` |
| Passwords | Argon2id/bcrypt, policy, never logged or re-filled into forms |
| Enumeration | Identical login/reset responses, dummy hash verification |
| Brute force | Account lockout + per-IP throttling |
| Errors | Generic pages with reference IDs; traces only when `APP_ENV=local` and `APP_DEBUG=true` |
| Secrets | `.env` git-ignored, deny rules, log redaction, no banking passwords/PINs/OTPs stored |
| Open redirect | Redirects restricted to same-site relative paths |
| Caching | `Cache-Control: no-store` on all pages |

## Testing results

Run: `php tests/run.php` (temporary SQLite database + PHP built-in web server; no MySQL required).

```
Unit/DatabaseTest                       .....
Unit/PasswordPolicyTest                 ....
Unit/RouterTest                         ....
Unit/SchemaGrammarTest                  ...
Unit/ValidatorTest                      .....
Integration/AuthServiceTest             .......
Integration/GateTest                    ........
Integration/MigrationSeederTest         ....
Integration/PasswordResetTest           ....
Integration/RememberAndAuditTest        .....
Integration/UserServiceTest             ..........
Feature/AuthFlowTest                    .........
Feature/UserManagementTest              .........

77 passed, 0 failed, 345 assertions
```

Coverage of the Phase 1 test checklist:

| Requirement | Tests |
|---|---|
| Database connection | `DatabaseTest::testConnectionAndPreparedStatements`, FK enforcement, transaction rollback |
| Migrations & seeders | `MigrationSeederTest` (all tables, idempotent seeding, rollback/re-migrate), `SchemaGrammarTest` (every migration compiles to valid MySQL 8 DDL) |
| Super Admin login/logout | `AuthFlowTest::testLoginRegeneratesSessionIdAndShowsDashboard`, `testLogoutEndsSession` |
| Invalid login | `AuthServiceTest::testInvalidLoginIsGenericAndRecorded`, `AuthFlowTest::testInvalidLoginShowsGenericError` |
| Lockout / throttling | `AuthServiceTest::testTemporaryLockoutAfterRepeatedFailures`, `testIpThrottle` |
| Password reset | `PasswordResetTest` (single use, expiry, hashing, rate limit), `AuthFlowTest::testPasswordResetOverHttp` |
| Session security | session ID regeneration, fingerprint, idle timeout, deactivation sign-out, remember-me rotation & theft detection |
| User creation/editing | `UserServiceTest`, `UserManagementTest::testSuperAdminCreatesUserAndOutputIsEscaped`, validation errors |
| Activation/deactivation | `UserServiceTest::testDeactivationRevokes…`, `UserManagementTest::testDeactivationSignsUserOutOnNextRequest` |
| Role assignment | `UserServiceTest` (super-admin protection, audit), `UserManagementTest::testRoleAssignmentAndSettingsUpdate` |
| Protected routes / unauthorized access | `AuthFlowTest::testGuestsAreRedirectedToLogin`, `UserManagementTest::testViewerCannotReachAdminPages`, `testManagerCanViewButNotModify`, `GateTest` |
| CSRF | `AuthFlowTest::testCsrfIsRequired` |
| Activity logging | `RememberAndAuditTest::testActivityLoggerCapturesContextAndRedactsSecrets`, `testAuditModelHasNoWriteMethods`, audit assertions throughout |
| XSS | `UserManagementTest::testSuperAdminCreatesUserAndOutputIsEscaped` |

## PHP 8.5 compatibility verification

- `composer.json`: `"php": ">=8.5"`, `config.platform.php = 8.5.0`, `platform-check: true`; no third-party packages (nothing that could lag behind 8.5).
- `declare(strict_types=1)` in every PHP file; explicit nullable types (no implicitly-nullable parameters, deprecated in 8.4).
- Avoided APIs deprecated in 8.4/8.5: driver-specific `PDO::MYSQL_ATTR_*` constants (session settings are applied with SQL instead), `session.sid_length` / `session.sid_bits_per_character` INI settings, `curl_close()`, non-canonical casts (`(boolean)`, `(integer)`…), backtick operator, `E_STRICT`, `__sleep/__wakeup`, `strftime`, `utf8_encode/decode`.
- Static scan for those patterns: clean.
- **Runtime limitation:** the automated suite was executed on **PHP 8.3.6** (the newest PHP available in the build environment) with the platform check bypassed, and against **SQLite** (no MySQL server was available). The code uses only syntax valid on 8.3 → 8.5. Before go-live, run on the target server:
  ```bash
  php -v                      # 8.5.x
  composer install
  php bin/console app:check
  php tests/run.php           # needs pdo_sqlite + curl
  php bin/console migrate     # against MySQL 8
  ```

## Pending — Phase 2 (Super Admin complete control system)

Prepared in Phase 1 (schema + Gate), UI and rules to be built in Phase 2:

1. Role management UI: create/edit/clone/deactivate unlimited custom roles (system roles protected).
2. Permission matrix UI per role: module × action (view, create, edit, delete, approve, export).
3. Per-user permission overrides UI (allow/deny, reason, expiry) — table `user_permission_overrides` exists.
4. Record-level data access: apply `Gate::dataScope()` (all / own / assigned / selected) to module queries.
5. GST-wise, bank-account-wise, platform-wise and partner-wise restrictions via `constraints` (needs the Phase 3–8 tables to select from).
6. Field-level access: new `field_permissions` table + `Gate::fieldAccess()` (visible / hidden / masked / read-only / editable) and view helpers.
7. Approval workflows (maker-checker) and the `approve` action.
8. User-specific access restrictions (e.g. allowed IP ranges / time windows), and "view as user" access preview for Super Admins.
9. Permission-change audit entries and a permissions report.
10. Optional for later phases: SMTP mail driver, two-factor authentication, real notifications feed, self-hosted CDN assets.
