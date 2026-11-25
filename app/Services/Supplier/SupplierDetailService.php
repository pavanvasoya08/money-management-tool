<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;
use App\Services\Product\HistoryWriter;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Supplier addresses, documents and payment references.
 *
 * - Addresses: business, billing, shipping and other; one default per type; archived, not deleted.
 * - Documents: GST certificate, PAN, registration proof, agreements, bank proof, other. Private
 *   storage, MIME / content checks; bank proofs additionally need suppliers.view_bank_details.
 * - Payment references: an authorised user can note that an existing, posted Phase 5 bank
 *   withdrawal was a payment to this supplier. Nothing is posted or changed in the bank ledger;
 *   Phase 11 (purchases) will build on these references.
 */
final class SupplierDetailService
{
    public const ADDRESS_TYPES = ['business' => 'Business address', 'billing' => 'Billing address', 'shipping' => 'Shipping / dispatch address', 'other' => 'Other address'];
    public const DOC_CATEGORIES = ['gst_certificate' => 'GST certificate', 'pan' => 'PAN document', 'business_registration' => 'Business registration proof', 'agreement' => 'Agreement / contract',
        'bank_proof' => 'Bank proof (cancelled cheque, bank letter)', 'other' => 'Other document'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function store(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/supplier-documents'));
    }

    // ---------------------------------------------------------------- addresses

    /** @return list<array<string, mixed>> */
    public function addresses(int $supplierId, bool $withArchived = false): array
    {
        return $this->db->select('SELECT a.*, st.name AS state, st.code AS state_code FROM supplier_addresses a LEFT JOIN gst_states st ON st.id = a.state_id
            WHERE a.supplier_id = ?' . ($withArchived ? '' : " AND a.status = 'active'") . ' ORDER BY a.status, a.address_type, a.is_default DESC, a.id', [$supplierId]);
    }

    /** One-line address. @param array<string, mixed> $a */
    public static function format(array $a): string
    {
        return implode(', ', array_filter([$a['line1'] ?? null, $a['line2'] ?? null, $a['city'] ?? null, ($a['state'] ?? null) ?: ($a['state_name'] ?? null),
            $a['pin_code'] ?? null, ($a['country'] ?? 'IN') !== 'IN' ? $a['country'] : null]));
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveAddress(array $s, array $data, array $actor, ?int $addressId = null): int
    {
        if ($s['status'] === 'archived') {
            throw new DomainRuleException('Archived suppliers are read-only.');
        }
        $existing = $addressId !== null ? ($this->db->first('SELECT * FROM supplier_addresses WHERE id = ? AND supplier_id = ?', [$addressId, $s['id']]) ?? throw new DomainRuleException('Address not found.')) : null;
        $type = (string) ($data['address_type'] ?? ($existing['address_type'] ?? ''));
        if (!isset(self::ADDRESS_TYPES[$type])) {
            throw ValidationException::withMessage('address_type', 'Choose the address type.');
        }
        $t = static fn (string $k, int $max): string => mb_substr(trim((string) ($data[$k] ?? '')), 0, $max);
        $line1 = $t('line1', 200);
        $city = $t('city', 100);
        $country = strtoupper($t('country', 2)) ?: 'IN';
        $pin = (string) preg_replace('/\s+/', '', $t('pin_code', 10));
        if (mb_strlen($line1) < 3) {
            throw ValidationException::withMessage('line1', 'Enter the address.');
        }
        if ($city === '') {
            throw ValidationException::withMessage('city', 'Enter the city.');
        }
        $stateId = (int) ($data['state_id'] ?? 0) ?: null;
        if ($country === 'IN') {
            if ($stateId === null || !$this->db->scalar('SELECT 1 FROM gst_states WHERE id = ?', [$stateId])) {
                throw ValidationException::withMessage('state_id', 'Choose the state.');
            }
            if (preg_match('/^[1-9]\d{5}$/', $pin) !== 1) {
                throw ValidationException::withMessage('pin_code', 'Enter the 6-digit PIN code.');
            }
        } else {
            $stateId = null;
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw ValidationException::withMessage('country', 'Use the two-letter country code (e.g. CN, US).');
            }
        }
        $row = ['address_type' => $type, 'label' => $t('label', 100) ?: null, 'line1' => $line1, 'line2' => $t('line2', 200) ?: null, 'city' => $city, 'state_id' => $stateId,
            'state_name' => $country === 'IN' ? null : ($t('state_name', 100) ?: null), 'pin_code' => $pin !== '' ? $pin : null, 'country' => $country, 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        $makeDefault = !empty($data['is_default']) || !$this->db->scalar("SELECT 1 FROM supplier_addresses WHERE supplier_id = ? AND address_type = ? AND status = 'active'" . ($addressId ? ' AND id <> ?' : ''),
            array_merge([$s['id'], $type], $addressId ? [$addressId] : []));
        $id = $this->db->transaction(function () use ($s, $row, $existing, $makeDefault, $type, $actor): int {
            if ($makeDefault) {
                $this->db->execute('UPDATE supplier_addresses SET is_default = 0 WHERE supplier_id = ? AND address_type = ?', [$s['id'], $type]);
            }
            if ($existing === null) {
                return $this->db->insert('supplier_addresses', $row + ['supplier_id' => (int) $s['id'], 'is_default' => $makeDefault ? 1 : 0, 'status' => 'active', 'created_by' => (int) $actor['id'], 'created_at' => now()]);
            }
            $this->db->update('supplier_addresses', $row + ['is_default' => $makeDefault ? 1 : (int) $existing['is_default']], ['id' => $existing['id']]);
            return (int) $existing['id'];
        });
        $view = array_diff_key($row, ['updated_by' => 1, 'updated_at' => 1]);
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], $existing === null ? 'address_added' : 'address_updated', null, null,
            $existing !== null ? array_intersect_key($existing, $view) : null, $view + ['address_id' => $id], null, null, $actor);
        App::activity()->log($existing === null ? 'supplier.address_added' : 'supplier.address_updated', ($existing === null ? 'Added' : 'Updated') . " {$type} address of supplier {$s['supplier_code']}", 'supplier', (int) $s['id'],
            module: 'suppliers', oldValues: $existing !== null ? array_intersect_key($existing, $view) : null, newValues: $view);
        return $id;
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $actor */
    public function archiveAddress(array $s, int $addressId, array $actor): void
    {
        $a = $this->db->first("SELECT * FROM supplier_addresses WHERE id = ? AND supplier_id = ? AND status = 'active'", [$addressId, $s['id']]) ?? throw new DomainRuleException('Address not found.');
        $this->db->update('supplier_addresses', ['status' => 'archived', 'is_default' => 0, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $addressId]);
        if ((int) $a['is_default'] === 1) {
            $next = $this->db->scalar("SELECT id FROM supplier_addresses WHERE supplier_id = ? AND address_type = ? AND status = 'active' ORDER BY id LIMIT 1", [$s['id'], $a['address_type']]);
            if ($next !== null) {
                $this->db->update('supplier_addresses', ['is_default' => 1], ['id' => $next]);
            }
        }
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'address_archived', null, null, ['address_id' => $addressId, 'address' => self::format($a)], null, null, null, $actor);
        App::activity()->log('supplier.address_archived', "Archived {$a['address_type']} address of supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers', oldValues: ['address_id' => $addressId]);
    }

    // ---------------------------------------------------------------- documents

    /** @return list<array<string, mixed>> */
    public function documents(int $supplierId, bool $withArchived = false, bool $withBankProof = true): array
    {
        return $this->db->select('SELECT d.*, u.name AS uploaded_by_name FROM supplier_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.supplier_id = ?'
            . ($withArchived ? '' : " AND d.status = 'active'") . ($withBankProof ? '' : " AND d.category <> 'bank_proof'") . ' ORDER BY d.status, d.category, d.created_at DESC', [$supplierId]);
    }

    /** @param array<string, mixed> $s @param array{name: string, tmp_name: string, error: int, size: int} $file @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function uploadDocument(array $s, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if ($s['status'] === 'archived') {
            throw new DomainRuleException('Archived suppliers are read-only.');
        }
        $category = (string) ($data['category'] ?? '');
        if (!isset(self::DOC_CATEGORIES[$category])) {
            throw ValidationException::withMessage('category', 'Choose a document category.');
        }
        if ($category === 'bank_proof' && !App::gate()->allows($actor, 'suppliers.view_bank_details')) {
            throw new \App\Exceptions\AuthorizationException('suppliers.view_bank_details', 'Bank proofs can only be handled by users who may see supplier bank details.');
        }
        $expiry = trim((string) ($data['expiry_date'] ?? '')) ?: null;
        if ($expiry !== null && !BusinessDate::isValid($expiry)) {
            throw ValidationException::withMessage('expiry_date', 'Enter a valid date.');
        }
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'document');
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $title = trim((string) ($data['title'] ?? ''));
        $desc = trim((string) ($data['description'] ?? ''));
        $id = $this->db->insert('supplier_documents', [
            'supplier_id' => (int) $s['id'], 'category' => $category, 'title' => mb_substr($title !== '' ? $title : self::DOC_CATEGORIES[$category], 0, 150),
            'description' => $desc !== '' ? mb_substr($desc, 0, 500) : null, 'original_name' => $info['original'], 'stored_name' => $saved['stored'], 'mime_type' => $info['mime'], 'size_bytes' => $info['size'],
            'expiry_date' => $expiry, 'status' => 'active', 'uploaded_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $values = ['document_id' => $id, 'category' => $category, 'file' => $info['original'], 'size' => $info['size']];
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'document_uploaded', null, null, null, $values, null, null, $actor);
        App::activity()->log('supplier.document_uploaded', "Uploaded {$category} \"{$info['original']}\" to supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers', newValues: $values);
        return $id;
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $doc @param array<string, mixed> $actor */
    public function archiveDocument(array $s, array $doc, string $reason, array $actor): void
    {
        if ($doc['status'] !== 'active') {
            throw new DomainRuleException('Already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('supplier_documents', ['status' => 'archived', 'archive_reason' => mb_substr($reason, 0, 255), 'archived_by' => (int) $actor['id'], 'archived_at' => now(), 'updated_at' => now()], ['id' => $doc['id']]);
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'document_archived', null, null, ['document_id' => (int) $doc['id'], 'title' => $doc['title']], null, $reason, null, $actor);
        App::activity()->log('supplier.document_archived', "Archived document \"{$doc['title']}\" of supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers', newValues: ['document_id' => (int) $doc['id'], 'reason' => $reason]);
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $s */
    public function documentResponse(array $doc, array $s, bool $inline): Response
    {
        App::activity()->log($inline ? 'supplier.document_viewed' : 'supplier.document_downloaded', ($inline ? 'Viewed' : 'Downloaded') . " \"{$doc['original_name']}\" of supplier {$s['supplier_code']}", 'supplier', (int) $s['id'],
            module: 'suppliers', newValues: ['document_id' => (int) $doc['id'], 'category' => $doc['category']]);
        return self::store()->response((string) $doc['stored_name'], (string) $doc['mime_type'], (string) $doc['original_name'], $inline && in_array($doc['mime_type'], SecureFileStore::PREVIEWABLE, true));
    }

    // ---------------------------------------------------------------- payment references (Phase 5 bank transactions)

    /** @param array{0: string, 1: list<mixed>}|null $bankScope only links whose account the user may see (alias a) @return list<array<string, mixed>> */
    public function paymentLinks(int $supplierId, ?array $bankScope): array
    {
        if ($bankScope === null) {
            return [];
        }
        [$w, $p] = $bankScope;
        return $this->db->select("SELECT l.*, t.transaction_no, t.transaction_date, t.amount, t.status AS txn_status, t.reference_no, a.display_name AS account_name, a.account_code, u.name AS linked_by_name
            FROM supplier_bank_transaction_links l JOIN bank_transactions t ON t.id = l.bank_transaction_id JOIN bank_accounts a ON a.id = t.bank_account_id
            LEFT JOIN users u ON u.id = l.linked_by WHERE l.supplier_id = ? AND {$w} ORDER BY l.status, t.transaction_date DESC, l.id DESC", array_merge([$supplierId], $p));
    }

    /** Posted withdrawals the user may see that are not yet linked to a supplier. @param array{0: string, 1: list<mixed>} $bankScope @return list<array<string, mixed>> */
    public function linkableTransactions(array $bankScope, int $limit = 200): array
    {
        [$w, $p] = $bankScope;
        return $this->db->select("SELECT t.id, t.transaction_no, t.transaction_date, t.amount, t.counterparty_name, t.reference_no, a.display_name AS account_name FROM bank_transactions t
            JOIN bank_accounts a ON a.id = t.bank_account_id WHERE {$w} AND t.type = 'withdrawal' AND t.status = 'posted'
            AND t.id NOT IN (SELECT bank_transaction_id FROM supplier_bank_transaction_links WHERE status = 'active') ORDER BY t.transaction_date DESC, t.id DESC LIMIT " . max(1, $limit), $p);
    }

    /**
     * Note that an existing posted bank withdrawal was a payment to this supplier. Nothing in
     * the bank ledger changes. @param array<string, mixed> $s @param array{0: string, 1: list<mixed>} $bankScope @param array<string, mixed> $actor
     */
    public function linkPayment(array $s, int $txnId, ?string $reference, ?string $notes, array $bankScope, array $actor): int
    {
        if ($s['status'] === 'archived') {
            throw new DomainRuleException('Archived suppliers are read-only.');
        }
        [$w, $p] = $bankScope;
        $txn = $this->db->first("SELECT t.* FROM bank_transactions t JOIN bank_accounts a ON a.id = t.bank_account_id WHERE t.id = ? AND {$w}", array_merge([$txnId], $p));
        if ($txn === null) {
            throw ValidationException::withMessage('bank_transaction_id', 'Choose a bank transaction you can see.');
        }
        if ($txn['type'] !== 'withdrawal' || $txn['status'] !== 'posted') {
            throw ValidationException::withMessage('bank_transaction_id', 'Only posted withdrawals can be referenced as supplier payments.');
        }
        if ($this->db->scalar("SELECT 1 FROM supplier_bank_transaction_links WHERE bank_transaction_id = ? AND status = 'active'", [$txnId])) {
            throw ValidationException::withMessage('bank_transaction_id', 'This transaction is already referenced for a supplier.');
        }
        $reference = $reference !== null && trim($reference) !== '' ? mb_substr(trim($reference), 0, 100) : null;
        $id = $this->db->insert('supplier_bank_transaction_links', ['supplier_id' => (int) $s['id'], 'bank_transaction_id' => $txnId, 'reference' => $reference,
            'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null, 'status' => 'active', 'linked_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        $values = ['link' => $id, 'transaction' => $txn['transaction_no'], 'amount' => Money::toDb(Money::fromDb($txn['amount'])), 'reference' => $reference];
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'payment_linked', null, null, null, $values, null, null, $actor);
        App::activity()->log('supplier.payment_linked', "Referenced bank transaction {$txn['transaction_no']} as a payment to supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers', newValues: $values);
        return $id;
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $actor */
    public function unlinkPayment(array $s, int $linkId, string $reason, array $actor): void
    {
        $l = $this->db->first("SELECT l.*, t.transaction_no FROM supplier_bank_transaction_links l JOIN bank_transactions t ON t.id = l.bank_transaction_id WHERE l.id = ? AND l.supplier_id = ? AND l.status = 'active'", [$linkId, $s['id']])
            ?? throw new DomainRuleException('Reference not found.');
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('supplier_bank_transaction_links', ['status' => 'removed', 'removed_by' => (int) $actor['id'], 'removed_at' => now(), 'remove_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()], ['id' => $linkId]);
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'payment_unlinked', null, null, ['link' => $linkId, 'transaction' => $l['transaction_no']], null, $reason, null, $actor);
        App::activity()->log('supplier.payment_unlinked', "Removed the payment reference {$l['transaction_no']} from supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers', newValues: ['link' => $linkId, 'reason' => $reason]);
    }
}
