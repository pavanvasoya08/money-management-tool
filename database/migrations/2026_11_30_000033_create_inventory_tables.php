<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Phase 7 — Inventory Management.
 *
 * Reuses Phase 6 products and variants (their UUIDs are the stable stock identifiers), Phase 6
 * suppliers, Phase 4 GST registrations / states / business activities and the Phase 2 user
 * resource assignments ("warehouse" type) — no second master for any of them.
 *
 * The stock ledger (inventory_stock_movements) is the source of truth:
 * - rows are only ever inserted; there is no updated_at and nothing edits or deletes them;
 * - every row has a source (document type + id + line) and a unique posting_key, so the same
 *   document line can never be posted twice, even by two requests at the same moment;
 * - a correction is a new row that points to the row it reverses (reversal_of_id is unique,
 *   so a movement can be reversed only once);
 * - inventory_stock_balances (quantity per warehouse / item / location / batch / condition) and
 *   inventory_stock_items (quantity and value per warehouse / item, also the row-lock target)
 *   are summaries written in the same database transaction as the ledger rows and can be
 *   rebuilt from the ledger at any time (InventoryLedger::reconcile()).
 *
 * Quantities are DECIMAL(18,4) in the product's stock unit; unit costs DECIMAL(18,4); values
 * DECIMAL(15,2). Arithmetic is done on scaled integers — never FLOAT.
 * Stock documents keep the source module and an optional link to a future source document
 * (purchase receipt → Phase 11, sales dispatch → Phase 9, customer return → Phase 10); no
 * purchase, sale, payment or journal entry is created here.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $audit = static function (Blueprint $t): void {
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
        };
        $qty = static fn (Blueprint $t, string $name): \App\Core\Schema\Column => $t->decimal($name, 18, 4);

        // ---------------------------------------------------------------- warehouses
        $schema->create('warehouse_types', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->integer('sort_order')->default(0);
            $audit($t);
            $t->unique('code');
        });
        $schema->create('warehouses', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->char('uuid', 36);
            $t->string('code', 30);
            $t->string('name', 150);
            $t->foreignId('warehouse_type_id', 'warehouse_types', 'id', 'RESTRICT');
            $t->string('line1', 200)->nullable();
            $t->string('line2', 200)->nullable();
            $t->string('city', 100)->nullable();
            $t->foreignId('state_id', 'gst_states', 'id', 'RESTRICT')->nullable();
            $t->string('state_name', 100)->nullable()->comment('Region outside India');
            $t->string('pin_code', 10)->nullable();
            $t->char('country', 2)->default('IN');
            $t->string('contact_person', 150)->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('email', 150)->nullable();
            $t->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $t->string('status_reason', 255)->nullable();
            $t->boolean('uses_locations')->default(false)->comment('Stock must be put on a storage location');
            $t->text('internal_notes')->nullable();
            $audit($t);
            $t->unique('code');
            $t->unique('uuid');
            $t->index(['status', 'name']);
        });
        $schema->create('warehouse_locations', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->foreignId('parent_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->string('code', 40);
            $t->string('name', 100);
            $t->enum('location_type', ['zone', 'section', 'aisle', 'rack', 'shelf', 'bin', 'other'])->default('rack');
            $t->string('description', 255)->nullable();
            $t->decimal('capacity', 18, 4)->nullable();
            $t->string('capacity_unit', 20)->nullable();
            $t->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $t->integer('sort_order')->default(0);
            $audit($t);
            $t->unique(['warehouse_id', 'code']);
            $t->index(['warehouse_id', 'parent_id']);
        });
        foreach (['warehouse_gst_mappings' => ['gst_registration_id', 'gst_registrations'], 'warehouse_business_mappings' => ['business_activity_id', 'gst_business_activities']] as $table => [$col, $on]) {
            $schema->create($table, static function (Blueprint $t) use ($audit, $col, $on): void {
                $t->id();
                $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
                $t->foreignId($col, $on, 'id', 'RESTRICT');
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->enum('status', ['active', 'ended'])->default('active');
                $t->string('notes', 255)->nullable();
                $t->string('end_reason', 255)->nullable();
                $audit($t);
                $t->index(['warehouse_id', 'status']);
                $t->index([$col, 'status']);
            });
        }

        // ---------------------------------------------------------------- units & product settings
        $schema->create('inventory_units', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 20)->comment('Matches products.unit');
            $t->string('name', 60);
            $t->enum('unit_type', ['count', 'weight', 'volume', 'length', 'area', 'time', 'other'])->default('count');
            $t->integer('decimals')->default(0)->comment('Decimal places allowed in quantities (0–4)');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->integer('sort_order')->default(0);
            $audit($t);
            $t->unique('code');
        });
        $schema->create('inventory_unit_conversions', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT')->nullable()->comment('NULL = applies to every product');
            $t->foreignId('from_unit_id', 'inventory_units', 'id', 'RESTRICT');
            $t->foreignId('to_unit_id', 'inventory_units', 'id', 'RESTRICT');
            $t->decimal('factor', 18, 6)->comment('1 from-unit = factor to-units');
            $t->string('scope_key', 60)->comment('product id or * + from + to, one active conversion per pair');
            $t->boolean('is_active')->default(true);
            $audit($t);
            $t->index(['product_id', 'is_active']);
            $t->index('scope_key');
        });
        $schema->create('product_inventory_settings', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->enum('track_level', ['product', 'variant'])->default('product');
            $t->foreignId('default_warehouse_id', 'warehouses', 'id', 'RESTRICT')->nullable();
            $t->decimal('reorder_level', 18, 4)->nullable();
            $t->decimal('min_qty', 18, 4)->nullable();
            $t->decimal('max_qty', 18, 4)->nullable();
            $t->decimal('safety_stock', 18, 4)->nullable();
            $t->decimal('reorder_qty', 18, 4)->nullable();
            $t->boolean('allow_negative')->default(false);
            $t->boolean('batch_tracking')->default(false);
            $t->boolean('serial_tracking')->default(false);
            $t->boolean('expiry_tracking')->default(false);
            $t->enum('valuation_method', ['fifo', 'weighted_average'])->nullable()->comment('NULL = company default');
            $t->enum('inventory_status', ['active', 'on_hold', 'discontinued'])->default('active');
            $t->string('notes', 500)->nullable();
            $audit($t);
            $t->unique('product_id');
        });
        $schema->create('inventory_reorder_rules', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT')->nullable();
            $t->string('scope_key', 60);
            $t->decimal('reorder_level', 18, 4)->nullable();
            $t->decimal('min_qty', 18, 4)->nullable();
            $t->decimal('max_qty', 18, 4)->nullable();
            $t->decimal('safety_stock', 18, 4)->nullable();
            $t->decimal('reorder_qty', 18, 4)->nullable();
            $t->boolean('is_active')->default(true);
            $audit($t);
            $t->unique('scope_key');
            $t->index(['warehouse_id', 'is_active']);
        });

        // ---------------------------------------------------------------- batches & serial numbers
        $schema->create('inventory_batches', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->string('batch_no', 60);
            $t->string('batch_key', 120)->comment('product:variant:BATCH — unique');
            $t->date('mfg_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT')->nullable();
            $t->string('supplier_reference', 100)->nullable();
            $t->string('purchase_reference', 100)->nullable();
            $t->decimal('unit_cost', 18, 4)->nullable()->comment('Cost of the first receipt');
            $t->enum('status', ['active', 'on_hold', 'closed'])->default('active');
            $t->string('notes', 500)->nullable();
            $audit($t);
            $t->unique('batch_key');
            $t->index(['product_id', 'expiry_date']);
        });
        $schema->create('inventory_serial_numbers', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->string('serial_no', 100);
            $t->string('serial_key', 140)->comment('product:SERIAL — unique');
            $t->foreignId('batch_id', 'inventory_batches', 'id', 'RESTRICT')->nullable();
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT')->nullable();
            $t->foreignId('location_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->string('stock_state', 20)->nullable();
            $t->enum('status', ['in_stock', 'in_transit', 'dispatched', 'returned_to_supplier', 'consumed', 'written_off', 'reversed'])->default('in_stock');
            $t->decimal('unit_cost', 18, 4)->nullable();
            $t->unsignedBigInteger('last_movement_id')->nullable();
            $audit($t);
            $t->unique('serial_key');
            $t->index(['product_id', 'status']);
            $t->index(['warehouse_id', 'status']);
        });

        // ---------------------------------------------------------------- stock documents
        $doc = static function (Blueprint $t): void {
            $t->enum('status', ['draft', 'pending_approval', 'approved', 'posted', 'rejected', 'cancelled', 'reversed'])->default('draft');
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->string('posting_error', 500)->nullable();
            $t->foreignId('approved_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('posted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('posted_at')->nullable();
            $t->foreignId('reversed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('reversed_at')->nullable();
            $t->string('reversal_reason', 500)->nullable();
        };
        $schema->create('inventory_entries', static function (Blueprint $t) use ($audit, $doc): void {
            $t->id();
            $t->string('entry_no', 20);
            $t->enum('direction', ['opening', 'inward', 'outward']);
            $t->string('entry_type', 30)->comment('purchase_receipt, customer_return, other_receipt, opening, sales_dispatch, supplier_return, damaged, expired, consumption, other_issue');
            $t->date('entry_date');
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT')->nullable()->comment('Phase 6 supplier for purchase receipts / supplier returns');
            $t->string('source_reference', 100)->nullable()->comment('Supplier invoice, order or other reference');
            $t->string('source_document_type', 40)->nullable()->comment('Future source module record, e.g. purchase_receipt (Phase 11)');
            $t->unsignedBigInteger('source_document_id')->nullable();
            $t->string('reason', 255)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('negative_override')->default(false);
            $t->boolean('reservation_override')->default(false);
            $t->boolean('expired_override')->default(false);
            $doc($t);
            $audit($t);
            $t->unique('entry_no');
            $t->unique(['source_document_type', 'source_document_id'], 'uq_inventory_entries_source_document');
            $t->index(['warehouse_id', 'status']);
            $t->index(['direction', 'entry_type', 'entry_date']);
        });

        $schema->create('inventory_reservations', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('reservation_no', 20);
            $t->enum('reference_type', ['sales_order', 'internal', 'other']);
            $t->string('reference_no', 100)->comment('e.g. order number; the future Phase 9 order id goes in reference_id');
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->date('reservation_date');
            $t->date('expires_on')->nullable();
            $t->enum('status', ['active', 'partially_fulfilled', 'fulfilled', 'released', 'expired', 'cancelled'])->default('active');
            $t->string('notes', 500)->nullable();
            $t->string('close_reason', 255)->nullable();
            $t->foreignId('closed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('closed_at')->nullable();
            $audit($t);
            $t->unique('reservation_no');
            $t->index(['warehouse_id', 'status']);
            $t->index(['reference_type', 'reference_no']);
        });
        $schema->create('inventory_reservation_items', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('reservation_id', 'inventory_reservations', 'id', 'RESTRICT');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $qty($t, 'quantity');
            $qty($t, 'qty_fulfilled')->default('0.0000');
            $qty($t, 'qty_released')->default('0.0000');
            $t->enum('status', ['active', 'partially_fulfilled', 'fulfilled', 'released', 'expired', 'cancelled'])->default('active');
            $t->string('dedupe_key', 200)->nullable()->comment('Set while open: reference + warehouse + item, unique');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique('dedupe_key');
            $t->index(['product_id', 'variant_id', 'status'], 'ix_inv_reservation_items_item_status');
        });

        $schema->create('inventory_entry_items', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('entry_id', 'inventory_entries', 'id', 'RESTRICT');
            $t->integer('line_no');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('location_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->foreignId('batch_id', 'inventory_batches', 'id', 'RESTRICT')->nullable();
            $t->string('batch_no', 60)->nullable()->comment('New batch to create on posting (inward)');
            $t->date('mfg_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->text('serial_numbers')->nullable()->comment('One per line');
            $qty($t, 'quantity')->comment('As entered, in unit_code');
            $t->string('unit_code', 20);
            $qty($t, 'base_quantity')->comment('In the product stock unit');
            $t->decimal('unit_cost', 18, 4)->nullable()->comment('Per stock unit; outward: cost at posting');
            $t->decimal('total_cost', 15, 2)->nullable();
            $t->string('stock_state', 20)->default('sellable');
            $t->enum('return_condition', ['sellable', 'damaged', 'inspection', 'restock_pending', 'rejected'])->nullable();
            $t->foreignId('reservation_item_id', 'inventory_reservation_items', 'id', 'RESTRICT')->nullable();
            $t->string('notes', 255)->nullable();
            $t->index(['entry_id', 'line_no']);
            $t->index(['product_id', 'variant_id']);
        });

        $schema->create('inventory_transfers', static function (Blueprint $t) use ($audit, $doc): void {
            $t->id();
            $t->string('transfer_no', 20);
            $t->foreignId('from_warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->foreignId('to_warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->date('transfer_date');
            $t->boolean('uses_transit')->default(true);
            $t->string('reason', 255)->nullable();
            $t->text('notes')->nullable();
            $t->enum('transfer_status', ['draft', 'pending_approval', 'approved', 'in_transit', 'partially_received', 'received', 'rejected', 'cancelled', 'reversed'])->default('draft');
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->string('posting_error', 500)->nullable();
            $t->foreignId('approved_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('dispatched_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('dispatched_at')->nullable();
            $t->dateTime('received_at')->nullable();
            $t->foreignId('reversed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('reversed_at')->nullable();
            $t->string('reversal_reason', 500)->nullable();
            $audit($t);
            $t->unique('transfer_no');
            $t->index(['from_warehouse_id', 'transfer_status']);
            $t->index(['to_warehouse_id', 'transfer_status']);
        });
        $schema->create('inventory_transfer_items', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('transfer_id', 'inventory_transfers', 'id', 'RESTRICT');
            $t->integer('line_no');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('from_location_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->foreignId('to_location_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->foreignId('batch_id', 'inventory_batches', 'id', 'RESTRICT')->nullable();
            $t->text('serial_numbers')->nullable();
            $qty($t, 'quantity')->comment('Stock unit');
            $qty($t, 'qty_received')->default('0.0000');
            $qty($t, 'qty_damaged')->default('0.0000')->comment('Received as damaged');
            $qty($t, 'qty_lost')->default('0.0000')->comment('Written off in transit');
            $t->decimal('total_cost', 15, 2)->nullable()->comment('Cost moved, set on dispatch');
            $t->string('notes', 255)->nullable();
            $t->index(['transfer_id', 'line_no']);
        });
        $schema->create('inventory_transfer_receipts', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('transfer_id', 'inventory_transfers', 'id', 'RESTRICT');
            $t->string('receipt_no', 20);
            $t->enum('kind', ['receipt', 'loss'])->default('receipt');
            $t->date('receipt_date');
            $t->string('notes', 500)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->unique('receipt_no');
            $t->index('transfer_id');
        });
        $schema->create('inventory_transfer_receipt_items', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('receipt_id', 'inventory_transfer_receipts', 'id', 'RESTRICT');
            $t->foreignId('transfer_item_id', 'inventory_transfer_items', 'id', 'RESTRICT');
            $qty($t, 'qty_received')->default('0.0000');
            $qty($t, 'qty_damaged')->default('0.0000');
            $qty($t, 'qty_lost')->default('0.0000');
            $t->text('serial_numbers')->nullable();
            $t->index('receipt_id');
        });

        $schema->create('inventory_adjustments', static function (Blueprint $t) use ($audit, $doc): void {
            $t->id();
            $t->string('adjustment_no', 20);
            $t->date('adjustment_date');
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->enum('reason', ['physical_difference', 'damaged', 'expired', 'missing', 'found', 'data_correction', 'condition_change', 'other']);
            $t->text('notes')->nullable();
            $doc($t);
            $audit($t);
            $t->unique('adjustment_no');
            $t->index(['warehouse_id', 'status']);
        });
        $schema->create('inventory_adjustment_items', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('adjustment_id', 'inventory_adjustments', 'id', 'RESTRICT');
            $t->integer('line_no');
            $t->enum('line_type', ['count', 'condition']);
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('location_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->foreignId('batch_id', 'inventory_batches', 'id', 'RESTRICT')->nullable();
            $t->string('stock_state', 20)->default('sellable');
            $t->string('to_state', 20)->nullable()->comment('Condition change target');
            $qty($t, 'system_qty')->comment('Book quantity when counted');
            $qty($t, 'physical_qty')->nullable();
            $qty($t, 'difference_qty')->comment('+ increase, − decrease (count); quantity moved (condition)');
            $t->decimal('unit_cost', 18, 4)->nullable();
            $t->decimal('total_cost', 15, 2)->nullable();
            $t->text('serial_numbers')->nullable();
            $t->string('notes', 255)->nullable();
            $t->index(['adjustment_id', 'line_no']);
        });

        // ---------------------------------------------------------------- ledger & summaries
        $schema->create('inventory_stock_items', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->string('item_key', 60)->comment('warehouse:product:variant');
            $qty($t, 'qty_on_hand')->default('0.0000')->comment('All conditions incl. in transit');
            $t->decimal('stock_value', 15, 2)->default('0.00');
            $t->decimal('last_unit_cost', 18, 4)->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->dateTime('updated_at')->nullable();
            $t->unique('item_key');
            $t->index(['product_id', 'variant_id']);
        });
        $schema->create('inventory_stock_movements', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->string('movement_no', 20);
            $t->date('movement_date');
            $t->foreignId('stock_item_id', 'inventory_stock_items', 'id', 'RESTRICT');
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('location_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->foreignId('batch_id', 'inventory_batches', 'id', 'RESTRICT')->nullable();
            $t->string('stock_state', 20)->default('sellable')->comment('sellable, damaged, quarantine, in_transit');
            $t->string('movement_type', 30);
            $qty($t, 'qty_in')->default('0.0000');
            $qty($t, 'qty_out')->default('0.0000');
            $t->decimal('unit_cost', 18, 4)->default('0.0000');
            $t->decimal('total_cost', 15, 2)->default('0.00')->comment('Signed: + adds value, − removes value');
            $t->string('source_type', 30)->comment('opening, inward, outward, transfer, adjustment, revaluation, reversal');
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('source_line_id')->nullable();
            $t->string('reference_no', 100);
            $t->string('posting_key', 120)->comment('Unique per source line and step — blocks double posting');
            $t->foreignId('reversal_of_id', 'inventory_stock_movements', 'id', 'RESTRICT')->nullable();
            $t->unsignedBigInteger('transfer_pair_id')->nullable()->comment('The other side of a transfer or condition move');
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('approved_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('posted_at');
            $t->unique('movement_no');
            $t->unique('posting_key');
            $t->unique('reversal_of_id');
            $t->index(['stock_item_id', 'movement_date']);
            $t->index(['warehouse_id', 'movement_date']);
            $t->index(['product_id', 'variant_id', 'movement_date'], 'ix_inv_movements_product_date');
            $t->index(['source_type', 'source_id']);
            $t->index(['batch_id']);
        });
        $schema->create('inventory_stock_balances', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('stock_item_id', 'inventory_stock_items', 'id', 'RESTRICT');
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('location_id', 'warehouse_locations', 'id', 'RESTRICT')->nullable();
            $t->foreignId('batch_id', 'inventory_batches', 'id', 'RESTRICT')->nullable();
            $t->string('stock_state', 20)->default('sellable');
            $t->string('balance_key', 100)->comment('item:location:batch:state');
            $qty($t, 'quantity')->default('0.0000');
            $t->unsignedBigInteger('last_movement_id')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique('balance_key');
            $t->index(['stock_item_id', 'stock_state']);
            $t->index(['warehouse_id', 'stock_state']);
            $t->index(['batch_id']);
        });
        $schema->create('inventory_serial_movements', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('serial_id', 'inventory_serial_numbers', 'id', 'RESTRICT');
            $t->foreignId('movement_id', 'inventory_stock_movements', 'id', 'RESTRICT');
            $t->dateTime('created_at')->nullable();
            $t->unique(['movement_id', 'serial_id']);
            $t->index('serial_id');
        });
        $schema->create('inventory_cost_layers', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('stock_item_id', 'inventory_stock_items', 'id', 'RESTRICT');
            $t->foreignId('batch_id', 'inventory_batches', 'id', 'RESTRICT')->nullable();
            $t->foreignId('movement_id', 'inventory_stock_movements', 'id', 'RESTRICT')->comment('Receipt that created the layer');
            $t->date('layer_date');
            $qty($t, 'qty_in');
            $qty($t, 'qty_remaining');
            $t->decimal('unit_cost', 18, 4);
            $t->decimal('value_remaining', 15, 2);
            $t->dateTime('created_at')->nullable();
            $t->index(['stock_item_id', 'qty_remaining']);
        });
        $schema->create('inventory_cost_layer_usage', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->foreignId('layer_id', 'inventory_cost_layers', 'id', 'RESTRICT');
            $t->foreignId('movement_id', 'inventory_stock_movements', 'id', 'RESTRICT')->comment('Issue that consumed the layer');
            $qty($t, 'quantity');
            $t->decimal('value', 15, 2);
            $t->dateTime('created_at')->nullable();
            $t->index('movement_id');
            $t->index('layer_id');
        });
        $schema->create('inventory_valuation_history', static function (Blueprint $t) use ($qty): void {
            $t->id();
            $t->enum('event', ['default_method', 'product_method', 'revaluation']);
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT')->nullable();
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'RESTRICT')->nullable();
            $t->string('old_method', 20)->nullable();
            $t->string('new_method', 20)->nullable();
            $qty($t, 'quantity')->nullable();
            $t->decimal('old_unit_cost', 18, 4)->nullable();
            $t->decimal('new_unit_cost', 18, 4)->nullable();
            $t->decimal('value_change', 15, 2)->nullable();
            $t->unsignedBigInteger('movement_id')->nullable();
            $t->string('reason', 500)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('approved_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->index(['product_id', 'id']);
        });

        // ---------------------------------------------------------------- corrections, files, history
        $schema->create('inventory_change_requests', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->enum('change_type', ['batch_correction', 'serial_correction', 'valuation_correction']);
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->json('old_values')->nullable();
            $t->json('new_values');
            $t->string('reason', 500);
            $t->enum('status', ['pending_approval', 'applied', 'rejected', 'cancelled'])->default('pending_approval');
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->foreignId('decided_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $audit($t);
            $t->index(['subject_type', 'subject_id', 'status'], 'ix_inv_change_requests_subject');
        });
        $schema->create('inventory_documents', static function (Blueprint $t): void {
            $t->id();
            $t->string('subject_type', 30)->comment('warehouse, entry, transfer, adjustment');
            $t->unsignedBigInteger('subject_id');
            $t->string('category', 40)->default('other');
            $t->string('title', 150);
            $t->string('stored_name', 100);
            $t->string('original_name', 255);
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->timestamps();
            $t->index(['subject_type', 'subject_id', 'status'], 'ix_inv_documents_subject');
        });
        $schema->create('inventory_history', static function (Blueprint $t): void {
            $t->id();
            $t->string('subject_type', 30)->comment('warehouse, location, settings, unit, entry, transfer, adjustment, reservation, batch, serial, valuation');
            $t->unsignedBigInteger('subject_id');
            $t->foreignId('warehouse_id', 'warehouses', 'id', 'SET NULL')->nullable();
            $t->foreignId('product_id', 'products', 'id', 'SET NULL')->nullable();
            $t->string('event', 40);
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('reason', 500)->nullable();
            $t->date('effective_date')->nullable();
            $t->foreignId('actor_id', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->index(['subject_type', 'subject_id', 'id'], 'ix_inv_history_subject');
            $t->index(['warehouse_id', 'id']);
            $t->index(['product_id', 'id']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach ([
            'inventory_history', 'inventory_documents', 'inventory_change_requests', 'inventory_valuation_history', 'inventory_cost_layer_usage', 'inventory_cost_layers',
            'inventory_serial_movements', 'inventory_stock_balances', 'inventory_stock_movements', 'inventory_stock_items',
            'inventory_adjustment_items', 'inventory_adjustments', 'inventory_transfer_receipt_items', 'inventory_transfer_receipts', 'inventory_transfer_items', 'inventory_transfers',
            'inventory_entry_items', 'inventory_reservation_items', 'inventory_reservations', 'inventory_entries',
            'inventory_serial_numbers', 'inventory_batches', 'inventory_reorder_rules', 'product_inventory_settings', 'inventory_unit_conversions', 'inventory_units',
            'warehouse_business_mappings', 'warehouse_gst_mappings', 'warehouse_locations', 'warehouses', 'warehouse_types',
        ] as $table) {
            $schema->drop($table);
        }
    }
};
