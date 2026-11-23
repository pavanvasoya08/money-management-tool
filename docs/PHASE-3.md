# Phase 3 — Partner Management & Capital Contributions

Phase 3 extends Phases 1 and 2 and replaces nothing. Partners, capital and profit sharing use the existing Gate, record scopes, field rules, approval engine and audit log. No separate permission or audit system was added.

## Updating from Phase 2

```bash
git pull
composer install                 # refreshes the autoloader (new classes)
php bin/console migrate          # 5 new migrations (000023 – 000027); existing data untouched
php bin/console db:seed          # registers 5 new modules, 50 new permissions, partner fields, 2 new workflows
php bin/console migrate:status   # every migration should show "Ran"
```

**XAMPP:** run the same commands from the project folder (`C:\xampp\htdocs\money-management-tool`) in the XAMPP Shell. You don't need to change `.env`.

**What the seeder does on an existing install**
- New permissions get their default role grants (for example the Partner role gets `capital_ledger.view_own`). Permissions you already configured are not touched. Review the defaults under **Control Center → Role management**.
- Phase 2 placeholder partner fields (`investment_amount`, `withdrawal_amount`, `profit_share_percent`, `contact_email`, `contact_phone`, and `bank_reference` for contributions) are replaced by the real fields, together with any rules attached to them.
- The *Partner withdrawal approval* workflow moves to the new **Capital Withdrawals** module. That only happens if it has never been used.
- No partners, transactions, percentages or documents are created.

To check the MySQL SQL first: `php bin/console migrate --pretend`.

## Quick start after updating

1. **Partners → Add partner.** A unique ID (`PTR-0001`, …) and a zero-balance capital account (`CAP-PTR-0001`) are created. Adding a partner grants no permissions.
2. *(Optional)* **Partner → Personal information → Login account:** link an existing user, or create a login with the limited **Partner** role.
3. **Capital Contributions → New**, then *Save & submit for approval*.
4. Approve it (Approvals inbox, or automatically if the workflow is inactive), then press **Post to ledger**. Only now does the balance change.
5. *(Optional)* **Control Center → Approval workflows → Capital contribution approval:** add steps (for example a second approver above ₹5,00,000) and activate it.
6. **Profit Sharing → New arrangement → New allocation:** enter each partner's percentage and the effective date.

## What was built

### Partner management
- Partners are unlimited: there is no fixed count. Each one has a unique partner ID, a profile photo, contact details, address, PIN, PAN, joining date, status, notes and a linked login. Created-by and updated timestamps are recorded.
- PAN is validated (`ABCPE1234F` pattern with a valid holder-type letter) and must be unique. Mobile numbers and PIN codes are validated too.
- **Status changes:** activate/deactivate, **suspend** (with a reason, which also suspends the linked login when you may suspend users), reactivate, **archive** and restore.
  - Archiving needs a zero posted balance and no open entries. History is kept and the profile becomes read-only.
- **Search and filter:** by name, ID, email, mobile or city, and by status.
- **Export:** CSV, Excel or PDF, using the same record scope and field rules as the page.

### Partner profile (11 tabs)
The tabs are Overview, Personal information, Capital contributions, Capital withdrawals, Capital balance, Profit sharing, GST registrations, Bank accounts, Financial reports, Documents and Activity history.

- Each tab is shown only when you hold the permission for its data.
- The GST and Bank tabs are honest placeholders until Phases 4 and 5. They show no invented data.

### Login linking
- **One login, one partner.** This is enforced by a unique key and checked with a clear message.
- **Link, unlink, create a login, suspend login and reactivate login.** Every one of these is recorded in a link history table and in the audit log.
- **Linking changes nothing else.** It never alters roles or permissions; what the partner sees comes from Phase 2.
- **Creating a login** offers only the limited Partner role, and only if you may assign roles. The temporary password must be changed at first sign-in.

### Capital register (contributions, withdrawals, adjustments)
- Every entry is a separate row with a number (`CC-2026-00001`, `CW-…`, `CA-…`, and `CR-…` for reversals). Additional capital never overwrites earlier entries.
- **Payment details:**
  - payment method (Bank transfer, Cash, Cheque, UPI, Other)
  - payment reference
  - a **temporary bank/cash reference** until Phase 5 bank accounts exist (there is a `bank_account_id` column ready for them)
  - notes and an optional supporting document
- **Statuses:** Draft → Pending approval → Approved → **Posted** → Reversed, plus Rejected and Cancelled. Drafts and rejected entries can be edited or cancelled. Editing a rejected entry turns it back into a draft.
- **Approval:** goes through the Phase 2 engine with three workflows: *Capital contribution*, *Partner withdrawal* and *Capital adjustment*.
  - In the Control Center you decide who can submit (`.create`), approve (`.approve`), reject (`.reject`), post (`.post`) and reverse (`.reverse`). Amount-based steps are supported.
  - An inactive workflow approves automatically, but **posting is always a separate step**.
- **Withdrawals:** can't exceed the *eligible available capital*. That is the posted balance minus withdrawals already pending or approved.
  - The form warns you live, and the server blocks the request at both submission and posting.
  - **Exception policy:** exceeding the limit is allowed only when the Security setting *"allow withdrawals beyond available capital"* is on **and** the user holds `capital_withdrawals.override_limit`. Every use is audited.
  - Reversing a contribution whose money has already been withdrawn is blocked in the same way.
- **Duplicate posting is impossible.** The guarded `UPDATE … WHERE status = 'approved'` must change exactly one row, inside a DB transaction, and the accounting queue has a unique key per event.
- **Adjustments:** opening capital migration, correction, reclassification or other. Each one increases or reduces capital, needs a mandatory reason and goes through approval.
- **Posted entries are never edited or deleted.** They are corrected by a **reversal** (a new posted entry in the opposite direction, linked both ways) and a replacement entry.
- **Not income or expense:** contributions are not revenue and withdrawals are not expenses. Both touch only the partner capital account.

### Capital ledger
- **Per partner:** date-wise lines with reference, particulars, debit and credit columns, running balance, and opening and closing balance for any period. Exports are available.
- **Posted entries only.** Rows in posted status and reversed originals count. Drafts, pending, approved-but-unposted, rejected and cancelled rows never do.
- **Formula:** *Closing = Opening + Contributions + Adjustments − Withdrawals − Reversed amounts*. This is shown on the balance report and covered by tests.
- **No floating point.** All money is `DECIMAL(15,2)` in the database and integer paise in PHP (`App\Support\Money`). Inputs with commas or more than two decimals are rejected, never rounded.

### Profit sharing (configuration only)
- **Arrangements:** each has a name, a calculation method (net, distributable or gross profit) and an allocation type (*complete* = must total exactly 100%, or *partial* = up to 100%). They can be active or inactive.
- **Allocations:** effective-dated lines of partner percentages, with up to four decimals, notes and an optional agreement document.
- **Rules:**
  - Percentages are independent of capital.
  - Active periods of one arrangement can't overlap.
  - History is preserved: allocations already in effect are never edited.
  - A new allocation can't start in the past once an earlier one exists, so there is no retroactive change. It ends the previous open-ended allocation the day before it starts.
  - Only future allocations can be edited or deactivated.
- **No profit is calculated or distributed.** That comes with the accounting phases.

### Financial summary (Overview tab)
The Overview tab shows:
- totals contributed and withdrawn (net of reversals) and the current balance
- opening capital and net adjustments
- pending contributions and withdrawals, by count and amount (including approved but not posted)
- the active profit share, plus the percentage history on its own tab
- the last contribution and withdrawal dates

Every figure comes from database rows.

### Documents
- **Validation:** the extension *and* the detected file type must both be allowed (PDF, JPG, PNG, WEBP, DOCX, XLSX) and must agree. Images must decode, PDFs must start with `%PDF`, Office files must be real ZIPs, and anything containing script markers is rejected. The limit is 5 MB.
- **Storage:** files get a random 32-character name and are stored in `storage/uploads/partner-documents/YYYY/MM/`. That folder is outside `public/` and has `Require all denied`.
- **Serving:** files are served only by a controller. It checks the permission, the partner's record scope (404 outside it), that the document belongs to the partner in the URL, and the `identity_documents` field rule for PAN and ID documents. Responses use `nosniff`, `attachment` and `no-store`.
- **Audit:** uploads, downloads and removals are logged. Removal is a soft delete, so the file and record are kept.

### Reports and exports
The eight reports are:
1. Partner list
2. Capital contributions
3. Capital withdrawals
4. Capital balances (with an as-of date)
5. Partner-wise capital ledger
6. Profit-sharing configuration
7. Pending contributions
8. Pending withdrawals

They support date, partner and status filters, search and pagination. Exports come in **CSV**, **Excel (.xlsx)** and **PDF**, all generated without third-party packages; Excel needs only the bundled `zip` extension. There is also a print view.

- **Permissions:** a report needs `partner_reports.view` plus view on its source module. Exporting needs `partner_reports.export` plus the module's `.export`.
- **Same data everywhere:** the same record scope and field rules apply to the screen and all three formats. Hidden columns are dropped and masked values stay masked.

### Dashboard widgets
The dashboard adds Total partners, Active partners, Total posted capital, Total posted withdrawals, Total outstanding capital, Pending contributions, Pending withdrawals, and a partner-wise capital distribution chart.

- The chart is a Chart.js horizontal bar chart with a table fallback.
- Every figure uses posted entries and is limited to the partners the user may see. A partner login sees only its own capital.

### Super Admin control (Phase 2 integration)
Five new modules are registered in the existing registry: **Capital Withdrawals**, **Capital Adjustments**, **Capital Ledger & Balances**, **Profit Sharing** and **Partner Reports**. The existing Partners and Capital Contributions modules got their real actions and fields.

All four levels apply:
- **Module and action:** for example `partners.view`, `partners.create`, `partners.edit`, `partners.change_status`, `capital_contributions.create`, `capital_withdrawals.approve`, `capital_ledger.view`, `profit_sharing.view` and `partners.export`.
- **Record scope:** all, own, assigned, selected partners, none, and so on. *Own* means the partner linked to the user's login, including that partner's capital entries.
- **Field access:** PAN, contact details, notes, amounts, references, balances, profit percentages and identity documents.

Default role grants (review them in the Control Center):

| Role | Phase 3 defaults |
|---|---|
| Partner | Own partner profile, financial summary, documents, own contributions, withdrawals and ledger (view only) |
| Accountant | Assigned partners: view, record and edit drafts of contributions and withdrawals, view and export assigned ledgers, reports. Can't change partner profiles or post. |
| Manager | Partner directory only (PAN masked). No capital figures and no profit sharing. |
| Viewer | Nothing new |
| Super Admin | Everything |

### Accounting integration foundation
- **Posting queue:** `App\Services\Accounting\PostingQueue` records each posted event in `accounting_posting_queue`. Examples:
  - a contribution: *Dr `payment_source:<method>:<ref>`, Cr `partner_capital:CAP-PTR-0001`*
  - a withdrawal: the reverse
  - an adjustment: a clearing reference
  - a reversal: the original's sides swapped
- **Phase 12** implements `PostingTarget::post()` and calls `PostingQueue::process()`.
- **Safety:** the unique key `(source_type, source_id, event)` guarantees no transaction is counted twice. No bank accounts or ledgers are invented.

## Security and testing results

```
php tests/run.php
119 passed, 0 failed, 794 assertions (PHP 8.3.6 · SQLite)
```

New suites:
- `tests/Integration/PartnerCapitalTest.php` (13 tests)
- `tests/Feature/PartnerSecurityTest.php` (5 HTTP tests)
- `tests/Unit/MoneyTest.php` (4 tests)

| # | Requirement | Covered by |
|---|---|---|
| 1 | Create multiple partners (no fixed count, unique IDs, no permissions granted) | `testCreateMultiplePartners…`, `testPanValidation…` |
| 2 | Edit partner profiles (audited, PAN masked in the audit log) | `testEditPartnerProfile…` |
| 3 | Link login accounts (1:1, roles unchanged, Partner role only, can't assign roles) | `testLinkLoginAccounts…` |
| 4–5 | Different contributions, several for the same partner | `testDifferentAndRepeatedContributions…` |
| 6–8 | Submit, approve, post, reject (pending, approved and rejected never count) | `testApprovalWorkflowGatesPosting…` |
| 9–10 | Withdrawals limited to eligible capital, pending reserved, exception policy | `testWithdrawalsCannotExceed…`, HTTP workflow test |
| 11 | Running, opening and closing balances; formula; as-of date | `testRunningBalanceOpeningClosing…` |
| 12 | Reversals, no double reversal, no duplicate posting, queue unique | `testReversalRestoresBalance…` |
| 13–15 | Profit-sharing history, 100% validation, overlap and retroactive blocks | `testProfitSharingHistory…` |
| 16 | Partner login sees only its records (lists, 404 on others, dashboard) | `testPartnerLoginSeesOnlyItsOwn…` |
| 17 | Hidden and masked financial fields on pages and in exports | `testHiddenAndMaskedFields…` |
| 18 | Document downloads: private storage, type checks, scope, identity rule, guests | `testDocumentsArePrivate…` |
| 19 | Exports (CSV, Excel, PDF) respect record scope and permissions | `testReportsAndExportsRespect…` |
| 20 | Audit logging (actor, IP, module, old and new values) | `testFinancialActionsAreAudited…`, HTTP workflow test |
| 21 | PHP 8.5: strict types, no deprecated APIs; `composer.json` targets 8.5 | Lint with PHP 8.3, which is the only PHP version in the build environment |
| 22 | Phase 1 and 2 still work | All 97 earlier tests pass (two counts updated for the new modules and setting) |

**Issues found and fixed during testing:**
- An entry page showed the partner balance without checking ledger record scope.
- The create form ignored a hidden amount field.
- A closure bug in "create login" broke that action.
- The DECIMAL(15,2) maximum was off by a factor of ten.
- Inline `style` attributes were blocked by the strict CSP; bar widths and chart heights are now set from data attributes.

**Not verified here:** the build environment has no MySQL and no PHP 8.5. The MySQL DDL from `migrate --pretend` was generated and reviewed. Please run the migrations on your XAMPP MySQL. The Chart.js widget couldn't be rendered offline; its table fallback was checked.

## Pending — Phase 4 (GST Registration Management)

- GST registration master: GSTIN validation (checksum, state code, PAN segment), legal and trade name, registration type, state and address, effective dates, status.
- Link GSTINs to partners (the GST tab on the partner profile is ready) and turn on the `selected_gst_registrations` record scope and GST resource assignments with real rows.
- Field rules for GST portal credentials. Store only non-secret identifiers, never portal passwords or OTPs.
- Documents for GST certificates, using the Phase 3 private document store.
- GST-registration filters in partner reports, and dashboard counts.
- Keep the `bank_account_id` and payment-source references ready for Phase 5 bank accounts.
