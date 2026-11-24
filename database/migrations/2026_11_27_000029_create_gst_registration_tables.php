<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Phase 4 — GST Registration Management (master data only).
 *
 * - Masters: GST states/UTs, registration types, partner relationship types, business activities.
 * - Registrations with addresses, partner mappings (many-to-many, effective-dated),
 *   business-activity mappings, documents, per-registration settings, status history
 *   and change requests (routed through the existing approval engine).
 * - gst_registration_links: generic association foundation for future modules
 *   (bank accounts, platforms, seller accounts, warehouses, products, sales, purchases).
 *
 * Registrations are never hard-deleted (no cascade from registrations); they are
 * cancelled and/or archived. No GST portal credentials are stored anywhere.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('gst_states', static function (Blueprint $t): void {
            $t->id();
            $t->char('code', 2)->comment('Official GST state code, e.g. 24');
            $t->string('name', 120);
            $t->string('abbreviation', 4)->nullable();
            $t->boolean('is_union_territory')->default(false);
            $t->boolean('allows_registration')->default(true)->comment('Valid as the first two GSTIN characters');
            $t->boolean('is_active')->default(true)->comment('Inactive = legacy code, kept for existing GSTINs');
            $t->string('notes', 255)->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique('code');
            $t->index(['is_active', 'sort_order']);
        });

        $schema->create('gst_registration_types', static function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->enum('id_basis', ['pan', 'tan', 'other'])->default('pan')->comment('What characters 3–12 of the GSTIN carry');
            $t->char('fourteenth_char', 1)->nullable()->comment('Expected 14th GSTIN character, if fixed (Z, C, D)');
            $t->boolean('has_validity_period')->default(false)->comment('e.g. casual / non-resident registrations');
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique('code');
        });

        $schema->create('gst_partner_relationship_types', static function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->boolean('counts_as_owner')->default(false)->comment('Used by the optional single-owner business rule');
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique('code');
        });

        $schema->create('gst_business_activities', static function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('code');
        });

        $schema->create('gst_registrations', static function (Blueprint $t): void {
            $t->id();
            $t->char('gstin', 15)->comment('Normalised: uppercase, no spaces');
            $t->string('legal_name', 200);
            $t->string('trade_name', 200)->nullable();
            $t->char('pan', 10)->nullable()->comment('Characters 3–12 of a PAN-based GSTIN');
            $t->foreignId('registration_type_id', 'gst_registration_types', 'id', 'RESTRICT');
            $t->foreignId('state_id', 'gst_states', 'id', 'RESTRICT')->comment('Derived from the GSTIN state code');
            $t->enum('status', ['pending_verification', 'active', 'inactive', 'suspended', 'cancelled'])->default('pending_verification');
            $t->string('status_reason', 255)->nullable();
            $t->date('registration_date')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('validity_end_date')->nullable()->comment('Casual / non-resident registrations');
            $t->date('cancellation_date')->nullable();
            $t->string('business_constitution', 60)->nullable();
            $t->string('taxpayer_category', 60)->nullable();
            $t->string('nature_of_business', 500)->nullable();
            $t->string('contact_person', 150)->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->string('contact_email', 191)->nullable();
            $t->string('portal_reference', 120)->nullable()->comment('ARN / application reference only — never credentials');
            $t->text('internal_notes')->nullable();
            $t->enum('approval_state', ['none', 'pending', 'rejected'])->default('none')->comment('State of the creation request, if a workflow applies');
            $t->boolean('is_archived')->default(false);
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('gstin');
            $t->index(['status', 'is_archived']);
            $t->index('state_id');
            $t->index('registration_type_id');
            $t->index('legal_name');
            $t->index('updated_at');
        });

        $schema->create('gst_registration_addresses', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->string('label', 100)->nullable()->comment('e.g. Warehouse, Branch');
            $t->boolean('is_principal')->default(false);
            $t->string('address_line1', 200);
            $t->string('address_line2', 200)->nullable();
            $t->string('city', 100);
            $t->string('district', 100)->nullable();
            $t->foreignId('state_id', 'gst_states', 'id', 'RESTRICT');
            $t->string('pin_code', 10);
            $t->string('country', 80)->default('India');
            $t->string('contact_name', 150)->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->string('contact_email', 191)->nullable();
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['gst_registration_id', 'is_principal', 'status'], 'ix_gst_addresses_registration_principal_status');
        });

        $schema->create('gst_registration_partner_mappings', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->foreignId('partner_id', 'partners', 'id', 'RESTRICT');
            $t->foreignId('relationship_type_id', 'gst_partner_relationship_types', 'id', 'RESTRICT');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->enum('status', ['active', 'ended'])->default('active');
            $t->string('notes', 255)->nullable();
            $t->string('end_reason', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('ended_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->timestamps();
            $t->index(['gst_registration_id', 'status']);
            $t->index(['partner_id', 'status']);
        });

        $schema->create('gst_registration_business_mappings', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->foreignId('business_activity_id', 'gst_business_activities', 'id', 'RESTRICT');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['gst_registration_id', 'status']);
            $t->index('business_activity_id');
        });

        $schema->create('gst_registration_documents', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->string('document_type', 40);
            $t->string('title', 150);
            $t->string('description', 500)->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('original_name', 200);
            $t->string('stored_name', 120)->comment('YYYY/MM/<random>.<ext> under storage/uploads/gst-documents');
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at');
            $t->unique('stored_name');
            $t->index(['gst_registration_id', 'status']);
            $t->index('expiry_date');
        });

        $schema->create('gst_registration_settings', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->boolean('is_default')->default(false)->comment('Default registration for business operations (at most one)');
            $t->foreignId('default_place_of_supply_state_id', 'gst_states', 'id', 'SET NULL')->nullable();
            $t->foreignId('default_state_id', 'gst_states', 'id', 'SET NULL')->nullable();
            $t->foreignId('applicable_registration_type_id', 'gst_registration_types', 'id', 'SET NULL')->nullable();
            $t->boolean('prices_include_tax')->default(false)->comment('Default for future sales/purchase entry; no calculation here');
            $t->boolean('e_invoicing_applicable')->default(false);
            $t->boolean('e_way_bill_applicable')->default(false);
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->string('notes', 500)->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('gst_registration_id');
        });

        $schema->create('gst_registration_status_history', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30);
            $t->date('effective_date')->nullable();
            $t->string('reason', 255)->nullable();
            $t->unsignedBigInteger('change_request_id')->nullable();
            $t->foreignId('changed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at');
            $t->index('gst_registration_id');
        });

        $schema->create('gst_registration_change_requests', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->enum('change_type', ['create', 'update', 'cancel']);
            $t->json('old_values')->nullable();
            $t->json('new_values');
            $t->string('reason', 255)->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->foreignId('requested_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->timestamps();
            $t->index(['gst_registration_id', 'status']);
        });

        $schema->create('gst_registration_links', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'RESTRICT');
            $t->string('linked_type', 40)->comment('Registered by future modules, e.g. bank_account, platform, warehouse');
            $t->unsignedBigInteger('linked_id');
            $t->string('relationship', 60)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->enum('status', ['active', 'ended'])->default('active');
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique(['gst_registration_id', 'linked_type', 'linked_id'], 'uq_gst_links_registration_type_id');
            $t->index(['linked_type', 'linked_id']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['gst_registration_links', 'gst_registration_change_requests', 'gst_registration_status_history', 'gst_registration_settings',
            'gst_registration_documents', 'gst_registration_business_mappings', 'gst_registration_partner_mappings', 'gst_registration_addresses',
            'gst_registrations', 'gst_business_activities', 'gst_partner_relationship_types', 'gst_registration_types', 'gst_states'] as $table) {
            $schema->drop($table);
        }
    }
};
