<?php
/** Inventory documents waiting for approval: what will happen on approval and a link to the document. @var array<string, mixed> $req */
use App\Core\App;
use App\Services\Inventory\InventoryScope;

$me = auth_user() ?? [];
$db = App::db();
$info = match ($req['subject_type']) {
    \App\Services\Inventory\EntryService::SUBJECT => ($e = $db->first('SELECT id, direction, entry_no, warehouse_id FROM inventory_entries WHERE id = ?', [$req['subject_id']]))
        ? ['Stock document ' . $e['entry_no'], route('inventory.' . $e['direction'] . '.show', ['id' => $e['id']]), [(int) $e['warehouse_id']], 'Approving posts it to the stock ledger at once; if stock is short it stays approved but unposted with the reason.'] : null,
    \App\Services\Inventory\TransferService::SUBJECT => ($t = $db->first('SELECT id, transfer_no, from_warehouse_id, to_warehouse_id FROM inventory_transfers WHERE id = ?', [$req['subject_id']]))
        ? ['Stock transfer ' . $t['transfer_no'], route('inventory.transfers.show', ['id' => $t['id']]), [(int) $t['from_warehouse_id'], (int) $t['to_warehouse_id']], 'Approving dispatches the stock (in transit until received, or moved at once).'] : null,
    \App\Services\Inventory\AdjustmentService::SUBJECT => ($a = $db->first('SELECT id, adjustment_no, warehouse_id FROM inventory_adjustments WHERE id = ?', [$req['subject_id']]))
        ? ['Stock adjustment ' . $a['adjustment_no'], route('inventory.adjustments.show', ['id' => $a['id']]), [(int) $a['warehouse_id']], 'Approving posts the differences to the stock ledger after re-checking the counted stock.'] : null,
    \App\Services\Inventory\CorrectionService::SUBJECT => ['Inventory correction', null, [], 'Approving applies the batch, serial or valuation correction; the change is recorded in the inventory history.'],
    default => null,
};
if ($info === null) {
    return;
}
[$label, $url, $whs, $note] = $info;
$visible = $url !== null && $me !== [] && can('inventory.view') && array_filter($whs, static fn (int $w): bool => InventoryScope::allows($me, $w)) !== [];
?>
<div class="alert alert-secondary small d-flex justify-content-between align-items-center gap-2"><span><i data-lucide="boxes" class="icon-sm"></i> <?= e($label) ?>. <?= e($note) ?></span><?php if ($visible): ?><a class="btn btn-sm btn-light" href="<?= e($url) ?>">Open document</a><?php endif; ?></div>
