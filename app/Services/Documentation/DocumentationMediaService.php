<?php

declare(strict_types=1);

namespace App\Services\Documentation;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Help & User Guide screenshots.
 *
 * - Uploads: PNG, JPG or WEBP only, max 5 MB. Extension, detected MIME type and
 *   a successful image decode must all agree; files containing script markers
 *   are rejected. Files get a random name under storage/uploads/documentation
 *   (outside public/) and are served only by HelpController.
 * - Bundled screenshots ship with the application in resources/help/screenshots
 *   and are referenced as "bundled:<file>" so seeding never copies files.
 * - Annotations are numbered markers stored as percentages of the image size.
 */
final class DocumentationMediaService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_MARKERS = 20;
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function uploadDirectory(): string
    {
        return App::basePath('storage/uploads/documentation');
    }

    public static function bundledDirectory(): string
    {
        return App::basePath('resources/help/screenshots');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $m = $this->db->first('SELECT * FROM documentation_media WHERE id = ?', [$id]);
        return $m === null ? null : DocumentationService::decodeMedia($m);
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @param array<string, mixed> $actor
     */
    public function store(int $guideId, array $file, string $caption, string $alt, array $actor, bool $httpUpload = true): int
    {
        if (!$this->db->scalar('SELECT 1 FROM documentation_guides WHERE id = ?', [$guideId])) {
            throw new DomainRuleException('Guide not found.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessage('screenshot', in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The image is too large.' : 'The upload failed. Please try again.');
        }
        if ($httpUpload && !is_uploaded_file($file['tmp_name'])) {
            throw ValidationException::withMessage('screenshot', 'Invalid upload.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessage('screenshot', 'Screenshots must be smaller than 5 MB.');
        }
        $original = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file['name'])))) ?: 'screenshot', 0, 200);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw ValidationException::withMessage('screenshot', 'Allowed image types: PNG, JPG, WEBP.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $info = @getimagesize($file['tmp_name']);
        $sample = (string) file_get_contents($file['tmp_name'], false, null, 0, 65536);
        if ($mime !== self::TYPES[$ext] || $info === false || preg_match('/<\?php|<\?=|<script\b/i', $sample) === 1) {
            throw ValidationException::withMessage('screenshot', 'The file is not a valid ' . strtoupper($ext) . ' image.');
        }

        $stored = gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $target = self::uploadDirectory() . '/' . $stored;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
            throw new DomainRuleException('Screenshot storage is not writable.');
        }
        if (!($httpUpload ? move_uploaded_file($file['tmp_name'], $target) : copy($file['tmp_name'], $target))) {
            throw new DomainRuleException('The screenshot could not be saved.');
        }
        @chmod($target, 0640);
        $id = $this->db->insert('documentation_media', [
            'guide_id' => $guideId, 'stored_name' => $stored, 'original_name' => $original, 'mime_type' => $mime, 'size_bytes' => $size,
            'width' => (int) $info[0], 'height' => (int) $info[1], 'caption' => self::clip($caption, 300), 'alt_text' => self::clip($alt, 300),
            'annotations' => null, 'sort_order' => 10 + (int) $this->db->scalar('SELECT COALESCE(MAX(sort_order), 0) FROM documentation_media WHERE guide_id = ?', [$guideId]),
            'uploaded_by' => (int) $actor['id'], 'created_at' => now(),
        ]);
        App::activity()->log('documentation.screenshot_uploaded', "Uploaded screenshot \"{$original}\"", 'documentation_media', $id, module: 'documentation',
            newValues: ['guide_id' => $guideId, 'file' => $original, 'size' => $size]);
        return $id;
    }

    /**
     * Update caption, alt text and numbered annotations.
     * @param list<array{x: mixed, y: mixed, label: mixed}>|string $annotations array or JSON from the editor
     * @param array<string, mixed> $actor
     */
    public function updateMeta(array $media, string $caption, string $alt, array|string $annotations, int $sort, array $actor): void
    {
        $clean = self::cleanAnnotations(is_string($annotations) ? (json_decode($annotations, true) ?: []) : $annotations);
        $new = ['caption' => self::clip($caption, 300), 'alt_text' => self::clip($alt, 300), 'annotations' => $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'sort_order' => $sort];
        $this->db->update('documentation_media', $new, ['id' => (int) $media['id']]);
        App::activity()->log('documentation.screenshot_updated', 'Updated screenshot caption and annotations', 'documentation_media', (int) $media['id'], module: 'documentation',
            oldValues: ['caption' => $media['caption'], 'annotations' => $media['annotations']], newValues: ['caption' => $new['caption'], 'annotations' => $clean], userId: (int) $actor['id']);
    }

    /** @param array<string, mixed> $actor */
    public function delete(array $media, array $actor): void
    {
        $this->db->delete('documentation_media', ['id' => (int) $media['id']]); // steps keep working: media_id is SET NULL
        $this->removeFile($media);
        App::activity()->log('documentation.screenshot_deleted', "Deleted screenshot \"{$media['original_name']}\"", 'documentation_media', (int) $media['id'], module: 'documentation',
            oldValues: ['guide_id' => (int) $media['guide_id'], 'file' => $media['original_name'], 'caption' => $media['caption']], userId: (int) $actor['id']);
    }

    /** Delete an uploaded file from disk. Bundled screenshots are part of the application and are never deleted. */
    public function removeFile(array $media): void
    {
        $name = (string) $media['stored_name'];
        if (str_starts_with($name, 'bundled:')) {
            return;
        }
        $path = $this->resolve($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Stream an image. The caller has already decided the user may see it. */
    public function response(array $media): Response
    {
        $path = $this->resolve((string) $media['stored_name']) ?? throw new HttpException(404, 'Screenshot not found.');
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => (string) $media['mime_type'],
            'Content-Length' => (string) filesize($path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** Absolute path inside the allowed directory, or null (blocks path traversal). */
    private function resolve(string $stored): ?string
    {
        [$base, $rel] = str_starts_with($stored, 'bundled:') ? [self::bundledDirectory(), substr($stored, 8)] : [self::uploadDirectory(), $stored];
        $real = realpath($base . '/' . $rel);
        $root = realpath($base);
        return $real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }

    /** @return list<array{x: float, y: float, label: string}> */
    public static function decodeAnnotations(string $json): array
    {
        if ($json === '') {
            return [];
        }
        return self::cleanAnnotations(json_decode($json, true) ?: []);
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return list<array{x: float, y: float, label: string}>
     */
    public static function cleanAnnotations(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !is_numeric($r['x'] ?? null) || !is_numeric($r['y'] ?? null)) {
                continue;
            }
            $out[] = [
                'x' => round(max(0.0, min(100.0, (float) $r['x'])), 2),
                'y' => round(max(0.0, min(100.0, (float) $r['y'])), 2),
                'label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 160),
            ];
            if (count($out) >= self::MAX_MARKERS) {
                break;
            }
        }
        return $out;
    }

    private static function clip(string $v, int $len): ?string
    {
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $len);
    }
}
