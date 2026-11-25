<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\BusinessDate;

/**
 * Effective-dated many-to-many links with history, shared by products and suppliers:
 * product ↔ GST registration, product ↔ business activity, supplier ↔ GST registration,
 * supplier ↔ business activity. A link is never deleted: ending it records the end date
 * and reason. One active link per pair. Links say what a record is used for; they never
 * imply ownership or move money.
 */
final class DatedMappingService
{
    /** key => [table, owner column, target column, target table, owner kind, label] */
    public const KINDS = [
        'product_gst' => ['product_gst_mappings', 'product_id', 'gst_registration_id', 'gst_registrations', 'product', 'GST registration'],
        'product_activity' => ['product_business_mappings', 'product_id', 'business_activity_id', 'gst_business_activities', 'product', 'business activity'],
        'supplier_gst' => ['supplier_gst_mappings', 'supplier_id', 'gst_registration_id', 'gst_registrations', 'supplier', 'GST registration'],
        'supplier_activity' => ['supplier_business_mappings', 'supplier_id', 'business_activity_id', 'gst_business_activities', 'supplier', 'business activity'],
    ];

    private string $table;
    private string $ownerCol;
    private string $targetCol;
    private string $targetTable;
    private string $owner;
    private string $label;

    public function __construct(private readonly Database $db, private readonly string $kind)
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException("Unknown mapping kind {$kind}.");
        }
        [$this->table, $this->ownerCol, $this->targetCol, $this->targetTable, $this->owner, $this->label] = self::KINDS[$kind];
    }

    /** @return list<array<string, mixed>> active first, then ended (newest first) */
    public function list(int $ownerId, bool $withEnded = true): array
    {
        $cols = $this->targetTable === 'gst_registrations'
            ? 't.gstin AS target_code, COALESCE(t.trade_name, t.legal_name) AS target_name, t.status AS target_status'
            : 't.code AS target_code, t.name AS target_name, CASE WHEN t.is_active = 1 THEN \'active\' ELSE \'inactive\' END AS target_status';
        return $this->db->select("SELECT m.*, m.{$this->targetCol} AS target_id, {$cols}, cu.name AS created_by_name FROM {$this->table} m
            JOIN {$this->targetTable} t ON t.id = m.{$this->targetCol} LEFT JOIN users cu ON cu.id = m.created_by
            WHERE m.{$this->ownerCol} = ?" . ($withEnded ? '' : " AND m.status = 'active'") . " ORDER BY CASE WHEN m.status = 'active' THEN 0 ELSE 1 END, m.effective_from DESC, m.id DESC", [$ownerId]);
    }

    /** @return list<int> */
    public function activeTargetIds(int $ownerId): array
    {
        return array_map('intval', array_column($this->db->select("SELECT {$this->targetCol} AS t FROM {$this->table} WHERE {$this->ownerCol} = ? AND status = 'active'", [$ownerId]), 't'));
    }

    public function isActive(int $ownerId, int $targetId): bool
    {
        return (bool) $this->db->scalar("SELECT 1 FROM {$this->table} WHERE {$this->ownerCol} = ? AND {$this->targetCol} = ? AND status = 'active'", [$ownerId, $targetId]);
    }

    /** @param array<string, mixed> $owner (id + code + name) @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function add(array $owner, int $targetId, array $data, array $actor): int
    {
        $from = (string) ($data['effective_from'] ?? '') ?: BusinessDate::today();
        $to = trim((string) ($data['effective_to'] ?? '')) ?: null;
        if (!BusinessDate::isValid($from)) {
            throw ValidationException::withMessage('effective_from', 'Enter a valid start date.');
        }
        if ($to !== null && (!BusinessDate::isValid($to) || $to < $from)) {
            throw ValidationException::withMessage('effective_to', 'The end date must be on or after the start date.');
        }
        $target = $this->target($targetId);
        if ($target === null) {
            throw ValidationException::withMessage($this->targetCol, 'Choose a ' . $this->label . '.');
        }
        if ($this->isActive((int) $owner['id'], $targetId)) {
            throw new DomainRuleException('This ' . $this->label . ' is already linked. End the current link before adding a new one.');
        }
        $notes = trim((string) ($data['notes'] ?? ''));
        $id = $this->db->insert($this->table, [
            $this->ownerCol => (int) $owner['id'], $this->targetCol => $targetId, 'effective_from' => $from, 'effective_to' => $to, 'status' => 'active',
            'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null, 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $values = ['mapping' => $id, $this->targetCol => $targetId, 'target' => $target['label'], 'from' => $from, 'to' => $to];
        $this->record($owner, 'mapping_added', null, $values, null, $from, $actor, 'Linked ' . $this->label . ' ' . $target['label']);
        return $id;
    }

    /** @param array<string, mixed> $owner @param array<string, mixed> $actor */
    public function end(array $owner, int $mappingId, ?string $date, string $reason, array $actor): void
    {
        $m = $this->db->first("SELECT * FROM {$this->table} WHERE id = ? AND {$this->ownerCol} = ?", [$mappingId, (int) $owner['id']]);
        if ($m === null) {
            throw new DomainRuleException('Mapping not found.');
        }
        if ($m['status'] !== 'active') {
            throw new DomainRuleException('This mapping has already ended.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $date = BusinessDate::isValid((string) $date) ? (string) $date : BusinessDate::today();
        if ($date < $m['effective_from']) {
            throw ValidationException::withMessage('effective_to', 'The end date cannot be before the start date (' . BusinessDate::format((string) $m['effective_from']) . ').');
        }
        $this->db->update($this->table, ['status' => 'ended', 'effective_to' => $date, 'end_reason' => mb_substr($reason, 0, 255), 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $mappingId]);
        $target = $this->target((int) $m[$this->targetCol]);
        $this->record($owner, 'mapping_ended', ['mapping' => $mappingId, 'status' => 'active'], ['mapping' => $mappingId, 'status' => 'ended', 'to' => $date], $reason, $date, $actor,
            'Ended the link to ' . $this->label . ' ' . ($target['label'] ?? '#' . $m[$this->targetCol]));
    }

    /** @return array{label: string}|null */
    private function target(int $id): ?array
    {
        if ($this->targetTable === 'gst_registrations') {
            $r = $this->db->first('SELECT gstin, legal_name, is_archived, status FROM gst_registrations WHERE id = ?', [$id]);
            return $r === null || (int) $r['is_archived'] === 1 ? null : ['label' => $r['gstin'] . ' (' . $r['legal_name'] . ')'];
        }
        $r = $this->db->first('SELECT code, name, is_active FROM gst_business_activities WHERE id = ?', [$id]);
        return $r === null || !(int) $r['is_active'] ? null : ['label' => $r['name']];
    }

    /** @param array<string, mixed> $owner @param array<string, mixed>|null $old @param array<string, mixed> $new @param array<string, mixed> $actor */
    private function record(array $owner, string $event, ?array $old, array $new, ?string $reason, ?string $date, array $actor, string $text): void
    {
        $event = ($this->targetTable === 'gst_registrations' ? 'gst_' : 'activity_') . $event;
        $history = $this->owner === 'product' ? 'product_history' : 'supplier_history';
        HistoryWriter::write($this->db, $history, $this->ownerCol, (int) $owner['id'], $event, null, null, $old, $new, $reason, $date, $actor);
        $code = (string) ($owner['product_code'] ?? $owner['supplier_code'] ?? '#' . $owner['id']);
        App::activity()->log($this->owner . '.' . $event, "{$text} on {$code}", $this->owner, (int) $owner['id'], module: $this->owner === 'product' ? 'products' : 'suppliers',
            oldValues: $old, newValues: $new + ($reason !== null ? ['reason' => $reason] : []));
    }
}
