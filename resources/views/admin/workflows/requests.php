<div class="card app-card list-card">
<div class="list-card-head"><div><h1>Approval requests</h1><p>All requests submitted by modules to the approval engine.</p></div></div>
<form class="table-toolbar"><select class="form-select toolbar-select" name="status" data-autosubmit aria-label="Status"><option value="">All statuses</option><?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></form>
<?= $view->partial('approvals/_table', ['requests' => $requests, 'empty' => 'No approval requests yet. They appear when financial modules (Phase 3+) submit transactions.']) ?></div>
