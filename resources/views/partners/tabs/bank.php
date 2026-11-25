<?php
/** Bank accounts associated with this partner (Phase 5). @var list<array<string, mixed>> $bankMappings */
use App\Services\Bank\BankRelationService;

$current = array_filter($bankMappings, static fn (array $m): bool => $m['status'] === 'active');
?>
<p class="text-secondary small">Bank accounts this partner is associated with (holder, joint holder, authorised operator, beneficiary). Associations do not change the partner's capital or profit-sharing percentage.
    Partner contributions are recorded under Capital and linked to the bank deposit that received them.</p>
<?php if ($bankMappings === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'landmark', 'text' => 'No bank account is associated with this partner' . (can('bank_accounts.manage_partners') ? '. Add the association on the bank account\'s Partner associations tab.' : '.')]) ?>
<?php else: ?>
<div class="small text-secondary mb-2"><?= count($current) ?> active · <?= count($bankMappings) - count($current) ?> ended</div>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Account</th><th>Bank</th><th>Relationship</th><th>From</th><th>To</th><th>Account status</th></tr></thead>
    <tbody><?php foreach ($bankMappings as $m): ?>
        <tr class="<?= $m['status'] !== 'active' ? 'text-secondary' : '' ?>">
            <td data-label="Account"><a class="fw-semibold" href="<?= e(route('bank.show', ['id' => $m['bank_account_id']])) ?>"><?= e($m['display_name']) ?></a><span class="d-block small text-secondary"><?= e($m['account_code']) ?> · XXXXXX<?= e($m['account_number_last4']) ?></span></td>
            <td data-label="Bank"><?= e($m['bank_name']) ?></td>
            <td data-label="Relationship"><?= e(BankRelationService::RELATIONSHIPS[$m['relationship']] ?? $m['relationship']) ?><?= $m['status'] !== 'active' ? ' <span class="badge badge-soft-secondary">ended</span>' : '' ?></td>
            <td data-label="From"><?= e(format_date($m['effective_from'])) ?></td><td data-label="To"><?= e(format_date($m['effective_to'])) ?></td>
            <td data-label="Account status"><?= $view->partial('bank/_status', ['a' => ['status' => $m['account_status'], 'is_central_funding' => $m['is_central_funding']]]) ?></td>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
