<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
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
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** extension => allowed MIME types (as detected by finfo) */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];
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
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessage('document', $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is too large.' : 'The upload failed. Please try again.');
        }
        if ($httpUpload && !is_uploaded_file($file['tmp_name'])) {
            throw ValidationException::withMessage('document', 'Invalid upload.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessage('document', 'Files must be between 1 byte and ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }
        $original = self::safeOriginalName($file['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ValidationException::withMessage('document', 'Allowed file types: PDF, JPG, PNG, WEBP, DOCX, XLSX.');
        }
        if ($category === 'photo' && !in_array($ext, self::IMAGE_EXT, true)) {
            throw ValidationException::withMessage('document', 'A profile photo must be a JPG, PNG or WEBP image.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, self::TYPES[$ext], true)) {
            throw ValidationException::withMessage('document', "The file content ({$mime}) does not match its .{$ext} extension.");
        }
        $this->assertContent($file['tmp_name'], $ext);

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = self::directory() . '/' . gmdate('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new DomainRuleException('Document storage is not writable.');
        }
        $target = $dir . '/' . $stored;
        $ok = $httpUpload ? move_uploaded_file($file['tmp_name'], $target) : copy($file['tmp_name'], $target);
        if (!$ok) {
            throw new DomainRuleException('The document could not be saved.');
        }
        @chmod($target, 0640);
        $storedPath = gmdate('Y/m') . '/' . $stored;
        $id = $this->db->insert('partner_documents', [
            'partner_id' => $partnerId, 'capital_transaction_id' => $transactionId, 'allocation_id' => $allocationId, 'category' => $category,
            'title' => mb_substr(trim($title) !== '' ? trim($title) : self::CATEGORIES[$category], 0, 150), 'original_name' => $original,
            'stored_name' => $storedPath, 'mime_type' => $mime === 'application/zip' || $mime === 'application/octet-stream' ? self::officeMime($ext) : $mime,
            'size_bytes' => $size, 'sha256' => hash_file('sha256', $target), 'uploaded_by' => (int) $actor['id'], 'created_at' => now(),
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
        $path = self::directory() . '/' . $doc['stored_name'];
        $real = realpath($path);
        $base = realpath(self::directory());
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new \App\Exceptions\HttpException(404, 'The file is no longer available.');
        }
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $doc['original_name']) ?: 'document';
        return new Response((string) file_get_contents($real), 200, [
            'Content-Type' => (string) $doc['mime_type'],
            'Content-Length' => (string) filesize($real),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string) $doc['original_name']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
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
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        return mb_substr(trim($name) !== '' ? trim($name) : 'document', 0, 200);
    }

    public static function formatSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function assertContent(string $path, string $ext): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $bad = match (true) {
            $ext === 'pdf' => !str_starts_with($head, '%PDF-'),
            in_array($ext, self::IMAGE_EXT, true) => @getimagesize($path) === false,
            in_array($ext, ['docx', 'xlsx'], true) => !str_starts_with($head, "PK\x03\x04"),
            default => true,
        };
        // Reject anything that carries server-side script markers, whatever the type.
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        if ($bad || preg_match('/<\?php|<\?=|<script\b/i', $sample) === 1) {
            throw ValidationException::withMessage('document', 'The file content is not a valid ' . strtoupper($ext) . ' document.');
        }
    }

    private static function officeMime(string $ext): string
    {
        return $ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
