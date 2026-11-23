<?php
/** Renders PartnerReportService output. @var array $result @var array $rows rows to show (page) @var bool $withTotals */
$cols = $result['columns'];
$cell = static function (mixed $v, array $c): string {
    if (($c['type'] ?? '') === 'money' && is_int($v)) {
        return '<span class="' . ($v < 0 ? 'text-danger' : '') . '">' . e(\App\Support\Money::format($v)) . '</span>';
    }
    return e($v === null || $v === '' ? '—' : (string) $v);
};
?>
<?php if ($rows === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'file-search', 'text' => $result['notice'] ?? 'No records match these filters.']) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack report-table">
    <thead><tr><?php foreach ($cols as $c): ?><th class="<?= in_array($c['type'] ?? '', ['money', 'number'], true) ? 'text-end' : '' ?>"><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($cols as $k => $c): ?><td data-label="<?= e($c['label']) ?>" class="<?= in_array($c['type'] ?? '', ['money', 'number'], true) ? 'text-end text-nowrap' : '' ?>"><?= $cell($r[$k] ?? null, $c) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    <?php if (!empty($withTotals) && $result['totals'] !== null): ?>
    <tfoot><tr class="fw-semibold"><?php foreach ($cols as $k => $c): ?><td class="<?= in_array($c['type'] ?? '', ['money', 'number'], true) ? 'text-end text-nowrap' : '' ?>"><?= array_key_exists($k, $result['totals']) ? $cell($result['totals'][$k], $c) : '' ?></td><?php endforeach; ?></tr></tfoot>
    <?php endif; ?>
</table></div>
<?php endif; ?>
