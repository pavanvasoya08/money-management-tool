<?php $pid = (int) $raw['id']; $sp = $summary['presented'] ?? []; ?>
<div class="row g-3">
    <div class="col-xl-8">
        <?php if ($summary !== null): ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="stat-card"><span class="stat-icon tone-blue"><i data-lucide="arrow-down-to-line"></i></span><span class="stat-label">Total contributed</span><span class="stat-value"><?= $view->partial('partials/money-cell', ['row' => $sp, 'key' => 'total_contributed', 'paise' => true]) ?></span><span class="small text-secondary">Posted, net of reversals</span></div></div>
            <div class="col-md-4"><div class="stat-card"><span class="stat-icon tone-red"><i data-lucide="arrow-up-from-line"></i></span><span class="stat-label">Total withdrawn</span><span class="stat-value"><?= $view->partial('partials/money-cell', ['row' => $sp, 'key' => 'total_withdrawn', 'paise' => true]) ?></span><span class="small text-secondary">Posted, net of reversals</span></div></div>
            <div class="col-md-4"><div class="stat-card"><span class="stat-icon tone-green"><i data-lucide="landmark"></i></span><span class="stat-label">Current capital balance</span><span class="stat-value"><?= $view->partial('partials/money-cell', ['row' => $sp, 'key' => 'capital_balance', 'paise' => true]) ?></span><span class="small text-secondary">Posted entries only</span></div></div>
        </div>
        <div class="card app-card mb-3"><div class="card-header">Financial summary</div><div class="card-body"><dl class="detail-list detail-grid mb-0">
            <?php $amountsVisible = isset($sp['capital_balance']) && is_int($sp['capital_balance']); $amt = static fn (int $p): string => $amountsVisible ? e(\App\Support\Money::format($p)) : '<span class="text-secondary">Hidden</span>'; ?>
            <dt>Opening capital</dt><dd><?= $amt((int) $summary['opening']) ?></dd>
            <dt>Adjustments (net)</dt><dd><?= $amt((int) $summary['adjustments']) ?></dd>
            <dt>Pending contributions</dt><dd><?= (int) $summary['pending_contribution_count'] ?> awaiting approval · <?= $amt((int) $summary['pending_contributions']) ?><?php if ($summary['unposted_contributions']): ?><div class="small text-secondary"><?= $amt((int) $summary['unposted_contributions']) ?> approved, not yet posted</div><?php endif; ?></dd>
            <dt>Pending withdrawals</dt><dd><?= (int) $summary['pending_withdrawal_count'] ?> awaiting approval · <?= $amt((int) $summary['pending_withdrawals']) ?><?php if ($summary['unposted_withdrawals']): ?><div class="small text-secondary"><?= $amt((int) $summary['unposted_withdrawals']) ?> approved, not yet posted</div><?php endif; ?></dd>
            <dt>Last contribution</dt><dd><?= e(format_date($summary['last_contribution_date'])) ?></dd>
            <dt>Last withdrawal</dt><dd><?= e(format_date($summary['last_withdrawal_date'])) ?></dd>
            <?php if ($activeShares !== null): ?>
                <dt>Active profit share</dt><dd><?php if ($activeShares === []): ?><span class="text-secondary">None in effect</span><?php else: foreach ($activeShares as $s): ?><div><?= e(\App\Core\App::fields()->access(auth_user(), 'profit_sharing', 'percentage')->showsRealValue() ? \App\Support\Percent::format($s['units']) : '••') ?> <span class="small text-secondary">· <?= e($s['arrangement']) ?></span></div><?php endforeach; endif; ?></dd>
            <?php endif; ?>
        </dl></div></div>
        <p class="small text-secondary">Capital contributions are not business revenue and withdrawals are not expenses — they only move this partner's capital account. Profit-sharing percentages are configured separately and do not follow capital.</p>
        <?php else: ?>
            <div class="alert alert-secondary">Financial figures are not available with your permissions.</div>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <div class="card app-card mb-3"><div class="card-header">Profile</div><div class="card-body"><dl class="detail-list mb-0">
            <dt>Partner ID</dt><dd><?= e($partner['partner_code']) ?></dd>
            <?php if (array_key_exists('email', $partner)): ?><dt>Email</dt><dd><?= e($partner['email'] ?? '—') ?></dd><?php endif; ?>
            <?php if (array_key_exists('mobile', $partner)): ?><dt>Mobile</dt><dd><?= e($partner['mobile'] ?? '—') ?></dd><?php endif; ?>
            <dt>Location</dt><dd><?= e(implode(', ', array_filter([$partner['city'], $partner['state'], $partner['country']])) ?: '—') ?></dd>
            <dt>Linked login</dt><dd><?= $partner['user_name'] ? e($partner['user_name']) : '<span class="text-secondary">Not linked</span>' ?></dd>
            <dt>Added</dt><dd><?= e(format_datetime($partner['created_at'])) ?><div class="small text-secondary">by <?= e($partner['created_by_name'] ?? '—') ?></div></dd>
        </dl></div></div>
        <?php if (can('partners.upload_documents') && $raw['status'] !== 'archived'): ?>
        <form class="card app-card" method="post" action="<?= e(route('partners.documents.store', ['id' => $pid])) ?>" enctype="multipart/form-data" data-loading>
            <div class="card-header">Profile photo</div><div class="card-body"><?= csrf_field() ?><input type="hidden" name="category" value="photo">
                <input class="form-control form-control-sm mb-2<?= invalid('document') ?>" type="file" name="document" accept="image/jpeg,image/png,image/webp" required aria-label="Photo file"><?= $view->partial('partials/field-error', ['field' => 'document']) ?>
                <button class="btn btn-sm btn-light" type="submit"><span class="btn-label"><?= empty($partner['photo_document_id']) ? 'Upload photo' : 'Replace photo' ?></span></button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
