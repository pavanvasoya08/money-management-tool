<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/** Private partner documents. Files live in storage/app/private (outside public/), served only through an authorised controller. */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('partner_documents', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('partner_id', 'partners', 'id', 'RESTRICT');
            $t->foreignId('capital_transaction_id', 'partner_capital_transactions', 'id', 'RESTRICT')->nullable();
            $t->foreignId('allocation_id', 'profit_sharing_allocations', 'id', 'RESTRICT')->nullable();
            $t->enum('category', ['agreement', 'pan', 'identity', 'contribution_proof', 'withdrawal_proof', 'adjustment_proof', 'profit_sharing_agreement', 'photo', 'other']);
            $t->string('title', 150);
            $t->string('original_name', 255);
            $t->string('stored_name', 100)->comment('Random name; never derived from user input');
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->foreignId('uploaded_by', 'users', 'id', 'RESTRICT');
            $t->dateTime('created_at');
            $t->dateTime('deleted_at')->nullable();
            $t->foreignId('deleted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->unique('stored_name');
            $t->index(['partner_id', 'category']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('partner_documents');
    }
};
