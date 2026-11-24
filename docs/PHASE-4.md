# Phase 4 — GST Registration Management

Phase 4 adds a register of the business's GST registrations. It builds on Phases 1–3 and replaces nothing. Permissions, record scopes, field rules, resource assignments, approval workflows and the audit log are the existing ones. No second permission or audit system was added.

**Scope boundary.** This phase stores master data only. It does **not** calculate tax, file returns, connect to the GST portal or create bank, marketplace, sales or purchase records. A GSTIN that passes validation has a correct *format*; the system never claims it was verified with the GST portal.

## Updating from Phase 3

```bash
git pull
composer install                 # refreshes the autoloader (new classes)
php bin/console migrate          # 1 new migration (000029): 13 gst_* tables; existing data untouched
php bin/console db:seed          # GST module permissions, state master, types, relationships, activities, 3 workflows, 2 settings, 10 help guides
php bin/console migrate:status   # every migration should show "Ran"
```

**XAMPP:** run the same commands in the XAMPP Shell from the project folder. `.env` needs no change.

**What the seeder does on an existing install**
- Registers the real **GST Registrations** module actions and **removes the retired placeholder actions** (and their grants and overrides) from the Phase 2 stub. The placeholder field `portal_username` is removed too; portal credentials must never be stored.
- Loads the official GST state and union-territory codes (01–38, 97 Other Territory, 99 Centre Jurisdiction). Legacy code 25 is kept inactive for history. 96 (Other Country) is listed but is not valid as a GSTIN state code.
- Loads 11 registration types, 6 partner relationship types and 3 starter business activities. All are editable in **Masters & rules**.
- Adds three **inactive** approval workflows: *New GST registration*, *GST key-detail change*, *GST registration cancellation*.
- Grants defaults to new permissions only: Partner → view own mapped registrations; Accountant → view assigned, history, export, print, download documents; Manager → view all. Super Admin has everything. This happens once, so anything you change later is kept.
- Creates **no** registrations, mappings or documents.

To check the MySQL SQL first: `php bin/console migrate --pretend`.

## Quick start after updating

1. *(Optional)* **Control Center → Approval workflows:** add steps to *New GST registration*, *GST key-detail change* or *GST registration cancellation* and activate them.
2. **Compliance → GST Management → Registrations → Add GST registration.** Type the GSTIN (checked live), the legal name and the principal place of business.
3. Approve it in **Approvals**, or it applies at once if the workflow is inactive. Then activate it after checking the certificate.
4. On the profile: map partners, add business activities and extra locations, upload the REG-06 certificate, and on **Settings** mark the default registration.
5. *(Optional)* **Control Center → Resource assignments:** assign registrations to accountants (scope *Assigned* / *Selected GST registrations*).

## What was built

### Data model (13 tables)
`gst_states`, `gst_registration_types`, `gst_partner_relationship_types`, `gst_business_activities` (masters); `gst_registrations` (unique GSTIN, status, approval state, archive fields); `gst_registration_addresses` (principal + unlimited additional places); `gst_registration_partner_mappings` and `gst_registration_business_mappings` (many-to-many, effective dates, active/ended history); `gst_registration_documents`; `gst_registration_settings` (one row per registration); `gst_registration_status_history`; `gst_registration_change_requests` (create / key-detail update / cancel, with old and new values); `gst_registration_links` (foundation for future modules; empty).

### GSTIN validation (`App\Services\Gst\GstinValidator`)
One server-side service used when adding and editing registrations and by the live check endpoint:
- normalises (removes spaces, uppercase) and requires exactly 15 letters/digits;
- state code must exist in the state master and allow registration (inactive legacy codes only for historic records);
- characters 3–12 must be a valid PAN (holder-type letter checked) or a TAN for TDS registrations; OIDAR, non-resident and UIN registrations are not PAN-based, so for them only the state code, structure and check character are checked;
- entity number 1–9/A–Z, 14th character as configured per type (normally `Z`);
- mod-36 check character;
- a separately entered PAN must match;
- duplicates are refused case-insensitively (also enforced by a unique index).

The browser check (`POST /gst/check-gstin`) shows the state and PAN while typing and fills the state. It always says "format check only".

### Registrations, lifecycle and approvals
- Form sections A (basic details), B (principal place of business), C (contact, portal reference such as the ARN, notes). No password, PIN or OTP field exists anywhere.
- Statuses: Pending verification, Active, Inactive, Suspended, Cancelled, with an enforced transition table. Cancelled is final. Suspension and cancellation need a reason; every change records its effective date in the status history.
- **Key details** (GSTIN, legal name, PAN, type, registration and effective dates) go through the *key-detail change* workflow; other fields save at once. New registrations and cancellations use their own workflows. Inactive workflows auto-approve through the same engine, so there is one code path. Rejected new registrations can be corrected and resubmitted, or archived.
- **Archive / restore** instead of delete. Only inactive, cancelled or rejected-new registrations can be archived; not the default registration, not with a pending change, and **not when another record refers to it** (`GstLinkRegistry::isReferenced`). Registrations are never deleted.

### Profile (8 tabs)
Overview · Registration details · Partner associations · Business activities · Business locations · Documents · Settings · History.

### Partner associations
Unlimited partners per registration and registrations per partner, with relationship type, from/to dates and notes. "End mapping" keeps the row as history. An optional **single-owner rule** (Business rules) limits each registration to one active mapping whose relationship type counts as owner. Mappings never touch capital or profit sharing. The partner profile's **GST** tab now lists the partner's registrations within the viewer's GST scope.

### Business activities, locations, documents, settings
- Activities: configurable master; mappings with dates, status and notes; audited.
- Locations: exactly one active principal (enforced), unlimited additional places; edit, make principal, activate/deactivate; never deleted.
- Documents: shared `SecureFileStore` (now also used by partner documents): content-type sniffing, 5 MB limit, random stored names outside `public/`, inline preview only for PDF and images, every view/download permission-checked and logged; archive with reason, history toggle; expiry dates feed the dashboard.
- Settings: default registration (only one, active and approved), default place of supply, default state, applicable type, effective dates, tax-related flags, notes. Stored for future modules; nothing is calculated.

### Future-module link foundation
`GstLinkRegistry`: a module registers a link type (`bank_account`, `seller_account`, …) and links its records to a registration instead of storing GSTINs. Phase 4 registers no types and creates no links. The Overview tab says so.

### Dashboard, list, reports
- Dashboard: counts by status, needs-attention list (pending verification or approval, rejected changes, expiring documents and validity, missing certificate), default registration, state-wise and partner-wise summaries, recently added and updated.
- List filters: search, GSTIN, legal name, trade name, partner, state, type, status, registration date range, archived; sorting; pagination; CSV/XLSX/PDF export.
- 7 reports: all, active, cancelled & suspended, state-wise, type summary, partner-wise, recently updated. Filters, sorting, pagination, CSV/XLSX/PDF, print. Record scope and field rules apply on screen and in every file.

### Phase 2 integration
- Module `gst_registrations` with 23 actions: view, view_all, view_own, view_assigned, create, edit, activate, deactivate, suspend, cancel, archive, approve, reject, manage_partners, manage_business_mappings, manage_locations, manage_documents, download_documents, manage_settings, view_history, manage_masters, export, print.
- Field rules: gstin, legal_name, trade_name, pan (masked to last 4), contact_person, contact_phone, contact_email, address, portal_reference, internal_notes. Applied in pages, the approval view, reports and exports.
- Record scopes: all, own (registrations mapped to the user's partner record or created by them), assigned, selected, selected partners (via the mapping table), selected GST registrations. `DataAccess` gained two generic mapping options (`owner_sql`, `columns_via`) so any module can express this; the Control Center only offers scopes a module supports.
- Approval engine: `submit()` takes an optional permission so each workflow is checked against its own action.

## Security and testing results

```
php tests/run.php
148 passed, 0 failed, 1305 assertions (PHP 8.3.6 · SQLite)
```

New suites:
- `tests/Integration/GstRegistrationTest.php` (17 tests)
- `tests/Feature/GstSecurityTest.php` (6 HTTP tests)

| # | Requirement | Covered by |
|---|---|---|
| 1 | Valid GSTIN accepted, normalised to uppercase | `testValidGstinIsAcceptedAndNormalised` |
| 2 | Invalid format, length, characters, PAN, entity number, 14th character | `testInvalidFormatsAreRejectedWithClearReasons` |
| 3 | Invalid and inactive state codes | `testInvalidStateCodesAreRejected`, `testStateMasterUsesOfficialCodes` |
| 4 | Check character and PAN mismatch | `testChecksumAndPanMismatchAreDetected`, `testTanBasedTypeNeedsTan` |
| 5 | Duplicate GSTIN | `testDuplicateGstinIsRejected`, HTTP duplicate check |
| 6 | One partner ↔ many registrations, many partners ↔ one registration, history | `testPartnersAndRegistrationsAreManyToManyWithHistory` |
| 7 | Optional single-owner rule | `testSingleOwnerRuleIsOptional` |
| 8 | Business-activity mappings and master code lock | `testBusinessActivityMappings` |
| 9 | Exactly one principal location | `testExactlyOnePrincipalLocation` |
| 10 | Status lifecycle, invalid transitions, history | `testStatusLifecycleAndHistory`, HTTP status change |
| 11 | Approval for create, key-detail change and cancellation | `testApprovalWorkflowsForCreateKeyChangeAndCancellation` |
| 12 | Archive rules; referenced registrations protected; never deleted | `testArchiveRulesAndReferencedRegistrationsAreProtected` |
| 13 | One default registration | `testOnlyOneDefaultRegistration` |
| 14 | Documents: type/content checks, private names, archive history, audit | `testDocumentsAreValidatedArchivedAndAudited` |
| 15 | Record scope: partner login, assigned accountant, viewer, manager; 404 on others | `GstSecurityTest` (4 tests) |
| 16 | Field rules and record scope in pages and exports; CSRF; audit without secrets | `testFieldRulesMaskAndHideInPagesAndExports`, `testAdminFlows…`, `testEveryChangeIsAuditedWithoutSecrets` |

Also checked by hand against a demo database: every GST page and tab, all 7 reports in 3 formats, and masters as five different users (0 server errors). The live GSTIN check, the state fill and the validity-date toggle were tested in Chromium.

**Issues found and fixed during testing:**
- Partner-wise report showed the registration status in the *Mapping* column (column-name collision).
- `archive()` did not consult the reference check its documentation promised.
- Needs-attention listed a registration twice when it was both pending verification and pending approval.
- Status tiles broke words mid-label; partner and document tables cut off at laptop width.
- The Print button was shown without the `print` permission.

**Design note on masking.** The GSTIN contains the PAN (characters 3–12). Masking the `pan` field hides the PAN field itself; anyone who can see the GSTIN can still read the PAN from it. To hide the PAN from a role, also mask or hide `gstin`.

**Not verified here:** the build environment has no MySQL and no PHP 8.5. The MySQL DDL for the new migration was generated through the same grammar `migrate --pretend` uses and reviewed (13 tables, all identifiers within 64 characters, `RESTRICT` foreign keys to registrations). Please run the migration on your XAMPP MySQL.

## Help & User Guide

New category **Phase 4 – GST Registration Management** with 10 guides and 20 annotated screenshots taken from the running application: understanding registrations, adding one, GSTIN validation, managing details and settings, mapping partners, business activities and masters, locations, documents, status and archiving, reports.

## Not included (by design)

Tax calculation, return filing, e-invoicing or e-way bill generation, GST portal integration or verification, and bank, marketplace, seller, product, sales or purchase records. Phase 5 has not been started.
