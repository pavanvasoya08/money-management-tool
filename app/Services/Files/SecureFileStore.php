<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Core\Response;
use App\Exceptions\DomainRuleException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Private file storage shared by partner documents (Phase 3) and GST documents
 * (Phase 4):
 *
 * - extension allow-list, detected MIME type must match the extension,
 *   content sniffing (PDF header, decodable image, Office zip) and a scan for
 *   script markers;
 * - size limit; random stored names in YYYY/MM folders under storage/ (never public/);
 * - downloads resolve the real path and refuse anything outside the base
 *   directory (no directory traversal), with no-store / nosniff / sandbox headers.
 */
final class SecureFileStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** extension => allowed MIME types (as detected by finfo) */
    public const TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        // Bank statements (Phase 5). Never offered in a generic document picker unless asked for via $onlyExt.
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'text/x-comma-separated-values'],
    ];
    /** Extensions accepted when a caller does not restrict them (CSV only where explicitly requested). */
    public const DEFAULT_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx'];
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    /** Types a browser can safely preview inline (sandboxed). */
    public const PREVIEWABLE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly string $directory, private readonly int $maxBytes = self::MAX_BYTES)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Validate an upload. Returns the safe original name, extension, normalised MIME type and size.
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @param list<string>|null $onlyExt restrict to these extensions (e.g. images only)
     * @return array{original: string, ext: string, mime: string, size: int}
     */
    public function validate(array $file, bool $httpUpload = true, string $field = 'document', ?array $onlyExt = null): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessage($field, $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'The file is too large.' : 'The upload failed. Please try again.');
        }
        if ($httpUpload && !is_uploaded_file($file['tmp_name'])) {
            throw ValidationException::withMessage($field, 'Invalid upload.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw ValidationException::withMessage($field, 'Files must be between 1 byte and ' . ($this->maxBytes / 1024 / 1024) . ' MB.');
        }
        $original = self::safeOriginalName($file['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = $onlyExt ?? self::DEFAULT_EXT;
        if (!isset(self::TYPES[$ext]) || !in_array($ext, $allowed, true)) {
            throw ValidationException::withMessage($field, 'Allowed file types: ' . strtoupper(implode(', ', array_unique(array_map(static fn (string $e): string => $e === 'jpeg' ? 'jpg' : $e, $allowed)))) . '.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, self::TYPES[$ext], true)) {
            throw ValidationException::withMessage($field, "The file content ({$mime}) does not match its .{$ext} extension.");
        }
        $this->assertContent($file['tmp_name'], $ext, $field);
        return ['original' => $original, 'ext' => $ext, 'mime' => in_array($mime, ['application/zip', 'application/octet-stream'], true) ? self::officeMime($ext) : $mime, 'size' => $size];
    }

    /**
     * Move a validated upload into storage under a random name.
     * @param array{tmp_name: string} $file
     * @return array{stored: string, sha256: string}
     */
    public function save(array $file, string $ext, bool $httpUpload = true): array
    {
        $folder = gmdate('Y/m');
        $dir = $this->directory . '/' . $folder;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new DomainRuleException('Document storage is not writable.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $dir . '/' . $name;
        $ok = $httpUpload ? move_uploaded_file($file['tmp_name'], $target) : copy($file['tmp_name'], $target);
        if (!$ok) {
            throw new DomainRuleException('The document could not be saved.');
        }
        @chmod($target, 0640);
        return ['stored' => $folder . '/' . $name, 'sha256' => (string) hash_file('sha256', $target)];
    }

    /** Absolute path of a stored file, or null if it is missing or outside the storage directory. */
    public function path(string $stored): ?string
    {
        $real = realpath($this->directory . '/' . $stored);
        $base = realpath($this->directory);
        return $real !== false && $base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }

    public function response(string $stored, string $mime, string $originalName, bool $inline = false): Response
    {
        $real = $this->path($stored) ?? throw new HttpException(404, 'The file is no longer available.');
        $inline = $inline && in_array($mime, self::PREVIEWABLE, true);
        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $originalName) ?: 'document';
        return new Response((string) file_get_contents($real), 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($real),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($originalName),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
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

    private function assertContent(string $path, string $ext, string $field): void
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $bad = match (true) {
            $ext === 'pdf' => !str_starts_with($head, '%PDF-'),
            in_array($ext, self::IMAGE_EXT, true) => @getimagesize($path) === false,
            in_array($ext, ['docx', 'xlsx'], true) => !str_starts_with($head, "PK\x03\x04"),
            // CSV must be plain text: no executable / archive / PDF signature and no NUL bytes.
            $ext === 'csv' => preg_match('/^(MZ|\x7fELF|PK\x03\x04|%PDF)/', $head) === 1 || str_contains((string) file_get_contents($path, false, null, 0, 65536), "\0"),
            default => true,
        };
        // Reject anything that carries server-side script markers, whatever the type.
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        if ($bad || preg_match('/<\?php|<\?=|<script\b/i', $sample) === 1) {
            throw ValidationException::withMessage($field, 'The file content is not a valid ' . strtoupper($ext) . ' document.');
        }
    }

    private static function officeMime(string $ext): string
    {
        return $ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
