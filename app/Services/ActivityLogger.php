<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

/**
 * Reusable, append-only audit logger for every module.
 *
 *   App::activity()->log('user.created', 'Created user Asha', 'user', 12, ['roles' => ['viewer']]);
 *
 * The current user, IP address and user agent are captured automatically.
 * Secrets in $properties are redacted. Logging never breaks the calling flow:
 * a failure is written to the error log instead.
 */
final class ActivityLogger
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $properties */
    public function log(
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $properties = [],
        ?int $userId = null,
    ): void {
        try {
            $request = App::request();
            $userId ??= App::auth()->id();
            $this->db->insert('activity_logs', [
                'user_id' => $userId,
                'action' => mb_substr($action, 0, 100),
                'description' => mb_substr($description, 0, 500),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'properties' => $properties === [] ? null : json_encode(\App\Core\Logger::redact($properties), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'ip_address' => $request?->ip() ?? (PHP_SAPI === 'cli' ? '127.0.0.1' : null),
                'user_agent' => $request?->userAgent() ?? (PHP_SAPI === 'cli' ? 'console' : null),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Logger::error('Activity log write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }
}
