<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Phase 8A — E-commerce Platform Management (the platform master only).
 *
 * A platform is a marketplace or sales channel (Amazon, Flipkart, own website, offline …).
 * Nothing is hardcoded: categories and platforms are rows. Platforms are never deleted, only
 * archived, so future seller accounts (8B), integrations (8C), orders (9), returns and
 * settlements (10) and fees (12) can always point back to them.
 *
 * Reuses Phase 4 GST registrations and business activities, Phase 3 partners and Phase 7
 * warehouses through effective-dated mapping tables (a mapping is a default or permitted scope,
 * never ownership and never a profit-sharing rule).
 *
 * Capabilities describe what a platform offers and what the ERP supports for it. The ERP status
 * "operational" can only be set for capabilities the ERP really implements — none in 8A.
 * connection_status is written only by a future integration (8C); in 8A it is always "not_connected".
 * No credentials, API keys or seller accounts are stored here (8B).
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $audit = static function (Blueprint $t): void {
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
        };

        $schema->create('ecommerce_platform_categories', static function (Blueprint $t) use ($audit): void {
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

        $schema->create('ecommerce_platforms', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->char('uuid', 36)->comment('Stable identifier for seller accounts, integrations, orders and settlements');
            $t->string('platform_code', 30);
            $t->string('name', 150);
            $t->foreignId('category_id', 'ecommerce_platform_categories', 'id', 'RESTRICT');
            $t->string('website_url', 255)->nullable();
            $t->text('description')->nullable();
            $t->char('country', 2)->default('IN');
            $t->string('currencies', 120)->default('INR')->comment('Comma-separated ISO 4217 codes the platform supports');
            $t->char('default_currency', 3)->default('INR');
            $t->char('business_currency', 3)->default('INR')->comment('Currency the business records this channel in');
            $t->string('languages', 120)->default('en')->comment('Comma-separated language codes');
            $t->string('time_zone', 64)->default('Asia/Kolkata');
            $t->string('date_format', 20)->default('d-m-Y');
            $t->string('number_format', 20)->default('en_IN');
            $t->string('reference_prefix', 12)->nullable()->comment('Prefix for this platform\'s references (orders, settlements) in later phases');
            $t->enum('status', ['under_configuration', 'active', 'inactive', 'archived'])->default('under_configuration');
            $t->string('status_reason', 255)->nullable();
            $t->enum('connection_status', ['not_connected', 'connected', 'disconnected', 'error'])->default('not_connected')
                ->comment('Set only by a future integration (Phase 8C)');
            $t->dateTime('connection_checked_at')->nullable();
            $t->integer('display_order')->default(0);
            $t->text('internal_notes')->nullable();
            $t->string('logo_stored_name', 100)->nullable();
            $t->string('logo_mime', 50)->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $audit($t);
            $t->unique('platform_code');
            $t->unique('uuid');
            $t->index(['status', 'display_order']);
            $t->index('category_id');
        });

        $schema->create('ecommerce_platform_capabilities', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('platform_id', 'ecommerce_platforms', 'id', 'RESTRICT');
            $t->string('capability_key', 40);
            $t->enum('platform_support', ['unknown', 'supported', 'not_supported'])->default('unknown')->comment('What the platform itself offers');
            $t->enum('erp_status', ['not_available', 'planned', 'operational'])->default('not_available')->comment('What this ERP can do with it');
            $t->string('notes', 255)->nullable();
            $audit($t);
            $t->unique(['platform_id', 'capability_key'], 'ux_ecom_capability');
        });

        $schema->create('ecommerce_platform_settings', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('platform_id', 'ecommerce_platforms', 'id', 'RESTRICT');
            $t->string('setting_key', 60);
            $t->string('value', 500)->nullable();
            $t->string('description', 255)->nullable();
            $audit($t);
            $t->unique(['platform_id', 'setting_key'], 'ux_ecom_setting');
        });

        foreach ([
            'ecommerce_platform_gst_mappings' => ['gst_registration_id', 'gst_registrations'],
            'ecommerce_platform_business_mappings' => ['business_activity_id', 'gst_business_activities'],
            'ecommerce_platform_partner_mappings' => ['partner_id', 'partners'],
            'ecommerce_platform_warehouse_mappings' => ['warehouse_id', 'warehouses'],
        ] as $table => [$col, $on]) {
            $schema->create($table, static function (Blueprint $t) use ($audit, $col, $on): void {
                $t->id();
                $t->foreignId('platform_id', 'ecommerce_platforms', 'id', 'RESTRICT');
                $t->foreignId($col, $on, 'id', 'RESTRICT');
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->enum('status', ['active', 'ended'])->default('active');
                $t->string('notes', 255)->nullable();
                $t->string('end_reason', 255)->nullable();
                $audit($t);
                $t->index(['platform_id', 'status'], 'ix_ecomap_' . $col . '_p');
                $t->index([$col, 'status'], 'ix_ecomap_' . $col . '_s');
            });
        }

        $schema->create('ecommerce_platform_documents', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('platform_id', 'ecommerce_platforms', 'id', 'RESTRICT');
            $t->string('document_type', 40)->default('other');
            $t->string('title', 150);
            $t->string('description', 500)->nullable();
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
            $t->index(['platform_id', 'status']);
        });

        $schema->create('ecommerce_platform_notes', static function (Blueprint $t) use ($audit): void {
            $t->id();
            $t->foreignId('platform_id', 'ecommerce_platforms', 'id', 'RESTRICT');
            $t->string('title', 150);
            $t->text('body');
            $t->enum('visibility', ['everyone', 'managers', 'private'])->default('everyone')
                ->comment('everyone = all who can view the platform; managers = platforms.manage_notes; private = author and Super Admin');
            $t->enum('status', ['active', 'archived'])->default('active');
            $audit($t);
            $t->index(['platform_id', 'status']);
        });

        $schema->create('ecommerce_platform_history', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('platform_id', 'ecommerce_platforms', 'id', 'RESTRICT');
            $t->string('event', 40);
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('reason', 500)->nullable();
            $t->date('effective_date')->nullable();
            $t->foreignId('actor_id', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->index(['platform_id', 'id']);
            $t->index(['event', 'id']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['ecommerce_platform_history', 'ecommerce_platform_notes', 'ecommerce_platform_documents', 'ecommerce_platform_warehouse_mappings', 'ecommerce_platform_partner_mappings',
            'ecommerce_platform_business_mappings', 'ecommerce_platform_gst_mappings', 'ecommerce_platform_settings', 'ecommerce_platform_capabilities',
            'ecommerce_platforms', 'ecommerce_platform_categories'] as $table) {
            $schema->drop($table);
        }
    }
};
