<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\BusinessDate;
use App\Support\Percent;

/**
 * Profit-sharing configuration. Percentages are configured explicitly and are
 * never derived from capital. Arrangements hold effective-dated allocations:
 *
 *  - A "complete" arrangement's allocation must total exactly 100%; a
 *    "partial" one at most 100%.
 *  - Active allocations of one arrangement never overlap.
 *  - History is preserved: an allocation that has already taken effect is
 *    never edited. A new allocation ends an open-ended previous one the day
 *    before it starts (an explicit end date is never moved), and it may not start before today (no retroactive change) once
 *    an earlier allocation exists. The very first allocation may record an
 *    existing agreement with a past start date.
 *  - Only future allocations can be edited or deactivated.
 * No profit is calculated or distributed here (Phase 12/14).
 */
final class ProfitSharingService
{
    public const TYPES = ['complete' => 'Complete allocation (must total 100%)', 'partial' => 'Partial allocation (up to 100%)'];
    public const METHODS = ['net_profit' => 'Share of net profit', 'distributable_profit' => 'Share of distributable profit (after reserves)', 'gross_profit' => 'Share of gross profit'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function arrangements(): array
    {
        $today = BusinessDate::today();
        return $this->db->select(
            "SELECT a.*, (SELECT COUNT(*) FROM profit_sharing_allocations x WHERE x.arrangement_id = a.id AND x.status = 'active') AS allocation_count,
                    (SELECT x.id FROM profit_sharing_allocations x WHERE x.arrangement_id = a.id AND x.status = 'active' AND x.effective_from <= ? AND (x.effective_to IS NULL OR x.effective_to >= ?) LIMIT 1) AS current_allocation_id
             FROM profit_sharing_arrangements a ORDER BY a.is_active DESC, a.name",
            [$today, $today]
        );
    }

    /** @return array<string, mixed>|null */
    public function arrangement(int $id): ?array
    {
        return $this->db->first('SELECT a.*, u.name AS created_by_name FROM profit_sharing_arrangements a JOIN users u ON u.id = a.created_by WHERE a.id = ?', [$id]);
    }

    /** Allocations with their lines, newest first. @return list<array<string, mixed>> */
    public function allocations(int $arrangementId): array
    {
        $rows = $this->db->select(
            'SELECT x.*, u.name AS created_by_name FROM profit_sharing_allocations x JOIN users u ON u.id = x.created_by WHERE x.arrangement_id = ? ORDER BY x.effective_from DESC, x.id DESC',
            [$arrangementId]
        );
        foreach ($rows as &$r) {
            $r['lines'] = $this->lines((int) $r['id']);
            $r['total'] = array_sum(array_column($r['lines'], 'units'));
            $r['state'] = $this->state($r);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function allocation(int $id): ?array
    {
        $r = $this->db->first('SELECT x.*, a.name AS arrangement_name, a.allocation_type FROM profit_sharing_allocations x JOIN profit_sharing_arrangements a ON a.id = x.arrangement_id WHERE x.id = ?', [$id]);
        if ($r !== null) {
            $r['lines'] = $this->lines($id);
            $r['state'] = $this->state($r);
        }
        return $r;
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $allocationId): array
    {
        $rows = $this->db->select(
            'SELECT l.*, p.partner_code, p.name AS partner_name, p.status AS partner_status FROM profit_sharing_allocation_lines l JOIN partners p ON p.id = l.partner_id WHERE l.allocation_id = ? ORDER BY l.percentage DESC, p.name',
            [$allocationId]
        );
        foreach ($rows as &$r) {
            $r['units'] = Percent::fromDb($r['percentage']);
        }
        return $rows;
    }

    /** A partner's percentage history across arrangements (newest first). @return list<array<string, mixed>> */
    public function partnerHistory(int $partnerId): array
    {
        $rows = $this->db->select(
            "SELECT l.percentage, x.id AS allocation_id, x.effective_from, x.effective_to, x.status, a.id AS arrangement_id, a.name AS arrangement_name, a.calculation_method
             FROM profit_sharing_allocation_lines l
             JOIN profit_sharing_allocations x ON x.id = l.allocation_id
             JOIN profit_sharing_arrangements a ON a.id = x.arrangement_id
             WHERE l.partner_id = ? ORDER BY x.effective_from DESC, x.id DESC",
            [$partnerId]
        );
        foreach ($rows as &$r) {
            $r['units'] = Percent::fromDb($r['percentage']);
            $r['state'] = $this->state($r);
        }
        return $rows;
    }

    /** Percentage in effect for a partner on a date, per active arrangement. @return list<array{arrangement: string, units: int}> */
    public function activeFor(int $partnerId, ?string $date = null): array
    {
        $date ??= BusinessDate::today();
        $out = [];
        foreach ($this->db->select(
            "SELECT a.name, l.percentage FROM profit_sharing_allocation_lines l
             JOIN profit_sharing_allocations x ON x.id = l.allocation_id AND x.status = 'active'
             JOIN profit_sharing_arrangements a ON a.id = x.arrangement_id AND a.is_active = 1
             WHERE l.partner_id = ? AND x.effective_from <= ? AND (x.effective_to IS NULL OR x.effective_to >= ?) ORDER BY a.name",
            [$partnerId, $date, $date]
        ) as $r) {
            $out[] = ['arrangement' => (string) $r['name'], 'units' => Percent::fromDb($r['percentage'])];
        }
        return $out;
    }

    // ---------------------------------------------------------------- arrangements

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function createArrangement(array $data, array $actor): int
    {
        $code = $this->uniqueCode((string) $data['name']);
        $id = $this->db->insert('profit_sharing_arrangements', [
            'code' => $code, 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'allocation_type' => $data['allocation_type'], 'calculation_method' => $data['calculation_method'], 'is_active' => 1,
            'created_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        App::activity()->log('profit_sharing.arrangement_created', "Created profit-sharing arrangement {$data['name']}", 'profit_sharing_arrangement', $id, module: 'profit_sharing',
            newValues: array_intersect_key($data, array_flip(['name', 'description', 'allocation_type', 'calculation_method'])));
        return $id;
    }

    /** @param array<string, mixed> $arr @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function updateArrangement(array $arr, array $data, array $actor): void
    {
        $hasAllocations = (bool) $this->db->scalar('SELECT 1 FROM profit_sharing_allocations WHERE arrangement_id = ?', [$arr['id']]);
        if ($hasAllocations && ($data['allocation_type'] !== $arr['allocation_type'] || $data['calculation_method'] !== $arr['calculation_method'])) {
            throw new DomainRuleException('The allocation type and calculation method are fixed once allocations exist. Create a new arrangement for different rules.');
        }
        $set = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'allocation_type' => $data['allocation_type'], 'calculation_method' => $data['calculation_method']];
        $this->db->update('profit_sharing_arrangements', $set + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $arr['id']]);
        App::activity()->log('profit_sharing.arrangement_updated', "Updated profit-sharing arrangement {$data['name']}", 'profit_sharing_arrangement', (int) $arr['id'], module: 'profit_sharing',
            oldValues: array_intersect_key($arr, $set), newValues: $set);
    }

    /** @param array<string, mixed> $arr @param array<string, mixed> $actor */
    public function setArrangementActive(array $arr, bool $active, array $actor): void
    {
        $this->db->update('profit_sharing_arrangements', ['is_active' => $active ? 1 : 0, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $arr['id']]);
        App::activity()->log('profit_sharing.arrangement_' . ($active ? 'activated' : 'deactivated'), ($active ? 'Activated' : 'Deactivated') . " arrangement {$arr['name']}", 'profit_sharing_arrangement', (int) $arr['id'],
            module: 'profit_sharing', oldValues: ['is_active' => (bool) $arr['is_active']], newValues: ['is_active' => $active]);
    }

    // ---------------------------------------------------------------- allocations

    /**
     * @param array<int, int> $lines partner id => percentage units
     * @param array<string, mixed> $arr @param array<string, mixed> $actor
     */
    public function createAllocation(array $arr, string $from, ?string $to, array $lines, ?string $notes, array $actor): int
    {
        if (!$arr['is_active']) {
            throw new DomainRuleException('Activate the arrangement before adding allocations.');
        }
        $this->validate($arr, $from, $to, $lines);
        $latest = $this->db->first("SELECT * FROM profit_sharing_allocations WHERE arrangement_id = ? AND status = 'active' ORDER BY effective_from DESC LIMIT 1", [$arr['id']]);
        if ($latest !== null) {
            if ($from <= (string) $latest['effective_from']) {
                throw ValidationException::withMessage('effective_from', 'A new allocation must start after the latest one (' . BusinessDate::format((string) $latest['effective_from']) . '). Earlier periods are history and cannot be changed.');
            }
            if ($from < BusinessDate::today()) {
                throw ValidationException::withMessage('effective_from', 'New percentages cannot apply retroactively. Use today or a future date.');
            }
        }
        $id = $this->db->transaction(function (Database $db) use ($arr, $from, $to, $lines, $notes, $actor, $latest): int {
            if ($latest !== null && $latest['effective_to'] === null) {
                // An open-ended previous allocation ends the day before the new one starts.
                // One with an explicit end date is left alone: overlapping it is an error.
                $db->update('profit_sharing_allocations', ['effective_to' => BusinessDate::addDays($from, -1), 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $latest['id']]);
            }
            $this->assertNoOverlap((int) $arr['id'], $from, $to, null);
            $id = $db->insert('profit_sharing_allocations', [
                'arrangement_id' => (int) $arr['id'], 'effective_from' => $from, 'effective_to' => $to, 'status' => 'active', 'notes' => $notes,
                'created_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->insertLines($db, $id, $lines);
            return $id;
        });
        App::activity()->log('profit_sharing.allocation_created', "Added allocation from {$from} to arrangement {$arr['name']}", 'profit_sharing_allocation', $id, module: 'profit_sharing',
            oldValues: $latest !== null ? ['previous_allocation' => (int) $latest['id'], 'previous_effective_to' => $latest['effective_to']] : null,
            newValues: ['effective_from' => $from, 'effective_to' => $to, 'percentages' => $this->linesForAudit($lines)]);
        return $id;
    }

    /** Edit a future allocation. @param array<string, mixed> $alloc @param array<int, int> $lines @param array<string, mixed> $actor */
    public function updateAllocation(array $alloc, string $from, ?string $to, array $lines, ?string $notes, array $actor): void
    {
        if ($alloc['state'] !== 'future') {
            throw new DomainRuleException('Allocations that have taken effect are history and cannot be edited. Add a new allocation instead.');
        }
        $arr = $this->arrangement((int) $alloc['arrangement_id']);
        $this->validate($arr, $from, $to, $lines);
        if ($from <= BusinessDate::today()) {
            throw ValidationException::withMessage('effective_from', 'A future allocation must keep a start date after today.');
        }
        $old = ['effective_from' => $alloc['effective_from'], 'effective_to' => $alloc['effective_to'], 'percentages' => $this->linesForAudit(array_column($alloc['lines'], 'units', 'partner_id'))];
        $this->db->transaction(function (Database $db) use ($alloc, $from, $to, $lines, $notes, $actor): void {
            $prev = $this->previous($alloc);
            if ($prev !== null) {
                if ($from <= (string) $prev['effective_from']) {
                    throw ValidationException::withMessage('effective_from', 'The allocation must start after the previous one.');
                }
                // Move the previous end date only if it was set automatically from this allocation's start.
                if ($prev['effective_to'] === null || (string) $prev['effective_to'] === BusinessDate::addDays((string) $alloc['effective_from'], -1)) {
                    $db->update('profit_sharing_allocations', ['effective_to' => BusinessDate::addDays($from, -1), 'updated_at' => now()], ['id' => (int) $prev['id']]);
                }
            }
            $this->assertNoOverlap((int) $alloc['arrangement_id'], $from, $to, (int) $alloc['id']);
            $db->update('profit_sharing_allocations', ['effective_from' => $from, 'effective_to' => $to, 'notes' => $notes, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $alloc['id']]);
            $db->execute('DELETE FROM profit_sharing_allocation_lines WHERE allocation_id = ?', [$alloc['id']]);
            $this->insertLines($db, (int) $alloc['id'], $lines);
        });
        App::activity()->log('profit_sharing.allocation_updated', "Edited future allocation #{$alloc['id']} of {$arr['name']}", 'profit_sharing_allocation', (int) $alloc['id'], module: 'profit_sharing',
            oldValues: $old, newValues: ['effective_from' => $from, 'effective_to' => $to, 'percentages' => $this->linesForAudit($lines)]);
    }

    /** Deactivate a future allocation; the previous allocation is extended back over its period. */
    public function deactivateAllocation(array $alloc, array $actor): void
    {
        if ($alloc['state'] !== 'future') {
            throw new DomainRuleException('Only future allocations can be deactivated. Allocations already in effect are preserved as history.');
        }
        $this->db->transaction(function (Database $db) use ($alloc, $actor): void {
            $prev = $this->previous($alloc);
            if ($prev !== null && $prev['effective_to'] === BusinessDate::addDays((string) $alloc['effective_from'], -1)) {
                $db->update('profit_sharing_allocations', ['effective_to' => $alloc['effective_to'], 'updated_at' => now()], ['id' => (int) $prev['id']]);
            }
            $db->update('profit_sharing_allocations', ['status' => 'inactive', 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $alloc['id']]);
        });
        App::activity()->log('profit_sharing.allocation_deactivated', "Deactivated future allocation #{$alloc['id']}", 'profit_sharing_allocation', (int) $alloc['id'], module: 'profit_sharing',
            oldValues: ['status' => 'active'], newValues: ['status' => 'inactive']);
    }

    /** @param array<string, mixed> $r */
    public function state(array $r): string
    {
        $today = BusinessDate::today();
        return match (true) {
            $r['status'] !== 'active' => 'inactive',
            (string) $r['effective_from'] > $today => 'future',
            $r['effective_to'] !== null && (string) $r['effective_to'] < $today => 'ended',
            default => 'current',
        };
    }

    // ---------------------------------------------------------------- rules

    /** @param array<string, mixed> $arr @param array<int, int> $lines */
    private function validate(array $arr, string $from, ?string $to, array $lines): void
    {
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessage('effective_to', 'The end date cannot be before the start date.');
        }
        $lines = array_filter($lines, static fn (int $u): bool => $u > 0);
        if ($lines === []) {
            throw ValidationException::withMessage('lines', 'Enter a percentage for at least one partner.');
        }
        $total = array_sum($lines);
        if ($arr['allocation_type'] === 'complete' && $total !== Percent::HUNDRED) {
            throw ValidationException::withMessage('lines', 'A complete allocation must total exactly 100%. Current total: ' . Percent::format($total) . '.');
        }
        if ($total > Percent::HUNDRED) {
            throw ValidationException::withMessage('lines', 'Percentages cannot total more than 100%. Current total: ' . Percent::format($total) . '.');
        }
        $ids = array_keys($lines);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $found = $this->db->select("SELECT id, name, status FROM partners WHERE id IN ({$in})", $ids);
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessage('lines', 'One of the selected partners does not exist.');
        }
        foreach ($found as $p) {
            if ($p['status'] === 'archived') {
                throw ValidationException::withMessage('lines', "{$p['name']} is archived and cannot receive a profit share.");
            }
        }
    }

    private function assertNoOverlap(int $arrangementId, string $from, ?string $to, ?int $exceptId): void
    {
        $sql = "SELECT id, effective_from, effective_to FROM profit_sharing_allocations WHERE arrangement_id = ? AND status = 'active'
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)";
        $params = [$arrangementId, $to ?? '9999-12-31', $from];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $clash = $this->db->first($sql, $params);
        if ($clash !== null) {
            throw ValidationException::withMessage('effective_from', 'This period overlaps the allocation effective ' . BusinessDate::format((string) $clash['effective_from'])
                . ($clash['effective_to'] ? ' – ' . BusinessDate::format((string) $clash['effective_to']) : ' onwards') . '.');
        }
    }

    /** @param array<string, mixed> $alloc @return array<string, mixed>|null */
    private function previous(array $alloc): ?array
    {
        return $this->db->first("SELECT * FROM profit_sharing_allocations WHERE arrangement_id = ? AND status = 'active' AND id <> ? AND effective_from < ? ORDER BY effective_from DESC LIMIT 1",
            [$alloc['arrangement_id'], $alloc['id'], $alloc['effective_from']]);
    }

    /** @param array<int, int> $lines */
    private function insertLines(Database $db, int $allocationId, array $lines): void
    {
        foreach ($lines as $partnerId => $units) {
            if ($units > 0) {
                $db->insert('profit_sharing_allocation_lines', ['allocation_id' => $allocationId, 'partner_id' => (int) $partnerId, 'percentage' => Percent::toDb($units)]);
            }
        }
    }

    /** @param array<int, int> $lines @return array<int, string> */
    private function linesForAudit(array $lines): array
    {
        return array_map(static fn (int $u): string => Percent::toDb($u), array_filter($lines, static fn (int $u): bool => $u > 0));
    }

    private function uniqueCode(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-') ?: 'arrangement';
        $base = substr($base, 0, 32);
        $code = $base;
        for ($i = 2; $this->db->scalar('SELECT 1 FROM profit_sharing_arrangements WHERE code = ?', [$code]); $i++) {
            $code = $base . '-' . $i;
        }
        return $code;
    }
}
