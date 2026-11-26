# Phase 7 — Inventory Management

Phase 7 adds a central inventory system:
- warehouses and nested storage locations;
- inventory settings for the existing products and variants;
- units and conversions;
- opening stock, stock inward and stock outward;
- transfers with in-transit stock and partial receipts;
- adjustments;
- batches with expiry, serial numbers and reservations;
- FIFO and weighted-average valuation;
- an immutable stock ledger, low-stock monitoring, 20 reports and 19 help guides.

It builds on Phases 1–6 and replaces nothing. Permissions, record scope, field rules, resource assignments, approval workflows and the audit log are the existing Phase 2 systems; no second permission engine was added. Products, variants, suppliers, GST registrations and business activities are reused, never duplicated.

**The stock ledger is the source of truth.**
- Every quantity and value comes from posted ledger movements (`inventory_stock_movements`), which are insert-only.
- The balance tables are summaries, changed only inside the same transaction that posts a movement.
- Corrections are reversals or adjustments, never edits.

**Scope boundary.** Phase 7 does not include:
- marketplace integrations;
- sales orders, purchase invoices, supplier payments or customer-return management;
- journal entries or an accounting engine;
- GST filing;
- automatic purchase orders;
- external warehouse integrations.

Stock movements create no bank transactions.

## Updating from Phase 6

```bash
git pull
composer install                 # refreshes the autoloader (new classes)
php bin/console migrate          # 1 new migration: 000033 (31 warehouse / inventory tables)
php bin/console db:seed          # inventory permissions, warehouse types, units, 10 workflows, 6 settings, 19 help guides
php bin/console migrate:status   # every migration should show "Ran"
```

**XAMPP:** run the same commands in the XAMPP Shell from the project folder. To check the MySQL SQL first, run `php bin/console migrate --pretend`. The migration has:
- 31 tables;
- 132 foreign keys;
- identifiers of at most 58 characters;
- rollback support.

**What the seeder does on an existing install**
- **Module.**
  - Replaces the Phase 2 placeholder actions of **Inventory** with 37 actions:
    - the 31 actions from the specification;
    - `view_all`, `view_own` and `view_assigned` (record scope);
    - `approve` and `override_stock_rules`;
    - `print`.
  - Removes the retired placeholder actions.
- **Field rules.**
  - `stock_value` and `unit_cost`;
  - `warehouse_contact`;
  - `internal_notes`.
- **Masters.**
  - 8 warehouse types: main, secondary, fulfilment, marketplace, retail store, returns, damaged goods, other. These are editable.
  - 16 units: the Phase 6 product units plus carton.
  - 5 company-wide conversions: dozen = 12 pieces, pair = 2 pieces, kg = 1000 g, l = 1000 ml, m = 100 cm.
- **Workflows.** Adds 10 **inactive** approval workflows:
  - opening stock;
  - stock inward;
  - stock outward;
  - transfer;
  - adjustment;
  - batch correction;
  - serial correction;
  - reserved-stock override;
  - negative-stock override;
  - valuation correction.
- **Settings.** Adds 6 settings in the **inventory** group:
  - default valuation method: weighted average;
  - removal method: FEFO;
  - expiry warning: 30 days;
  - block expired dispatch: on;
  - in-transit transfers: on;
  - reservation validity: 7 days.
- **Default grants**, applied once; later changes are kept.
  - **Accountant:** view all warehouses and stock, stock cost and ledger, documents, history, export and print. No stock movements.
  - **Manager:** records inward, outward, transfers, adjustments, reservations, batches, serials, locations and documents. Cannot see costs, approve, reverse or change settings.
  - **Super Admin** has everything.
  - **Viewer** and **Partner** get nothing.
- **Template.** Adds a new **Stock operator** template: assigned warehouses only; can record inward, outward and transfers; cannot approve, adjust or see costs. Refreshes the **Inventory manager** template.
- **Data.** Creates **no** warehouses, stock or documents.

## Warehouses and locations

**Inventory → Warehouses → Add warehouse.**
- Each warehouse has a name, an automatic or manual code, a type, address and contact details, and internal notes. Contact details and notes are field-protected.
- Switch on **Storage locations are required** to make every receipt name a location.
- On the warehouse page (nine tabs):
  - link GST registrations and business activities with effective dates;
  - assign users. Users with *assigned warehouses* visibility see only those warehouses everywhere, including exports.
- Statuses are Active, Inactive and Archived:
  - **Inactive** blocks all movements.
  - **Archive** is refused while the warehouse holds stock or has open documents.
  - **Delete** is only possible for an archived warehouse that was never used.

**Storage locations:**
- types zone, section, aisle, rack, shelf and bin, nested up to 6 levels;
- an optional capacity;
- a location cannot sit under itself or one of its sub-locations;
- a location is never deleted, and cannot be archived while it holds stock.

## Products, units and settings

Inventory uses Phase 6 products and variants. A product is stockable when it **tracks inventory** and is not a service. Its inventory settings:
- stock per product or per variant;
- default warehouse and inventory status;
- reorder level and quantity, minimum, maximum and safety stock;
- negative stock (off by default);
- batch, expiry and serial tracking;
- valuation method.

The stock unit is the product's own unit. Documents can be entered in any unit that converts to it, either company-wide or per product. Conversions are exact fractions, and a conversion that would create a loop or a second path is refused. These settings are locked once they matter:
- the tracking level and the product unit, after the first movement;
- tracking and the valuation method, while stock is on hand.

## Stock inward / outward workflow

| Step | What happens |
|---|---|
| Draft | Lines are validated: item, convertible unit, location rules, batch and expiry, the serial count equal to the quantity, unique serials. Nothing affects stock. |
| Submit | Conditional status change to *pending approval* (prevents double submission). The workflow is chosen by direction; documents with overrides use the override workflows. An inactive workflow approves at once. |
| Approve | The approval listener posts the document in one transaction: it locks each stock item, re-checks free stock, posts the ledger movements with a unique posting key, updates the summaries and moves the status *approved → posted* with a conditional update. |
| Posting refused | For example, stock was issued meanwhile. The document stays *approved* with the reason shown, and can be posted again or cancelled. Nothing is half-posted. |
| Reverse | Posts opposite movements at the original cost (`reversal_of_id` is unique). A receipt is reversible only while its stock is on hand; under FIFO, only if its cost layer is untouched. |

**Inward types:**
- opening stock (one posted entry per item and warehouse);
- **purchase receipt**, with a supplier and reference; it can be linked to its purchase document in Phase 11 through `source_document_type/id`, which is unique;
- **customer return**, where each line is classified as sellable, damaged, inspection required, rejected or restock pending. Only *sellable* goes to available stock.
- other receipt;
- transfers in and adjustments in, which are posted by their own modules.

**Outward types:**
- sales dispatch, reserved for Phase 9 orders and manual until then;
- supplier return;
- damaged and expired write-offs;
- internal consumption;
- adjustment out.

**Outward rules:**
- Issues are limited to free stock: sellable stock minus reservations, minus expired batches (while blocking is on) and batches on hold.
- Negative stock is refused unless the product allows it or an approved override is set.
- Batches are picked by FEFO or FIFO when none is named.
- Serial numbers must be in stock at that warehouse.

## Transfers

- **Between warehouses, dispatch** moves the stock to the *in transit* condition at the source (`transit_out` / `transit_in`). It stays counted as stock of the source until received.
- **The destination** records receipts: good and damaged quantities, the destination location and the serials.
- **Partial receipts** leave the rest in transit.
- **Loss in transit** is written off as an authorised adjustment at its cost.
- **Same warehouse:** a transfer with the same source and destination warehouse is a location move.
- **Statuses:** Draft, Pending approval, Approved, In transit, Partially received, Received, Rejected, Cancelled and Reversed.
- **Not purchases or sales:** internal movement types are excluded from receipts and issues in the reports.
- **Refused:**
  - a transfer to the same location;
  - quantities above the free stock;
  - reversing a transfer that has a written-off loss.

## Adjustments and reversals

- **Count lines** keep the system quantity (snapshot), the physical quantity and the difference. Posting re-checks the system quantity and refuses if stock moved since the count.
- **Condition lines** move stock between available, inspection and damaged.
- **Reasons:**
  - physical difference;
  - damaged;
  - expired;
  - missing;
  - found;
  - data correction;
  - other.
- **Found stock** is valued at the entered cost or the current average.
- **Posted adjustments** are reversed with opposite movements, never deleted.

## Batches and serial numbers

- **Batches** have a number (unique per product and variant), manufacturing and expiry dates, supplier and purchase references, receipt cost, status (active, on hold, closed) and full movement history.
  - Near-expiry uses the warning period.
  - Expired batches are not issued unless an override is approved.
  - Batch number and date corrections are applied at once when no stock has moved; otherwise they go through approval.
- **Serial numbers** cover one unit each and are unique per product. Each one records its current warehouse, location, batch, condition and status (in stock, in transit, dispatched, returned to supplier, consumed, written off, reversed), with history in `inventory_serial_movements`. Corrections go through approval.

## Reservations

- **What a reservation holds:** stock reserved against a sales order, an internal requirement or another reference. Reserved stock is excluded from available stock.
- **Statuses:** Active, Partially fulfilled, Fulfilled, Released, Expired and Cancelled.
- **Rules:**
  - Only free stock can be reserved.
  - The same reference cannot reserve the same item twice (a unique dedupe key).
  - Reservations expire on their expiry date.
- **Fulfilment:** an outward line fulfils a reservation.
- **`ReservationService::releaseReference()`:** Phase 9 calls this to release a cancelled order's reservations.

## Valuation (FIFO and weighted average)

All quantities are scaled integers (1/10000) stored as DECIMAL(18,4); money is stored as DECIMAL. No FLOAT or DOUBLE is used.

- **Weighted average:**
  - Each receipt adds its cost to the item's value.
  - Issues leave at value × qty / on-hand, with exact rounding.
- **FIFO:**
  - Each receipt creates a cost layer.
  - Issues consume the oldest layers first, recorded in `inventory_cost_layer_usage`.
  - Reversals restore the layers.
- **Configuring the method:** the default is configured by Super Admin in **Inventory settings** with a reason, logged in `inventory_valuation_history`. A product may use its own method; it is locked while stock is on hand.
- **Revaluation** of stock on hand is approved through the valuation-correction workflow.
- **Valuation views:** by item, product, variant and warehouse, plus the total, on the dashboard, the Valuation page and the reports.
- **Reconcile with ledger** compares every balance, item quantity, item value and FIFO layer with the ledger. A Super Admin can rebuild the summaries from the ledger.
- **No accounting entries:** none are created. The ledger keeps the unit cost and value of every movement, including opening stock and issues (future COGS), for Phase 12.

## Permissions and approval workflows

**Module `inventory` (37 actions):**
- `view_dashboard`, `view` (+ scope `view_all` / `view_own` / `view_assigned`)
- `manage_warehouses`, `create_warehouse`, `edit_warehouse`, `archive_warehouse`, `manage_locations`
- `manage_inventory_settings`, `manage_units`, `manage_reorder_settings`
- `manage_opening_stock`, `approve_opening_stock`
- `manage_stock_inward`, `manage_stock_outward`
- `manage_stock_transfers`, `approve_stock_transfers`, `receive_stock_transfers`
- `manage_stock_adjustments`, `approve_stock_adjustments`, `approve`, `override_stock_rules`
- `manage_batches`, `manage_serial_numbers`, `manage_reservations`, `release_reservations`
- `manage_stock_valuation`, `view_stock_cost`, `view_stock_ledger`, `reverse_stock_movements`
- `manage_documents`, `download_documents`, `export`, `print`, `view_history`

**Record scope** is the warehouse, and resource type `warehouse` is used for assignments. Every controller loads records through `InventoryScope`, so a record outside the scope returns 404. Transfers are visible from either side.

**Field rules:**
- stock value;
- unit cost (hidden or masked on pages, reports and exports; cost columns are also dropped without `view_stock_cost`);
- warehouse contact;
- internal notes.

**Approval workflows.** All 10 are inactive by default; switch them on under Control Center → Approval workflows. Self-approval is blocked, and history is kept in the approval engine and in `inventory_history`.

| Workflow | Approves |
|---|---|
| Opening stock | Opening stock entries before posting |
| Stock inward / Stock outward | Receipts / issues before posting |
| Stock transfer | Transfers before dispatch |
| Stock adjustment | Counts and condition changes before posting |
| Batch / Serial number correction | Corrections to batches or serials that moved |
| Reserved-stock override / Negative-stock override | Outwards that issue reserved stock / go below zero |
| Valuation correction | Revaluations of stock on hand |

**Audit:**
- Every create, change, status change, posting, reversal, export and document upload or download is written to the audit log.
- Every inventory record change is also written to the insert-only `inventory_history`, with old and new values, reason, actor and warehouse.

## Pages, reports and help

**Pages (22):**
- dashboard;
- warehouses, add / edit warehouse and the warehouse page (9 tabs);
- storage locations;
- warehouse stock;
- product inventory list and product page (8 tabs);
- opening stock, inward and outward (list, form, detail);
- transfers and transfer detail (receive and write off);
- adjustments;
- batches and batch detail;
- serial numbers and serial detail;
- reservations;
- stock ledger;
- valuation;
- low stock;
- 20 reports;
- settings (rules, valuation method, units and conversions, warehouse types);
- history.

The approval page shows what approving an inventory document will do.

**Reports (20).** Every report has filters (dates, warehouse, location, product, variant, category, brand, batch, serial, movement type, status, GST registration, business activity) and CSV, Excel or PDF export. Exports are logged.
- complete stock summary;
- warehouse-, product- and variant-wise stock;
- stock ledger, inward, outward, transfers, adjustments and opening stock;
- batch-wise stock and expiry;
- serial numbers;
- reserved and available stock;
- low stock with suggested reorder and the Phase 6 preferred supplier (no purchase order is created);
- out-of-stock;
- valuation;
- movement summary;
- damaged stock.

**Help.** The new category **Phase 7 – Inventory Management** has 19 guides with annotated screenshots from the running application. Besides the 17 requested, it adds Units of measurement and Inventory permissions. Every signed-in user can read them.

## Integration readiness (Phases 8–12)

| Phase | Ready now |
|---|---|
| 8 E-commerce | Available stock per warehouse and item (`StockQueryService::items`); warehouse types *marketplace* / *fulfilment*; GST and activity mappings per warehouse |
| 9 Sales | `ReservationService::reserve / fulfil / releaseReference`; *sales dispatch* outward with `source_document_type/id` (unique, so an order cannot be deducted twice); outward lines can fulfil reservations |
| 10 Returns | *Customer return* inward with per-line condition (sellable, damaged, inspection required, rejected, restock pending) |
| 11 Purchases | *Purchase receipt* inward with supplier, reference and `EntryService::linkSource()`; batches keep supplier and purchase references; low-stock report lists preferred suppliers |
| 12 Accounting | Opening stock value, unit cost and value of every movement, FIFO layers and usage, valuation history |

## Testing

`php tests/run.php` → **204 passed, 0 failed, 3,487 assertions** (SQLite, PHP 8.3 sandbox).

New tests:
- **`tests/Integration/InventoryTest.php`** (14 tests). It covers:
  - warehouses, nested locations and cycles;
  - archive rules and mappings;
  - unit conversions and loops;
  - product and variant settings;
  - opening stock and duplicates;
  - inward and outward;
  - FIFO and weighted average;
  - transfers with partial receipt, loss and reversal;
  - adjustments with recount;
  - batches, FEFO and expiry;
  - serial numbers;
  - reservations;
  - negative stock;
  - duplicate posting;
  - **two concurrent PHP processes** trying to oversell;
  - reversals;
  - reconcile;
  - approvals and permissions.
- **`tests/Feature/InventorySecurityTest.php`** (4 HTTP tests). It covers:
  - every inventory page and all 20 report exports render;
  - viewer gets 403;
  - an assigned-warehouse user sees only their warehouses, with other records returning 404, including exports;
  - cost columns are hidden without `view_stock_cost`;
  - field rules;
  - negative stock is refused through HTTP;
  - CSRF;
  - the line editor round trip with unit conversion and kept input after errors.

Existing tests were updated for the new counts: workflows, settings, templates and help categories.

A crawl of all inventory pages on demo data found no server errors and no PHP notices:
- admin opened 174 pages;
- an accountant with assigned warehouses opened 153;
- a manager opened 227;
- the two users without inventory access opened only pages that correctly return 403.

## Known limits (honest list)

- **MySQL:** tests run on SQLite here. The MySQL DDL was generated and checked (`migrate --pretend`) but not executed against a MySQL server in this sandbox. Row locking uses `UPDATE … lock_version` on the stock item, which works on both.
- **Document linking:** purchase receipts, sales dispatches and customer returns are recorded manually until Phases 9–11 link them to their documents.
- **Units on transfers and reservations:** transfers and reservations are entered in the product's stock unit; unit conversion is available on inward, outward and opening stock.
- **Quantity totals:** dashboard quantity totals add different units and are labelled as indicative.
- **Charts:** no Chart.js charts were added; the dashboard uses lists and bars.
