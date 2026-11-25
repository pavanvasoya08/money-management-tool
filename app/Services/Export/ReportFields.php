<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Core\App;
use App\Services\Authorization\FieldPolicy;
use App\Support\Money;

/**
 * Applies the user's field rules to a report result before it is shown or exported:
 * hidden fields lose their column entirely, masked fields are masked in every row and in
 * the totals. Columns declare their field as ['field' => [module, field]]. Money values
 * are integer paise.
 */
final class ReportFields
{
    /** @param array<string, mixed> $user @param array<string, mixed> $out columns, rows, totals @return array<string, mixed> */
    public static function apply(array $user, array $out): array
    {
        $fields = App::fields();
        foreach ($out['columns'] as $col => $def) {
            if (!isset($def['field'])) {
                continue;
            }
            [$fm, $ff] = $def['field'];
            $access = $fields->access($user, $fm, $ff);
            if (!$access->canView()) {
                unset($out['columns'][$col]);
                foreach ($out['rows'] as &$r) {
                    unset($r[$col]);
                }
                unset($r);
                if (is_array($out['totals'] ?? null)) {
                    unset($out['totals'][$col]);
                }
                continue;
            }
            if (!$access->showsRealValue()) {
                $mask = $fields->maskType($fm, $ff);
                foreach ($out['rows'] as &$r) {
                    if (($r[$col] ?? '') !== '' && $r[$col] !== null) {
                        $r[$col] = FieldPolicy::mask(is_int($r[$col]) ? Money::toDb($r[$col]) : (string) $r[$col], $mask);
                    }
                }
                unset($r);
                if (is_array($out['totals'] ?? null) && isset($out['totals'][$col])) {
                    $out['totals'][$col] = FieldPolicy::mask(is_int($out['totals'][$col]) ? Money::toDb($out['totals'][$col]) : (string) $out['totals'][$col], $mask);
                }
            }
        }
        return $out;
    }
}
