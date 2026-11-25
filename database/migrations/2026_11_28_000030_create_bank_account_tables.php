<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Migration;
use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;

/**
 * Phase 5 — Bank Account Management.
 *
 * - bank_accounts: account master. The full account number is stored encrypted
 *   (AES-256-GCM, APP_KEY) with a keyed hash for duplicate detection and the last
 *   four digits for display. No banking passwords, PINs, OTPs or card data exist.
 * - bank_transactions: the single ledger (source of truth for balances). Every money
 *   movement is a row; balances = opening balance + posted credits − posted debits.
 *   An internal transfer is one bank_transfers row with exactly two linked ledger
 *   rows (transfer_out / transfer_in). Reversals are new posted rows (reversal_of_id),
 *   so posted rows are never edited or deleted.
 * - Partner capital stays in Phase 3: a deposit/withdrawal may reference one capital
 *   entry (one live link per capital entry, enforced under a row lock), never duplicate it.
 * - Statements → imports → rows; reconciliation → matches. Reconciliation never
 *   changes ledger rows.
 * - approval_workflows.approve_action / reject_action are added by the following
 *   migration (000031) so ALTERs stay in their own _add_ migration.
 */
return new class extends Migration {
    public function up(Schema $schema, Database $db): void
    {
        $schema->create('bank_account_types', static function (Blueprint $t): void {
            $t->id();
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->boolean('allows_overdraft')->default(false)->comment('Default for new accounts of this type');
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('code');
        });

        $schema->create('bank_accounts', static function (Blueprint $t): void {
            $t->id();
            $t->string('account_code', 20)->comment('BNK-0001');
            $t->string('display_name', 150);
            $t->string('bank_name', 150);
            $t->string('branch_name', 150)->nullable();
            $t->string('account_holder_name', 200);
            $t->text('account_number_encrypted')->comment('AES-256-GCM with APP_KEY; never shown in full without permission');
            $t->char('account_number_hash', 64)->comment('HMAC-SHA256 of the normalised number, for duplicate detection');
            $t->string('account_number_last4', 4);
            $t->char('ifsc_code', 11);
            $t->string('micr_code', 9)->nullable();
            $t->foreignId('account_type_id', 'bank_account_types');
            $t->char('currency', 3)->default('INR');
            $t->date('opening_date')->nullable();
            $t->decimal('opening_balance', 15, 2)->default('0.00')->comment('Changed after activation only through opening-balance adjustment entries');
            $t->date('opening_balance_date');
            $t->enum('status', ['draft', 'pending_approval', 'active', 'inactive', 'frozen', 'closed'])->default('draft');
            $t->string('status_reason', 255)->nullable();
            $t->string('purpose', 255)->nullable();
            $t->text('internal_notes')->nullable();
            $t->boolean('is_central_funding')->default(false)->comment('The one primary central joint funding account');
            $t->boolean('allow_overdraft')->default(false);
            $t->decimal('overdraft_limit', 15, 2)->default('0.00');
            $t->date('closed_on')->nullable();
            $t->foreignId('approval_request_id', 'approval_requests', 'id', 'SET NULL')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->boolean('is_archived')->default(false);
            $t->dateTime('archived_at')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->string('archive_reason', 255)->nullable();
            $t->unsignedInteger('lock_version')->default(0)->comment('Bumped inside posting transactions: serialises balance checks');
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('account_code');
            $t->unique(['ifsc_code', 'account_number_hash'], 'uq_bank_accounts_ifsc_number');
            $t->index('account_number_hash');
            $t->index(['status', 'is_archived']);
            $t->index('is_central_funding');
        });

        $schema->create('bank_account_partner_mappings', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->foreignId('partner_id', 'partners');
            $t->enum('relationship', ['account_holder', 'joint_holder', 'authorized_operator', 'beneficiary', 'other']);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->enum('status', ['active', 'ended'])->default('active');
            $t->string('notes', 255)->nullable();
            $t->string('end_reason', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['bank_account_id', 'status']);
            $t->index(['partner_id', 'status']);
        });

        $schema->create('bank_account_gst_mappings', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->foreignId('gst_registration_id', 'gst_registrations');
            $t->enum('designation', ['primary', 'secondary'])->default('secondary');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->enum('status', ['active', 'ended'])->default('active');
            $t->string('notes', 255)->nullable();
            $t->string('end_reason', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['bank_account_id', 'status']);
            $t->index(['gst_registration_id', 'status', 'designation'], 'ix_bank_gst_maps_registration_status');
        });

        $schema->create('bank_account_business_mappings', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->foreignId('business_activity_id', 'gst_business_activities');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->enum('status', ['active', 'inactive'])->default('active');
            $t->string('notes', 255)->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['bank_account_id', 'status']);
            $t->index('business_activity_id');
        });

        $schema->create('bank_statements', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->date('period_from');
            $t->date('period_to');
            $t->decimal('opening_balance', 15, 2)->nullable()->comment('As printed on the statement');
            $t->decimal('closing_balance', 15, 2)->nullable()->comment('As printed on the statement');
            $t->string('original_name', 255);
            $t->string('stored_name', 100);
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->char('file_sha256', 64);
            $t->enum('import_status', ['not_imported', 'previewed', 'imported', 'failed'])->default('not_imported');
            $t->string('notes', 500)->nullable();
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->index(['bank_account_id', 'period_from']);
            $t->index('file_sha256');
        });

        $schema->create('bank_statement_imports', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('statement_id', 'bank_statements');
            $t->json('column_map')->comment('CSV column index per field');
            $t->string('date_format', 20);
            $t->char('delimiter', 1)->default(',');
            $t->boolean('has_header')->default(true);
            $t->enum('status', ['preview', 'imported', 'discarded'])->default('preview');
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_valid')->default(0);
            $t->unsignedInteger('rows_invalid')->default(0);
            $t->unsignedInteger('rows_duplicate')->default(0);
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('imported_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('imported_at')->nullable();
            $t->timestamps();
            $t->index(['statement_id', 'status']);
        });

        $schema->create('bank_statement_rows', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('import_id', 'bank_statement_imports');
            $t->foreignId('statement_id', 'bank_statements');
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->unsignedInteger('row_no');
            $t->date('txn_date')->nullable();
            $t->string('description', 500)->nullable();
            $t->string('reference_no', 100)->nullable();
            $t->decimal('debit', 15, 2)->default('0.00');
            $t->decimal('credit', 15, 2)->default('0.00');
            $t->decimal('balance', 15, 2)->nullable();
            $t->char('row_hash', 64)->comment('Account + date + amounts + reference + description: duplicate detection');
            $t->enum('status', ['valid', 'invalid', 'duplicate'])->default('valid');
            $t->string('errors', 500)->nullable();
            $t->enum('match_status', ['unmatched', 'matched', 'ignored'])->default('unmatched');
            $t->string('ignore_reason', 255)->nullable();
            $t->timestamps();
            $t->index(['import_id', 'status']);
            $t->index(['bank_account_id', 'row_hash']);
            $t->index(['bank_account_id', 'match_status', 'txn_date'], 'ix_bank_stmt_rows_account_match_date');
        });

        $schema->create('bank_transfers', static function (Blueprint $t): void {
            $t->id();
            $t->string('transfer_no', 20)->comment('TRF-000001');
            $t->foreignId('from_account_id', 'bank_accounts');
            $t->foreignId('to_account_id', 'bank_accounts');
            $t->date('transfer_date');
            $t->decimal('amount', 15, 2);
            $t->string('reference_no', 100)->nullable()->comment('Reference / UTR');
            $t->string('purpose', 255);
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'SET NULL')->nullable();
            $t->foreignId('business_activity_id', 'gst_business_activities', 'id', 'SET NULL')->nullable();
            $t->text('notes')->nullable();
            $t->enum('status', ['draft', 'pending_approval', 'approved', 'rejected', 'posted', 'reversed', 'cancelled'])->default('draft');
            $t->foreignId('approval_request_id', 'approval_requests', 'id', 'SET NULL')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->string('posting_error', 255)->nullable()->comment('Why an approved transfer could not be posted yet');
            $t->dateTime('submitted_at')->nullable();
            $t->foreignId('approved_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('posted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('posted_at')->nullable();
            $t->foreignId('reversed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('reversed_at')->nullable();
            $t->string('reversal_reason', 500)->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('transfer_no');
            $t->index(['status', 'transfer_date']);
            $t->index(['from_account_id', 'status']);
            $t->index(['to_account_id', 'status']);
        });

        $schema->create('bank_transactions', static function (Blueprint $t): void {
            $t->id();
            $t->string('transaction_no', 20)->comment('BTX-000001');
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->date('transaction_date');
            $t->enum('type', ['deposit', 'withdrawal', 'transfer_in', 'transfer_out', 'bank_charges', 'interest', 'adjustment', 'opening_adjustment', 'reversal']);
            $t->enum('direction', ['credit', 'debit']);
            $t->decimal('amount', 15, 2)->comment('Always positive; direction gives the sign');
            $t->string('reference_no', 100)->nullable();
            $t->string('utr_cheque_no', 60)->nullable();
            $t->string('description', 500)->nullable();
            $t->string('counterparty_name', 200)->nullable();
            $t->foreignId('partner_id', 'partners', 'id', 'SET NULL')->nullable();
            $t->foreignId('gst_registration_id', 'gst_registrations', 'id', 'SET NULL')->nullable();
            $t->foreignId('business_activity_id', 'gst_business_activities', 'id', 'SET NULL')->nullable();
            $t->string('source_module', 40)->default('manual')->comment('manual, capital_contribution, capital_withdrawal, transfer, statement, reversal');
            $t->foreignId('capital_transaction_id', 'partner_capital_transactions', 'id', 'RESTRICT')->nullable()->comment('Phase 3 capital entry this bank movement belongs to');
            $t->foreignId('transfer_id', 'bank_transfers')->nullable();
            $t->foreignId('statement_row_id', 'bank_statement_rows', 'id', 'SET NULL')->nullable()->comment('Statement line this entry was created from (manual action)');
            $t->foreignId('reversal_of_id', 'bank_transactions')->nullable();
            $t->string('reversal_reason', 500)->nullable();
            $t->enum('status', ['draft', 'pending_approval', 'approved', 'rejected', 'posted', 'reversed', 'cancelled'])->default('draft');
            $t->foreignId('approval_request_id', 'approval_requests', 'id', 'SET NULL')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->string('posting_error', 255)->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->foreignId('approved_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('posted_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('posted_at')->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('updated_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('transaction_no');
            $t->index('capital_transaction_id'); // one live link per capital entry is enforced in BankTransactionService (rejected/cancelled/reversed links may be replaced)
            $t->unique('reversal_of_id', 'uq_bank_txn_reversal_of');
            $t->unique(['transfer_id', 'type'], 'uq_bank_txn_transfer_side');
            $t->index(['bank_account_id', 'status', 'transaction_date'], 'ix_bank_txn_account_status_date');
            $t->index(['status', 'transaction_date']);
            $t->index(['bank_account_id', 'transaction_date', 'amount'], 'ix_bank_txn_duplicate_check');
            $t->index('partner_id');
            $t->index('gst_registration_id');
        });

        $schema->create('bank_reconciliations', static function (Blueprint $t): void {
            $t->id();
            $t->string('reconciliation_no', 20)->comment('REC-000001');
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->foreignId('statement_id', 'bank_statements', 'id', 'SET NULL')->nullable();
            $t->date('period_from');
            $t->date('period_to');
            $t->decimal('statement_closing_balance', 15, 2)->nullable();
            $t->decimal('ledger_closing_balance', 15, 2)->nullable()->comment('Snapshot when completed');
            $t->enum('status', ['in_progress', 'completed', 'reopen_pending'])->default('in_progress');
            $t->dateTime('reconciled_at')->nullable();
            $t->foreignId('reconciled_by', 'users', 'id', 'SET NULL')->nullable();
            $t->unsignedInteger('times_reopened')->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('created_by', 'users', 'id', 'SET NULL')->nullable();
            $t->timestamps();
            $t->unique('reconciliation_no');
            $t->index(['bank_account_id', 'status']);
        });

        $schema->create('bank_reconciliation_matches', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('reconciliation_id', 'bank_reconciliations');
            $t->foreignId('statement_row_id', 'bank_statement_rows');
            $t->foreignId('bank_transaction_id', 'bank_transactions');
            $t->enum('match_type', ['manual', 'suggested'])->default('manual');
            $t->enum('status', ['active', 'removed'])->default('active');
            $t->unsignedBigInteger('active_row_key')->nullable()->comment('= statement_row_id while active: one active match per statement line');
            $t->unsignedBigInteger('active_txn_key')->nullable()->comment('= bank_transaction_id while active: one active match per ledger entry');
            $t->foreignId('matched_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('matched_at');
            $t->foreignId('removed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('removed_at')->nullable();
            $t->string('removed_reason', 255)->nullable();
            $t->timestamps();
            $t->unique('active_row_key', 'uq_bank_match_active_row');
            $t->unique('active_txn_key', 'uq_bank_match_active_txn');
            $t->index(['reconciliation_id', 'status']);
        });

        $schema->create('bank_account_documents', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->foreignId('bank_transaction_id', 'bank_transactions', 'id', 'SET NULL')->nullable();
            $t->foreignId('transfer_id', 'bank_transfers', 'id', 'SET NULL')->nullable();
            $t->enum('document_type', ['cancelled_cheque', 'account_proof', 'bank_statement', 'account_opening', 'transaction_proof', 'other']);
            $t->string('title', 150);
            $t->string('description', 500)->nullable();
            $t->string('original_name', 255);
            $t->string('stored_name', 100);
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->enum('status', ['active', 'archived'])->default('active');
            $t->string('archive_reason', 255)->nullable();
            $t->foreignId('uploaded_by', 'users', 'id', 'SET NULL')->nullable();
            $t->foreignId('archived_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->timestamps();
            $t->index(['bank_account_id', 'status']);
            $t->index('bank_transaction_id');
            $t->index('transfer_id');
        });

        $schema->create('bank_change_requests', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->foreignId('reconciliation_id', 'bank_reconciliations', 'id', 'SET NULL')->nullable();
            $t->enum('change_type', ['create', 'update', 'close', 'reconciliation_reopen']);
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('reason', 500)->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $t->foreignId('approval_request_id', 'approval_requests', 'id', 'SET NULL')->nullable();
            $t->foreignId('requested_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->timestamps();
            $t->index(['bank_account_id', 'status']);
        });

        $schema->create('bank_account_history', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('bank_account_id', 'bank_accounts');
            $t->string('event', 40)->comment('created, updated, status, central, mapping, …');
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('reason', 500)->nullable();
            $t->date('effective_date')->nullable();
            $t->foreignId('changed_by', 'users', 'id', 'SET NULL')->nullable();
            $t->dateTime('created_at');
            $t->index(['bank_account_id', 'created_at']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['bank_account_history', 'bank_change_requests', 'bank_account_documents', 'bank_reconciliation_matches', 'bank_reconciliations',
            'bank_transactions', 'bank_transfers', 'bank_statement_rows', 'bank_statement_imports', 'bank_statements',
            'bank_account_business_mappings', 'bank_account_gst_mappings', 'bank_account_partner_mappings', 'bank_accounts', 'bank_account_types'] as $table) {
            $schema->drop($table);
        }
    }
};
