<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Services\Files\SecureFileStore;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;

/**
 * Private partner documents.
 *  - Extension AND detected MIME type must both be on the allow-list and agree.
 *  - Size limit; images must decode; PDFs must start with %PDF; Office files must be real ZIPs.
 *  - Stored as <random 32 hex>.<ext> under storage/uploads/partner-documents (outside public/,
 *    with "Require all denied"). The original name is kept only as metadata.
 *  - Served only through PartnerDocumentController after record + permission + field checks,
 *    with nosniff and attachment headers. Uploads, downloads and removals are audited.
 */
final class DocumentService
{
    public const MAX_BYTES = SecureFileStore::MAX_BYTES;
    /** extension => allowed MIME types (as detected by finfo) */
    public const TYPES = SecureFileStore::TYPES;
    public const IMAGE_EXT = SecureFileStore::IMAGE_EXT;
    public const CATEGORIES = [
        'agreement' => 'Partnership agreement', 'pan' => 'PAN document', 'identity' => 'Identity document',
        'contribution_proof' => 'Capital contribution proof', 'withdrawal_proof' => 'Withdrawal proof', 'adjustment_proof' => 'Adjustment support',
        'profit_sharing_agreement' => 'Profit-sharing agreement', 'photo' => 'Profile photo', 'other' => 'Other supporting document',
    ];
    /** Categories protected by the partners.identity_documents field rule. */
    public const IDENTITY = ['pan', 'identity'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function directory(): string
    {
        return App::basePath('storage/uploads/partner-documents');
    }

    /**
     * Store an uploaded file.
     * @param array{name: string, tmp_name: string, error: int, size: int} $file from Request::file()
     * @param bool $httpUpload true for real uploads (checked with is_uploaded_file); false only for trusted internal callers/tests
     * @param array<string, mixed> $actor
     */
    public function store(int $partnerId, array $file, string $category, string $title, array $actor, ?int $transactionId = null, ?int $allocationId = null, bool $httpUpload = true): int
    {
        if (!isset(self::CATEGORIES[$category])) {
            throw ValidationException::withMessage('category', 'Choose a document type.');
        }
        $store = new SecureFileStore(self::directory(), self::MAX_BYTES);
        $info = $store->validate($file, $httpUpload, 'document', $category === 'photo' ? self::IMAGE_EXT : null);
        $original = $info['original'];
        $size = $info['size'];
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $id = $this->db->insert('partner_documents', [
            'partner_id' => $partnerId, 'capital_transaction_id' => $transactionId, 'allocation_id' => $allocationId, 'category' => $category,
            'title' => mb_substr(trim($title) !== '' ? trim($title) : self::CATEGORIES[$category], 0, 150), 'original_name' => $original,
            'stored_name' => $saved['stored'], 'mime_type' => $info['mime'],
            'size_bytes' => $size, 'sha256' => $saved['sha256'], 'uploaded_by' => (int) $actor['id'], 'created_at' => now(),
        ]);
        if ($category === 'photo') {
            $this->db->update('partners', ['photo_document_id' => $id, 'updated_at' => now()], ['id' => $partnerId]);
        }
        App::activity()->log('partner.document_uploaded', "Uploaded {$category} document \"{$original}\"", 'partner_document', $id, module: 'partners',
            newValues: ['partner_id' => $partnerId, 'category' => $category, 'file' => $original, 'size' => $size, 'transaction_id' => $transactionId]);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT d.*, u.name AS uploaded_by_name FROM partner_documents d JOIN users u ON u.id = d.uploaded_by WHERE d.id = ? AND d.deleted_at IS NULL', [$id]);
    }

    /**
     * Documents of a partner the user may see (identity documents need the identity_documents field).
     * @param array<string, mixed> $user @return list<array<string, mixed>>
     */
    public function forPartner(array $user, int $partnerId, ?int $transactionId = null): array
    {
        $sql = "SELECT d.*, u.name AS uploaded_by_name, t.transaction_no FROM partner_documents d JOIN users u ON u.id = d.uploaded_by
                LEFT JOIN partner_capital_transactions t ON t.id = d.capital_transaction_id
                WHERE d.partner_id = ? AND d.deleted_at IS NULL AND d.category <> 'photo'";
        $params = [$partnerId];
        if ($transactionId !== null) {
            $sql .= ' AND d.capital_transaction_id = ?';
            $params[] = $transactionId;
        }
        $rows = $this->db->select($sql . ' ORDER BY d.created_at DESC, d.id DESC', $params);
        return array_values(array_filter($rows, fn (array $d): bool => $this->categoryVisible($user, (string) $d['category'])));
    }

    /** @param array<string, mixed> $user */
    public function categoryVisible(array $user, string $category): bool
    {
        return !in_array($category, self::IDENTITY, true) || App::fields()->access($user, 'partners', 'identity_documents')->canView();
    }

    /** Stream a stored document (caller has already authorised). @param array<string, mixed> $doc */
    public function response(array $doc, bool $inline = false): Response
    {
        return (new SecureFileStore(self::directory()))->response((string) $doc['stored_name'], (string) $doc['mime_type'], (string) $doc['original_name'], $inline);
    }

    /** Soft delete: the record and file are kept for audit; the document disappears from the UI. */
    public function remove(array $doc, array $actor): void
    {
        $this->db->update('partner_documents', ['deleted_at' => now(), 'deleted_by' => (int) $actor['id']], ['id' => (int) $doc['id']]);
        if ($doc['category'] === 'photo') {
            $this->db->execute('UPDATE partners SET photo_document_id = NULL WHERE id = ? AND photo_document_id = ?', [$doc['partner_id'], $doc['id']]);
        }
        App::activity()->log('partner.document_removed', "Removed document \"{$doc['original_name']}\"", 'partner_document', (int) $doc['id'], module: 'partners',
            oldValues: ['partner_id' => (int) $doc['partner_id'], 'category' => $doc['category'], 'file' => $doc['original_name']]);
    }

    public static function safeOriginalName(string $name): string
    {
        return SecureFileStore::safeOriginalName($name);
    }

    public static function formatSize(int $bytes): string
    {
        return SecureFileStore::formatSize($bytes);
    }
}
