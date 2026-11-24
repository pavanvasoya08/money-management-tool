<?php $pid = (int) $raw['id']; $sp = $summary['presented'] ?? []; ?>
<div>
    <div>
        <?php if ($summary !== null): ?>
        <div class="kpi-group mb-4">
            <div class="stat-card"><span class="stat-icon tone-blue"><i data-lucide="arrow-down-to-line"></i></span><span class="stat-label">Total contributed</span><span class="stat-value"><?= $view->partial('partials/money-cell', ['row' => $sp, 'key' => 'total_contributed', 'paise' => true]) ?></span><span class="small text-secondary">Posted, net of reversals</span></div>
            <div class="stat-card"><span class="stat-icon tone-red"><i data-lucide="arrow-up-from-line"></i></span><span class="stat-label">Total withdrawn</span><span class="stat-value"><?= $view->partial('partials/money-cell', ['row' => $sp, 'key' => 'total_withdrawn', 'paise' => true]) ?></span><span class="small text-secondary">Posted, net of reversals</span></div>
            <div class="stat-card"><span class="stat-icon tone-green"><i data-lucide="landmark"></i></span><span class="stat-label">Current capital balance</span><span class="stat-value"><?= $view->partial('partials/money-cell', ['row' => $sp, 'key' => 'capital_balance', 'paise' => true]) ?></span><span class="small text-secondary">Posted entries only</span></div>
        </div>
        <h2 class="card-section-title">Financial summary</h2><div class="mb-3"><dl class="detail-list detail-grid mb-0">
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
        </dl></div>
        <p class="small text-secondary">Capital contributions are not business revenue and withdrawals are not expenses — they only move this partner's capital account. Profit-sharing percentages are configured separately and do not follow capital.</p>
        <?php else: ?>
            <div class="alert alert-secondary">Financial figures are not available with your permissions.</div>
        <?php endif; ?>
    </div>
</div>
