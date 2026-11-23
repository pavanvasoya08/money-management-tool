# Phase 2 — Super Admin Control Center & Advanced Permissions

Phase 2 builds on the Phase 1 foundation. The Phase 1 login, sessions, users, roles, settings and audit log keep working; Phase 2 adds to them and replaces nothing.

## Upgrading an existing Phase 1 install

```bash
git pull
composer install                 # refreshes the autoloader
php bin/console migrate          # 11 new migrations (000012 – 000022), no data loss
php bin/console db:seed          # adds 21 modules, 234 permissions, 59 protectable fields, workflows, templates
php bin/console migrate:status   # every migration should show "Ran"
```

You can run the seeders as often as you like. They add new modules, permissions and fields and leave everything you have already customised alone: role grants, field rules, workflow steps and templates. Default grants only go to newly created system roles and newly added permissions.

To check the MySQL SQL before you run it: `php bin/console migrate --pretend`.

**XAMPP:** run the same commands from the project folder in the XAMPP shell (or any terminal where `php` is XAMPP's PHP 8.5). Your `.env` needs no changes.

After upgrading, sign in as a Super Admin and open **Control Center** in the sidebar.

## What was built

### Control Center (`/admin`, real Super Admins only)
These 14 sections share one left-hand navigation: Overview, User management, Role management, Module permissions, Action permissions, Data visibility, Field visibility, Resource assignments, Approval workflows, Permission templates, User access preview, Login sessions, Audit logs and Security settings.

The Control Center can't be delegated. Its permissions (`super_admin.*`) are marked *super-admin only*. They can't be granted to a role, a user override or a template, so nobody can raise their own access.

### Advanced user management
- An 8-tab user profile: Overview, Roles, Permissions, Data access, Field access, Approvals, Sign-ins and Activity.
- **Suspend** a user (with a reason and an optional end date) or unsuspend them.
- **Account expiry date**: after it passes, the user can't sign in or act.
- **Access restrictions**: allowed IP addresses or CIDR ranges, and allowed sign-in hours.
- **Force logout**: revokes every server-side session of that user. They're signed out on their next request.
- **Soft delete and restore**: only an inactive user can be deleted. A deleted user behaves as unknown at sign-in, and all history is kept.
- Self-protection: you can't suspend, delete, restrict or change the permissions of your own account, and the last active Super Admin can't be removed.

### Roles
- Unlimited custom roles: create, edit, activate/deactivate, duplicate, or copy permissions, data scopes and field rules from another role.
- Safe delete: system roles can't be deleted. A role with users must reassign them to another active role (never in bulk to Super Admin). A role that is an approval-workflow approver is blocked until you change the workflow. A full snapshot is written to the audit log.

### Modules and permission matrix
- 21 modules (Dashboard, Partners … Financial Reports, User Management, Activity Logs, Settings, Control Center), each with its own actions: view, view all/own/assigned, create, edit, delete, export, approve, reject, plus module-specific actions such as `close_period`, `reconcile` and `force_logout`.
- Matrix UI: checkboxes, select all / deselect all, a whole column or row in one click, search, a filter (granted only / available now), copy from another role, a live "effective preview", an unsaved-changes counter with a leave-page warning, and confirmation before granting high-impact actions (delete, approve, export, reset password, …).
- **Action permissions** view: see every role and user holding a permission, and the source of each.

### Explicit overrides (Allow / Deny / Inherit)
These are per user, with an optional expiry and reason. The resolution order is deterministic:

1. Account not usable (inactive, suspended, expired, deleted, outside allowed IP/hours) → **deny**
2. Super Admin → **allow**
3. Module disabled or super-admin-only → **deny**
4. Explicit **Deny** override → **deny**
5. Explicit **Allow** override → **allow** (can be switched off in Security settings)
6. Any active role grants it → **allow**
7. Otherwise → **deny**

"Restrict module" denies every action of one module for one user in a single click.

### Record-level visibility (9 scopes)
The scopes are `all`, `own`, `assigned`, `selected`, `selected_partners`, `selected_gst_registrations`, `selected_bank_accounts`, `selected_platforms` and `none`. They can be set per role and per module, or overridden per user.

`DataAccess::constrain()` turns the scope into a SQL `WHERE` fragment with bound parameters, so lists, exports and the API only ever load permitted rows. Opening a record you can't see returns **404**, never 403, so record IDs can't be probed.

### Field-level access
The levels are `hidden` < `masked` < `visible` < `read_only` < `editable`. There's a default per field, a rule per role (the most permissive role wins) and an override per user (which always wins).

Rules apply on the server everywhere:
- Pages: hidden fields aren't rendered, and masked fields show `r•••@example.com`, `••••3210`, and so on.
- CSV export: hidden columns are removed from the header and the rows.
- JSON API: hidden fields are left out of the payload.
- Forms: non-editable inputs are disabled. A crafted POST that changes a read-only field is rejected with 403 and logged.

### Resource assignments
Users can be assigned to specific partners, GST registrations, bank accounts, platforms or users. The `assigned` and `selected_*` scopes use these. Partner, GST, bank and platform records come from Phases 3–8. Until then those lists are empty: no placeholder data is created.

### Approval workflow engine
- Six workflows are seeded **inactive**: expense, purchase, bank transfer, partner withdrawal, sales return and journal entry. They have no steps.
- Steps run in sequence. Each step has an approver (a role, a specific user, or anyone with `<module>.approve`) and a minimum amount, so higher amounts can require extra levels.
- Self-approval is blocked (configurable), and a rejection needs a reason. Concurrent decisions are safe because of optimistic locking. The full history is kept.
- An approvals inbox at `/approvals` is shown to anyone who can approve something.
- **No financial transactions exist yet.** From Phase 3 onwards, modules call `App::approvals()->submit(...)`.

### Permission templates
These are reusable sets of permissions plus data scopes. Applying one to a role *replaces* the role's permissions (with confirmation). Applying one to a user *adds* missing permissions as explicit Allow overrides. Templates can be duplicated and deleted. Six starter templates are seeded and contain no business data.

### User access preview
Pick a user to see their sidebar, every page (allowed or blocked), each module's actions, data scope and field access, and the reason for each decision. Preview is read-only and you stay signed in as yourself, with no identity switching. Every preview is audited.

### Login sessions
A registry of active sessions across devices with IP address, device and last activity. You can revoke one session or all sessions for a user, and recent failed sign-ins are listed.

### Structured audit log
Every administrative change records: actor, affected user and/or role, module, subject, **old and new values** (JSON), IP address, user agent and time.

You can filter by actor, affected user, role, module, action prefix, date range and text, and open a detail page per entry. Secrets are never stored.

### Security settings
These cover session timeouts, lockout, password policy, remember-me, whether per-user Allow grants apply, self-approval blocking, and access requests. Changes take effect immediately and are audited.

An optional **access request** flow lets a user ask for a missing permission from the 403 page. A Super Admin approves (optionally with an expiry) or rejects it.

## Test results

```
php tests/run.php
97 passed, 0 failed, 541 assertions   (PHP 8.3.6 · SQLite)
```

`tests/Integration/AccessControlTest.php` and `tests/Feature/ControlCenterSecurityTest.php` cover the security cases from the brief:

| # | Case | Result |
|---|---|---|
| 1 | Ordinary users (even with every `users.*` permission) get 403 on all `/admin` routes | ✅ |
| 2 | No self-elevation: a crafted POST to the override endpoint is rejected; `super_admin.*` can't be granted | ✅ |
| 3 | A Super Admin can't change their own access | ✅ |
| 4 | Explicit Deny beats a role grant; Allow adds a permission; Inherit falls back; expired overrides are ignored | ✅ |
| 5 | The `own` scope limits lists, and tampered record IDs return 404 (web and API) | ✅ |
| 6 | The `none` data-scope override hides every record | ✅ |
| 7 | Hidden fields are removed and masked fields masked in pages, API and CSV export | ✅ |
| 8 | A crafted write to a read-only field is rejected (403) and the value is unchanged | ✅ |
| 9 | Export requires `users.export` | ✅ |
| 10 | Force logout ends an active session on its next request | ✅ |
| 11 | Suspended and expired users can't sign in or act; deletion requires an inactive user | ✅ |
| 12 | Approvals: amount-based steps, self-approval blocked, wrong approver blocked, rejection reason required | ✅ |
| + | Role duplicate/delete with reassignment, templates, preview doesn't switch identity, API requires authentication (401) | ✅ |

The MySQL DDL from `migrate --pretend` was generated and reviewed for all new migrations. The test suite runs on SQLite because MySQL isn't available in the build environment, so please run `php bin/console migrate` on your XAMPP MySQL after pulling.

## Pending — Phase 3 (Partner management)

- Partner records (profile, contact, PAN, share ratio, joining/exit dates, status) with the `partners.*` permissions already seeded.
- Capital contributions and withdrawals that submit to the **partner withdrawal** workflow.
- Turning on the `selected_partners` data scope and partner resource assignments against real partner rows.
- Applying partner-module field rules (PAN, contact and share ratio masking) in partner pages, exports and API.
- Partner dashboard widgets fed by real data only.
