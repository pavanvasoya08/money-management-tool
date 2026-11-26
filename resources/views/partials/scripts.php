<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js" crossorigin="anonymous"></script>
<?php if (!empty($withCharts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<?php endif; ?>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<script src="<?= e(asset('assets/js/inventory.js')) ?>"></script>
<?php if (!empty($withAdmin)): ?>
<script src="<?= e(asset('assets/js/admin.js')) ?>"></script>
<?php endif; ?>
<?php if (!empty($withHelp)): ?>
<script src="<?= e(asset('assets/js/help.js')) ?>"></script>
<?php endif; ?>
