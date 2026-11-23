<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Partner capital: one capital account per partner and an append-only
 * transaction register. Balances are always derived from posted rows:
 *   balance = SUM(credit) - SUM(debit) over status IN ('posted','reversed')
 * A reversal is a new posted row in the opposite direction that points to
 * the original (reversal_of_id); the original is marked 'reversed' but
 * keeps counting, so the pair nets to zero and history stays immutable.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('partner_capital_accounts', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('partner_id', 'partners', 'id', 'RESTRICT');
            $t->string('account_code', 30)->comment('e.g. CAP-PTR-0001');
            $t->string('accounting_ledger_ref', 60)->nullable()->comment('Phase 12: chart-of-accounts ledger mapped to this capital account');
            $t->enum('status', ['open', 'closed'])->default('open');
            $t->timestamps();
            $t->unique('partner_id');
            $t->unique('account_code');
        });
        $schema->create('partner_capital_transactions', static function (Blueprint $t): void {
            $t->id();
            $t->string('transaction_no', 30)->comment('CC-/CW-/CA-/CR-YYYY-NNNNN');
            $t->foreignId('partner_id', 'partners', 'id', 'RESTRICT');
            $t->foreignId('capital_account_id', 'partner_capital_accounts', 'id', 'RESTRICT');
            $t->enum('type', ['contribution', 'withdrawal', 'adjustment', 'reversal']);
            $t->enum('direction', ['credit', 'debit'])->comment('credit increases capital, debit reduces it');
            $t->decimal('amount', 15, 2)->comment('Always positive; direction gives the sign');
            $t->date('transaction_date');
            $t->enum('payment_method', ['bank_transfer', 'cash', 'cheque', 'upi', 'other'])->nullable();
            $t->string('payment_reference', 100)->nullable()->comment('UTR / cheque no. / UPI ref');
            $t->string('payment_source_ref', 150)->nullable()->comment('Temporary bank/cash reference until Phase 5 bank accounts');
            $t->unsignedBigInteger('bank_account_id')->nullable()->comment('Phase 5: bank_accounts.id');
            $t->enum('adjustment_category', ['opening_balance', 'correction', 'reclassification', 'other'])->nullable();
            $t->string('reason', 500)->nullable();
            $t->text('notes')->nullable();
            $t->enum('status', ['draft', 'pending_approval', 'approved', 'rejected', 'posted', 'reversed', 'cancelled'])->default('draft');
            $t->foreignId('approval_request_id', 'approval_requests', 'id', 'SET NULL')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->foreignId('reversal_of_id', 'partner_capital_transactions', 'id', 'RESTRICT')->nullable();
            $t->unsignedBigInteger('reversed_by_id')->nullable()->comment('The reversal row that cancelled this posted row');
            $t->foreignId('created_by', 'users', 'id', 'RESTRICT');
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->foreignId('approved_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('posted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('posted_at')->nullable();
            $t->foreignId('reversed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('reversed_at')->nullable();
            $t->foreignId('cancelled_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->integer('lock_version')->default(0)->comment('Optimistic lock');
            $t->timestamps();
            $t->unique('transaction_no');
            $t->unique('reversal_of_id'); // a posted row can be reversed only once
            $t->index(['partner_id', 'status']);
            $t->index(['type', 'status']);
            $t->index('transaction_date');
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        $schema->drop('partner_capital_transactions');
        $schema->drop('partner_capital_accounts');
    }
};
