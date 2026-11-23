<?php
$editing = $entry !== null;
$v = static fn (string $f, string $d = ''): string => (string) old($f, $entry[$f] ?? $d);
$amount = $editing ? \App\Support\Money::toDb(\App\Support\Money::fromDb($entry['amount'])) : '';
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit ' . e($entry['transaction_no']) : 'New ' . e(strtolower($names[0])) ?></h1>
    <p><?= $type === 'contribution' ? 'Each contribution is a separate entry — earlier contributions are never overwritten.' : ($type === 'withdrawal' ? 'Withdrawals cannot exceed the partner\'s eligible available capital.' : 'Adjustments need a reason and go through approval like any capital entry.') ?>
    Balances change only after approval <b>and</b> posting.</p></div></div>
<?php if ($editing && $entry['status'] === 'rejected'): ?><div class="alert alert-warning small"><b>Rejected:</b> <?= e($entry['rejection_reason'] ?? '') ?> — saving changes turns it back into a draft you can resubmit.</div><?php endif; ?>
<form method="post" action="<?= e($editing ? route($prefix . '.update', ['id' => $entry['id']]) : route($prefix . '.store')) ?>" enctype="multipart/form-data" novalidate data-loading class="form-narrow-lg" data-capital-form>
    <?= csrf_field() ?>
    <div class="card app-card mb-3"><div class="card-body row g-3">
        <div class="col-md-7"><label class="form-label" for="partner_id">Partner <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('partner_id') ?>" id="partner_id" name="partner_id" required data-availability="<?= e(json_encode(array_map(static fn (array $a): array => ['balance' => \App\Support\Money::format($a['balance']), 'available' => \App\Support\Money::format($a['available']), 'availablePaise' => $a['available']], $availability), JSON_THROW_ON_ERROR)) ?>">
                <option value="">Choose a partner…</option>
                <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) old('partner_id', $selectedPartner) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['partner_code']) ?>)</option><?php endforeach; ?>
            </select><?= $view->partial('partials/field-error', ['field' => 'partner_id']) ?>
            <?php if ($partners === []): ?><div class="form-text text-danger">No active partners you can record entries for.</div><?php endif; ?>
            <?php if ($availability !== []): ?><div class="form-text" data-availability-text>Choose a partner to see the eligible available capital.</div><?php endif; ?>
        </div>
        <div class="col-md-5"><label class="form-label" for="transaction_date">Date <span class="text-danger">*</span></label>
            <input class="form-control<?= invalid('transaction_date') ?>" type="date" id="transaction_date" name="transaction_date" value="<?= e($v('transaction_date', \App\Support\BusinessDate::today())) ?>" max="<?= e(\App\Support\BusinessDate::today()) ?>" required><?= $view->partial('partials/field-error', ['field' => 'transaction_date']) ?></div>
        <div class="col-md-5"><label class="form-label" for="amount">Amount (₹) <span class="text-danger">*</span></label>
            <input class="form-control<?= invalid('amount') ?>" type="text" inputmode="decimal" id="amount" name="amount" value="<?= e((string) old('amount', $amount)) ?>" placeholder="e.g. 150000.00" required autocomplete="off" pattern="\d{1,13}(\.\d{1,2})?">
            <div class="form-text">Digits only, up to 2 decimals. No commas.</div><?= $view->partial('partials/field-error', ['field' => 'amount']) ?>
            <div class="alert alert-warning small py-2 mt-2 d-none" data-overdraw-warning><i data-lucide="alert-triangle" class="icon-sm"></i> This amount exceeds the eligible available capital. It will be blocked unless the exception policy applies to you.</div>
        </div>
        <?php if ($type === 'adjustment'): ?>
            <div class="col-md-4"><label class="form-label" for="adjustment_category">Adjustment type <span class="text-danger">*</span></label>
                <select class="form-select<?= invalid('adjustment_category') ?>" id="adjustment_category" name="adjustment_category" required><option value="">Choose…</option><?php foreach (\App\Services\Partners\CapitalService::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('adjustment_category') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'adjustment_category']) ?></div>
            <div class="col-md-3"><label class="form-label" for="direction">Effect <span class="text-danger">*</span></label>
                <select class="form-select<?= invalid('direction') ?>" id="direction" name="direction" required><option value="credit" <?= $v('direction', 'credit') === 'credit' ? 'selected' : '' ?>>Increase capital</option><option value="debit" <?= $v('direction') === 'debit' ? 'selected' : '' ?>>Reduce capital</option></select><?= $view->partial('partials/field-error', ['field' => 'direction']) ?></div>
        <?php else: ?>
            <div class="col-md-7"><label class="form-label" for="payment_method">Payment method <span class="text-danger">*</span></label>
                <select class="form-select<?= invalid('payment_method') ?>" id="payment_method" name="payment_method" required><option value="">Choose…</option><?php foreach (\App\Services\Partners\CapitalService::METHODS as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('payment_method') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'payment_method']) ?></div>
            <div class="col-md-6"><label class="form-label" for="payment_reference">Payment reference</label>
                <input class="form-control<?= invalid('payment_reference') ?>" id="payment_reference" name="payment_reference" value="<?= e($v('payment_reference')) ?>" maxlength="100" placeholder="UTR / cheque no. / UPI ref."><?= $view->partial('partials/field-error', ['field' => 'payment_reference']) ?></div>
            <div class="col-md-6"><label class="form-label" for="payment_source_ref"><?= $type === 'contribution' ? 'Received in (bank / cash)' : 'Paid from (bank / cash)' ?></label>
                <input class="form-control<?= invalid('payment_source_ref') ?>" id="payment_source_ref" name="payment_source_ref" value="<?= e($v('payment_source_ref')) ?>" maxlength="150" placeholder="e.g. Current a/c ending 4417 or Cash in hand">
                <div class="form-text">Temporary reference until bank accounts are added in Phase 5. Never enter passwords, PINs or OTPs.</div><?= $view->partial('partials/field-error', ['field' => 'payment_source_ref']) ?></div>
        <?php endif; ?>
        <?php if ($type !== 'contribution'): ?>
            <div class="col-12"><label class="form-label" for="reason">Reason <span class="text-danger">*</span></label>
                <input class="form-control<?= invalid('reason') ?>" id="reason" name="reason" value="<?= e($v('reason')) ?>" maxlength="500" required><?= $view->partial('partials/field-error', ['field' => 'reason']) ?></div>
        <?php endif; ?>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control<?= invalid('notes') ?>" id="notes" name="notes" rows="2" maxlength="5000"><?= e($v('notes')) ?></textarea></div>
        <?php if (!$editing && $canAttach): ?>
            <div class="col-md-7"><label class="form-label" for="document">Supporting document (optional<?= $type === 'adjustment' ? ', recommended' : '' ?>)</label>
                <input class="form-control<?= invalid('document') ?>" type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"><?= $view->partial('partials/field-error', ['field' => 'document']) ?></div>
            <div class="col-md-5"><label class="form-label" for="document_title">Document title</label><input class="form-control" id="document_title" name="document_title" maxlength="150"></div>
        <?php endif; ?>
    </div></div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="small text-secondary"><?= $workflow['is_active'] ? 'Approval workflow is active for this register.' : 'The approval workflow is inactive: submissions are approved automatically and still need posting.' ?></span>
        <div class="d-flex gap-2">
            <a class="btn btn-light" href="<?= e($editing ? route($prefix . '.show', ['id' => $entry['id']]) : route($prefix . '.index')) ?>">Cancel</a>
            <button class="btn btn-outline-primary" type="submit" name="then" value="draft"><span class="btn-label">Save draft</span></button>
            <?php if (!$editing): ?><button class="btn btn-primary" type="submit" name="then" value="submit"><span class="btn-label">Save &amp; submit for approval</span></button><?php endif; ?>
        </div>
    </div>
</form>
