<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;

/**
 * Bank account documents (cancelled cheque, account proof, statements as files, opening
 * forms, transaction proofs). Private storage outside public/, extension + MIME + content
 * checks, 5 MB limit, random stored names; every view and download is permission-checked
 * by the controller and logged here. Documents are archived, never deleted.
 */
final class BankDocumentService
{
    public const TYPES = [
        'cancelled_cheque' => 'Cancelled cheque',
        'account_proof' => 'Bank account proof / letter',
        'bank_statement' => 'Bank statement (file)',
        'account_opening' => 'Account opening document',
        'transaction_proof' => 'Transaction or transfer proof',
        'other' => 'Other supporting document',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function store(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/bank-documents'));
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor
     */
    public function upload(array $acc, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if ((int) $acc['is_archived'] === 1) {
            throw new DomainRuleException('This account is archived and read-only.');
        }
        $type = (string) ($data['document_type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw ValidationException::withMessage('document_type', 'Choose a document type.');
        }
        $txnId = (int) ($data['bank_transaction_id'] ?? 0) ?: null;
        $transferId = (int) ($data['transfer_id'] ?? 0) ?: null;
        if ($txnId !== null && !$this->db->scalar('SELECT 1 FROM bank_transactions WHERE id = ? AND bank_account_id = ?', [$txnId, $acc['id']])) {
            $txnId = null;
        }
        if ($transferId !== null && !$this->db->scalar('SELECT 1 FROM bank_transfers WHERE id = ? AND (from_account_id = ? OR to_account_id = ?)', [$transferId, $acc['id'], $acc['id']])) {
            $transferId = null;
        }
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'document');
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $title = trim((string) ($data['title'] ?? ''));
        $id = $this->db->insert('bank_account_documents', [
            'bank_account_id' => (int) $acc['id'], 'bank_transaction_id' => $txnId, 'transfer_id' => $transferId, 'document_type' => $type,
            'title' => mb_substr($title !== '' ? $title : self::TYPES[$type], 0, 150), 'description' => ($d = trim((string) ($data['description'] ?? ''))) !== '' ? mb_substr($d, 0, 500) : null,
            'original_name' => $info['original'], 'stored_name' => $saved['stored'], 'mime_type' => $info['mime'], 'size_bytes' => $info['size'],
            'status' => 'active', 'uploaded_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        App::activity()->log('bank.document_uploaded', "Uploaded {$type} \"{$info['original']}\" to {$acc['account_code']}", 'bank_account', (int) $acc['id'], module: 'bank_accounts',
            newValues: ['entity' => 'document', 'document_id' => $id, 'type' => $type, 'file' => $info['original'], 'size' => $info['size'], 'transaction_id' => $txnId, 'transfer_id' => $transferId]);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM bank_account_documents WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function forAccount(int $accountId, bool $withArchived = false): array
    {
        return $this->db->select('SELECT d.*, u.name AS uploaded_by_name, t.transaction_no, tr.transfer_no FROM bank_account_documents d LEFT JOIN users u ON u.id = d.uploaded_by
            LEFT JOIN bank_transactions t ON t.id = d.bank_transaction_id LEFT JOIN bank_transfers tr ON tr.id = d.transfer_id
            WHERE d.bank_account_id = ?' . ($withArchived ? '' : " AND d.status = 'active'") . ' ORDER BY d.status, d.created_at DESC', [$accountId]);
    }

    /** @return list<array<string, mixed>> */
    public function forTransaction(int $txnId): array
    {
        return $this->db->select("SELECT * FROM bank_account_documents WHERE bank_transaction_id = ? AND status = 'active' ORDER BY id DESC", [$txnId]);
    }

    /** @return list<array<string, mixed>> */
    public function forTransfer(int $transferId): array
    {
        return $this->db->select("SELECT * FROM bank_account_documents WHERE transfer_id = ? AND status = 'active' ORDER BY id DESC", [$transferId]);
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $acc @param array<string, mixed> $actor */
    public function archive(array $doc, array $acc, string $reason, array $actor): void
    {
        if ($doc['status'] !== 'active') {
            throw new DomainRuleException('Already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('bank_account_documents', ['status' => 'archived', 'archive_reason' => mb_substr($reason, 0, 255), 'archived_by' => (int) $actor['id'], 'archived_at' => now(), 'updated_at' => now()], ['id' => $doc['id']]);
        App::activity()->log('bank.document_archived', "Archived document \"{$doc['title']}\" of {$acc['account_code']}", 'bank_account', (int) $acc['id'], module: 'bank_accounts',
            newValues: ['entity' => 'document', 'document_id' => (int) $doc['id'], 'reason' => $reason]);
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $acc */
    public function response(array $doc, array $acc, bool $inline): Response
    {
        App::activity()->log($inline ? 'bank.document_viewed' : 'bank.document_downloaded', ($inline ? 'Viewed' : 'Downloaded') . " \"{$doc['original_name']}\" of {$acc['account_code']}", 'bank_account', (int) $acc['id'],
            module: 'bank_accounts', newValues: ['entity' => 'document', 'document_id' => (int) $doc['id']]);
        return self::store()->response((string) $doc['stored_name'], (string) $doc['mime_type'], (string) $doc['original_name'], $inline && in_array($doc['mime_type'], SecureFileStore::PREVIEWABLE, true));
    }
}
