<?php

declare(strict_types=1);

use App\Controllers\AccessRequestController;
use App\Controllers\AccountController;
use App\Controllers\Admin\AccessController;
use App\Controllers\Admin\AdminUserController;
use App\Controllers\Admin\ControlCenterController;
use App\Controllers\Admin\RoleController;
use App\Controllers\Admin\TemplateController;
use App\Controllers\Admin\WorkflowController;
use App\Controllers\Api\UserApiController;
use App\Controllers\ApprovalController;
use App\Controllers\ActivityLogController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\Bank\BankAccountController;
use App\Controllers\Bank\BankReconciliationController;
use App\Controllers\Bank\BankRelationController;
use App\Controllers\Bank\BankReportController;
use App\Controllers\Bank\BankSettingsController;
use App\Controllers\Bank\BankStatementController;
use App\Controllers\Bank\BankTransactionController;
use App\Controllers\Bank\BankTransferController;
use App\Controllers\Gst\GstMasterController;
use App\Controllers\Gst\GstRegistrationController;
use App\Controllers\Gst\GstRelationController;
use App\Controllers\Gst\GstReportController;
use App\Controllers\HelpController;
use App\Controllers\HelpManageController;
use App\Controllers\PasswordResetController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Controllers\Partners\AdjustmentController;
use App\Controllers\Partners\CapitalLedgerController;
use App\Controllers\Partners\ContributionController;
use App\Controllers\Partners\PartnerAccountController;
use App\Controllers\Partners\PartnerController;
use App\Controllers\Partners\PartnerDocumentController;
use App\Controllers\Partners\PartnerReportController;
use App\Controllers\Partners\ProfitSharingController;
use App\Controllers\Partners\WithdrawalController;
use App\Core\Router;

/*
 * Every route except the guest pages requires authentication. Sensitive
 * routes add a permission check ('can:<permission>') that is enforced by the
 * central Gate — controllers never compare role names.
 */
return static function (Router $r): void {
    $r->get('/', [DashboardController::class, 'home'], 'home');

    // ---------- Guests only ----------
    $r->group(['middleware' => ['guest']], static function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'], 'login');
        $r->post('/login', [AuthController::class, 'login'], 'login.attempt');
        $r->get('/forgot-password', [PasswordResetController::class, 'showRequest'], 'password.request');
        $r->post('/forgot-password', [PasswordResetController::class, 'sendLink'], 'password.email');
        $r->get('/reset-password/{token}', [PasswordResetController::class, 'showReset'], 'password.reset');
        $r->post('/reset-password', [PasswordResetController::class, 'reset'], 'password.update');
    });

    // ---------- Authenticated ----------
    $r->group(['middleware' => ['auth']], static function (Router $r): void {
        $r->post('/logout', [AuthController::class, 'logout'], 'logout');
        $r->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');

        $r->get('/account', [AccountController::class, 'profile'], 'account.profile');
        $r->get('/account/password', [AccountController::class, 'showPassword'], 'account.password');
        $r->post('/account/password', [AccountController::class, 'updatePassword'], 'account.password.update');

        // ---------- Phase 3: partners & capital ----------
        $r->group(['prefix' => 'partners'], static function (Router $r): void {
            $r->get('/', [PartnerController::class, 'index'], 'partners.index', ['can:partners.view']);
            $r->get('/create', [PartnerController::class, 'create'], 'partners.create', ['can:partners.create']);
            $r->post('/', [PartnerController::class, 'store'], 'partners.store', ['can:partners.create']);
            $r->get('/export', [PartnerController::class, 'export'], 'partners.export', ['can:partners.export']);
            $r->get('/{id}', [PartnerController::class, 'show'], 'partners.show', ['can:partners.view']);
            $r->get('/{id}/photo', [PartnerController::class, 'photo'], 'partners.photo', ['can:partners.view']);
            $r->get('/{id}/edit', [PartnerController::class, 'edit'], 'partners.edit', ['can:partners.edit']);
            $r->post('/{id}', [PartnerController::class, 'update'], 'partners.update', ['can:partners.edit']);
            $r->post('/{id}/status', [PartnerController::class, 'status'], 'partners.status', ['can:partners.change_status']);
            $r->post('/{id}/suspend', [PartnerController::class, 'suspend'], 'partners.suspend', ['can:partners.suspend']);
            $r->post('/{id}/reactivate', [PartnerController::class, 'reactivate'], 'partners.reactivate', ['can:partners.suspend']);
            $r->post('/{id}/archive', [PartnerController::class, 'archive'], 'partners.archive', ['can:partners.archive']);
            $r->post('/{id}/restore', [PartnerController::class, 'restore'], 'partners.restore', ['can:partners.archive']);
            $r->post('/{id}/link', [PartnerAccountController::class, 'link'], 'partners.link', ['can:partners.link_account']);
            $r->post('/{id}/unlink', [PartnerAccountController::class, 'unlink'], 'partners.unlink', ['can:partners.link_account']);
            $r->post('/{id}/login', [PartnerAccountController::class, 'createLogin'], 'partners.login.create', ['can:partners.link_account']);
            $r->post('/{id}/login/suspend', [PartnerAccountController::class, 'suspendLogin'], 'partners.login.suspend', ['can:partners.link_account']);
            $r->post('/{id}/login/reactivate', [PartnerAccountController::class, 'reactivateLogin'], 'partners.login.reactivate', ['can:partners.link_account']);
            $r->post('/{id}/documents', [PartnerDocumentController::class, 'store'], 'partners.documents.store', ['can:partners.upload_documents']);
            $r->get('/{id}/documents/{doc}', [PartnerDocumentController::class, 'download'], 'partners.documents.download', ['can:partners.view_documents']);
            $r->post('/{id}/documents/{doc}/remove', [PartnerDocumentController::class, 'destroy'], 'partners.documents.remove', ['can:partners.delete_documents']);
        });

        foreach ([['contributions', ContributionController::class, 'capital_contributions'], ['withdrawals', WithdrawalController::class, 'capital_withdrawals'], ['adjustments', AdjustmentController::class, 'capital_adjustments']] as [$path, $ctl, $mod]) {
            $r->group(['prefix' => 'capital/' . $path], static function (Router $r) use ($path, $ctl, $mod): void {
                $n = 'capital.' . $path;
                $r->get('/', [$ctl, 'index'], "{$n}.index", ["can:{$mod}.view"]);
                $r->get('/create', [$ctl, 'create'], "{$n}.create", ["can:{$mod}.create"]);
                $r->post('/', [$ctl, 'store'], "{$n}.store", ["can:{$mod}.create"]);
                $r->get('/export', [$ctl, 'export'], "{$n}.export", ["can:{$mod}.export"]);
                $r->get('/{id}', [$ctl, 'show'], "{$n}.show", ["can:{$mod}.view"]);
                $r->get('/{id}/edit', [$ctl, 'edit'], "{$n}.edit", ["can:{$mod}.edit"]);
                $r->post('/{id}', [$ctl, 'update'], "{$n}.update", ["can:{$mod}.edit"]);
                $r->post('/{id}/submit', [$ctl, 'submit'], "{$n}.submit", ["can:{$mod}.create"]);
                $r->post('/{id}/cancel', [$ctl, 'cancel'], "{$n}.cancel", ["can:{$mod}.cancel"]);
                $r->post('/{id}/post', [$ctl, 'post'], "{$n}.post", ["can:{$mod}.post"]);
                $r->post('/{id}/reverse', [$ctl, 'reverse'], "{$n}.reverse", ["can:{$mod}.reverse"]);
            });
        }

        $r->group(['prefix' => 'capital/ledger'], static function (Router $r): void {
            $r->get('/', [CapitalLedgerController::class, 'index'], 'capital.ledger.index', ['can:capital_ledger.view']);
            $r->get('/{id}', [CapitalLedgerController::class, 'show'], 'capital.ledger.show', ['can:capital_ledger.view']);
            $r->get('/{id}/export', [CapitalLedgerController::class, 'export'], 'capital.ledger.export', ['can:capital_ledger.export']);
        });

        $r->group(['prefix' => 'profit-sharing'], static function (Router $r): void {
            $r->get('/', [ProfitSharingController::class, 'index'], 'profit-sharing.index', ['can:profit_sharing.view']);
            $r->get('/create', [ProfitSharingController::class, 'create'], 'profit-sharing.create', ['can:profit_sharing.create']);
            $r->post('/', [ProfitSharingController::class, 'store'], 'profit-sharing.store', ['can:profit_sharing.create']);
            $r->get('/{id}', [ProfitSharingController::class, 'show'], 'profit-sharing.show', ['can:profit_sharing.view']);
            $r->get('/{id}/edit', [ProfitSharingController::class, 'edit'], 'profit-sharing.edit', ['can:profit_sharing.edit']);
            $r->post('/{id}', [ProfitSharingController::class, 'update'], 'profit-sharing.update', ['can:profit_sharing.edit']);
            $r->post('/{id}/status', [ProfitSharingController::class, 'status'], 'profit-sharing.status', ['can:profit_sharing.change_status']);
            $r->get('/{id}/allocations/create', [ProfitSharingController::class, 'createAllocation'], 'profit-sharing.allocations.create', ['can:profit_sharing.create']);
            $r->post('/{id}/allocations', [ProfitSharingController::class, 'storeAllocation'], 'profit-sharing.allocations.store', ['can:profit_sharing.create']);
            $r->get('/{id}/allocations/{alloc}/edit', [ProfitSharingController::class, 'editAllocation'], 'profit-sharing.allocations.edit', ['can:profit_sharing.edit']);
            $r->post('/{id}/allocations/{alloc}', [ProfitSharingController::class, 'updateAllocation'], 'profit-sharing.allocations.update', ['can:profit_sharing.edit']);
            $r->post('/{id}/allocations/{alloc}/deactivate', [ProfitSharingController::class, 'deactivateAllocation'], 'profit-sharing.allocations.deactivate', ['can:profit_sharing.change_status']);
        });

        $r->group(['prefix' => 'partner-reports'], static function (Router $r): void {
            $r->get('/', [PartnerReportController::class, 'index'], 'partner-reports.index', ['can:partner_reports.view']);
            $r->get('/{report}', [PartnerReportController::class, 'show'], 'partner-reports.show', ['can:partner_reports.view']);
            $r->get('/{report}/export', [PartnerReportController::class, 'export'], 'partner-reports.export', ['can:partner_reports.export']);
        });

        // User management
        $r->group(['prefix' => 'users'], static function (Router $r): void {
            $r->get('/', [UserController::class, 'index'], 'users.index', ['can:users.view']);
            $r->get('/create', [UserController::class, 'create'], 'users.create', ['can:users.create']);
            $r->post('/', [UserController::class, 'store'], 'users.store', ['can:users.create']);
            $r->get('/export', [UserController::class, 'export'], 'users.export', ['can:users.export']);
            $r->get('/login-history', [UserController::class, 'loginHistory'], 'users.login-history', ['can:users.view_login_history']);
            $r->get('/{id}', [UserController::class, 'show'], 'users.show', ['can:users.view']);
            $r->get('/{id}/edit', [UserController::class, 'edit'], 'users.edit', ['can:users.edit']);
            $r->post('/{id}', [UserController::class, 'update'], 'users.update', ['can:users.edit']);
            $r->post('/{id}/roles', [UserController::class, 'updateRoles'], 'users.roles', ['can:users.assign_roles']);
            $r->post('/{id}/status', [UserController::class, 'updateStatus'], 'users.status', ['can:users.change_status']);
            $r->post('/{id}/password', [UserController::class, 'resetPassword'], 'users.password', ['can:users.reset_password']);
        });

        $r->get('/activity', [ActivityLogController::class, 'index'], 'activity.index', ['can:activity_logs.view']);

        // Approval inbox (any user who can approve in some module)
        $r->get('/approvals', [ApprovalController::class, 'index'], 'approvals.index');
        $r->get('/approvals/{id}', [ApprovalController::class, 'show'], 'approvals.show');
        $r->post('/approvals/{id}/approve', [ApprovalController::class, 'approve'], 'approvals.approve');
        $r->post('/approvals/{id}/reject', [ApprovalController::class, 'reject'], 'approvals.reject');
        $r->post('/approvals/{id}/cancel', [ApprovalController::class, 'cancel'], 'approvals.cancel');

        $r->post('/access-requests', [AccessRequestController::class, 'store'], 'access-requests.store');

        // ---------- Phase 4: GST registration management ----------
        $r->group(['prefix' => 'gst'], static function (Router $r): void {
            $v = ['can:gst_registrations.view'];
            $r->get('/', [GstRegistrationController::class, 'dashboard'], 'gst.dashboard', $v);
            $r->get('/registrations', [GstRegistrationController::class, 'index'], 'gst.index', $v);
            $r->get('/registrations/export', [GstRegistrationController::class, 'export'], 'gst.export', ['can:gst_registrations.export']);
            $r->get('/registrations/create', [GstRegistrationController::class, 'create'], 'gst.create', ['can:gst_registrations.create']);
            $r->post('/registrations', [GstRegistrationController::class, 'store'], 'gst.store', ['can:gst_registrations.create']);
            $r->post('/check-gstin', [GstRegistrationController::class, 'check'], 'gst.check', $v);
            $r->get('/registrations/{id}', [GstRegistrationController::class, 'show'], 'gst.show', $v);
            $r->get('/registrations/{id}/edit', [GstRegistrationController::class, 'edit'], 'gst.edit', ['can:gst_registrations.edit']);
            $r->post('/registrations/{id}', [GstRegistrationController::class, 'update'], 'gst.update', ['can:gst_registrations.edit']);
            // Target-specific permission (activate / deactivate / suspend / cancel) is checked in the service.
            $r->post('/registrations/{id}/status', [GstRegistrationController::class, 'status'], 'gst.status', $v);
            $r->post('/registrations/{id}/archive', [GstRegistrationController::class, 'archive'], 'gst.archive', ['can:gst_registrations.archive']);
            $r->post('/registrations/{id}/restore', [GstRegistrationController::class, 'restore'], 'gst.restore', ['can:gst_registrations.archive']);
            $r->post('/registrations/{id}/resubmit', [GstRegistrationController::class, 'resubmit'], 'gst.resubmit', ['can:gst_registrations.create']);

            $r->post('/registrations/{id}/partners', [GstRelationController::class, 'addPartner'], 'gst.partners.add', ['can:gst_registrations.manage_partners']);
            $r->post('/registrations/{id}/partners/{mapping}/end', [GstRelationController::class, 'endPartner'], 'gst.partners.end', ['can:gst_registrations.manage_partners']);
            $r->post('/registrations/{id}/activities', [GstRelationController::class, 'addActivity'], 'gst.activities.add', ['can:gst_registrations.manage_business_mappings']);
            $r->post('/registrations/{id}/activities/{mapping}', [GstRelationController::class, 'updateActivity'], 'gst.activities.update', ['can:gst_registrations.manage_business_mappings']);
            $r->post('/registrations/{id}/locations', [GstRelationController::class, 'saveLocation'], 'gst.locations.add', ['can:gst_registrations.manage_locations']);
            $r->post('/registrations/{id}/locations/{location}', [GstRelationController::class, 'updateLocation'], 'gst.locations.update', ['can:gst_registrations.manage_locations']);
            $r->post('/registrations/{id}/locations/{location}/status', [GstRelationController::class, 'locationStatus'], 'gst.locations.status', ['can:gst_registrations.manage_locations']);
            $r->post('/registrations/{id}/locations/{location}/principal', [GstRelationController::class, 'principal'], 'gst.locations.principal', ['can:gst_registrations.manage_locations']);
            $r->post('/registrations/{id}/settings', [GstRelationController::class, 'saveSettings'], 'gst.settings', ['can:gst_registrations.manage_settings']);
            $r->post('/registrations/{id}/documents', [GstRelationController::class, 'uploadDocument'], 'gst.documents.upload', ['can:gst_registrations.manage_documents']);
            $r->get('/documents/{doc}/download', [GstRelationController::class, 'download'], 'gst.documents.download', ['can:gst_registrations.download_documents']);
            $r->get('/documents/{doc}/preview', [GstRelationController::class, 'preview'], 'gst.documents.preview', ['can:gst_registrations.download_documents']);
            $r->post('/documents/{doc}/archive', [GstRelationController::class, 'archiveDocument'], 'gst.documents.archive', ['can:gst_registrations.manage_documents']);

            $r->get('/reports', [GstReportController::class, 'index'], 'gst.reports', $v);
            $r->get('/reports/{report}', [GstReportController::class, 'show'], 'gst.reports.show', $v);
            $r->get('/reports/{report}/export', [GstReportController::class, 'export'], 'gst.reports.export', ['can:gst_registrations.export']);

            $m = ['can:gst_registrations.manage_masters'];
            $r->get('/masters', [GstMasterController::class, 'index'], 'gst.masters', $m);
            $r->post('/masters/rules', [GstMasterController::class, 'rules'], 'gst.masters.rules', $m);
            $r->post('/masters/{master}', [GstMasterController::class, 'save'], 'gst.masters.store', $m);
            $r->post('/masters/{master}/{id}', [GstMasterController::class, 'update'], 'gst.masters.update', $m);
        });

        // ---------- Phase 5: bank account management ----------
        // Route guards check the permission; every action also loads records through the bank-account record scope (404 outside it).
        $r->group(['prefix' => 'bank'], static function (Router $r): void {
            $v = ['can:bank_accounts.view'];
            $r->get('/', [BankAccountController::class, 'dashboard'], 'bank.dashboard', ['can:bank_accounts.view_dashboard']);
            $r->get('/accounts', [BankAccountController::class, 'index'], 'bank.index', $v);
            $r->get('/accounts/export', [BankAccountController::class, 'export'], 'bank.export', ['can:bank_accounts.export']);
            $r->get('/accounts/create', [BankAccountController::class, 'create'], 'bank.create', ['can:bank_accounts.create']);
            $r->post('/accounts', [BankAccountController::class, 'store'], 'bank.store', ['can:bank_accounts.create']);
            $r->get('/accounts/{id}', [BankAccountController::class, 'show'], 'bank.show', $v);
            $r->post('/accounts/{id}/reveal', [BankAccountController::class, 'reveal'], 'bank.reveal', ['can:bank_accounts.view_full_account_number']);
            $r->get('/accounts/{id}/edit', [BankAccountController::class, 'edit'], 'bank.edit', ['can:bank_accounts.edit']);
            $r->post('/accounts/{id}', [BankAccountController::class, 'update'], 'bank.update', ['can:bank_accounts.edit']);
            $r->post('/accounts/{id}/submit', [BankAccountController::class, 'submit'], 'bank.submit', ['can:bank_accounts.create']);
            $r->post('/accounts/{id}/status', [BankAccountController::class, 'status'], 'bank.status', $v);
            $r->post('/accounts/{id}/central', [BankAccountController::class, 'central'], 'bank.central', ['can:bank_accounts.manage_central_account']);
            $r->post('/accounts/{id}/archive', [BankAccountController::class, 'archive'], 'bank.archive', ['can:bank_accounts.archive']);
            $r->post('/accounts/{id}/restore', [BankAccountController::class, 'restore'], 'bank.restore', ['can:bank_accounts.archive']);
            $p = ['can:bank_accounts.manage_partners'];
            $r->post('/accounts/{id}/partners', [BankRelationController::class, 'addPartner'], 'bank.partners.add', $p);
            $r->post('/accounts/{id}/partners/{mapping}/end', [BankRelationController::class, 'endPartner'], 'bank.partners.end', $p);
            $g = ['can:bank_accounts.manage_gst_mappings'];
            $r->post('/accounts/{id}/gst', [BankRelationController::class, 'addGst'], 'bank.gst.add', $g);
            $r->post('/accounts/{id}/gst/{mapping}/designation', [BankRelationController::class, 'designation'], 'bank.gst.designation', $g);
            $r->post('/accounts/{id}/gst/{mapping}/end', [BankRelationController::class, 'endGst'], 'bank.gst.end', $g);
            $b = ['can:bank_accounts.manage_business_mappings'];
            $r->post('/accounts/{id}/activities', [BankRelationController::class, 'addActivity'], 'bank.activities.add', $b);
            $r->post('/accounts/{id}/activities/{mapping}', [BankRelationController::class, 'updateActivity'], 'bank.activities.update', $b);
            $r->post('/accounts/{id}/documents', [BankRelationController::class, 'uploadDocument'], 'bank.documents.upload', ['can:bank_accounts.manage_documents']);
            $r->get('/documents/{doc}/download', [BankRelationController::class, 'download'], 'bank.documents.download', ['can:bank_accounts.download_documents']);
            $r->get('/documents/{doc}/preview', [BankRelationController::class, 'preview'], 'bank.documents.preview', ['can:bank_accounts.download_documents']);
            $r->post('/documents/{doc}/archive', [BankRelationController::class, 'archiveDocument'], 'bank.documents.archive', ['can:bank_accounts.manage_documents']);

            $r->get('/transactions', [BankTransactionController::class, 'index'], 'bank.transactions.index', $v);
            $r->get('/transactions/export', [BankTransactionController::class, 'export'], 'bank.transactions.export', ['can:bank_accounts.export']);
            $r->get('/transactions/create', [BankTransactionController::class, 'create'], 'bank.transactions.create', $v);
            $r->post('/transactions', [BankTransactionController::class, 'store'], 'bank.transactions.store', $v);
            $r->get('/transactions/{id}', [BankTransactionController::class, 'show'], 'bank.transactions.show', $v);
            $r->get('/transactions/{id}/edit', [BankTransactionController::class, 'edit'], 'bank.transactions.edit', $v);
            $r->post('/transactions/{id}', [BankTransactionController::class, 'update'], 'bank.transactions.update', $v);
            $r->post('/transactions/{id}/submit', [BankTransactionController::class, 'submit'], 'bank.transactions.submit', $v);
            $r->post('/transactions/{id}/cancel', [BankTransactionController::class, 'cancel'], 'bank.transactions.cancel', $v);
            $r->post('/transactions/{id}/post', [BankTransactionController::class, 'post'], 'bank.transactions.post', ['can:bank_accounts.approve_transactions']);
            $r->post('/transactions/{id}/reverse', [BankTransactionController::class, 'reverse'], 'bank.transactions.reverse', ['can:bank_accounts.reverse_transactions']);
            $r->post('/transactions/{id}/link-capital', [BankTransactionController::class, 'linkCapital'], 'bank.transactions.link', ['can:bank_accounts.manage_transactions']);

            $r->get('/transfers', [BankTransferController::class, 'index'], 'bank.transfers.index', $v);
            $r->get('/transfers/create', [BankTransferController::class, 'create'], 'bank.transfers.create', ['can:bank_accounts.manage_transfers']);
            $r->post('/transfers', [BankTransferController::class, 'store'], 'bank.transfers.store', ['can:bank_accounts.manage_transfers']);
            $r->get('/transfers/{id}', [BankTransferController::class, 'show'], 'bank.transfers.show', $v);
            $r->post('/transfers/{id}/submit', [BankTransferController::class, 'submit'], 'bank.transfers.submit', ['can:bank_accounts.manage_transfers']);
            $r->post('/transfers/{id}/cancel', [BankTransferController::class, 'cancel'], 'bank.transfers.cancel', ['can:bank_accounts.manage_transfers']);
            $r->post('/transfers/{id}/post', [BankTransferController::class, 'post'], 'bank.transfers.post', ['can:bank_accounts.approve_transfers']);
            $r->post('/transfers/{id}/reverse', [BankTransferController::class, 'reverse'], 'bank.transfers.reverse', ['can:bank_accounts.reverse_transactions']);

            $r->get('/statements', [BankStatementController::class, 'index'], 'bank.statements.index', $v);
            $r->post('/statements', [BankStatementController::class, 'store'], 'bank.statements.store', ['can:bank_accounts.manage_statements']);
            $r->get('/statements/{id}', [BankStatementController::class, 'show'], 'bank.statements.show', $v);
            $r->get('/statements/{id}/import', [BankStatementController::class, 'import'], 'bank.statements.import', ['can:bank_accounts.import_statements']);
            $r->post('/statements/{id}/preview', [BankStatementController::class, 'preview'], 'bank.statements.preview', ['can:bank_accounts.import_statements']);
            $r->post('/statements/{id}/imports/{import}/confirm', [BankStatementController::class, 'confirm'], 'bank.statements.confirm', ['can:bank_accounts.import_statements']);
            $r->post('/statements/{id}/imports/{import}/discard', [BankStatementController::class, 'discard'], 'bank.statements.discard', ['can:bank_accounts.import_statements']);
            $r->get('/statements/{id}/download', [BankStatementController::class, 'download'], 'bank.statements.download', ['can:bank_accounts.download_documents']);
            $r->post('/statements/{id}/archive', [BankStatementController::class, 'archive'], 'bank.statements.archive', ['can:bank_accounts.manage_statements']);

            $rc = ['can:bank_accounts.manage_reconciliation'];
            $r->get('/reconciliation', [BankReconciliationController::class, 'index'], 'bank.reconciliation.index', $v);
            $r->post('/reconciliation', [BankReconciliationController::class, 'store'], 'bank.reconciliation.store', $rc);
            $r->get('/reconciliation/{id}', [BankReconciliationController::class, 'show'], 'bank.reconciliation.show', $v);
            $r->post('/reconciliation/{id}/match', [BankReconciliationController::class, 'match'], 'bank.reconciliation.match', $rc);
            $r->post('/reconciliation/{id}/accept', [BankReconciliationController::class, 'accept'], 'bank.reconciliation.accept', $rc);
            $r->post('/reconciliation/{id}/matches/{match}/remove', [BankReconciliationController::class, 'unmatch'], 'bank.reconciliation.unmatch', $rc);
            $r->post('/reconciliation/{id}/rows/{row}/ignore', [BankReconciliationController::class, 'ignore'], 'bank.reconciliation.ignore', $rc);
            $r->post('/reconciliation/{id}/complete', [BankReconciliationController::class, 'complete'], 'bank.reconciliation.complete', $rc);
            $r->post('/reconciliation/{id}/reopen', [BankReconciliationController::class, 'reopen'], 'bank.reconciliation.reopen', ['can:bank_accounts.reopen_reconciliation']);

            $r->get('/reports', [BankReportController::class, 'index'], 'bank.reports', $v);
            $r->get('/reports/{report}', [BankReportController::class, 'show'], 'bank.reports.show', $v);
            $r->get('/reports/{report}/export', [BankReportController::class, 'export'], 'bank.reports.export', ['can:bank_accounts.export']);

            $r->get('/settings', [BankSettingsController::class, 'index'], 'bank.settings', ['can:bank_accounts.manage_account_types']);
            $r->post('/settings/types', [BankSettingsController::class, 'storeType'], 'bank.settings.types.store', ['can:bank_accounts.manage_account_types']);
            $r->post('/settings/types/{id}', [BankSettingsController::class, 'updateType'], 'bank.settings.types.update', ['can:bank_accounts.manage_account_types']);
            $r->post('/settings/rules', [BankSettingsController::class, 'rules'], 'bank.settings.rules', ['can:bank_accounts.manage_settings']);
        });

        // ---------- Help & User Guide ----------
        // Reading is open to EVERY signed-in user: no module, role, record or field check.
        // Drafts and management need documentation.* permissions (granted through the Control Center).
        $r->group(['prefix' => 'help'], static function (Router $r): void {
            $r->get('/', [HelpController::class, 'index'], 'help.index');
            $r->get('/search', [HelpController::class, 'search'], 'help.search');
            $r->get('/category/{slug}', [HelpController::class, 'category'], 'help.category');
            $r->get('/guides/{slug}', [HelpController::class, 'guide'], 'help.guide');
            $r->get('/media/{id}', [HelpController::class, 'media'], 'help.media');

            $r->group(['prefix' => 'manage'], static function (Router $r): void {
                $m = ['can:documentation.manage'];
                $r->get('/', [HelpManageController::class, 'index'], 'help.manage', $m);
                $r->get('/categories/create', [HelpManageController::class, 'createCategory'], 'help.manage.categories.create', $m);
                $r->post('/categories', [HelpManageController::class, 'storeCategory'], 'help.manage.categories.store', $m);
                $r->get('/categories/{id}/edit', [HelpManageController::class, 'editCategory'], 'help.manage.categories.edit', $m);
                $r->post('/categories/{id}', [HelpManageController::class, 'updateCategory'], 'help.manage.categories.update', $m);
                $r->post('/categories/{id}/move', [HelpManageController::class, 'moveCategory'], 'help.manage.categories.move', $m);
                $r->post('/categories/{id}/delete', [HelpManageController::class, 'deleteCategory'], 'help.manage.categories.delete', ['can:documentation.delete']);
                $r->get('/guides/create', [HelpManageController::class, 'createGuide'], 'help.manage.guides.create', $m);
                $r->post('/guides', [HelpManageController::class, 'storeGuide'], 'help.manage.guides.store', $m);
                $r->get('/guides/{id}/edit', [HelpManageController::class, 'editGuide'], 'help.manage.guides.edit', $m);
                $r->get('/guides/{id}/preview', [HelpManageController::class, 'preview'], 'help.manage.guides.preview', $m);
                $r->get('/guides/{id}/revisions', [HelpManageController::class, 'revisions'], 'help.manage.guides.revisions', $m);
                $r->get('/guides/{id}/revisions/{rev}', [HelpManageController::class, 'revision'], 'help.manage.guides.revision', $m);
                $r->post('/guides/{id}', [HelpManageController::class, 'updateGuide'], 'help.manage.guides.update', $m);
                $r->post('/guides/{id}/move', [HelpManageController::class, 'moveGuide'], 'help.manage.guides.move', $m);
                $r->post('/guides/{id}/publish', [HelpManageController::class, 'publish'], 'help.manage.guides.publish', ['can:documentation.publish']);
                $r->post('/guides/{id}/unpublish', [HelpManageController::class, 'unpublish'], 'help.manage.guides.unpublish', ['can:documentation.publish']);
                $r->post('/guides/{id}/delete', [HelpManageController::class, 'deleteGuide'], 'help.manage.guides.delete', ['can:documentation.delete']);
                $r->post('/guides/{id}/media', [HelpManageController::class, 'uploadMedia'], 'help.manage.media.store', $m);
                $r->post('/media/{id}', [HelpManageController::class, 'updateMedia'], 'help.manage.media.update', $m);
                $r->post('/media/{id}/delete', [HelpManageController::class, 'deleteMedia'], 'help.manage.media.delete', ['can:documentation.delete']);
            });
        });

        // ---------- Super Admin Control Center (real Super Admins only — never delegable) ----------
        $r->group(['prefix' => 'admin', 'middleware' => ['super-admin']], static function (Router $r): void {
            $r->get('/', [ControlCenterController::class, 'overview'], 'admin.overview');

            $r->get('/users/{id}', [AdminUserController::class, 'show'], 'admin.users.show');
            foreach (['overrides', 'restrict-module', 'data-scope', 'fields', 'resources', 'template', 'suspend', 'unsuspend', 'expiry', 'restrictions', 'force-logout', 'delete', 'restore'] as $action) {
                $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));
                $r->post('/users/{id}/' . $action, [AdminUserController::class, $method], 'admin.users.' . $action);
            }

            $r->get('/roles', [RoleController::class, 'index'], 'admin.roles');
            $r->get('/roles/create', [RoleController::class, 'create'], 'admin.roles.create');
            $r->post('/roles', [RoleController::class, 'store'], 'admin.roles.store');
            $r->get('/roles/{id}', [RoleController::class, 'show'], 'admin.roles.show');
            $r->post('/roles/{id}', [RoleController::class, 'update'], 'admin.roles.update');
            foreach (['duplicate', 'delete', 'permissions', 'copy', 'data-scope', 'fields'] as $action) {
                $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));
                $r->post('/roles/{id}/' . $action, [RoleController::class, $method], 'admin.roles.' . $action);
            }

            $r->get('/permissions', [RoleController::class, 'matrix'], 'admin.permissions');
            $r->get('/actions', [AccessController::class, 'actions'], 'admin.actions');
            $r->post('/modules/{id}/toggle', [AccessController::class, 'toggleModule'], 'admin.modules.toggle');
            $r->post('/actions/{id}/toggle', [AccessController::class, 'toggleAction'], 'admin.actions.toggle');
            $r->get('/data-visibility', [AccessController::class, 'dataVisibility'], 'admin.data');
            $r->post('/data-visibility', [AccessController::class, 'saveDataVisibility'], 'admin.data.save');
            $r->get('/fields', [AccessController::class, 'fields'], 'admin.fields');
            $r->post('/fields', [AccessController::class, 'saveFields'], 'admin.fields.save');
            $r->get('/resources', [AccessController::class, 'resources'], 'admin.resources');
            $r->get('/preview', [AccessController::class, 'preview'], 'admin.preview');

            $r->get('/workflows', [WorkflowController::class, 'index'], 'admin.workflows');
            $r->get('/workflows/requests', [WorkflowController::class, 'requests'], 'admin.workflows.requests');
            $r->get('/workflows/{id}', [WorkflowController::class, 'show'], 'admin.workflows.show');
            $r->post('/workflows/{id}', [WorkflowController::class, 'update'], 'admin.workflows.update');
            $r->post('/workflows/{id}/steps', [WorkflowController::class, 'steps'], 'admin.workflows.steps');

            $r->get('/templates', [TemplateController::class, 'index'], 'admin.templates');
            $r->get('/templates/create', [TemplateController::class, 'create'], 'admin.templates.create');
            $r->post('/templates', [TemplateController::class, 'store'], 'admin.templates.store');
            $r->get('/templates/{id}', [TemplateController::class, 'show'], 'admin.templates.show');
            $r->post('/templates/{id}', [TemplateController::class, 'update'], 'admin.templates.update');
            $r->post('/templates/{id}/duplicate', [TemplateController::class, 'duplicate'], 'admin.templates.duplicate');
            $r->post('/templates/{id}/delete', [TemplateController::class, 'delete'], 'admin.templates.delete');
            $r->post('/templates/{id}/apply', [TemplateController::class, 'apply'], 'admin.templates.apply');

            $r->get('/sessions', [ControlCenterController::class, 'sessions'], 'admin.sessions');
            $r->post('/sessions/{id}/revoke', [ControlCenterController::class, 'revokeSession'], 'admin.sessions.revoke');
            $r->get('/audit', [ControlCenterController::class, 'audit'], 'admin.audit');
            $r->get('/audit/{id}', [ControlCenterController::class, 'auditEntry'], 'admin.audit.show');
            $r->get('/security', [ControlCenterController::class, 'security'], 'admin.security');
            $r->post('/security', [ControlCenterController::class, 'saveSecurity'], 'admin.security.save');
            $r->get('/access-requests', [ControlCenterController::class, 'accessRequests'], 'admin.access-requests');
            $r->post('/access-requests/{id}', [ControlCenterController::class, 'decideAccessRequest'], 'admin.access-requests.decide');
        });

        $r->get('/settings', [SettingsController::class, 'index'], 'settings.index', ['can:settings.view']);
        $r->post('/settings', [SettingsController::class, 'update'], 'settings.update', ['can:settings.edit']);
    });

    // ---------- JSON API (session-authenticated; same Gate, record and field rules) ----------
    $r->group(['prefix' => 'api', 'middleware' => ['auth']], static function (Router $r): void {
        $r->get('/users', [UserApiController::class, 'index'], 'api.users', ['can:users.view']);
        $r->get('/users/{id}', [UserApiController::class, 'show'], 'api.users.show', ['can:users.view']);
    });
};
