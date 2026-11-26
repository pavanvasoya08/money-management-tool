<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;

/**
 * Supporting documents of warehouses and stock documents (opening stock, inward, outward,
 * transfers, adjustments): stored outside the web root, type-checked by content, served only
 * after a permission and warehouse-scope check; every view and download is logged.
 */
final class InventoryDocumentService
{
    public const SUBJECTS = ['warehouse' => 'warehouses', 'entry' => 'inventory_entries', 'transfer' => 'inventory_transfers', 'adjustment' => 'inventory_adjustments'];
    public const CATEGORIES = ['delivery_note' => 'Delivery note / challan', 'invoice_copy' => 'Supplier invoice copy', 'count_sheet' => 'Stock count sheet', 'photo' => 'Photo',
        'inspection_report' => 'Inspection report', 'lease_agreement' => 'Lease / rent agreement', 'licence' => 'Licence / registration', 'other' => 'Other'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function store(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/inventory-documents'));
    }

    /** @return list<array<string, mixed>> */
    public function list(string $subject, int $id, bool $withArchived = false): array
    {
        return $this->db->select('SELECT d.*, u.name AS uploaded_by_name FROM inventory_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.subject_type = ? AND d.subject_id = ?'
            . ($withArchived ? '' : " AND d.status = 'active'") . ' ORDER BY d.id DESC', [$subject, $id]);
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function upload(string $subject, int $id, int $warehouseId, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if (!isset(self::SUBJECTS[$subject])) {
            throw new DomainRuleException('Unknown record.');
        }
        $category = (string) ($data['category'] ?? 'other');
        if (!isset(self::CATEGORIES[$category])) {
            throw ValidationException::withMessage('category', 'Choose a document category.');
        }
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'document');
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $title = trim((string) ($data['title'] ?? ''));
        $docId = $this->db->insert('inventory_documents', ['subject_type' => $subject, 'subject_id' => $id, 'category' => $category,
            'title' => mb_substr($title !== '' ? $title : self::CATEGORIES[$category], 0, 150), 'stored_name' => $saved['stored'], 'original_name' => $info['original'],
            'mime_type' => $info['mime'], 'size_bytes' => $info['size'], 'sha256' => $saved['sha256'], 'status' => 'active', 'uploaded_by' => (int) $actor['id'],
            'created_at' => now(), 'updated_at' => now()]);
        InventoryHistory::write($this->db, $subject, $id, 'document_uploaded', null, ['document_id' => $docId, 'file' => $info['original'], 'category' => $category], null, $actor, $warehouseId);
        App::activity()->log('inventory.document_uploaded', "Uploaded \"{$info['original']}\" to {$subject} #{$id}", $subject, $id, module: 'inventory',
            newValues: ['document_id' => $docId, 'category' => $category, 'size' => $info['size']]);
        return $docId;
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $actor */
    public function archive(array $doc, int $warehouseId, string $reason, array $actor): void
    {
        if ($doc['status'] !== 'active') {
            throw new DomainRuleException('Already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('inventory_documents', ['status' => 'archived', 'archive_reason' => mb_substr($reason, 0, 255), 'archived_by' => (int) $actor['id'], 'archived_at' => now(), 'updated_at' => now()], ['id' => $doc['id']]);
        InventoryHistory::write($this->db, (string) $doc['subject_type'], (int) $doc['subject_id'], 'document_archived', null, ['document_id' => (int) $doc['id']], $reason, $actor, $warehouseId);
        App::activity()->log('inventory.document_archived', "Archived \"{$doc['original_name']}\"", (string) $doc['subject_type'], (int) $doc['subject_id'], module: 'inventory', newValues: ['document_id' => (int) $doc['id'], 'reason' => $reason]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM inventory_documents WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $doc */
    public function response(array $doc, bool $inline): Response
    {
        App::activity()->log($inline ? 'inventory.document_viewed' : 'inventory.document_downloaded', ($inline ? 'Viewed' : 'Downloaded') . " \"{$doc['original_name']}\"", (string) $doc['subject_type'], (int) $doc['subject_id'],
            module: 'inventory', newValues: ['document_id' => (int) $doc['id']]);
        return self::store()->response((string) $doc['stored_name'], (string) $doc['mime_type'], (string) $doc['original_name'], $inline && in_array($doc['mime_type'], SecureFileStore::PREVIEWABLE, true));
    }

    /** Warehouse of the record a document belongs to (for the scope check). */
    public function warehouseOf(string $subject, int $id): ?int
    {
        $w = match ($subject) {
            'warehouse' => $this->db->scalar('SELECT id FROM warehouses WHERE id = ?', [$id]),
            'entry' => $this->db->scalar('SELECT warehouse_id FROM inventory_entries WHERE id = ?', [$id]),
            'transfer' => $this->db->scalar('SELECT from_warehouse_id FROM inventory_transfers WHERE id = ?', [$id]),
            'adjustment' => $this->db->scalar('SELECT warehouse_id FROM inventory_adjustments WHERE id = ?', [$id]),
            default => null,
        };
        return $w !== null ? (int) $w : null;
    }
}
