<?php
/**
 * Screenshot with numbered markers. Marker positions are percentages applied by
 * help.js from data attributes (the CSP forbids inline styles).
 * @var array<string, mixed> $m  media row with decoded 'annotations'
 * @var bool|null $preview
 */
$markers = (array) ($m['annotations'] ?? []);
$src = route('help.media', ['id' => $m['id']]);
?>
<figure class="doc-shot" id="shot-<?= (int) $m['id'] ?>">
    <div class="doc-shot-frame">
        <a href="<?= e($src) ?>" target="_blank" rel="noopener" title="Open full size">
            <img src="<?= e($src) ?>" alt="<?= e($m['alt_text'] ?: ($m['caption'] ?: 'Screenshot')) ?>" loading="lazy"<?= !empty($m['width']) ? ' width="' . (int) $m['width'] . '" height="' . (int) $m['height'] . '"' : '' ?>>
        </a>
        <?php foreach ($markers as $i => $a): ?>
            <span class="doc-marker" data-x="<?= e((string) $a['x']) ?>" data-y="<?= e((string) $a['y']) ?>" aria-hidden="true"><?= $i + 1 ?></span>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($m['caption'])): ?><figcaption><?= e($m['caption']) ?></figcaption><?php endif; ?>
    <?php if ($markers !== []): ?>
        <ol class="doc-legend">
            <?php foreach ($markers as $a): ?><li><?= e($a['label']) ?></li><?php endforeach; ?>
        </ol>
    <?php endif; ?>
</figure>
