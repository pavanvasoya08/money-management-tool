<?php /** @var \App\Core\Paginator $paginator */ ?>
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 px-3 py-2 border-top small text-secondary">
    <span>Showing <?= (int) $paginator->from() ?>–<?= (int) $paginator->to() ?> of <?= (int) $paginator->total ?></span>
    <?php if ($paginator->lastPage > 1): ?>
    <nav aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $paginator->page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= e(query_with(['page' => $paginator->page - 1])) ?>" aria-label="Previous">&lsaquo;</a>
            </li>
            <?php
            $start = max(1, $paginator->page - 2);
            $end = min($paginator->lastPage, $paginator->page + 2);
            for ($p = $start; $p <= $end; $p++): ?>
                <li class="page-item <?= $p === $paginator->page ? 'active' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $p])) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $paginator->page >= $paginator->lastPage ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= e(query_with(['page' => $paginator->page + 1])) ?>" aria-label="Next">&rsaquo;</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>
