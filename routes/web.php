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
use App\Controllers\Product\ProductCatalogController;
use App\Controllers\Product\ProductController;
use App\Controllers\Product\ProductDetailController;
use App\Controllers\Product\ProductReportController;
use App\Controllers\Supplier\SupplierController;
use App\Controllers\Supplier\SupplierDetailController;
use App\Controllers\Supplier\SupplierReportController;
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
use App\Controllers\Inventory\AdjustmentController as InventoryAdjustmentController;
use App\Controllers\Inventory\BatchSerialController;
use App\Controllers\Inventory\EntryController as InventoryEntryController;
use App\Controllers\Inventory\InventoryController;
use App\Controllers\Inventory\InventorySettingsController;
use App\Controllers\Inventory\ProductInventoryController;
use App\Controllers\Inventory\ReservationController as InventoryReservationController;
use App\Controllers\Inventory\TransferController as InventoryTransferController;
use App\Controllers\Inventory\WarehouseController;
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

        // ---------- Phase 6: products ----------
        // Route guards check the permission; every record is also loaded through the product record scope (404 outside it).
        // Static paths are registered before /{id} so they are matched first.
        $r->group(['prefix' => 'products'], static function (Router $r): void {
            $v = ['can:products.view'];
            $r->get('/dashboard', [ProductController::class, 'dashboard'], 'products.dashboard', ['can:products.view_dashboard']);
            $r->get('/', [ProductController::class, 'index'], 'products.index', $v);
            $r->get('/export', [ProductController::class, 'export'], 'products.export', ['can:products.export']);
            $r->get('/create', [ProductController::class, 'create'], 'products.create', ['can:products.create']);
            $r->post('/', [ProductController::class, 'store'], 'products.store', ['can:products.create']);
            $r->get('/history', [ProductController::class, 'historyPage'], 'products.history', ['can:products.view_history']);

            $r->get('/categories', [ProductCatalogController::class, 'categories'], 'products.categories', $v);
            $r->post('/categories', [ProductCatalogController::class, 'saveCategory'], 'products.categories.store', ['can:products.manage_categories']);
            $r->post('/categories/{id}', [ProductCatalogController::class, 'saveCategory'], 'products.categories.update', ['can:products.manage_categories']);
            $r->post('/categories/{id}/status', [ProductCatalogController::class, 'categoryStatus'], 'products.categories.status', ['can:products.manage_categories']);
            $r->get('/categories/{id}/image', [ProductCatalogController::class, 'categoryImage'], 'products.categories.image', $v);
            $r->get('/brands', [ProductCatalogController::class, 'brands'], 'products.brands', $v);
            $r->post('/brands', [ProductCatalogController::class, 'saveBrand'], 'products.brands.store', ['can:products.manage_brands']);
            $r->post('/brands/{id}', [ProductCatalogController::class, 'saveBrand'], 'products.brands.update', ['can:products.manage_brands']);
            $r->post('/brands/{id}/status', [ProductCatalogController::class, 'brandStatus'], 'products.brands.status', ['can:products.manage_brands']);
            $r->get('/brands/{id}/logo', [ProductCatalogController::class, 'brandLogo'], 'products.brands.logo', $v);
            $r->get('/attributes', [ProductCatalogController::class, 'attributes'], 'products.attributes', $v);
            $r->post('/attributes', [ProductCatalogController::class, 'saveAttribute'], 'products.attributes.store', ['can:products.manage_attributes']);
            $r->post('/attributes/{id}', [ProductCatalogController::class, 'saveAttribute'], 'products.attributes.update', ['can:products.manage_attributes']);
            $r->post('/attributes/{id}/values', [ProductCatalogController::class, 'saveValue'], 'products.attributes.values.store', ['can:products.manage_attributes']);
            $r->post('/attributes/{id}/values/{value}', [ProductCatalogController::class, 'saveValue'], 'products.attributes.values.update', ['can:products.manage_attributes']);
            $r->get('/variants', [ProductCatalogController::class, 'variants'], 'products.variants', $v);
            $r->get('/pricing', [ProductCatalogController::class, 'pricing'], 'products.pricing', $v);
            $r->get('/tax', [ProductCatalogController::class, 'tax'], 'products.tax', $v);
            $r->post('/tax/rates', [ProductCatalogController::class, 'saveRate'], 'products.tax.rates.store', ['can:products.manage_settings']);
            $r->post('/tax/rates/{id}', [ProductCatalogController::class, 'saveRate'], 'products.tax.rates.update', ['can:products.manage_settings']);
            $r->post('/tax/categories', [ProductCatalogController::class, 'saveTaxCategory'], 'products.tax.categories.store', ['can:products.manage_settings']);
            $r->post('/tax/categories/{id}', [ProductCatalogController::class, 'saveTaxCategory'], 'products.tax.categories.update', ['can:products.manage_settings']);
            $r->post('/tax/codes', [ProductCatalogController::class, 'saveCode'], 'products.tax.codes.store', ['can:products.manage_settings']);
            $r->post('/tax/codes/{id}', [ProductCatalogController::class, 'saveCode'], 'products.tax.codes.update', ['can:products.manage_settings']);
            $r->get('/supplier-mappings', [ProductCatalogController::class, 'mappings'], 'products.mappings', $v);
            $r->get('/documents', [ProductCatalogController::class, 'documents'], 'products.documents', $v);
            $r->get('/settings', [ProductCatalogController::class, 'settings'], 'products.settings', ['can:products.manage_settings']);
            $r->post('/settings/rules', [ProductCatalogController::class, 'saveRules'], 'products.settings.rules', ['can:products.manage_settings']);
            $r->post('/settings/types', [ProductCatalogController::class, 'saveType'], 'products.settings.types.store', ['can:products.manage_settings']);
            $r->post('/settings/types/{id}', [ProductCatalogController::class, 'saveType'], 'products.settings.types.update', ['can:products.manage_settings']);
            $r->get('/reports', [ProductReportController::class, 'index'], 'products.reports', $v);
            $r->get('/reports/{report}', [ProductReportController::class, 'show'], 'products.reports.show', $v);
            $r->get('/reports/{report}/export', [ProductReportController::class, 'export'], 'products.reports.export', ['can:products.export']);

            $r->get('/{id}', [ProductController::class, 'show'], 'products.show', $v);
            $r->get('/{id}/edit', [ProductController::class, 'edit'], 'products.edit', ['can:products.edit']);
            $r->post('/{id}', [ProductController::class, 'update'], 'products.update', ['can:products.edit']);
            $r->post('/{id}/submit', [ProductController::class, 'submit'], 'products.submit', ['can:products.create']);
            $r->post('/{id}/status', [ProductController::class, 'status'], 'products.status', $v);
            $r->post('/{id}/sku', [ProductController::class, 'sku'], 'products.sku', ['can:products.manage_sku']);
            $r->post('/{id}/archive', [ProductController::class, 'archive'], 'products.archive', ['can:products.archive']);
            $r->post('/{id}/restore', [ProductController::class, 'restore'], 'products.restore', ['can:products.restore']);
            $r->post('/{id}/archived-use', [ProductController::class, 'archivedUse'], 'products.archived-use', ['can:products.restore']);

            $r->post('/{id}/variants', [ProductDetailController::class, 'addVariant'], 'products.variants.store', ['can:products.manage_variants']);
            $r->post('/{id}/variants/{variant}', [ProductDetailController::class, 'updateVariant'], 'products.variants.update', ['can:products.manage_variants']);
            $r->post('/{id}/variants/{variant}/status', [ProductDetailController::class, 'variantStatus'], 'products.variants.status', ['can:products.manage_variants']);
            $r->post('/{id}/components', [ProductDetailController::class, 'addComponent'], 'products.components.store', ['can:products.edit']);
            $r->post('/{id}/components/{item}/remove', [ProductDetailController::class, 'removeComponent'], 'products.components.remove', ['can:products.edit']);
            $r->post('/{id}/prices', [ProductDetailController::class, 'addPrice'], 'products.prices.store', ['can:products.manage_pricing']);
            $r->post('/{id}/prices/{price}/cancel', [ProductDetailController::class, 'cancelPrice'], 'products.prices.cancel', ['can:products.manage_pricing']);
            $r->post('/{id}/tax', [ProductDetailController::class, 'addTax'], 'products.tax.store', $v);
            $r->post('/{id}/tax/{config}/cancel', [ProductDetailController::class, 'cancelTax'], 'products.tax.cancel', $v);
            $r->post('/{id}/gst', [ProductDetailController::class, 'addGst'], 'products.gst.store', ['can:products.manage_gst_mappings']);
            $r->post('/{id}/gst/{mapping}/end', [ProductDetailController::class, 'endGst'], 'products.gst.end', ['can:products.manage_gst_mappings']);
            $r->post('/{id}/activities', [ProductDetailController::class, 'addActivity'], 'products.activities.store', ['can:products.manage_business_mappings']);
            $r->post('/{id}/activities/{mapping}/end', [ProductDetailController::class, 'endActivity'], 'products.activities.end', ['can:products.manage_business_mappings']);
            $r->post('/{id}/suppliers', [ProductDetailController::class, 'addSupplier'], 'products.suppliers.store', ['can:products.manage_supplier_mappings']);
            $r->post('/{id}/suppliers/{mapping}', [ProductDetailController::class, 'updateSupplier'], 'products.suppliers.update', ['can:products.manage_supplier_mappings']);
            $r->post('/{id}/suppliers/{mapping}/end', [ProductDetailController::class, 'endSupplier'], 'products.suppliers.end', ['can:products.manage_supplier_mappings']);
            $r->post('/{id}/suppliers/{mapping}/preferred', [ProductDetailController::class, 'preferSupplier'], 'products.suppliers.preferred', ['can:products.manage_supplier_mappings']);
            $r->post('/{id}/images', [ProductDetailController::class, 'uploadImage'], 'products.images.store', ['can:products.manage_documents']);
            $r->get('/{id}/images/{image}', [ProductDetailController::class, 'image'], 'products.images.show', $v);
            $r->post('/{id}/images/{image}/primary', [ProductDetailController::class, 'primaryImage'], 'products.images.primary', ['can:products.manage_documents']);
            $r->post('/{id}/images/{image}/archive', [ProductDetailController::class, 'archiveImage'], 'products.images.archive', ['can:products.manage_documents']);
            $r->post('/{id}/documents', [ProductDetailController::class, 'uploadDocument'], 'products.documents.store', ['can:products.manage_documents']);
            $r->get('/{id}/documents/{doc}', [ProductDetailController::class, 'document'], 'products.documents.show', ['can:products.download_documents']);
            $r->post('/{id}/documents/{doc}/archive', [ProductDetailController::class, 'archiveDocument'], 'products.documents.archive', ['can:products.manage_documents']);
        });

        // ---------- Phase 6: suppliers ----------
        $r->group(['prefix' => 'suppliers'], static function (Router $r): void {
            $v = ['can:suppliers.view'];
            $r->get('/dashboard', [SupplierController::class, 'dashboard'], 'suppliers.dashboard', ['can:suppliers.view_dashboard']);
            $r->get('/', [SupplierController::class, 'index'], 'suppliers.index', $v);
            $r->get('/export', [SupplierController::class, 'export'], 'suppliers.export', ['can:suppliers.export']);
            $r->get('/create', [SupplierController::class, 'create'], 'suppliers.create', ['can:suppliers.create']);
            $r->post('/', [SupplierController::class, 'store'], 'suppliers.store', ['can:suppliers.create']);
            $r->post('/check-gstin', [SupplierController::class, 'checkGstin'], 'suppliers.check-gstin', $v);
            $r->get('/types', [SupplierDetailController::class, 'types'], 'suppliers.types', $v);
            $r->post('/types', [SupplierDetailController::class, 'saveType'], 'suppliers.types.store', ['can:suppliers.manage_supplier_types']);
            $r->post('/types/{id}', [SupplierDetailController::class, 'saveType'], 'suppliers.types.update', ['can:suppliers.manage_supplier_types']);
            $r->get('/addresses', [SupplierDetailController::class, 'addressList'], 'suppliers.addresses', $v);
            $r->get('/bank-details', [SupplierDetailController::class, 'bankList'], 'suppliers.bank-details', ['can:suppliers.view_bank_details']);
            $r->get('/documents', [SupplierDetailController::class, 'documentList'], 'suppliers.documents', $v);
            $r->get('/product-mappings', [SupplierDetailController::class, 'mappingList'], 'suppliers.mappings', $v);
            $r->get('/history', [SupplierDetailController::class, 'historyList'], 'suppliers.history', ['can:suppliers.view_history']);
            $r->get('/reports', [SupplierReportController::class, 'index'], 'suppliers.reports', $v);
            $r->get('/reports/{report}', [SupplierReportController::class, 'show'], 'suppliers.reports.show', $v);
            $r->get('/reports/{report}/export', [SupplierReportController::class, 'export'], 'suppliers.reports.export', ['can:suppliers.export']);

            $r->get('/{id}', [SupplierController::class, 'show'], 'suppliers.show', $v);
            $r->get('/{id}/edit', [SupplierController::class, 'edit'], 'suppliers.edit', ['can:suppliers.edit']);
            $r->post('/{id}', [SupplierController::class, 'update'], 'suppliers.update', ['can:suppliers.edit']);
            $r->post('/{id}/submit', [SupplierController::class, 'submit'], 'suppliers.submit', ['can:suppliers.create']);
            $r->post('/{id}/status', [SupplierController::class, 'status'], 'suppliers.status', $v);
            $r->post('/{id}/archive', [SupplierController::class, 'archive'], 'suppliers.archive', ['can:suppliers.archive']);
            $r->post('/{id}/restore', [SupplierController::class, 'restore'], 'suppliers.restore', ['can:suppliers.restore']);
            $r->post('/{id}/addresses', [SupplierDetailController::class, 'saveAddress'], 'suppliers.addresses.store', ['can:suppliers.manage_addresses']);
            $r->post('/{id}/addresses/{address}', [SupplierDetailController::class, 'saveAddress'], 'suppliers.addresses.update', ['can:suppliers.manage_addresses']);
            $r->post('/{id}/addresses/{address}/archive', [SupplierDetailController::class, 'archiveAddress'], 'suppliers.addresses.archive', ['can:suppliers.manage_addresses']);
            $r->post('/{id}/bank', [SupplierDetailController::class, 'addBank'], 'suppliers.bank.store', ['can:suppliers.manage_bank_details']);
            $r->post('/{id}/bank/{detail}/primary', [SupplierDetailController::class, 'primaryBank'], 'suppliers.bank.primary', ['can:suppliers.manage_bank_details']);
            $r->post('/{id}/bank/{detail}/deactivate', [SupplierDetailController::class, 'deactivateBank'], 'suppliers.bank.deactivate', ['can:suppliers.manage_bank_details']);
            $r->post('/{id}/bank/{detail}/reveal', [SupplierDetailController::class, 'revealBank'], 'suppliers.bank.reveal', ['can:suppliers.view_full_account_number']);
            $r->post('/{id}/documents', [SupplierDetailController::class, 'uploadDocument'], 'suppliers.documents.store', ['can:suppliers.manage_documents']);
            $r->get('/{id}/documents/{doc}', [SupplierDetailController::class, 'document'], 'suppliers.documents.show', ['can:suppliers.download_documents']);
            $r->post('/{id}/documents/{doc}/archive', [SupplierDetailController::class, 'archiveDocument'], 'suppliers.documents.archive', ['can:suppliers.manage_documents']);
            $r->post('/{id}/gst', [SupplierDetailController::class, 'addGst'], 'suppliers.gst.store', ['can:suppliers.manage_business_mappings']);
            $r->post('/{id}/gst/{mapping}/end', [SupplierDetailController::class, 'endGst'], 'suppliers.gst.end', ['can:suppliers.manage_business_mappings']);
            $r->post('/{id}/activities', [SupplierDetailController::class, 'addActivity'], 'suppliers.activities.store', ['can:suppliers.manage_business_mappings']);
            $r->post('/{id}/activities/{mapping}/end', [SupplierDetailController::class, 'endActivity'], 'suppliers.activities.end', ['can:suppliers.manage_business_mappings']);
            $r->post('/{id}/products', [SupplierDetailController::class, 'addProduct'], 'suppliers.products.store', ['can:suppliers.manage_product_mappings']);
            $r->post('/{id}/payments', [SupplierDetailController::class, 'linkPayment'], 'suppliers.payments.store', ['can:suppliers.link_bank_transactions']);
            $r->post('/{id}/payments/{link}/remove', [SupplierDetailController::class, 'unlinkPayment'], 'suppliers.payments.remove', ['can:suppliers.link_bank_transactions']);
        });

        // ---------- Phase 7: inventory ----------
        // Route guards check the permission; every record is loaded through the warehouse record scope (404 outside it).
        $r->group(['prefix' => 'inventory'], static function (Router $r): void {
            $v = ['can:inventory.view'];
            $r->get('/', [InventoryController::class, 'dashboard'], 'inventory.dashboard', ['can:inventory.view_dashboard']);
            $r->get('/stock', [InventoryController::class, 'stock'], 'inventory.stock', $v);
            $r->get('/ledger', [InventoryController::class, 'ledger'], 'inventory.ledger', ['can:inventory.view_stock_ledger']);
            $r->get('/valuation', [InventoryController::class, 'valuation'], 'inventory.valuation', ['can:inventory.view_stock_cost']);
            $r->post('/valuation/reconcile', [InventoryController::class, 'reconcile'], 'inventory.valuation.reconcile', ['can:inventory.manage_stock_valuation']);
            $r->get('/low-stock', [InventoryController::class, 'lowStock'], 'inventory.low-stock', $v);
            $r->get('/history', [InventoryController::class, 'history'], 'inventory.history', ['can:inventory.view_history']);
            $r->get('/reports', [InventoryController::class, 'reports'], 'inventory.reports', $v);
            $r->get('/reports/{report}', [InventoryController::class, 'report'], 'inventory.reports.show', $v);
            $r->get('/reports/{report}/export', [InventoryController::class, 'exportReport'], 'inventory.reports.export', ['can:inventory.export']);
            $r->get('/documents/{doc}', [InventoryController::class, 'document'], 'inventory.documents.show', ['can:inventory.download_documents']);
            $r->post('/documents/{doc}/archive', [InventoryController::class, 'archiveDocument'], 'inventory.documents.archive', ['can:inventory.manage_documents']);

            $r->get('/settings', [InventorySettingsController::class, 'index'], 'inventory.settings', $v);
            $r->post('/settings/rules', [InventorySettingsController::class, 'saveRules'], 'inventory.settings.rules', ['can:inventory.manage_inventory_settings']);
            $r->post('/settings/method', [InventorySettingsController::class, 'saveMethod'], 'inventory.settings.method', ['can:inventory.manage_stock_valuation']);
            $r->post('/settings/units', [InventorySettingsController::class, 'saveUnit'], 'inventory.settings.units', ['can:inventory.manage_units']);
            $r->post('/settings/conversions', [InventorySettingsController::class, 'addConversion'], 'inventory.settings.conversions', ['can:inventory.manage_units']);
            $r->post('/settings/conversions/{id}/end', [InventorySettingsController::class, 'endConversion'], 'inventory.settings.conversions.end', ['can:inventory.manage_units']);
            $r->post('/settings/types', [InventorySettingsController::class, 'saveType'], 'inventory.settings.types', ['can:inventory.manage_warehouses']);

            $r->get('/warehouses', [WarehouseController::class, 'index'], 'inventory.warehouses.index', $v);
            $r->get('/warehouses/create', [WarehouseController::class, 'create'], 'inventory.warehouses.create', ['can:inventory.create_warehouse']);
            $r->post('/warehouses', [WarehouseController::class, 'store'], 'inventory.warehouses.store', ['can:inventory.create_warehouse']);
            $r->get('/warehouses/{id}', [WarehouseController::class, 'show'], 'inventory.warehouses.show', $v);
            $r->get('/warehouses/{id}/edit', [WarehouseController::class, 'edit'], 'inventory.warehouses.edit', ['can:inventory.edit_warehouse']);
            $r->post('/warehouses/{id}', [WarehouseController::class, 'update'], 'inventory.warehouses.update', ['can:inventory.edit_warehouse']);
            $r->post('/warehouses/{id}/status', [WarehouseController::class, 'status'], 'inventory.warehouses.status', ['can:inventory.manage_warehouses']);
            $r->post('/warehouses/{id}/archive', [WarehouseController::class, 'archive'], 'inventory.warehouses.archive', ['can:inventory.archive_warehouse']);
            $r->post('/warehouses/{id}/restore', [WarehouseController::class, 'restore'], 'inventory.warehouses.restore', ['can:inventory.archive_warehouse']);
            $r->post('/warehouses/{id}/delete', [WarehouseController::class, 'destroy'], 'inventory.warehouses.delete', ['can:inventory.archive_warehouse']);
            $r->post('/warehouses/{id}/gst', [WarehouseController::class, 'addGst'], 'inventory.warehouses.gst.store', ['can:inventory.manage_warehouses']);
            $r->post('/warehouses/{id}/gst/{mapping}/end', [WarehouseController::class, 'endGst'], 'inventory.warehouses.gst.end', ['can:inventory.manage_warehouses']);
            $r->post('/warehouses/{id}/activities', [WarehouseController::class, 'addActivity'], 'inventory.warehouses.activities.store', ['can:inventory.manage_warehouses']);
            $r->post('/warehouses/{id}/activities/{mapping}/end', [WarehouseController::class, 'endActivity'], 'inventory.warehouses.activities.end', ['can:inventory.manage_warehouses']);
            $r->post('/warehouses/{id}/users', [WarehouseController::class, 'assign'], 'inventory.warehouses.users', ['can:inventory.manage_warehouses']);
            $r->post('/warehouses/{id}/documents', [WarehouseController::class, 'uploadDoc'], 'inventory.warehouses.documents.store', ['can:inventory.manage_documents']);
            $r->post('/warehouses/{id}/locations', [WarehouseController::class, 'saveLocation'], 'inventory.locations.store', ['can:inventory.manage_locations']);
            $r->post('/warehouses/{id}/locations/{location}/status', [WarehouseController::class, 'locationStatus'], 'inventory.locations.status', ['can:inventory.manage_locations']);
            $r->get('/locations', [WarehouseController::class, 'locations'], 'inventory.locations', $v);

            $r->get('/products', [ProductInventoryController::class, 'index'], 'inventory.products.index', $v);
            $r->get('/products/{id}', [ProductInventoryController::class, 'show'], 'inventory.products.show', $v);
            $r->post('/products/{id}/settings', [ProductInventoryController::class, 'saveSettings'], 'inventory.products.settings', ['can:inventory.manage_inventory_settings']);
            $r->post('/products/{id}/rules', [ProductInventoryController::class, 'saveRule'], 'inventory.products.rules', ['can:inventory.manage_reorder_settings']);
            $r->post('/products/{id}/rules/{rule}/end', [ProductInventoryController::class, 'endRule'], 'inventory.products.rules.end', ['can:inventory.manage_reorder_settings']);
            $r->post('/products/{id}/conversions', [ProductInventoryController::class, 'addConversion'], 'inventory.products.conversions', ['can:inventory.manage_units']);
            $r->post('/products/{id}/revalue', [ProductInventoryController::class, 'revalue'], 'inventory.products.revalue', ['can:inventory.manage_stock_valuation']);

            foreach (['opening' => 'manage_opening_stock', 'inward' => 'manage_stock_inward', 'outward' => 'manage_stock_outward'] as $dir => $perm) {
                $r->get("/{$dir}", [InventoryEntryController::class, 'index'], "inventory.{$dir}.index", $v);
                $r->get("/{$dir}/create", [InventoryEntryController::class, 'create'], "inventory.{$dir}.create", ["can:inventory.{$perm}"]);
                $r->post("/{$dir}", [InventoryEntryController::class, 'store'], "inventory.{$dir}.store", ["can:inventory.{$perm}"]);
                $r->get("/{$dir}/{id}", [InventoryEntryController::class, 'show'], "inventory.{$dir}.show", $v);
                $r->get("/{$dir}/{id}/edit", [InventoryEntryController::class, 'edit'], "inventory.{$dir}.edit", ["can:inventory.{$perm}"]);
                $r->post("/{$dir}/{id}", [InventoryEntryController::class, 'update'], "inventory.{$dir}.update", ["can:inventory.{$perm}"]);
                $r->post("/{$dir}/{id}/submit", [InventoryEntryController::class, 'submit'], "inventory.{$dir}.submit", ["can:inventory.{$perm}"]);
                $r->post("/{$dir}/{id}/post", [InventoryEntryController::class, 'post'], "inventory.{$dir}.post", ["can:inventory.{$perm}"]);
                $r->post("/{$dir}/{id}/cancel", [InventoryEntryController::class, 'cancel'], "inventory.{$dir}.cancel", ["can:inventory.{$perm}"]);
                $r->post("/{$dir}/{id}/reverse", [InventoryEntryController::class, 'reverse'], "inventory.{$dir}.reverse", ['can:inventory.reverse_stock_movements']);
                $r->post("/{$dir}/{id}/documents", [InventoryEntryController::class, 'uploadDoc'], "inventory.{$dir}.documents.store", ['can:inventory.manage_documents']);
            }

            $r->get('/transfers', [InventoryTransferController::class, 'index'], 'inventory.transfers.index', $v);
            $r->get('/transfers/create', [InventoryTransferController::class, 'create'], 'inventory.transfers.create', ['can:inventory.manage_stock_transfers']);
            $r->post('/transfers', [InventoryTransferController::class, 'store'], 'inventory.transfers.store', ['can:inventory.manage_stock_transfers']);
            $r->get('/transfers/{id}', [InventoryTransferController::class, 'show'], 'inventory.transfers.show', $v);
            $r->get('/transfers/{id}/edit', [InventoryTransferController::class, 'edit'], 'inventory.transfers.edit', ['can:inventory.manage_stock_transfers']);
            $r->post('/transfers/{id}', [InventoryTransferController::class, 'update'], 'inventory.transfers.update', ['can:inventory.manage_stock_transfers']);
            $r->post('/transfers/{id}/submit', [InventoryTransferController::class, 'submit'], 'inventory.transfers.submit', ['can:inventory.manage_stock_transfers']);
            $r->post('/transfers/{id}/dispatch', [InventoryTransferController::class, 'dispatch'], 'inventory.transfers.dispatch', ['can:inventory.receive_stock_transfers']);
            $r->post('/transfers/{id}/receive', [InventoryTransferController::class, 'receive'], 'inventory.transfers.receive', ['can:inventory.receive_stock_transfers']);
            $r->post('/transfers/{id}/loss', [InventoryTransferController::class, 'loss'], 'inventory.transfers.loss', ['can:inventory.receive_stock_transfers']);
            $r->post('/transfers/{id}/cancel', [InventoryTransferController::class, 'cancel'], 'inventory.transfers.cancel', ['can:inventory.manage_stock_transfers']);
            $r->post('/transfers/{id}/reverse', [InventoryTransferController::class, 'reverse'], 'inventory.transfers.reverse', ['can:inventory.reverse_stock_movements']);
            $r->post('/transfers/{id}/documents', [InventoryTransferController::class, 'uploadDoc'], 'inventory.transfers.documents.store', ['can:inventory.manage_documents']);

            $r->get('/adjustments', [InventoryAdjustmentController::class, 'index'], 'inventory.adjustments.index', $v);
            $r->get('/adjustments/create', [InventoryAdjustmentController::class, 'create'], 'inventory.adjustments.create', ['can:inventory.manage_stock_adjustments']);
            $r->post('/adjustments', [InventoryAdjustmentController::class, 'store'], 'inventory.adjustments.store', ['can:inventory.manage_stock_adjustments']);
            $r->get('/adjustments/{id}', [InventoryAdjustmentController::class, 'show'], 'inventory.adjustments.show', $v);
            $r->get('/adjustments/{id}/edit', [InventoryAdjustmentController::class, 'edit'], 'inventory.adjustments.edit', ['can:inventory.manage_stock_adjustments']);
            $r->post('/adjustments/{id}', [InventoryAdjustmentController::class, 'update'], 'inventory.adjustments.update', ['can:inventory.manage_stock_adjustments']);
            $r->post('/adjustments/{id}/submit', [InventoryAdjustmentController::class, 'submit'], 'inventory.adjustments.submit', ['can:inventory.manage_stock_adjustments']);
            $r->post('/adjustments/{id}/post', [InventoryAdjustmentController::class, 'post'], 'inventory.adjustments.post', ['can:inventory.manage_stock_adjustments']);
            $r->post('/adjustments/{id}/cancel', [InventoryAdjustmentController::class, 'cancel'], 'inventory.adjustments.cancel', ['can:inventory.manage_stock_adjustments']);
            $r->post('/adjustments/{id}/reverse', [InventoryAdjustmentController::class, 'reverse'], 'inventory.adjustments.reverse', ['can:inventory.reverse_stock_movements']);
            $r->post('/adjustments/{id}/documents', [InventoryAdjustmentController::class, 'uploadDoc'], 'inventory.adjustments.documents.store', ['can:inventory.manage_documents']);

            $r->get('/batches', [BatchSerialController::class, 'batches'], 'inventory.batches', $v);
            $r->get('/batches/{id}', [BatchSerialController::class, 'batch'], 'inventory.batches.show', $v);
            $r->post('/batches/{id}', [BatchSerialController::class, 'updateBatch'], 'inventory.batches.update', ['can:inventory.manage_batches']);
            $r->post('/batches/{id}/correct', [BatchSerialController::class, 'correctBatch'], 'inventory.batches.correct', ['can:inventory.manage_batches']);
            $r->get('/serials', [BatchSerialController::class, 'serials'], 'inventory.serials', $v);
            $r->get('/serials/{id}', [BatchSerialController::class, 'serial'], 'inventory.serials.show', $v);
            $r->post('/serials/{id}/correct', [BatchSerialController::class, 'correctSerial'], 'inventory.serials.correct', ['can:inventory.manage_serial_numbers']);

            $r->get('/reservations', [InventoryReservationController::class, 'index'], 'inventory.reservations', $v);
            $r->get('/reservations/create', [InventoryReservationController::class, 'create'], 'inventory.reservations.create', ['can:inventory.manage_reservations']);
            $r->post('/reservations', [InventoryReservationController::class, 'store'], 'inventory.reservations.store', ['can:inventory.manage_reservations']);
            $r->get('/reservations/{id}', [InventoryReservationController::class, 'show'], 'inventory.reservations.show', $v);
            $r->post('/reservations/{id}/close', [InventoryReservationController::class, 'close'], 'inventory.reservations.close', ['can:inventory.release_reservations']);
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
