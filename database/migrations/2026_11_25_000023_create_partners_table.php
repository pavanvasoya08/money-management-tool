<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/** Partner master. A partner is a business entity, separate from an ERP login (user_id is an optional link). */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('partners', static function (Blueprint $t): void {
            $t->id();
            $t->string('partner_code', 20)->comment('Unique human-readable ID, e.g. PTR-0001');
            $t->string('name', 150);
            $t->string('email', 191)->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('state', 100)->nullable();
            $t->string('country', 100)->default('India');
            $t->string('pin_code', 12)->nullable();
            $t->char('pan_number', 10)->nullable()->comment('Indian PAN, upper case');
            $t->date('date_of_joining');
            $t->enum('status', ['active', 'inactive', 'suspended', 'archived'])->default('active');
            $t->string('status_reason', 255)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('photo_document_id')->nullable()->comment('partner_documents.id of the profile photo');
            $t->foreignId('user_id', 'users', 'id', 'SET NULL')->nullable()->comment('Linked ERP login (one user per partner)');
            $t->foreignId('created_by', 'users', 'id', 'RESTRICT');
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->timestamps();
            $t->unique('partner_code');
            $t->unique('user_id');
            $t->unique('pan_number');
            $t->index('status');
            $t->index('name');
        });
        $schema->create('partner_account_links', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('partner_id', 'partners', 'id', 'CASCADE');
            $t->foreignId('user_id', 'users', 'id', 'RESTRICT');
            $t->enum('action', ['linked', 'unlinked', 'login_created', 'login_suspended', 'login_reactivated']);
            $t->string('note', 255)->nullable();
            $t->foreignId('acted_by', 'users', 'id', 'RESTRICT');
            $t->dateTime('created_at');
            $t->index(['partner_id', 'created_at']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('partner_account_links');
        $schema->drop('partners');
    }
};
