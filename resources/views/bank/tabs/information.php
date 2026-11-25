<?php
/** @var bool $fullNumberAllowed */
use App\Support\BankView;
use App\Support\Money;

$show = static fn (string $k): bool => array_key_exists($k, $acc);
$rows = [
    'Display name' => e($raw['display_name']), 'Account code' => e($raw['account_code']), 'Bank' => e($raw['bank_name']),
    'Branch' => $show('branch_name') ? e($acc['branch_name'] ?? '—') : null, 'Account holder' => $show('account_holder_name') ? e($acc['account_holder_name']) : null,
    'IFSC' => $show('ifsc_code') ? '<span class="text-mono">' . e($acc['ifsc_code']) . '</span>' : null, 'MICR' => $show('micr_code') ? e($acc['micr_code'] ?? '—') : null,
    'Account type' => e($raw['type_name']), 'Currency' => e($raw['currency']), 'Opened on' => e(format_date($raw['opening_date'])),
    'Opening balance' => $show('opening_balance') ? (is_numeric($acc['opening_balance']) ? e(Money::format(Money::fromDb($acc['opening_balance']))) : e($acc['opening_balance'])) . ' <span class="small text-secondary">as of ' . e(format_date($raw['opening_balance_date'])) . '</span>' : null,
    'Overdraft' => (int) $raw['allow_overdraft'] ? 'Allowed up to ' . BankView::amount(Money::fromDb($raw['overdraft_limit']), $fields['current_balance'] ?? null) : 'Not allowed — the balance cannot go below zero',
    'Purpose' => e($raw['purpose'] ?? '—'), 'Internal notes' => $show('internal_notes') ? nl2br(e($acc['internal_notes'] ?? '—')) : null,
    'Added' => e(format_datetime($raw['created_at'])) . ' by ' . e($raw['created_by_name'] ?? '—'), 'Last changed' => e(format_datetime($raw['updated_at'])) . ' by ' . e($raw['updated_by_name'] ?? '—'),
];
?>
<dl class="row info-grid mb-0">
    <?php if ($show('account_number')): ?>
        <dt class="col-sm-4">Account number</dt>
        <dd class="col-sm-8"><span class="text-mono" data-account-number><?= e($acc['account_number']) ?></span>
            <?php if ($fullNumberAllowed): ?><button class="btn btn-sm btn-light ms-2" type="button" data-reveal-number="<?= e(route('bank.reveal', ['id' => $raw['id']])) ?>"><i data-lucide="eye" class="icon-sm"></i>Show full number</button><span class="small text-secondary ms-1">(logged)</span><?php endif; ?></dd>
    <?php endif; ?>
    <?php foreach ($rows as $label => $value): if ($value === null) { continue; } ?>
        <dt class="col-sm-4"><?= e($label) ?></dt><dd class="col-sm-8"><?= $value ?></dd>
    <?php endforeach; ?>
</dl>
<?php if (!(int) $raw['is_archived'] && $raw['status'] !== 'closed' && can('bank_accounts.edit')): ?><a class="btn btn-light mt-3" href="<?= e(route('bank.edit', ['id' => $raw['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit details</a><?php endif; ?>
