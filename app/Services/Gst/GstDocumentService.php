<?php

declare(strict_types=1);

namespace App\Services\Gst;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;
use App\Support\BusinessDate;

/**
 * GST registration documents (certificates and supporting files).
 * Stored privately through SecureFileStore (extension + MIME + content checks,
 * 5 MB limit, random names under storage/uploads/gst-documents, traversal-safe
 * downloads). Documents are archived, never deleted.
 */
final class GstDocumentService
{
    public const TYPES = [
        'registration_certificate' => 'GST registration certificate (REG-06)',
        'amendment_certificate' => 'Amendment certificate',
        'cancellation_certificate' => 'Cancellation order / certificate',
        'business_proof' => 'Proof of place of business',
        'authorization' => 'Authorisation letter / board resolution',
        'supporting' => 'Supporting business document',
        'other' => 'Other relevant document',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function store(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/gst-documents'));
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @param array<string, mixed> $reg @param array<string, mixed> $data @param array<string, mixed> $actor
     */
    public function upload(array $reg, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if ((int) $reg['is_archived'] === 1) {
            throw new DomainRuleException('This registration is archived and read-only.');
        }
        $type = (string) ($data['document_type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw ValidationException::withMessage('document_type', 'Choose a document type.');
        }
        $expiry = trim((string) ($data['expiry_date'] ?? ''));
        if ($expiry !== '' && !BusinessDate::isValid($expiry)) {
            throw ValidationException::withMessage('expiry_date', 'Enter a valid expiry date or leave it empty.');
        }
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'document');
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $title = trim((string) ($data['title'] ?? ''));
        $id = $this->db->insert('gst_registration_documents', [
            'gst_registration_id' => (int) $reg['id'], 'document_type' => $type, 'title' => mb_substr($title !== '' ? $title : self::TYPES[$type], 0, 150),
            'description' => ($d = trim((string) ($data['description'] ?? ''))) !== '' ? mb_substr($d, 0, 500) : null, 'expiry_date' => $expiry !== '' ? $expiry : null,
            'original_name' => $info['original'], 'stored_name' => $saved['stored'], 'mime_type' => $info['mime'], 'size_bytes' => $info['size'], 'sha256' => $saved['sha256'],
            'status' => 'active', 'uploaded_by' => (int) $actor['id'], 'created_at' => now(),
        ]);
        $this->db->update('gst_registrations', ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $reg['id']]);
        App::activity()->log('gst.document_uploaded', "Uploaded {$type} \"{$info['original']}\" to {$reg['gstin']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            newValues: ['entity' => 'document', 'document_id' => $id, 'type' => $type, 'file' => $info['original'], 'size' => $info['size'], 'expiry_date' => $expiry ?: null]);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM gst_registration_documents WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function forRegistration(int $regId, bool $withArchived = false): array
    {
        return $this->db->select(
            'SELECT d.*, u.name AS uploaded_by_name, au.name AS archived_by_name FROM gst_registration_documents d
             LEFT JOIN users u ON u.id = d.uploaded_by LEFT JOIN users au ON au.id = d.archived_by
             WHERE d.gst_registration_id = ?' . ($withArchived ? '' : " AND d.status = 'active'") . " ORDER BY CASE d.status WHEN 'active' THEN 0 ELSE 1 END, d.created_at DESC, d.id DESC",
            [$regId]
        );
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function archive(array $doc, array $reg, string $reason, array $actor): void
    {
        if ($doc['status'] !== 'active') {
            throw new DomainRuleException('This document is already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'Give a reason for archiving the document.');
        }
        $this->db->update('gst_registration_documents', ['status' => 'archived', 'archived_at' => now(), 'archived_by' => (int) $actor['id'], 'archive_reason' => mb_substr(trim($reason), 0, 255)], ['id' => (int) $doc['id']]);
        App::activity()->log('gst.document_archived', "Archived document \"{$doc['original_name']}\" of {$reg['gstin']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            oldValues: ['entity' => 'document', 'document_id' => (int) $doc['id'], 'status' => 'active'], newValues: ['entity' => 'document', 'document_id' => (int) $doc['id'], 'status' => 'archived', 'reason' => trim($reason)]);
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function response(array $doc, array $reg, array $actor, bool $preview): Response
    {
        App::activity()->log($preview ? 'gst.document_previewed' : 'gst.document_downloaded', ($preview ? 'Previewed' : 'Downloaded') . " \"{$doc['original_name']}\" of {$reg['gstin']}",
            'gst_registration', (int) $reg['id'], module: 'gst_registrations', newValues: ['entity' => 'document', 'document_id' => (int) $doc['id']], userId: (int) $actor['id']);
        return self::store()->response((string) $doc['stored_name'], (string) $doc['mime_type'], (string) $doc['original_name'], $preview);
    }

    public static function previewable(array $doc): bool
    {
        return in_array($doc['mime_type'], SecureFileStore::PREVIEWABLE, true);
    }
}
