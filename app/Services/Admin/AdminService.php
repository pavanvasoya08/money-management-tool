<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;

/**
 * Base for Super Admin control services. Every public mutator re-checks that
 * the actor is a Super Admin (defence in depth on top of route middleware)
 * and writes a structured audit entry.
 */
abstract class AdminService
{
    protected Database $db;

    public function __construct()
    {
        $this->db = App::db();
    }

    /** @param array<string, mixed> $actor */
    protected function requireSuperAdmin(array $actor): void
    {
        if (!App::gate()->isSuperAdmin($actor) || !\App\Services\Authorization\AccountState::usable($actor)) {
            throw new AuthorizationException('super_admin', 'Only an active Super Admin can change access control.');
        }
    }

    /** Users may never change their own permissions or privileges. @param array<string, mixed> $actor */
    protected function forbidSelf(array $actor, int $targetUserId): void
    {
        if ((int) $actor['id'] === $targetUserId) {
            throw new DomainRuleException('You cannot change your own permissions or access. Ask another Super Admin.');
        }
    }

    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new */
    protected function audit(string $action, string $description, ?string $subjectType, ?int $subjectId, ?array $old, ?array $new, ?int $affectedUser = null, ?int $affectedRole = null, string $module = 'super_admin'): void
    {
        App::activity()->log($action, $description, $subjectType, $subjectId, [], null, $module, $old, $new, $affectedUser, $affectedRole);
    }

    /** @param list<int|string> $ids @return list<int> */
    protected static function intIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v): bool => is_scalar($v) && ctype_digit((string) $v) && (int) $v > 0))));
    }

    /** @return array<string, mixed> */
    protected function role(int $id): array
    {
        return $this->db->first('SELECT * FROM roles WHERE id = ?', [$id]) ?? throw new DomainRuleException('Role not found.');
    }

    /** @return array<string, mixed> */
    protected function module(string $key): array
    {
        return $this->db->first('SELECT * FROM modules WHERE module_key = ?', [$key]) ?? throw new DomainRuleException('Unknown module.');
    }

    protected function flush(): void
    {
        App::flushAuthorization();
    }
}
