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
 *   // structured change (Phase 2+): who changed what, from which value to which value
 *   App::activity()->log('role.permissions_changed', 'Updated Manager permissions', 'role', $roleId,
 *       module: 'super_admin', oldValues: ['granted' => $before], newValues: ['granted' => $after],
 *       affectedRoleId: $roleId);
 *
 * The current user, IP address and user agent are captured automatically.
 * Secrets in $properties are redacted. Logging never breaks the calling flow:
 * a failure is written to the error log instead.
 */
final class ActivityLogger
{
    private ?bool $structured = null;

    public function __construct(private readonly Database $db)
    {
    }

    /** Structured audit columns exist once the Phase 2 migration has run. */
    private function structured(): bool
    {
        if ($this->structured === null) {
            try {
                $this->db->scalar('SELECT module FROM activity_logs WHERE 1 = 0');
                $this->structured = true;
            } catch (Throwable) {
                $this->structured = false;
            }
        }
        return $this->structured;
    }

    /** @param array<string, mixed> $properties */
    public function log(
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $properties = [],
        ?int $userId = null,
        ?string $module = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $affectedUserId = null,
        ?int $affectedRoleId = null,
    ): void {
        try {
            $request = App::request();
            $userId ??= App::auth()->id();
            if ($affectedUserId === null && $subjectType === 'user' && $subjectId !== null) {
                $affectedUserId = $subjectId;
            }
            if ($affectedRoleId === null && $subjectType === 'role' && $subjectId !== null) {
                $affectedRoleId = $subjectId;
            }
            $json = static fn (?array $v): ?string => $v === null ? null
                : json_encode(Logger::redact($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
            ] + ($this->structured() ? [
                'module' => $module ?? strtok($action, '.'),
                'old_values' => $json($oldValues),
                'new_values' => $json($newValues),
                'affected_user_id' => $affectedUserId,
                'affected_role_id' => $affectedRoleId,
            ] : []));
        } catch (Throwable $e) {
            Logger::error('Activity log write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }
}
