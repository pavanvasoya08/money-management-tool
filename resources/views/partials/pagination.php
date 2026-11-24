<?php /** @var \App\Core\Paginator $paginator */ ?>
<div class="pagination-bar">
    <?php if ($paginator->lastPage > 1): ?>
    <nav aria-label="Pagination">
        <ul class="pagination mb-0">
            <li class="page-item <?= $paginator->page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= e(query_with(['page' => $paginator->page - 1])) ?>" aria-label="Previous"><i data-lucide="chevron-left" class="icon-sm"></i></a>
            </li>
            <?php
            $start = max(1, $paginator->page - 2);
            $end = min($paginator->lastPage, $paginator->page + 2);
            for ($p = $start; $p <= $end; $p++): ?>
                <li class="page-item <?= $p === $paginator->page ? 'active' : '' ?>"><a class="page-link" href="<?= e(query_with(['page' => $p])) ?>"<?= $p === $paginator->page ? ' aria-current="page"' : '' ?>><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $paginator->page >= $paginator->lastPage ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= e(query_with(['page' => $paginator->page + 1])) ?>" aria-label="Next"><i data-lucide="chevron-right" class="icon-sm"></i></a>
            </li>
        </ul>
    </nav>
    <?php else: ?><span></span><?php endif; ?>
    <span>Showing <?= (int) $paginator->from() ?>–<?= (int) $paginator->to() ?> of <?= (int) $paginator->total ?></span>
</div>
