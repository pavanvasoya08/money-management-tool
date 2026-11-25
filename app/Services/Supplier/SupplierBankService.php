<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Product\HistoryWriter;
use App\Support\FieldCipher;

/**
 * Supplier bank details — the supplier's own accounts for future payments, kept apart from
 * the company's bank accounts (Phase 5). Only business details are stored: holder, bank,
 * branch, account number, IFSC / SWIFT, account type. Never passwords, PINs, OTPs or card
 * data. The account number is encrypted (AES-256-GCM with APP_KEY) with a keyed hash for
 * duplicate checks; only the last four digits appear in lists, history and audit logs.
 *
 * New details and a change of the primary account go through the supplier bank-detail
 * workflow and cannot be used until approved.
 */
final class SupplierBankService
{
    public const STATUSES = ['pending_approval' => 'Pending approval', 'active' => 'Active', 'inactive' => 'Inactive', 'rejected' => 'Rejected'];
    public const ACCOUNT_TYPES = ['current' => 'Current account', 'savings' => 'Savings account', 'other' => 'Other'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> (no encrypted number) */
    public function forSupplier(int $supplierId): array
    {
        $rows = $this->db->select("SELECT b.*, u.name AS created_by_name FROM supplier_bank_details b LEFT JOIN users u ON u.id = b.created_by WHERE b.supplier_id = ?
            ORDER BY CASE b.status WHEN 'active' THEN 0 WHEN 'pending_approval' THEN 1 ELSE 2 END, b.is_primary DESC, b.id DESC", [$supplierId]);
        return array_map(static fn (array $r): array => array_diff_key($r, ['account_number_encrypted' => 1, 'account_number_hash' => 1]), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        return $this->db->first('SELECT * FROM supplier_bank_details WHERE id = ? AND supplier_id = ?', [$id, $supplierId]);
    }

    public static function masked(array $row): string
    {
        return 'XXXXXX' . (string) ($row['account_number_last4'] ?? '');
    }

    /** Full number for users with suppliers.view_full_account_number and no masking field rule. @param array<string, mixed> $row @param array<string, mixed> $user */
    public function fullNumber(array $row, array $user): ?string
    {
        if (!App::gate()->allows($user, 'suppliers.view_bank_details') || !App::gate()->allows($user, 'suppliers.view_full_account_number')) {
            return null;
        }
        if (!App::fields()->access($user, 'suppliers', 'bank_account_number')->showsRealValue()) {
            return null;
        }
        return FieldCipher::decrypt((string) $row['account_number_encrypted']);
    }

    /**
     * Add bank details. They stay "pending approval" until the bank-detail workflow approves
     * them (at once when it is inactive).
     * @param array<string, mixed> $s @param array<string, mixed> $data @param array<string, mixed> $actor @return array<string, mixed> change request
     */
    public function add(array $s, array $data, array $actor): array
    {
        if ($s['status'] === 'archived') {
            throw new DomainRuleException('Archived suppliers are read-only.');
        }
        if (!FieldCipher::configured()) {
            throw new DomainRuleException('APP_KEY is not set, so account numbers cannot be stored securely. Ask an administrator to run "php bin/console key:generate".');
        }
        $holder = trim((string) ($data['account_holder_name'] ?? ''));
        $bank = trim((string) ($data['bank_name'] ?? ''));
        $branch = trim((string) ($data['branch_name'] ?? ''));
        $number = strtoupper((string) preg_replace('/[\s-]+/', '', (string) ($data['account_number'] ?? '')));
        $ifsc = strtoupper(trim((string) ($data['ifsc_code'] ?? '')));
        $swift = strtoupper(trim((string) ($data['swift_code'] ?? '')));
        $type = (string) ($data['account_type'] ?? 'current');
        if (mb_strlen($holder) < 2 || mb_strlen($holder) > 200) {
            throw ValidationException::withMessage('account_holder_name', 'Enter the account holder name as the bank records it.');
        }
        if (mb_strlen($bank) < 2 || mb_strlen($bank) > 150) {
            throw ValidationException::withMessage('bank_name', 'Enter the bank name.');
        }
        if (preg_match('/^[A-Z0-9]{6,34}$/', $number) !== 1) {
            throw ValidationException::withMessage('account_number', 'Enter 6–34 letters or digits (spaces and hyphens are ignored).');
        }
        if ($s['country'] === 'IN') {
            if (preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc) !== 1) {
                throw ValidationException::withMessage('ifsc_code', 'Enter the 11-character IFSC code (4 letters, 0, then 6 letters or digits).');
            }
        } elseif ($ifsc !== '') {
            throw ValidationException::withMessage('ifsc_code', 'IFSC codes apply to Indian bank accounts only.');
        }
        if ($swift !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $swift) !== 1) {
            throw ValidationException::withMessage('swift_code', 'A SWIFT / BIC code has 8 or 11 characters.');
        }
        if ($s['country'] !== 'IN' && $swift === '') {
            throw ValidationException::withMessage('swift_code', 'Enter the SWIFT / BIC code for an overseas account.');
        }
        if (!isset(self::ACCOUNT_TYPES[$type])) {
            throw ValidationException::withMessage('account_type', 'Choose the account type.');
        }
        $hash = FieldCipher::blindIndex($number, 'supplier_bank_account');
        if ($this->db->scalar("SELECT 1 FROM supplier_bank_details WHERE supplier_id = ? AND account_number_hash = ? AND status IN ('active', 'pending_approval')", [$s['id'], $hash])) {
            throw ValidationException::withMessage('account_number', 'This account is already recorded for this supplier.');
        }
        $other = $this->db->first("SELECT s.supplier_code, s.name FROM supplier_bank_details b JOIN suppliers s ON s.id = b.supplier_id WHERE b.account_number_hash = ? AND b.supplier_id <> ? AND b.status IN ('active', 'pending_approval') LIMIT 1", [$hash, $s['id']]);
        if ($other !== null && empty($data['duplicate_ok'])) {
            throw ValidationException::withMessage('duplicate_ok', "Supplier {$other['supplier_code']} ({$other['name']}) already has this account number. Tick the confirmation if both really are paid into the same account.");
        }
        $notes = trim((string) ($data['notes'] ?? ''));
        $first = !$this->db->scalar("SELECT 1 FROM supplier_bank_details WHERE supplier_id = ? AND status = 'active'", [$s['id']]);
        $id = $this->db->insert('supplier_bank_details', [
            'supplier_id' => (int) $s['id'], 'account_holder_name' => $holder, 'bank_name' => $bank, 'branch_name' => $branch !== '' ? mb_substr($branch, 0, 150) : null,
            'account_number_encrypted' => FieldCipher::encrypt($number), 'account_number_hash' => $hash, 'account_number_last4' => substr($number, -4),
            'ifsc_code' => $ifsc !== '' ? $ifsc : null, 'swift_code' => $swift !== '' ? $swift : null, 'account_type' => $type, 'is_primary' => 0, 'status' => 'pending_approval',
            'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null, 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $view = $this->view($this->find((int) $s['id'], $id));
        $makePrimary = $first || !empty($data['is_primary']);
        try {
            return (new SupplierService($this->db))->request($s, 'bank', null, $view + ['bank_detail_id' => $id, 'make_primary' => $makePrimary], 'New bank details', $actor, 'suppliers.manage_bank_details', $id);
        } catch (\Throwable $e) {
            $this->db->update('supplier_bank_details', ['status' => 'rejected', 'status_reason' => 'Request could not be submitted'], ['id' => $id]);
            throw $e;
        }
    }

    /** Switching the primary account changes where payments go, so it needs approval too. @param array<string, mixed> $s @param array<string, mixed> $row @param array<string, mixed> $actor */
    public function requestPrimary(array $s, array $row, string $reason, array $actor): array
    {
        if ($row['status'] !== 'active' || (int) $row['is_primary'] === 1) {
            throw new DomainRuleException('Only an active, non-primary account can be made primary.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($this->db->scalar("SELECT 1 FROM supplier_change_requests WHERE supplier_id = ? AND change_type = 'bank' AND status = 'pending'", [$s['id']])) {
            throw new DomainRuleException('A bank-detail change for this supplier is already waiting for approval.');
        }
        return (new SupplierService($this->db))->request($s, 'bank', null, $this->view($row) + ['bank_detail_id' => (int) $row['id'], 'make_primary' => true, 'primary_only' => true],
            $reason, $actor, 'suppliers.manage_bank_details', (int) $row['id']);
    }

    /** Deactivating details is always safe (no approval). @param array<string, mixed> $s @param array<string, mixed> $row @param array<string, mixed> $actor */
    public function deactivate(array $s, array $row, string $reason, array $actor): void
    {
        if ($row['status'] !== 'active') {
            throw new DomainRuleException('Only active bank details can be deactivated.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('supplier_bank_details', ['status' => 'inactive', 'is_primary' => 0, 'status_reason' => mb_substr($reason, 0, 255), 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $row['id']]);
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'bank_deactivated', 'active', 'inactive', $this->view($row), null, $reason, null, $actor);
        App::activity()->log('supplier.bank_deactivated', "Deactivated bank account " . self::masked($row) . " of supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers',
            oldValues: $this->view($row), newValues: ['status' => 'inactive', 'reason' => $reason]);
    }

    /** Called by SupplierService when a bank request is decided. @param array<string, mixed> $s @param array<string, mixed> $change @param array<string, mixed> $by */
    public function decide(array $s, array $change, string $status, ?string $comment, array $by): void
    {
        $row = $change['bank_detail_id'] !== null ? $this->find((int) $s['id'], (int) $change['bank_detail_id']) : null;
        if ($row === null) {
            return;
        }
        $primaryOnly = !empty($change['new_values']['primary_only']);
        if ($status !== 'approved') {
            if (!$primaryOnly && $row['status'] === 'pending_approval') {
                $this->db->update('supplier_bank_details', ['status' => 'rejected', 'status_reason' => $status === 'rejected' ? ($comment ?? 'Rejected') : 'Request withdrawn', 'updated_at' => now()], ['id' => $row['id']]);
            }
            HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'bank_' . $status, null, null, null, $this->view($row), $comment, null, $by);
            return;
        }
        if (!empty($change['new_values']['make_primary'])) {
            $this->db->execute('UPDATE supplier_bank_details SET is_primary = 0, updated_at = ? WHERE supplier_id = ? AND id <> ?', [now(), $s['id'], $row['id']]);
        }
        $this->db->update('supplier_bank_details', ['status' => 'active', 'is_primary' => !empty($change['new_values']['make_primary']) ? 1 : (int) $row['is_primary'], 'status_reason' => null,
            'updated_by' => $by['id'], 'updated_at' => now()], ['id' => $row['id']]);
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], $primaryOnly ? 'bank_primary' : 'bank_approved', $primaryOnly ? null : 'pending_approval', $primaryOnly ? null : 'active',
            null, $this->view($row) + ['primary' => !empty($change['new_values']['make_primary'])], $change['reason'], null, $by);
    }

    /** History / audit view: never the full number. @param array<string, mixed> $row @return array<string, mixed> */
    public function view(array $row): array
    {
        return ['holder' => $row['account_holder_name'], 'bank' => $row['bank_name'], 'branch' => $row['branch_name'], 'account' => self::masked($row),
            'ifsc' => $row['ifsc_code'], 'swift' => $row['swift_code'], 'type' => $row['account_type']];
    }
}
