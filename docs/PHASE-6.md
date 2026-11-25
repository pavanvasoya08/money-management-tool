# Phase 6 — Product & Supplier Management

Phase 6 adds the product catalogue (categories, brands, attributes, variants, SKUs, barcodes, prices, GST/HSN, images and documents) and supplier management (GST details, contacts, addresses, documents, secure bank details, payment references and product-supplier mapping). It builds on Phases 1–5 and replaces nothing. Permissions, record scopes, field rules, resource assignments, approval workflows and the audit log are the existing Phase 2 ones; no second permission or audit system was added.

**Scope boundary.**
- **No inventory, stock or warehouses.** The *tracks inventory* flag only prepares Phase 7.
- **No marketplace, purchase or sales records.** Marketplace prices are plain price rows per channel name.
- **No GST calculation, return or filing.** HSN/SAC codes and GSTINs are checked for **format only**; nothing is verified with the GST portal or any government system.
- **No supplier payment engine.** A supplier "payment reference" only links an existing, posted withdrawal from Bank Management to a supplier; nothing is posted or created in the bank ledger.
- **Supplier bank details are kept separate from company bank accounts.** They are encrypted with `APP_KEY` like Phase 5 account numbers. Banking passwords, PINs, OTPs and card data are never stored, and there is no field for them.

## Updating from Phase 5

```bash
git pull
composer install                 # refreshes the autoloader (new classes)
php bin/console migrate          # 1 new migration: 000032 (33 product_* / supplier_* / gst_* tables)
php bin/console db:seed          # product & supplier permissions, masters, 11 workflows, 7 settings, 14 help guides
php bin/console migrate:status   # every migration should show "Ran"
```

**XAMPP:** run the same commands in the XAMPP Shell from the project folder. To check the MySQL SQL first: `php bin/console migrate --pretend`.

`APP_KEY` (created in Phase 5) is required: supplier account numbers are encrypted with it. Never change or lose it once data exists.

**What the seeder does on an existing install**
- **Modules.** Replaces the Phase 2 placeholder actions of **Products** (30 actions) and **Suppliers** (27 actions), and removes the retired placeholder actions.
- **Product field rules.** purchase cost, selling price, supplier details, tax configuration and internal notes.
- **Supplier field rules.** contact email, phone and person, GSTIN, PAN, addresses, payment terms, bank account number (masked to the last 4 by default) and internal notes.
- **Masters.** 5 product types, 7 supplier types, 6 variant attributes (size, colour, material, weight, capacity, packaging — no values), 5 tax categories and 10 GST rates. The rates are the slabs in force since 22 Sep 2025 (0, 5, 18, 40 %), the special rates (3, 1.5, 0.25, 0.1 %), and the retired 12 % and 28 % slabs, seeded **inactive** for history. All masters are editable.
- **Workflows.** Adds 11 **inactive** approval workflows:
  - Products: new product, key-detail change, SKU change, GST/HSN change, price change, product archival, preferred supplier change.
  - Suppliers: new supplier, supplier GSTIN change, supplier bank details, supplier archival.
  - While a workflow is inactive, the action applies at once.
- **Settings.** Adds 7 settings in the new **products** group: automatic SKUs, manual SKUs, product SKU pattern `{CAT}-{SEQ:5}`, variant SKU pattern `{PARENT}-{SEQ:2}`, one preferred supplier, GST/HSN required for activation (off), and one supplier per GSTIN.
- **Default grants**, applied **once** (later changes are kept):
  - Accountant: product dashboard, view all products, downloads, history, export and print; supplier dashboard, **view assigned suppliers**, downloads, history, export and print.
  - Manager: maintain products, variants and supplier mappings, and maintain suppliers, addresses, documents and mappings. No pricing, tax, SKU, settings or bank-detail rights.
  - Super Admin has everything. Viewer and Partner get nothing.
- **Data.** Creates **no** products, suppliers, prices or mappings.

## Quick start after updating

1. **Product Management → Categories / Brands / Attributes:** set up your catalogue structure and attribute values.
2. *(Optional)* **Product Management → Settings:** adjust the SKU patterns and rules.
3. **All Products → Add product.** Then, on the product page, add variants, prices, GST/HSN, images and documents, and link GST registrations and business activities.
4. **Supplier Management → Add supplier.** Then add addresses, documents and bank details, and link products on the Products tab.
5. *(Optional)* **Control Center:** switch on the workflows you want; assign suppliers or products to users with *Assigned* scope; set field rules for purchase cost, contacts or GSTIN.

## What was built

### Data model (33 tables)
- **Tax masters:** `gst_tax_rates`, `product_tax_categories`, `gst_hsn_sac_codes`.
- **Catalogue masters:** `product_types`, `product_categories` (self-referencing), `product_brands`, `product_attributes`, `product_attribute_values`.
- **Products:** `products`, `product_variants`, `product_variant_attributes`, `product_sku_registry`, `product_sku_sequences`, `product_bundle_items`, `product_images`, `product_documents`.
- **Product pricing and tax:** `product_prices`, `product_tax_configs`.
- **Product mappings:** `product_gst_mappings`, `product_business_mappings`.
- **Suppliers:** `supplier_types`, `suppliers`, `supplier_addresses`, `supplier_bank_details`, `supplier_documents`, `supplier_gst_mappings`, `supplier_business_mappings`, `supplier_bank_transaction_links`.
- **Product–supplier mapping:** `product_supplier_mappings`.
- **Changes and history:** `product_change_requests`, `supplier_change_requests`, `product_history`, `supplier_history`.

Money is `DECIMAL(15,2)` and all arithmetic uses integer paise. Products, variants and suppliers carry a UUID as a stable identifier for later integrations.

### Products
- Types (editable master): physical product, bundle / combo, kit, service, other.
- Statuses: draft, pending approval, active, inactive, discontinued, archived. There is no delete; archived products are read-only and can be restored.
- Category and sub-category: the sub-category must be under the chosen category.
- Changing key details on an active product goes through the *key-detail change* workflow. Descriptions and notes change at once.
- `ProductService::assertUsable()` is provided for later phases. It refuses archived, draft or inactive products unless a per-product "archived use allowed" exception is set.

### Categories, brands, attributes, variants
- **Categories** nest up to 10 levels, with a cycle guard (a category cannot go under itself or a descendant). Categories and brands have optional images and logos and are archived rather than deleted.
- **Attributes** are choice lists, numbers (with a unit) or text. Values belong to choice-list attributes. An attribute's type is locked once it is used.
- **Variants** are a unique combination of attribute values per product. Each variant has its own code, SKU, barcode, status, image, prices, GST/HSN and suppliers.
- **Bundle and kit components** are checked against loops, including indirect ones.

### SKUs and barcodes
- SKUs are generated from configurable patterns. Tokens: `{CAT}`, `{BRAND}`, `{TYPE}`, `{NAME}`, `{YYYY}`, `{YY}`, `{PARENT}`, `{SEQ:n}`. Each filled-in prefix has its own sequence, which skips numbers already taken by typed SKUs. Manual SKUs are optional, can be switched off, and are normalised to capitals without spaces.
- One registry covers products and variants, so a SKU is unique across both. A SKU used once stays reserved after it is changed or its product is archived.
- SKU changes go through the *SKU change* workflow.
- Barcodes: EAN-8, EAN-13, UPC-A and GTIN-14 are checked with the GS1 check digit. Every barcode must be unique.

### Pricing
- **Price types (8):**
  - Selling: standard selling, minimum selling, maximum selling (MRP), wholesale, and marketplace selling (per channel).
  - Purchase: standard purchase, and supplier purchase (per linked supplier).
  - Any type can be scoped to one GST registration.
- **Effective dates.** A new version closes the previous one in the same slot the day before it starts. Earlier prices are never overwritten, and "price on date X" lookups work for any date.
- **Approval.** With the workflow on, new prices stay *pending* and do not apply until approved.
- **Cancelling.** Only pending or future prices can be cancelled. Cancelling a future price re-opens the price it would have replaced.
- **Bounds.** Selling prices must lie between the minimum and maximum selling prices in force.

### GST / HSN
- **Configuration.** A dated configuration per product, variant and/or GST registration: HSN or SAC, GST rate, tax category, applicability (taxable, exempt, nil rated, non-GST, zero rated) and price inclusion. The most specific configuration wins.
- **History.** Earlier periods keep their own rate. A retired slab (e.g. 12 % until 21 Sep 2025) can be recorded for its historic period.
- **Split permissions.** `manage_hsn` covers codes and `manage_gst_rates` covers rate, category and applicability. Changes go through the *GST/HSN change* workflow.
- **Format checks only.** HSN must be 4, 6 or 8 digits; SAC must be 6 digits starting with 99. There is no tax calculation or filing, and no default rate for all products.

### Suppliers
- **Types and treatments.** 7 supplier types. GST treatments: registered, composition, SEZ, unregistered and overseas. Statuses: draft, pending approval, active, inactive, blocked, archived.
- **GSTIN checks.** The GSTIN is checked for structure, state code, PAN segment and check character. There is a live check on the form. The PAN is derived from the GSTIN when left empty.
- **Duplicates.** One supplier per GSTIN (configurable). Suppliers that share a PAN need an explicit confirmation, and only when the GSTIN or PAN changes.
- **GST-detail changes.** On an active supplier, changes to GSTIN, PAN, treatment or legal name need a reason, `manage_gst_details` and the *supplier GSTIN change* workflow.
- **Addresses.** Business, billing, shipping/dispatch and other addresses, with one default per type. Foreign addresses are supported.
- **Documents.** Stored outside the web root, with MIME and extension checks. Bank proofs are visible only with `view_bank_details`. Views and downloads are logged.
- **Bank details.**
  - The account number is encrypted (AES-256-GCM with `APP_KEY`), has a keyed-hash duplicate check, and is shown masked.
  - Revealing the full number needs `view_full_account_number` and is logged without the number.
  - New details and primary-account changes go through the *supplier bank details* workflow, and are unusable until approved.
- **Payment references.** Only posted withdrawals in the user's bank scope can be referenced, and one bank entry can be linked to only one supplier. Bank Management is untouched.
- `SupplierService::assertUsable()` is provided for later phases.

### Product–supplier mapping
- **Many-to-many links**, optionally per variant, with:
  - the supplier's product code and SKU;
  - minimum and maximum order quantity, lead time and priority;
  - effective dates;
  - an optional purchase price (stored as a supplier purchase price with full history).
- **Preferred supplier.**
  - It is chosen explicitly and is never assumed to be the cheapest.
  - Changes go through the *preferred supplier* workflow.
  - "Only one preferred" is configurable.
- Ended mappings stay as history.

### GST registration and business-activity mappings
Products and suppliers link to the company's GST registrations (Phase 4) and business activities with dates. The links show on both sides and drive the `gst_registration` record scope through the mapping tables. Price and tax configurations scoped to a registration require the product to be linked to it first.

### Pages
- **Product Management:** dashboard, all products, add/edit, product page with 10 tabs, categories, brands, attributes, variants, pricing, GST & HSN (configurations and masters), supplier mapping, documents, history, reports and settings.
- **Supplier Management:** dashboard, all suppliers, add/edit, supplier page with 10 tabs, types, addresses, bank details, documents, product mappings, history and reports.
- All forms, lists, filters and reports work on database records. There is no sample data.

### Reports (10 + 8)
- **Product reports:** product list, category-wise, brand-wise, variants, pricing (as of any date), GST/HSN, without suppliers, without tax configuration, product-supplier mapping, inactive/archived.
- **Supplier reports:** supplier list, supplier-wise products, GST details, contacts, payment terms (with the primary bank account masked), purchase prices, without products, by status.
- All reports have filters and CSV, Excel and PDF export (logged), and apply record scope and field rules on screen and in every export.

### Phase 2 integration
- **Permissions.** The two modules' actions in the Control Center, with role grants and user allow/deny overrides.
- **Record scope.** All, own (created by me) or assigned products and suppliers, and products and suppliers linked to the user's GST registrations.
- **Field rules.** Hidden fields are not rendered and are left out of exports. Masked fields stay masked everywhere.
- **Supplier scope on product pages.** Product pages and product reports show suppliers outside the user's supplier scope as "Restricted supplier", or leave them out of supplier lists.
- **Approvals.** 11 workflows with self-approval blocked by the existing engine.
- **Audit.** Every change, document access, export and full-number reveal is written to the activity log.

## Security and testing results

```
php tests/run.php
186 passed, 0 failed, 2358 assertions (PHP 8.3.6 · SQLite)
```

New suites:
- `tests/Integration/ProductSupplierTest.php` (13 tests)
- `tests/Feature/ProductSecurityTest.php` (4 HTTP tests)

| # | Scenario | Covered by |
|---|---|---|
| 1 | Nested categories, cycle guard, depth limit; brands; archived masters kept but not selectable | `testCategoriesNestWithoutLoopsAndBrandsAreManaged` |
| 2 | Pattern SKUs, per-prefix sequences, manual SKUs normalised, uniqueness, retired SKUs reserved | `testProductsWithSkusGeneratedFromPatternAndNeverReused` |
| 3 | Barcode check digits and uniqueness | same |
| 4 | Variants from attributes, own SKUs, duplicate combinations refused, SKU change via approval | `testVariantsWithFlexibleAttributesAndOwnSkus` |
| 5 | Bundle/kit components without loops | `testBundleComponentsPreventLoops` |
| 6 | Price versions with history, bounds, future cancel, approval before applying | `testPricesAreVersionedWithHistoryBoundsAndApproval` |
| 7 | HSN/SAC format, dated GST history incl. retired slab, split HSN/rate permissions, approval | `testTaxConfigurationsValidateCodesSplitPermissionsAndKeepHistory` |
| 8 | GSTIN/PAN validation, duplicate GSTIN and shared-PAN rules, overseas suppliers | `testSuppliersValidateGstinPanDuplicatesAndKeepBankDetailsSeparate` |
| 9 | Supplier bank details encrypted, masked, approved before use, never company accounts | same + `testAdminFlowsCsrfBankRevealAndAllPages` |
| 10 | Many-to-many mapping, supplier prices, preferred supplier via approval, ended mappings | `testProductsAndSuppliersAreManyToManyWithSupplierPricesAndPreferred` |
| 11 | GST-registration and activity mappings and the GST record scope | `testGstAndBusinessActivityMappingsForProductsAndSuppliers` |
| 12 | Approvals for new products/suppliers and archival; self-approval blocked | `testApprovalsForNewProductsSuppliersArchiveAndSelfApprovalBlock` |
| 13 | Images and documents (type checks, primary image), status lifecycle, restore | `testDocumentsImagesAndStatusLifecycle` |
| 14 | Payment references only to posted withdrawals in scope; ledger unchanged | `testSupplierPaymentReferencesUseExistingBankTransactionsOnly` |
| 15 | Report filters, as-of dates, `assertUsable` foundations for later phases | `testReportsFiltersAndIntegrationFoundations` |
| 16 | Viewer has no access; accountant sees only assigned suppliers (404 elsewhere), also in exports | `testViewerHasNoAccessAndAccountantSeesOnlyAssignedSuppliers` |
| 17 | Supplier scope also applied to product pages, mapping lists and product reports | same |
| 18 | Manager maintains the catalogue but cannot see bank details, bank proofs or settings | `testManagerCanMaintainCatalogueButNotBankDetailsOrSettings` |
| 19 | User-specific deny overrides; hidden/masked fields on pages and in CSV exports | `testUserSpecificOverridesAndFieldRules` |
| 20 | CSRF (419); full-number reveal logged, never stored in logs; GSTIN check endpoint; every page, tab and 18 reports × 3 formats | `testAdminFlowsCsrfBankRevealAndAllPages` |

Phases 1–5 are unaffected: all their existing suites pass unchanged, apart from count assertions updated for the new settings (7), workflows (11), the one-time seeding marker and the help category.

**Also checked by hand against a demo database:**
- Every product and supplier page and tab (70 URLs) as five different users. There were no server errors, PHP warnings or browser console errors. Out-of-scope records answer 404 and missing permissions answer 403.
- The page scripts: the live GSTIN check, the full-number reveal, the price-form fields and sub-category filtering.
- The screenshots for the help guides.

**Issues found and fixed during testing:**
- The product's Suppliers tab, the cross-product mapping page and two product reports showed suppliers outside the user's supplier scope. They now hide or redact them.
- The live GSTIN check sent a GET request to a POST-only route.
- Dashboard bars, category indentation and one truncated description used inline styles that the Content Security Policy blocks. They now use classes and `data-width`.
- Two dashboard status tiles combined two statuses but linked to a list of only one. They are now split.
- The supplier duplicate check asked for the shared-PAN confirmation on every save. It now asks only when the GSTIN or PAN changes.
- The supplier report used the session user's permissions instead of the report user's.
- The GST registration page did not show the products and suppliers linked to it. It now shows both counts, within the viewer's own scope, with links to the filtered lists.

**Not verified here:** the build environment has no MySQL and no PHP 8.5 (tests run on PHP 8.3 with SQLite). The MySQL DDL for migration 000032 was generated with the grammar that `migrate --pretend` uses and reviewed: 33 tables, 123 foreign keys, longest identifier 61 characters (limit 64). Please run the migration on your XAMPP MySQL.

## Known limitations
- Prices are stored per currency without conversion. Report totals do not add amounts in different currencies.
- Variant attribute values are fixed per variant. Changing an attribute's values does not rename existing variants.
- Image processing is limited to validation. There is no resizing or thumbnail generation, so large images are served as uploaded (5 MB maximum).
- The product and supplier masters (types, attributes, tax categories, rates) are shared by the whole company. There are no per-GSTIN masters.
- Supplier payment references are links only. Payment processing, purchase invoices and supplier balances arrive in Phase 11.

## Help & User Guide

New category **Phase 6 – Product & Supplier Management** with 14 guides and 31 annotated screenshots taken from the running application:
- **Products:** the product dashboard, adding a product, categories and brands, variants and attributes, SKUs and barcodes, pricing, GST/HSN, images and documents.
- **Suppliers:** adding a supplier, supplier bank details and payment references, product-supplier mapping, GST and activity mappings.
- **Reports and access:** reports, and permissions and approvals.

## Not included (by design)

Inventory and warehouses (Phase 7), marketplace integrations and seller accounts, sales orders and customers, purchase invoices and supplier payments (Phase 11), expenses, the accounting engine (Phase 12), GST return calculation or filing, and any automatic government verification. Phase 7 has not been started.
