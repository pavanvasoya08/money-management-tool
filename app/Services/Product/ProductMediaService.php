<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;

/**
 * Product images and documents. Files are stored outside public/ under random names with
 * extension, MIME and content checks (no executables), a 5 MB limit, and are only served
 * through permission-checked routes. Images: JPG / PNG / WebP, several per product, one
 * primary, optionally per variant. Documents carry a category. Nothing is deleted; files
 * are archived with a reason. Views and downloads of documents are logged.
 */
final class ProductMediaService
{
    public const CATEGORIES = ['specification' => 'Specification', 'manual' => 'Manual / instructions', 'certificate' => 'Certificate', 'warranty' => 'Warranty document',
        'compliance' => 'Compliance document', 'other' => 'Other attachment'];
    public const MAX_IMAGES = 30;

    public function __construct(private readonly Database $db)
    {
    }

    public static function store(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/product-media'));
    }

    // ---------------------------------------------------------------- images

    /** @return list<array<string, mixed>> */
    public function images(int $productId, bool $withArchived = false): array
    {
        return $this->db->select('SELECT i.*, v.name AS variant_name, u.name AS uploaded_by_name FROM product_images i LEFT JOIN product_variants v ON v.id = i.variant_id
            LEFT JOIN users u ON u.id = i.uploaded_by WHERE i.product_id = ?' . ($withArchived ? '' : " AND i.status = 'active'") . ' ORDER BY i.status, i.is_primary DESC, i.variant_id, i.sort_order, i.id', [$productId]);
    }

    /**
     * @param array<string, mixed> $p @param array{name: string, tmp_name: string, error: int, size: int} $file @param array<string, mixed> $data @param array<string, mixed> $actor
     */
    public function uploadImage(array $p, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        if ((int) $this->db->scalar("SELECT COUNT(*) FROM product_images WHERE product_id = ? AND status = 'active'", [$p['id']]) >= self::MAX_IMAGES) {
            throw new DomainRuleException('A product can have at most ' . self::MAX_IMAGES . ' active images. Archive some first.');
        }
        $variantId = (int) ($data['variant_id'] ?? 0) ?: null;
        if ($variantId !== null && !$this->db->scalar("SELECT 1 FROM product_variants WHERE id = ? AND product_id = ? AND status <> 'archived'", [$variantId, $p['id']])) {
            throw ValidationException::withMessage('variant_id', 'Choose a variant of this product.');
        }
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'image', SecureFileStore::IMAGE_EXT);
        $size = @getimagesize((string) $file['tmp_name']);
        if ($size === false) {
            throw ValidationException::withMessage('image', 'This file is not a readable image.');
        }
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $hasPrimary = (bool) $this->db->scalar("SELECT 1 FROM product_images WHERE product_id = ? AND status = 'active' AND is_primary = 1 AND " . ($variantId === null ? 'variant_id IS NULL' : 'variant_id = ?'),
            array_merge([$p['id']], $variantId === null ? [] : [$variantId]));
        $caption = trim((string) ($data['caption'] ?? ''));
        $id = $this->db->insert('product_images', [
            'product_id' => (int) $p['id'], 'variant_id' => $variantId, 'original_name' => $info['original'], 'stored_name' => $saved['stored'], 'mime_type' => $info['mime'], 'size_bytes' => $info['size'],
            'width' => (int) $size[0], 'height' => (int) $size[1], 'caption' => $caption !== '' ? mb_substr($caption, 0, 255) : null, 'is_primary' => $hasPrimary ? 0 : 1,
            'sort_order' => (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM product_images WHERE product_id = ?', [$p['id']]),
            'status' => 'active', 'uploaded_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->log($p, 'image_uploaded', "Uploaded image \"{$info['original']}\" to {$p['product_code']}", ['image_id' => $id, 'file' => $info['original'], 'size' => $info['size'], 'variant_id' => $variantId], $actor, $variantId);
        return $id;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $img @param array<string, mixed> $actor */
    public function setPrimary(array $p, array $img, array $actor): void
    {
        if ($img['status'] !== 'active' || $p['status'] === 'archived') {
            throw new DomainRuleException('Only active images of products that are not archived can be made primary.');
        }
        $variantId = $img['variant_id'] !== null ? (int) $img['variant_id'] : null;
        $this->db->transaction(function () use ($p, $img, $variantId): void {
            $this->db->execute('UPDATE product_images SET is_primary = 0, updated_at = ? WHERE product_id = ? AND ' . ($variantId === null ? 'variant_id IS NULL' : 'variant_id = ?'),
                array_merge([now(), $p['id']], $variantId === null ? [] : [$variantId]));
            $this->db->update('product_images', ['is_primary' => 1, 'updated_at' => now()], ['id' => $img['id']]);
        });
        $this->log($p, 'image_primary', "Set the primary image of {$p['product_code']}" . ($variantId ? ' (variant)' : ''), ['image_id' => (int) $img['id'], 'variant_id' => $variantId], $actor, $variantId);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $img @param array<string, mixed> $actor */
    public function archiveImage(array $p, array $img, string $reason, array $actor): void
    {
        if ($img['status'] !== 'active') {
            throw new DomainRuleException('Already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->transaction(function () use ($img, $reason, $actor): void {
            $this->db->update('product_images', ['status' => 'archived', 'is_primary' => 0, 'archive_reason' => mb_substr($reason, 0, 255), 'archived_by' => (int) $actor['id'], 'archived_at' => now(), 'updated_at' => now()], ['id' => $img['id']]);
            if ((int) $img['is_primary'] === 1) {
                $next = $this->db->scalar("SELECT id FROM product_images WHERE product_id = ? AND status = 'active' AND " . ($img['variant_id'] === null ? 'variant_id IS NULL' : 'variant_id = ?') . ' ORDER BY sort_order, id LIMIT 1',
                    array_merge([$img['product_id']], $img['variant_id'] === null ? [] : [$img['variant_id']]));
                if ($next !== null) {
                    $this->db->update('product_images', ['is_primary' => 1], ['id' => $next]);
                }
            }
        });
        $this->log($p, 'image_archived', "Archived image \"{$img['original_name']}\" of {$p['product_code']}", ['image_id' => (int) $img['id'], 'reason' => $reason], $actor, $img['variant_id'] !== null ? (int) $img['variant_id'] : null);
    }

    /** Inline image (preview). */
    public function imageResponse(array $img): Response
    {
        return self::store()->response((string) $img['stored_name'], (string) $img['mime_type'], (string) $img['original_name'], true);
    }

    // ---------------------------------------------------------------- documents

    /** @return list<array<string, mixed>> */
    public function documents(int $productId, bool $withArchived = false): array
    {
        return $this->db->select('SELECT d.*, v.name AS variant_name, u.name AS uploaded_by_name FROM product_documents d LEFT JOIN product_variants v ON v.id = d.variant_id
            LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.product_id = ?' . ($withArchived ? '' : " AND d.status = 'active'") . ' ORDER BY d.status, d.category, d.created_at DESC', [$productId]);
    }

    /** @param array<string, mixed> $p @param array{name: string, tmp_name: string, error: int, size: int} $file @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function uploadDocument(array $p, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        $category = (string) ($data['category'] ?? '');
        if (!isset(self::CATEGORIES[$category])) {
            throw ValidationException::withMessage('category', 'Choose a document category.');
        }
        $variantId = (int) ($data['variant_id'] ?? 0) ?: null;
        if ($variantId !== null && !$this->db->scalar('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $p['id']])) {
            $variantId = null;
        }
        $expiry = trim((string) ($data['expiry_date'] ?? '')) ?: null;
        if ($expiry !== null && !\App\Support\BusinessDate::isValid($expiry)) {
            throw ValidationException::withMessage('expiry_date', 'Enter a valid date.');
        }
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'document');
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $title = trim((string) ($data['title'] ?? ''));
        $desc = trim((string) ($data['description'] ?? ''));
        $id = $this->db->insert('product_documents', [
            'product_id' => (int) $p['id'], 'variant_id' => $variantId, 'category' => $category, 'title' => mb_substr($title !== '' ? $title : self::CATEGORIES[$category], 0, 150),
            'description' => $desc !== '' ? mb_substr($desc, 0, 500) : null, 'original_name' => $info['original'], 'stored_name' => $saved['stored'], 'mime_type' => $info['mime'], 'size_bytes' => $info['size'],
            'expiry_date' => $expiry, 'status' => 'active', 'uploaded_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->log($p, 'document_uploaded', "Uploaded {$category} \"{$info['original']}\" to {$p['product_code']}", ['document_id' => $id, 'category' => $category, 'file' => $info['original'], 'size' => $info['size']], $actor, $variantId);
        return $id;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $doc @param array<string, mixed> $actor */
    public function archiveDocument(array $p, array $doc, string $reason, array $actor): void
    {
        if ($doc['status'] !== 'active') {
            throw new DomainRuleException('Already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('product_documents', ['status' => 'archived', 'archive_reason' => mb_substr($reason, 0, 255), 'archived_by' => (int) $actor['id'], 'archived_at' => now(), 'updated_at' => now()], ['id' => $doc['id']]);
        $this->log($p, 'document_archived', "Archived document \"{$doc['title']}\" of {$p['product_code']}", ['document_id' => (int) $doc['id'], 'reason' => $reason], $actor, null);
    }

    /** @param array<string, mixed> $doc @param array<string, mixed> $p */
    public function documentResponse(array $doc, array $p, bool $inline): Response
    {
        App::activity()->log($inline ? 'product.document_viewed' : 'product.document_downloaded', ($inline ? 'Viewed' : 'Downloaded') . " \"{$doc['original_name']}\" of {$p['product_code']}", 'product', (int) $p['id'],
            module: 'products', newValues: ['document_id' => (int) $doc['id']]);
        return self::store()->response((string) $doc['stored_name'], (string) $doc['mime_type'], (string) $doc['original_name'], $inline && in_array($doc['mime_type'], SecureFileStore::PREVIEWABLE, true));
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $values @param array<string, mixed> $actor */
    private function log(array $p, string $event, string $text, array $values, array $actor, ?int $variantId): void
    {
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $p['id'], $event, null, null, null, $values, null, null, $actor, $variantId);
        App::activity()->log('product.' . $event, $text, 'product', (int) $p['id'], module: 'products', newValues: $values);
    }
}
