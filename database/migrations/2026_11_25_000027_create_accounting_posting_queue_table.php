<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Accounting integration foundation. Posted business events are queued here
 * for the Phase 12 accounting engine. The unique key (source_type, source_id,
 * event) guarantees one business transaction can never be posted twice.
 * Account references are descriptive until Phase 5 (bank) and Phase 12 (ledgers) exist.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('accounting_posting_queue', static function (Blueprint $t): void {
            $t->id();
            $t->string('source_type', 60);
            $t->unsignedBigInteger('source_id');
            $t->string('event', 60);
            $t->date('entry_date');
            $t->decimal('amount', 15, 2);
            $t->string('debit_ref', 150);
            $t->string('credit_ref', 150);
            $t->json('payload')->nullable();
            $t->enum('status', ['pending', 'processed', 'skipped'])->default('pending');
            $t->unsignedBigInteger('journal_entry_id')->nullable()->comment('Phase 12 journal entry created from this row');
            $t->dateTime('processed_at')->nullable();
            $t->dateTime('created_at');
            $t->unique(['source_type', 'source_id', 'event']);
            $t->index('status');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('accounting_posting_queue');
    }
};
