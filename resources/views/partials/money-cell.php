<?php
/** A money value after field rules: int paise → formatted; masked string → as is; missing key → hidden. */
if (!array_key_exists($key, $row)) {
    echo '<span class="text-secondary" title="Hidden by your field permissions">Hidden</span>';
} elseif (is_string($row[$key]) && !is_numeric($row[$key])) {
    echo e($row[$key]);
} else {
    $p = is_int($row[$key]) && !empty($paise) ? $row[$key] : \App\Support\Money::fromDb($row[$key]);
    echo '<span class="' . ($p < 0 ? 'text-danger' : '') . '">' . e(\App\Support\Money::format($p)) . '</span>';
}
