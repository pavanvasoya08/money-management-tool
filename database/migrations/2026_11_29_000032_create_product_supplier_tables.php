<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Phase 6 — Product & Supplier Management.
 *
 * Masters: product types, nested categories, brands, flexible attributes (attribute → allowed
 * values; variants store one value per attribute), GST rate and tax-category masters and an
 * HSN/SAC reference master. GST registrations, states and business activities are reused
 * from Phase 4 — no second master.
 *
 * - products / product_variants carry a UUID: the stable identifier inventory (Phase 7),
 *   marketplace listings (Phase 8), orders (Phase 9) and purchases (Phase 11) will reference.
 * - product_sku_registry: every product and variant SKU, current and retired, in one table
 *   with a unique index, so a SKU can never be used twice (retired SKUs stay reserved).
 * - product_prices / product_tax_configs are versioned: rows are never edited after they
 *   become active; a new version closes the previous one (effective_until) and marks it
 *   superseded. Supplier-specific purchase prices are product_prices rows with supplier_id,
 *   so all price history lives in one place. Future transactions store the price row id
 *   and copy the amount, so later changes can never rewrite history.
 * - Suppliers keep their own bank details (account number encrypted with APP_KEY, keyed
 *   hash + last four digits) — entirely separate from the company's bank_accounts.
 * - supplier_bank_transaction_links only *references* existing Phase 5 bank transactions;
 *   no payment is recorded here.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $audit = static function (Blueprint $t): void {
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
        };

        // ---------------------------------------------------------------- tax masters (GST)
        $schema->create('gst_tax_rates', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 20);
            $t->string('name', 60);
            $t->decimal('rate', 5, 2)->comment('GST rate in percent (IGST equivalent)');
            $t->decimal('cess_rate', 5, 2)->default('0.00')->comment('Compensation cess in percent, if any');
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->integer('sort_order')->default(0);
            $audit($t);
            $t->unique('code');
        });
        $schema->create('product_tax_categories', static function (Blueprint $t) use ($audit): void {
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
        $schema->create('gst_hsn_sac_codes', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 8);
            $t->enum('kind', ['hsn', 'sac']);
            $t->string('description', 500);
            $t->foreignId('default_rate_id', 'gst_tax_rates', 'id', 'RESTRICT')->nullable();
            $t->boolean('is_active')->default(true);
            $audit($t);
            $t->unique('code');
            $t->index(['kind', 'is_active']);
        });

        // ---------------------------------------------------------------- product masters
        $schema->create('product_types', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('is_service')->default(false)->comment('Services use SAC codes instead of HSN');
            $t->boolean('tracks_inventory')->default(true)->comment('Default for new products of this type (Phase 7)');
            $t->boolean('has_components')->default(false)->comment('Bundles / kits list component products');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->integer('sort_order')->default(0);
            $audit($t);
            $t->unique('code');
        });
        $schema->create('product_categories', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->foreignId('parent_id', 'product_categories', 'id', 'RESTRICT')->nullable();
            $t->string('description', 500)->nullable();
            $t->string('image_stored', 100)->nullable();
            $t->string('image_mime', 60)->nullable();
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->boolean('is_archived')->default(false);
            $t->integer('display_order')->default(0);
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $audit($t);
            $t->unique('code');
            $t->index(['parent_id', 'display_order']);
        });
        $schema->create('product_brands', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->string('description', 500)->nullable();
            $t->string('logo_stored', 100)->nullable();
            $t->string('logo_mime', 60)->nullable();
            $t->string('website', 255)->nullable();
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->boolean('is_archived')->default(false);
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $audit($t);
            $t->unique('code');
            $t->index('name');
        });
        $schema->create('product_attributes', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 100);
            $t->enum('input_type', ['select', 'text', 'number'])->default('select');
            $t->string('unit', 20)->nullable()->comment('e.g. kg, ml, cm (number attributes)');
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $audit($t);
            $t->unique('code');
        });
        $schema->create('product_attribute_values', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('attribute_id', 'product_attributes', 'id', 'RESTRICT');
            $t->string('value', 100);
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $audit($t);
            $t->unique(['attribute_id', 'value']);
        });

        // ---------------------------------------------------------------- supplier masters & suppliers
        $schema->create('supplier_types', static function (Blueprint $t) use ($audit): void {
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
        $schema->create('suppliers', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->char('uuid', 36)->comment('Stable identifier for purchases (Phase 11) and accounting (Phase 12)');
            $t->string('supplier_code', 20);
            $t->string('name', 200)->comment('Display / trade name');
            $t->string('legal_name', 200);
            $t->foreignId('supplier_type_id', 'supplier_types', 'id', 'RESTRICT');
            $t->string('contact_person', 150)->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('website', 255)->nullable();
            $t->char('pan', 10)->nullable();
            $t->char('gstin', 15)->nullable();
            $t->enum('gst_treatment', ['registered', 'composition', 'unregistered', 'overseas', 'sez'])->default('registered');
            $t->foreignId('gst_state_id', 'gst_states', 'id', 'RESTRICT')->nullable()->comment('Derived from the GSTIN state code');
            $t->char('country', 2)->default('IN');
            $t->string('payment_terms', 100)->nullable();
            $t->integer('credit_period_days')->nullable();
            $t->char('default_currency', 3)->default('INR');
            $t->enum('status', ['draft', 'pending_approval', 'active', 'inactive', 'blocked', 'archived'])->default('draft');
            $t->string('status_before_archive', 30)->nullable();
            $t->string('status_reason', 255)->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->text('internal_notes')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $audit($t);
            $t->unique('uuid');
            $t->unique('supplier_code');
            $t->index('gstin');
            $t->index('pan');
            $t->index(['status', 'name']);
        });
        $schema->create('supplier_addresses', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT');
            $t->enum('address_type', ['business', 'billing', 'shipping', 'other']);
            $t->string('label', 100)->nullable();
            $t->string('line1', 200);
            $t->string('line2', 200)->nullable();
            $t->string('city', 100);
            $t->foreignId('state_id', 'gst_states', 'id', 'RESTRICT')->nullable();
            $t->string('state_name', 100)->nullable()->comment('Region for addresses outside India');
            $t->string('pin_code', 10)->nullable();
            $t->char('country', 2)->default('IN');
            $t->boolean('is_default')->default(false)->comment('Default address of its type');
            $t->enum('status', ['active', 'archived'])->default('active');
            $audit($t);
            $t->index(['supplier_id', 'address_type', 'status']);
        });
        $schema->create('supplier_bank_details', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT');
            $t->string('account_holder_name', 200);
            $t->string('bank_name', 150);
            $t->string('branch_name', 150)->nullable();
            $t->text('account_number_encrypted')->comment('AES-256-GCM with APP_KEY');
            $t->char('account_number_hash', 64)->comment('Keyed hash for duplicate detection');
            $t->char('account_number_last4', 4);
            $t->char('ifsc_code', 11)->nullable();
            $t->string('swift_code', 11)->nullable();
            $t->enum('account_type', ['current', 'savings', 'other'])->default('current');
            $t->boolean('is_primary')->default(false);
            $t->enum('status', ['pending_approval', 'active', 'inactive', 'rejected'])->default('pending_approval');
            $t->string('status_reason', 255)->nullable();
            $t->string('notes', 255)->nullable();
            $audit($t);
            $t->index(['supplier_id', 'status']);
            $t->index('account_number_hash');
        });
        $schema->create('supplier_documents', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT');
            $t->enum('category', ['gst_certificate', 'pan', 'business_registration', 'agreement', 'bank_proof', 'other']);
            $t->string('title', 150);
            $t->string('description', 500)->nullable();
            $t->string('original_name', 255);
            $t->string('stored_name', 100);
            $t->string('mime_type', 100);
            $t->unsignedInteger('size_bytes');
            $t->date('expiry_date')->nullable();
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
        });
        foreach (['supplier_gst_mappings' => ['gst_registration_id', 'gst_registrations'], 'supplier_business_mappings' => ['business_activity_id', 'gst_business_activities']] as $table => [$col, $on]) {
            $schema->create($table, static function (Blueprint $t) use ($audit, $col, $on): void {
                $t->id();
                $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT');
                $t->foreignId($col, $on, 'id', 'RESTRICT');
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->enum('status', ['active', 'ended'])->default('active');
                $t->string('notes', 255)->nullable();
                $t->string('end_reason', 255)->nullable();
                $audit($t);
                $t->index(['supplier_id', 'status']);
                $t->index([$col, 'status']);
            });
        }
        $schema->create('supplier_bank_transaction_links', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT');
            $t->foreignId('bank_transaction_id', 'bank_transactions', 'id', 'RESTRICT')->comment('An existing Phase 5 ledger entry; nothing is posted here');
            $t->string('reference', 100)->nullable()->comment('Supplier invoice / payment reference');
            $t->string('notes', 255)->nullable();
            $t->enum('status', ['active', 'removed'])->default('active');
            $t->foreignId('linked_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('removed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('removed_at')->nullable();
            $t->string('remove_reason', 255)->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
            $t->index(['bank_transaction_id', 'status']);
        });

        // ---------------------------------------------------------------- products
        $schema->create('products', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->char('uuid', 36)->comment('Stable identifier for inventory, listings, orders and purchases');
            $t->string('product_code', 20);
            $t->string('sku', 80)->nullable()->comment('Current parent SKU (unique through product_sku_registry)');
            $t->string('name', 200);
            $t->string('short_description', 255)->nullable();
            $t->text('description')->nullable();
            $t->foreignId('product_type_id', 'product_types', 'id', 'RESTRICT');
            $t->foreignId('category_id', 'product_categories', 'id', 'RESTRICT')->nullable();
            $t->foreignId('subcategory_id', 'product_categories', 'id', 'RESTRICT')->nullable();
            $t->foreignId('brand_id', 'product_brands', 'id', 'RESTRICT')->nullable();
            $t->string('manufacturer', 150)->nullable();
            $t->string('model_number', 100)->nullable();
            $t->string('barcode', 50)->nullable();
            $t->string('barcode_type', 10)->nullable()->comment('ean13, ean8, upc, other');
            $t->string('unit', 20)->default('pcs')->comment('Unit of measure (Phase 7 stock unit)');
            $t->string('tags', 500)->nullable()->comment('Comma-separated, lower case');
            $t->boolean('tracks_inventory')->default(true);
            $t->boolean('has_variants')->default(false);
            $t->enum('status', ['draft', 'pending_approval', 'active', 'inactive', 'discontinued', 'archived'])->default('draft');
            $t->string('status_before_archive', 30)->nullable();
            $t->string('status_reason', 255)->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->boolean('archived_use_allowed')->default(false)->comment('Archived but still allowed in new transactions (explicit exception)');
            $t->text('internal_notes')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $audit($t);
            $t->unique('uuid');
            $t->unique('product_code');
            $t->index(['status', 'name']);
            $t->index('category_id');
            $t->index('brand_id');
            $t->index('sku');
            $t->index('barcode');
        });
        $schema->create('product_variants', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->char('uuid', 36)->comment('Stable identifier for inventory-tracked variants');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->string('variant_code', 30);
            $t->string('name', 200);
            $t->string('sku', 80)->nullable();
            $t->string('barcode', 50)->nullable();
            $t->string('attribute_signature', 255)->nullable()->comment('Sorted attribute=value pairs; one variant per combination');
            $t->enum('status', ['active', 'inactive', 'discontinued', 'archived'])->default('active');
            $t->integer('sort_order')->default(0);
            $t->dateTime('archived_at')->nullable();
            $audit($t);
            $t->unique('uuid');
            $t->unique('variant_code');
            $t->unique(['product_id', 'name']);
            $t->unique(['product_id', 'attribute_signature']);
            $t->index('sku');
        });
        $schema->create('product_variant_attributes', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT');
            $t->foreignId('attribute_id', 'product_attributes', 'id', 'RESTRICT');
            $t->foreignId('value_id', 'product_attribute_values', 'id', 'RESTRICT')->nullable();
            $t->string('value_text', 150)->nullable();
            $t->timestamps();
            $t->unique(['variant_id', 'attribute_id']);
            $t->index('value_id');
        });
        $schema->create('product_sku_registry', static function (Blueprint $t): void {
            $t->id();
            $t->string('sku', 80)->comment('Upper case; unique across products and variants, current and retired');
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->boolean('is_current')->default(true);
            $t->boolean('is_generated')->default(false);
            $t->dateTime('retired_at')->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->unique('sku');
            $t->index(['product_id', 'variant_id', 'is_current']);
        });
        $schema->create('product_sku_sequences', static function (Blueprint $t): void {
            $t->id();
            $t->string('scope_key', 120)->comment('The SKU pattern with its tokens filled in, except the sequence');
            $t->unsignedInteger('last_value')->default(0);
            $t->dateTime('updated_at')->nullable();
            $t->unique('scope_key');
        });
        $schema->create('product_bundle_items', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('bundle_product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('component_product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('component_variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->string('notes', 255)->nullable();
            $audit($t);
            $t->index('bundle_product_id');
            $t->index('component_product_id');
        });
        $schema->create('product_images', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->string('original_name', 255);
            $t->string('stored_name', 100);
            $t->string('mime_type', 60);
            $t->unsignedInteger('size_bytes');
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->string('caption', 255)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->integer('sort_order')->default(0);
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'status']);
            $t->index(['variant_id', 'status']);
        });
        $schema->create('product_documents', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->enum('category', ['specification', 'manual', 'certificate', 'warranty', 'compliance', 'other']);
            $t->string('title', 150);
            $t->string('description', 500)->nullable();
            $t->string('original_name', 255);
            $t->string('stored_name', 100);
            $t->string('mime_type', 100);
            $t->unsignedInteger('size_bytes');
            $t->date('expiry_date')->nullable();
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'status']);
        });
        $schema->create('product_prices', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->enum('price_type', ['standard_purchase', 'standard_selling', 'minimum_selling', 'maximum_selling', 'wholesale', 'retail', 'marketplace_selling', 'supplier_purchase']);
            $t->decimal('amount', 15, 2);
            $t->char('currency', 3)->default('INR');
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT')->nullable()->comment('Required for supplier purchase prices');
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT')->nullable();
            $t->string('sales_channel', 60)->nullable()->comment('Channel code; Phase 8 links it to a marketplace / seller account');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->enum('status', ['pending_approval', 'active', 'superseded', 'cancelled', 'rejected'])->default('pending_approval');
            $t->string('slot_key', 191)->comment('Product, variant, type, supplier, GSTIN, channel and currency this price applies to');
            $t->string('notes', 255)->nullable();
            $t->foreignId('supersedes_id', 'product_prices', 'id', 'RESTRICT')->nullable();
            $t->foreignId('decided_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $audit($t);
            $t->index(['product_id', 'price_type', 'status']);
            $t->index(['slot_key', 'status']);
            $t->index(['supplier_id', 'status']);
        });
        $schema->create('product_tax_configs', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT')->nullable()->comment('NULL = applies to every mapped registration');
            $t->string('hsn_code', 8)->nullable();
            $t->string('sac_code', 6)->nullable();
            $t->foreignId('hsn_sac_id', 'gst_hsn_sac_codes', 'id', 'RESTRICT')->nullable();
            $t->foreignId('tax_category_id', 'product_tax_categories', 'id', 'RESTRICT');
            $t->foreignId('gst_rate_id', 'gst_tax_rates', 'id', 'RESTRICT')->nullable();
            $t->enum('applicability', ['taxable', 'exempt', 'nil_rated', 'zero_rated', 'non_gst'])->default('taxable');
            $t->enum('price_inclusion', ['exclusive', 'inclusive'])->default('exclusive');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->enum('status', ['pending_approval', 'active', 'superseded', 'cancelled', 'rejected'])->default('pending_approval');
            $t->string('slot_key', 191);
            $t->string('notes', 255)->nullable();
            $t->foreignId('supersedes_id', 'product_tax_configs', 'id', 'RESTRICT')->nullable();
            $t->foreignId('decided_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $audit($t);
            $t->index(['product_id', 'status']);
            $t->index(['slot_key', 'status']);
            $t->index('hsn_code');
        });
        foreach (['product_gst_mappings' => ['gst_registration_id', 'gst_registrations'], 'product_business_mappings' => ['business_activity_id', 'gst_business_activities']] as $table => [$col, $on]) {
            $schema->create($table, static function (Blueprint $t) use ($audit, $col, $on): void {
                $t->id();
                $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
                $t->foreignId($col, $on, 'id', 'RESTRICT');
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->enum('status', ['active', 'ended'])->default('active');
                $t->string('notes', 255)->nullable();
                $t->string('end_reason', 255)->nullable();
                $audit($t);
                $t->index(['product_id', 'status']);
                $t->index([$col, 'status']);
            });
        }
        $schema->create('product_supplier_mappings', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT');
            $t->string('supplier_product_code', 80)->nullable();
            $t->string('supplier_sku', 80)->nullable();
            $t->decimal('min_order_qty', 12, 3)->nullable();
            $t->decimal('max_order_qty', 12, 3)->nullable();
            $t->integer('lead_time_days')->nullable();
            $t->boolean('is_preferred')->default(false);
            $t->integer('priority')->default(10)->comment('1 = first choice');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->enum('status', ['active', 'inactive', 'ended'])->default('active');
            $t->string('notes', 500)->nullable();
            $t->string('end_reason', 255)->nullable();
            $audit($t);
            $t->index(['product_id', 'variant_id', 'status']);
            $t->index(['supplier_id', 'status']);
        });

        // ---------------------------------------------------------------- change requests & history
        $schema->create('product_change_requests', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id', 'products', 'id', 'RESTRICT');
            $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
            $t->enum('change_type', ['create', 'update', 'sku', 'archive', 'preferred_supplier']);
            $t->json('old_values')->nullable();
            $t->json('new_values');
            $t->string('reason', 500)->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $t->foreignId('approval_request_id', 'approval_requests', 'id', 'SET NULL')->nullable();
            $t->foreignId('requested_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'status']);
        });
        $schema->create('supplier_change_requests', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('supplier_id', 'suppliers', 'id', 'RESTRICT');
            $t->foreignId('bank_detail_id', 'supplier_bank_details', 'id', 'RESTRICT')->nullable();
            $t->enum('change_type', ['create', 'update', 'gstin', 'bank', 'archive']);
            $t->json('old_values')->nullable();
            $t->json('new_values');
            $t->string('reason', 500)->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $t->foreignId('approval_request_id', 'approval_requests', 'id', 'SET NULL')->nullable();
            $t->foreignId('requested_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
        });
        foreach (['product_history' => ['product_id', 'products'], 'supplier_history' => ['supplier_id', 'suppliers']] as $table => [$col, $on]) {
            $schema->create($table, static function (Blueprint $t) use ($col, $on, $table): void {
                $t->id();
                $t->foreignId($col, $on, 'id', 'RESTRICT');
                if ($table === 'product_history') {
                    $t->foreignId('variant_id', 'product_variants', 'id', 'RESTRICT')->nullable();
                }
                $t->string('event', 40);
                $t->string('from_status', 30)->nullable();
                $t->string('to_status', 30)->nullable();
                $t->json('old_values')->nullable();
                $t->json('new_values')->nullable();
                $t->string('reason', 500)->nullable();
                $t->date('effective_date')->nullable();
                $t->foreignId('actor_id', 'users', 'id', 'SET NULL')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index([$col, 'id']);
            });
        }
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['supplier_history', 'product_history', 'supplier_change_requests', 'product_change_requests', 'product_supplier_mappings',
            'product_business_mappings', 'product_gst_mappings', 'product_tax_configs', 'product_prices', 'product_documents', 'product_images',
            'product_bundle_items', 'product_sku_sequences', 'product_sku_registry', 'product_variant_attributes', 'product_variants', 'products',
            'supplier_bank_transaction_links', 'supplier_business_mappings', 'supplier_gst_mappings', 'supplier_documents', 'supplier_bank_details',
            'supplier_addresses', 'suppliers', 'supplier_types', 'product_attribute_values', 'product_attributes', 'product_brands', 'product_categories',
            'product_types', 'gst_hsn_sac_codes', 'product_tax_categories', 'gst_tax_rates'] as $table) {
            $schema->drop($table);
        }
    }
};
