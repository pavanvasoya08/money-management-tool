# Phase 5 — Bank Account Management

Phase 5 adds bank accounts, a bank ledger, internal transfers, statements, CSV import and reconciliation. It builds on Phases 1–4 and replaces nothing. Permissions, record scopes, field rules, resource assignments, approval workflows and the audit log are the existing Phase 2 ones; no second permission or audit system was added.

**Scope boundary.** There is **no bank API integration and no automatic bank synchronisation**. Every entry and every statement is recorded or uploaded by a user. Importing a statement CSV only stores its lines for reconciliation; it never creates, changes or posts ledger entries. The system never stores internet-banking passwords, ATM PINs, UPI PINs, OTPs or card credentials, and has no field for them.

## Updating from Phase 4

```bash
git pull
composer install                 # refreshes the autoloader (new classes)
php bin/console key:generate     # ONCE: writes APP_KEY to .env (needed to encrypt account numbers)
php bin/console migrate          # 2 new migrations: 000030 (15 bank_* tables), 000031 (approval_workflows: approve_action, reject_action)
php bin/console db:seed          # bank permissions, account types, 3 business activities, 7 workflows, 3 settings, 14 help guides
php bin/console migrate:status   # every migration should show "Ran"
php bin/console app:check        # now also reports whether APP_KEY is set
```

**XAMPP:** run the same commands in the XAMPP Shell from the project folder.

**`APP_KEY` is required.** Account numbers are encrypted with AES-256-GCM using `APP_KEY`, and looked up through a keyed hash. `key:generate` refuses to overwrite an existing key unless you pass `--force`. **Never change or lose the key once accounts exist** — the stored numbers could no longer be decrypted. Back it up with your database backups, and keep `.env` out of version control (it already is).

**What the seeder does on an existing install**
- Registers the **Bank Accounts** module with 39 actions and field rules (account number, IFSC, MICR, holder, branch, opening and current balance, counterparty, internal notes), and removes the retired Phase 2 placeholder actions.
- Loads 6 account types (current, savings, cash credit, overdraft, fixed deposit, other) — editable in **Bank settings** — and adds 3 business activities to the shared master: marketplace settlements, supplier payments, general operations.
- Adds 7 **inactive** approval workflows (new account, key-detail change / central account change, closure, deposit, withdrawal, adjustment, reconciliation reopening) and gives the existing *Internal bank transfer* workflow its own approve/reject permissions. While a workflow is inactive, the action applies at once.
- Grants defaults **once** (so later changes are kept): Partner → view own linked accounts; Accountant → dashboard, view assigned, record deposits/withdrawals/other entries, statements, import, reconcile, documents, history, export, print; Manager → dashboard and view all. Super Admin has everything.
- Creates **no** accounts, entries or statements.

To check the MySQL SQL first: `php bin/console migrate --pretend`.

## Quick start after updating

1. **Bank Management → Bank Accounts → Add bank account.** Enter the account details and the opening balance with its date; **Save & submit**.
2. On the central account's **Settings** tab, **Designate as central funding account**.
3. On each account, link partners, GST registrations and business activities.
4. Record partner capital in **Partners & Capital** as before, then **Record deposit** on the central account and choose the contribution in **Capital entry**.
5. Move money with **Internal Transfers → New transfer**.
6. Upload each month's statement; import CSV statements; reconcile.
7. *(Optional)* **Control Center:** switch on the bank workflows you want, and assign accounts to accountants (resource type *Bank accounts*, scope *Assigned*).

## What was built

### Data model (15 tables + 2 columns)
`bank_account_types`, `bank_accounts`, partner/GST/business-activity mapping tables, `bank_statements`, `bank_statement_imports`, `bank_statement_rows`, `bank_transfers`, `bank_transactions` (the single ledger), `bank_reconciliations`, `bank_reconciliation_matches`, `bank_account_documents`, `bank_change_requests`, `bank_account_history`. All money columns are `DECIMAL(15,2)` and all arithmetic uses integer paise. `approval_workflows` gains optional `approve_action` / `reject_action` so one module can have separate approvers.

### Accounts
- Unlimited accounts; statuses Draft → Pending approval → Active ↔ Inactive ↔ Frozen/Blocked → Closed, with transition rules and a status history. Closing needs a zero balance. Accounts with entries are never deleted; archiving makes them read-only.
- **Account numbers:** encrypted at rest; a keyed blind index detects duplicates; only the last four digits are stored in clear. Pages, lists, reports and exports show `XXXXXX1234`. The full number is revealed on request only to users with `view_full_account_number`, and each reveal is logged. Audit logs never contain the number.
- **Duplicates:** the same number at the same IFSC is refused (unique index). The same number at another IFSC needs an explicit confirmation — numbers are not assumed to be globally unique.
- The opening balance is locked after activation; corrections go through an *opening-balance adjustment* entry.
- Key-detail changes (number, IFSC, holder, type, currency, opening balance, overdraft) go through the change-request workflow when it is active.

### Central joint funding account
Exactly one at a time, designated or removed with a reason through the controlled change process (approval when the *Bank account change* workflow is on). The previous central account loses the designation when the new one takes effect. Its card shows partner contributions received (linked to Phase 3 capital entries), transfers out, transfers back in, the current and available balance, and the transfer history with per-entry opening/closing balances.

### Mappings
Partners (holder, joint holder, authorised operator, beneficiary, other), GST registrations (primary/secondary; one primary account per registration) and business activities (the Phase 4 master). All are many-to-many with effective dates, end dates and history. **Mappings never imply ownership, capital share or profit share.** A GST registration referenced by an account or bank entry cannot be archived.

### Ledger and transactions
- Types: deposit, withdrawal, internal transfer (in/out), bank charges, interest received, other adjustment, opening-balance adjustment, reversal.
- Statuses: draft, pending approval, approved, rejected, posted, reversed.
- **Balance = opening balance + posted credits − posted debits**, always calculated from the ledger (an account page shows each component). There is no way to edit a balance.
- **Posting** locks the account row (and, for transfers, both accounts in id order), re-checks the balance and uses a guarded status update, so an entry can never post twice.
- A withdrawal, charge or transfer that would make a balance negative is refused unless the account allows an overdraft, and then only up to the limit.
- When an approved entry cannot post (the balance changed meanwhile), it stays *approved* with the reason and can be posted later.
- Posted entries are never edited or deleted. **Reversal** creates a new posted entry linked to the original (one reversal per entry) and marks the original *reversed*.
- A possible duplicate (same account, amount and reference within a configurable number of days) must be confirmed before saving.

### Partner capital link (Phase 3)
A deposit may reference one approved/posted capital contribution, and a withdrawal one capital withdrawal. Amounts must match, and the partner comes from the capital entry. One live link per capital entry is enforced under a lock. Posting records the bank account on the capital entry. **Capital is never created or duplicated by Bank Management, and capital is never treated as revenue.**

### Internal transfers
One transfer record plus two ledger entries (transfer out / transfer in) with one TRF reference, posted together in one database transaction. Same-account and cross-currency transfers are refused; the source balance is validated and locked. Transfers have their own approve/reject permissions and are excluded from money-received/paid totals. Reversing a transfer reverses both sides together, and is refused if the destination no longer holds the money (unless it has overdraft room).

### Statements, CSV import, reconciliation
- **Statements:** upload per account with period, opening/closing balances, notes and file (PDF, images, spreadsheets, CSV up to 10 MB, stored privately; downloads need `download_documents` and are logged). The period cannot end in the future.
- **CSV import:** column-mapping UI (separator, date/description/reference/debit/credit or signed amount/balance, date format, header row), automatic detection of common headers and date formats, preview with per-line validation, duplicate detection against lines already imported for the account, then confirm or discard. Indian digit grouping, ₹ and Dr/Cr suffixes are parsed. Import history is kept. **No ledger entries are created.**
- **Reconciliation:** start for an account, period and statement; suggestions by amount, direction, date tolerance and reference; accept suggestions in bulk or match manually; record a missing entry from a line (a normal ledger entry, through the normal rules); ignore a line with a reason; unmatch. One active match per line and per entry. Completing needs every line matched or ignored, and a note if closing balances differ. Who and when is recorded; reopening a completed reconciliation needs `reopen_reconciliation` and, when the workflow is on, approval. Reconciliation never changes ledger entries.

### Pages
Bank dashboard; accounts list; add/edit account; account profile with 12 tabs (overview, account information, transactions, transfers, partners, GST, activities, statements, reconciliation, documents, settings, history); transactions list, form and detail; transfers list, form and detail; statements list, detail and import; reconciliation list and workspace; reports index and 11 reports; bank settings. Partner profiles gain a **Bank accounts** tab, capital entries show their bank link, GST registrations list their accounts, the Approvals inbox shows bank requests, and the main dashboard has a bank widget.

### Reports (11)
Bank account summary, account-wise balances, transactions, deposits and withdrawals, internal transfers, partner-linked accounts, GST-wise accounts, monthly cash flow, reconciliation, unmatched statement lines, bank charges and interest. Each has filters and CSV, Excel and PDF export plus print; record scope and field rules apply to screens and exports.

### Phase 2 integration
Module `bank_accounts` with role permissions, user allow/deny overrides, record scopes (all / own = linked partner or created by me / assigned), resource assignments (type *Bank accounts*), field rules, approval workflows with per-workflow approve/reject permissions and self-approval blocking, and audit entries for every change.

## Security and testing results

```
php tests/run.php
169 passed, 0 failed, 1710 assertions (PHP 8.3.6 · SQLite)
```

New suites:
- `tests/Integration/BankAccountTest.php` (16 tests)
- `tests/Feature/BankSecurityTest.php` (5 HTTP tests)

| # | Scenario | Covered by |
|---|---|---|
| 1 | Create multiple accounts; encrypted, masked numbers | `testCreateMultipleAccountsWithEncryptedNumbersAndDuplicateRules` |
| 2 | Duplicate detection (same IFSC refused, other IFSC needs confirmation) | same |
| 3 | One central funding account; change through approval | `testCentralFundingAccountIsUniqueAndChangedThroughApproval` |
| 4 | Partner, GST and activity mappings: many-to-many with history | `testPartnerGstAndActivityMappingsAreManyToMany` |
| 5 | Deposits, withdrawals, balance components | `testDepositsWithdrawalsAndBalanceComponents` |
| 6 | Negative balance prevented unless overdraft is configured | `testNegativeBalanceIsPreventedUnlessOverdraftIsConfigured` |
| 7 | Transfer posts both sides atomically; never posts twice; no same-account transfer | `testInternalTransferPostsBothSidesAtomicallyAndCannotPostTwice`, HTTP |
| 8 | Transfer reversal reverses both sides; refused if the money is gone | `testTransferReversalIsRefusedWhenDestinationNoLongerHoldsTheMoney` |
| 9 | Approval workflows gate posting; self-approval blocked | `testApprovalWorkflowsGatePostingAndBlockSelfApproval` |
| 10 | Approved entry that cannot post waits with a reason | `testApprovedEntryThatCannotPostWaitsWithAReason` |
| 11 | Reversal only; posted entries never edited; audit history | `testReversalAndAuditHistory` |
| 12 | Capital contribution linked to a deposit without duplicating capital | `testPartnerContributionLinksToADepositWithoutDuplicatingCapital` |
| 13 | Statement upload, CSV import preview, duplicates, reconciliation, reopen | `testStatementImportPreviewDuplicatesAndReconciliation` |
| 14 | Amount parsing and column/date-format detection | `testStatementAmountParsing`, `testStatementColumnAndDateFormatGuessing` |
| 15 | Closing needs zero balance; archived accounts read-only; opening balance locked | `testClosingNeedsZeroBalance…`, `testOpeningBalanceIsLockedAfterActivation` |
| 16 | Role permissions (accountant, partner, viewer, manager) and record scope, 404 outside scope | `BankSecurityTest` (3 tests) |
| 17 | User-specific overrides; field rules in pages and exports; download permission | `testUserSpecificOverridesAndFieldRules` |
| 18 | Full number needs its own permission and is logged; CSRF; all pages, tabs and 11 reports × 3 formats | `testAdminFlowsCsrfFullNumberAndExports` |

Phases 1–4 are unaffected: all their existing suites pass unchanged, apart from count assertions updated for the new settings (3), workflows (7) and help category.

Also checked by hand against a demo database: every bank page and tab as five different users (no server errors or PHP warnings; out-of-scope records answer 404, the viewer 403), and the screenshots for the help guides.

**Issues found and fixed during testing:**
- The shared money rule refused zero, so a zero opening balance or overdraft limit could not be saved.
- Decimal values read back from SQLite as numbers made unchanged overdraft limits look like key changes (and trigger approval); values are now compared as money.
- Re-saving an unchanged account number looked like a change because each encryption uses a fresh IV; numbers are now compared by their keyed hash.
- A transaction column referenced statement lines before that table existed in the migration.
- The `approval_workflows` change lived in the create-tables migration; it now has its own migration so the MySQL DDL check passes.
- Statement import did not detect a "Value Date" column when it was the only date, and always defaulted to DD/MM/YYYY; both are now detected.
- Reconciliation history did not record which reconciliation an unmatch or ignore belonged to.
- The counterparty field was only hidden in the form; it is now also refused on the server for users who may not edit it.
- Dashboard filter selects were cut off; a reconciliation tile mixed two figures; entry numbers wrapped.

**Not verified here:** the build environment has no MySQL and no PHP 8.5 (tests run on PHP 8.3 with SQLite). The MySQL DDL for both migrations was generated through the grammar `migrate --pretend` uses and reviewed (all identifiers ≤ 56 characters, foreign keys `BIGINT UNSIGNED`). Please run the migrations on your XAMPP MySQL. The dashboard charts load Chart.js from a CDN, which was unreachable here; the "Show as table" fallback was verified instead.

## Known limitations
- No bank API, no automatic synchronisation, no OFX/MT940 import — CSV only.
- Reconciliation matching is one-to-one (one statement line ↔ one ledger entry); one line covering several entries must be split or ignored with a note.
- Transfers between accounts in different currencies are not supported.
- Single currency totals: dashboard and report totals in rupees count INR accounts only.

## Help & User Guide

New category **Phase 5 – Bank Account Management** with 14 guides and 30 annotated screenshots taken from the running application: the dashboard, adding an account, the central account, linking partners, linking GST and activities, deposits, withdrawals, internal transfers, viewing transactions and reversals, uploading statements, CSV import, reconciliation, reports, and permissions and security.

## Not included (by design)

Bank API integration, automatic statement fetching, payment initiation, accounting journal posting (Phase 12), and marketplace, product, sales or purchase records. Phase 6 has not been started.
