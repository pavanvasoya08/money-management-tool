<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\App;
use App\Exceptions\DomainRuleException;

/**
 * Optional self-service access requests (Security settings → enable).
 * Approval creates an explicit, optionally expiring, Allow override.
 */
final class AccessRequestService extends AdminService
{
    public static function enabled(): bool
    {
        return (bool) App::settings()->get('access_requests_enabled', false);
    }

    /** @param array<string, mixed> $user */
    public function request(array $user, string $permissionSlug, string $reason): int
    {
        if (!self::enabled()) {
            throw new DomainRuleException('Access requests are not enabled.');
        }
        $perm = $this->db->first('SELECT p.id, p.name FROM permissions p JOIN modules m ON m.id = p.module_id WHERE p.slug = ? AND m.super_admin_only = 0', [$permissionSlug])
            ?? throw new DomainRuleException('This permission cannot be requested.');
        if (App::gate()->allows($user, $permissionSlug)) {
            throw new DomainRuleException('You already have this permission.');
        }
        if ($this->db->scalar("SELECT 1 FROM access_requests WHERE user_id = ? AND permission_id = ? AND status = 'pending'", [$user['id'], $perm['id']])) {
            throw new DomainRuleException('You already have a pending request for this permission.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainRuleException('Please explain briefly why you need access.');
        }
        $id = $this->db->insert('access_requests', [
            'user_id' => (int) $user['id'], 'permission_id' => (int) $perm['id'], 'reason' => mb_substr($reason, 0, 500),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        App::activity()->log('access_request.created', "Requested access: {$perm['name']}", 'access_request', $id, module: 'super_admin', affectedUserId: (int) $user['id']);
        return $id;
    }

    /** @param array<string, mixed> $actor */
    public function decide(int $requestId, bool $approve, array $actor, ?string $note = null, ?string $expiresAt = null): void
    {
        $this->requireSuperAdmin($actor);
        $req = $this->db->first('SELECT r.*, p.slug FROM access_requests r JOIN permissions p ON p.id = r.permission_id WHERE r.id = ?', [$requestId])
            ?? throw new DomainRuleException('Request not found.');
        if ($req['status'] !== 'pending') {
            throw new DomainRuleException('This request was already decided.');
        }
        $this->forbidSelf($actor, (int) $req['user_id']);
        if ($approve) {
            (new PermissionService())->setUserOverrides((int) $req['user_id'], [(int) $req['permission_id'] => 'allow'], $actor, $expiresAt, 'Access request #' . $requestId);
        }
        $this->db->update('access_requests', [
            'status' => $approve ? 'approved' : 'rejected', 'decided_by' => (int) $actor['id'], 'decided_at' => now(),
            'decision_note' => $note, 'grant_expires_at' => $approve ? $expiresAt : null, 'updated_at' => now(),
        ], ['id' => $requestId]);
        $this->audit('access_request.' . ($approve ? 'approved' : 'rejected'), ($approve ? 'Approved' : 'Rejected') . " access request for {$req['slug']}", 'access_request', $requestId,
            ['status' => 'pending'], ['status' => $approve ? 'approved' : 'rejected', 'permission' => $req['slug'], 'expires_at' => $expiresAt, 'note' => $note], affectedUser: (int) $req['user_id']);
    }

    public function pendingCount(): int
    {
        return (int) $this->db->scalar("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'");
    }
}
