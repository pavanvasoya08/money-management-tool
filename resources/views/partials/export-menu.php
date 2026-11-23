<?php /** @var string $url export URL without format; current filters are appended */ $sep = str_contains($url, '?') ? '&' : '?'; $qs = $_GET; unset($qs['page'], $qs['format']); ?>
<div class="dropdown d-inline-block">
    <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i data-lucide="download" class="icon-sm"></i>Export</button>
    <ul class="dropdown-menu dropdown-menu-end">
        <?php foreach (\App\Services\Export\TableExport::FORMATS as $fmt => $label): ?>
            <li><a class="dropdown-item" href="<?= e($url . $sep . http_build_query(['format' => $fmt] + $qs)) ?>"><?= e($label) ?> (.<?= e($fmt) ?>)</a></li>
        <?php endforeach; ?>
    </ul>
</div>
