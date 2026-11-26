<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;

/**
 * Platform documents (agreements, seller policies, commission and fee schedules, integration notes):
 * stored outside the web root, type-checked by content and size, served only after a permission and
 * platform-scope check; uploads, views, downloads and archiving are logged.
 */
final class PlatformDocumentService
{
    public const TYPES = ['agreement' => 'Platform agreement', 'seller_policy' => 'Seller policy', 'commission' => 'Commission document', 'fee_schedule' => 'Fee schedule',
        'integration' => 'Integration documentation', 'other' => 'Other business document'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function store(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/platform-documents'));
    }

    /** @return list<array<string, mixed>> */
    public function list(int $platformId, bool $withArchived = false): array
    {
        return $this->db->select('SELECT d.*, u.name AS uploaded_by_name FROM ecommerce_platform_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.platform_id = ?'
            . ($withArchived ? '' : " AND d.status = 'active'") . ' ORDER BY d.id DESC', [$platformId]);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $file @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function upload(array $p, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('This platform is archived and read-only.');
        }
        $type = (string) ($data['document_type'] ?? 'other');
        if (!isset(self::TYPES[$type])) {
            throw ValidationException::withMessage('document_type', 'Choose a document type.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessage('title', 'Enter a document title.');
        }
        $desc = trim((string) ($data['description'] ?? ''));
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'file');
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $id = $this->db->insert('ecommerce_platform_documents', ['platform_id' => (int) $p['id'], 'document_type' => $type, 'title' => mb_substr($title, 0, 150),
            'description' => $desc !== '' ? mb_substr($desc, 0, 500) : null, 'stored_name' => $saved['stored'], 'original_name' => $info['original'], 'mime_type' => $info['mime'],
            'size_bytes' => $info['size'], 'sha256' => $saved['sha256'], 'status' => 'active', 'uploaded_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        PlatformHistory::write($this->db, (int) $p['id'], 'document_uploaded', null, ['document_id' => $id, 'title' => $title, 'type' => $type, 'file' => $info['original']], null, $actor);
        App::activity()->log('platforms.document_uploaded', "Uploaded \"{$info['original']}\" to platform {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms',
            newValues: ['document_id' => $id, 'type' => $type, 'size' => $info['size']]);
        return $id;
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $actor */
    public function archive(array $doc, string $reason, array $actor): void
    {
        if ($doc['status'] !== 'active') {
            throw new DomainRuleException('Already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('ecommerce_platform_documents', ['status' => 'archived', 'archive_reason' => mb_substr($reason, 0, 255), 'archived_by' => (int) $actor['id'],
            'archived_at' => now(), 'updated_at' => now()], ['id' => $doc['id']]);
        PlatformHistory::write($this->db, (int) $doc['platform_id'], 'document_archived', null, ['document_id' => (int) $doc['id'], 'title' => $doc['title']], $reason, $actor);
        App::activity()->log('platforms.document_archived', "Archived platform document \"{$doc['original_name']}\"", 'platform', (int) $doc['platform_id'], module: 'platforms',
            newValues: ['document_id' => (int) $doc['id'], 'reason' => $reason]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM ecommerce_platform_documents WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $actor */
    public function response(array $doc, bool $inline, array $actor): Response
    {
        $event = $inline ? 'document_viewed' : 'document_downloaded';
        PlatformHistory::write($this->db, (int) $doc['platform_id'], $event, null, ['document_id' => (int) $doc['id'], 'title' => $doc['title']], null, $actor);
        App::activity()->log('platforms.' . $event, ($inline ? 'Viewed' : 'Downloaded') . " platform document \"{$doc['original_name']}\"", 'platform', (int) $doc['platform_id'],
            module: 'platforms', newValues: ['document_id' => (int) $doc['id']]);
        return self::store()->response((string) $doc['stored_name'], (string) $doc['mime_type'], (string) $doc['original_name'], $inline && in_array($doc['mime_type'], SecureFileStore::PREVIEWABLE, true));
    }
}
