<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Services\UserService;

/**
 * Partner master data and login linking.
 * A partner is a business entity; an ERP login (users row) is optional and
 * linked 1:1 (unique partners.user_id). Linking never changes roles or
 * permissions — access always comes from Phase 2 authorization.
 */
final class PartnerService
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended', 'archived' => 'Archived'];
    private const PROFILE_FIELDS = ['name', 'email', 'mobile', 'address', 'city', 'state', 'country', 'pin_code', 'pan_number', 'date_of_joining', 'notes'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT p.*, c.account_code, c.id AS capital_account_id, u.name AS user_name, u.email AS user_email, u.status AS user_status,
                    u.suspended_at AS user_suspended_at, u.deleted_at AS user_deleted_at, cb.name AS created_by_name
             FROM partners p
             LEFT JOIN partner_capital_accounts c ON c.partner_id = p.id
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN users cb ON cb.id = p.created_by
             WHERE p.id = ?',
            [$id]
        );
    }

    /**
     * @param array{q?: string, status?: string} $filters
     * @param array{0: string, 1: list<mixed>} $scope record-level visibility from DataAccess
     */
    public function paginate(array $filters, int $page, int $perPage, array $scope): Paginator
    {
        [$where, $params] = $this->where($filters, $scope);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM partners p WHERE {$where}", $params);
        $perPage = max(1, min(500, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT p.*, u.name AS user_name FROM partners p LEFT JOIN users u ON u.id = p.user_id
             WHERE {$where} ORDER BY p.name, p.id LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return new Paginator($rows, $total, max(1, $page), $perPage);
    }

    /** @param array{q?: string, status?: string} $filters @param array{0: string, 1: list<mixed>} $scope @return list<array<string, mixed>> */
    public function all(array $filters, array $scope, int $limit = 5000): array
    {
        [$where, $params] = $this->where($filters, $scope);
        return $this->db->select("SELECT p.*, u.name AS user_name FROM partners p LEFT JOIN users u ON u.id = p.user_id WHERE {$where} ORDER BY p.name, p.id LIMIT " . max(1, $limit), $params);
    }

    /** Partners for pickers (id, code, name, status), limited by scope. @param array{0: string, 1: list<mixed>} $scope */
    public function options(array $scope, bool $includeArchived = false): array
    {
        [$where, $params] = $scope;
        $sql = "SELECT p.id, p.partner_code, p.name, p.status FROM partners p WHERE {$where}" . ($includeArchived ? '' : " AND p.status <> 'archived'") . ' ORDER BY p.name';
        return $this->db->select($sql, $params);
    }

    /** @param array{0: string, 1: list<mixed>} $scope @return array{total: int, active: int, inactive: int, suspended: int, archived: int} */
    public function counts(array $scope): array
    {
        [$where, $params] = $scope;
        $row = $this->db->first(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended,
                SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS archived
             FROM partners p WHERE {$where}",
            $params
        ) ?? [];
        return array_map('intval', ['total' => $row['total'] ?? 0, 'active' => $row['active'] ?? 0, 'inactive' => $row['inactive'] ?? 0, 'suspended' => $row['suspended'] ?? 0, 'archived' => $row['archived'] ?? 0]);
    }

    /** @param array<string, mixed> $data validated profile fields @param array<string, mixed> $actor */
    public function create(array $data, array $actor): int
    {
        $data = $this->normalise($data);
        $this->assertUniquePan($data['pan_number'] ?? null, null);
        $id = $this->db->transaction(function (Database $db) use ($data, $actor): int {
            $now = now();
            $code = $this->nextCode();
            $id = $db->insert('partners', array_intersect_key($data, array_flip(self::PROFILE_FIELDS)) + [
                'partner_code' => $code, 'status' => 'active', 'created_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('partner_capital_accounts', ['partner_id' => $id, 'account_code' => 'CAP-' . $code, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
            return $id;
        });
        $p = $this->find($id);
        App::activity()->log('partner.created', "Created partner {$p['partner_code']} {$p['name']}", 'partner', $id, module: 'partners', newValues: self::auditView($p));
        return $id;
    }

    /** @param array<string, mixed> $partner @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function update(array $partner, array $data, array $actor): void
    {
        $this->assertNotArchived($partner);
        $data = array_intersect_key($this->normalise($data), array_flip(self::PROFILE_FIELDS));
        if (array_key_exists('pan_number', $data)) {
            $this->assertUniquePan($data['pan_number'], (int) $partner['id']);
        }
        $changes = [];
        foreach ($data as $k => $v) {
            if ((string) ($partner[$k] ?? '') !== (string) ($v ?? '')) {
                $changes[$k] = $v;
            }
        }
        if ($changes === []) {
            return;
        }
        $this->db->update('partners', $changes + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $partner['id']]);
        App::activity()->log('partner.updated', "Updated partner {$partner['partner_code']} {$partner['name']}", 'partner', (int) $partner['id'], module: 'partners',
            oldValues: self::auditView(array_intersect_key($partner, $changes)), newValues: self::auditView($changes));
    }

    /** Activate / deactivate. @param array<string, mixed> $partner @param array<string, mixed> $actor */
    public function setStatus(array $partner, string $status, ?string $reason, array $actor): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new DomainRuleException('Invalid status.');
        }
        $this->assertNotArchived($partner);
        $this->changeStatus($partner, $status, $reason, $actor, $status === 'active' ? 'partner.activated' : 'partner.deactivated');
    }

    /**
     * Suspend partner access: the partner record is suspended (no new capital
     * entries) and, when a login is linked and the actor may suspend users,
     * the login is suspended and signed out too.
     * @param array<string, mixed> $partner @param array<string, mixed> $actor
     * @return bool whether the linked login was suspended as well
     */
    public function suspend(array $partner, string $reason, array $actor): bool
    {
        $this->assertNotArchived($partner);
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required to suspend a partner.');
        }
        $this->changeStatus($partner, 'suspended', $reason, $actor, 'partner.suspended');
        return $this->suspendLinkedLogin($partner, $reason, $actor, quiet: true);
    }

    /** @param array<string, mixed> $partner @param array<string, mixed> $actor */
    public function reactivate(array $partner, array $actor): void
    {
        if ($partner['status'] !== 'suspended') {
            throw new DomainRuleException('Only a suspended partner can be reactivated.');
        }
        $this->changeStatus($partner, 'active', null, $actor, 'partner.reactivated');
    }

    /** Archive: hidden from day-to-day lists; history kept. Requires no open entries and zero balance. */
    public function archive(array $partner, string $reason, array $actor): void
    {
        $this->assertNotArchived($partner);
        $open = (int) $this->db->scalar("SELECT COUNT(*) FROM partner_capital_transactions WHERE partner_id = ? AND status IN ('draft','pending_approval','approved')", [$partner['id']]);
        if ($open > 0) {
            throw new DomainRuleException("This partner has {$open} capital entr" . ($open === 1 ? 'y' : 'ies') . ' that are not posted, cancelled or rejected. Finish or cancel them before archiving.');
        }
        $balance = App::capitalLedger()->balance((int) $partner['id']);
        if ($balance !== 0) {
            throw new DomainRuleException('The partner still has a posted capital balance of ' . \App\Support\Money::format($balance) . '. Settle it through a posted withdrawal or adjustment before archiving.');
        }
        $this->db->update('partners', ['status' => 'archived', 'status_reason' => mb_substr($reason, 0, 255) ?: null, 'archived_at' => now(), 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $partner['id']]);
        $this->db->update('partner_capital_accounts', ['status' => 'closed', 'updated_at' => now()], ['partner_id' => (int) $partner['id']]);
        App::activity()->log('partner.archived', "Archived partner {$partner['partner_code']} {$partner['name']}", 'partner', (int) $partner['id'], module: 'partners',
            oldValues: ['status' => $partner['status']], newValues: ['status' => 'archived', 'reason' => $reason]);
    }

    /** @param array<string, mixed> $partner @param array<string, mixed> $actor */
    public function restore(array $partner, array $actor): void
    {
        if ($partner['status'] !== 'archived') {
            throw new DomainRuleException('Only an archived partner can be restored.');
        }
        $this->db->update('partners', ['status' => 'inactive', 'status_reason' => null, 'archived_at' => null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $partner['id']]);
        $this->db->update('partner_capital_accounts', ['status' => 'open', 'updated_at' => now()], ['partner_id' => (int) $partner['id']]);
        App::activity()->log('partner.restored', "Restored partner {$partner['partner_code']} {$partner['name']} (inactive)", 'partner', (int) $partner['id'], module: 'partners',
            oldValues: ['status' => 'archived'], newValues: ['status' => 'inactive']);
    }

    // ---------------------------------------------------------------- login linking

    /** @param array<string, mixed> $partner @param array<string, mixed> $actor */
    public function link(array $partner, int $userId, array $actor, ?string $note = null): void
    {
        $this->assertNotArchived($partner);
        if ($partner['user_id'] !== null) {
            throw new DomainRuleException('This partner already has a linked login. Unlink it first.');
        }
        $user = User::find($userId);
        if ($user === null || !empty($user['deleted_at'])) {
            throw ValidationException::withMessage('user_id', 'Choose an existing, non-deleted user.');
        }
        $other = $this->db->first('SELECT partner_code, name FROM partners WHERE user_id = ?', [$userId]);
        if ($other !== null) {
            // Business rule: one login belongs to at most one partner profile.
            throw ValidationException::withMessage('user_id', "{$user['name']} is already linked to partner {$other['partner_code']} {$other['name']}.");
        }
        $this->db->transaction(function (Database $db) use ($partner, $userId, $actor, $note): void {
            $changed = $db->execute('UPDATE partners SET user_id = ?, updated_by = ?, updated_at = ? WHERE id = ? AND user_id IS NULL', [$userId, (int) $actor['id'], now(), (int) $partner['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException('The partner was linked by someone else meanwhile. Please refresh.');
            }
            $this->history($db, (int) $partner['id'], $userId, 'linked', $note, $actor);
        });
        App::activity()->log('partner.login_linked', "Linked login {$user['name']} to partner {$partner['partner_code']}", 'partner', (int) $partner['id'],
            module: 'partners', oldValues: ['user_id' => null], newValues: ['user_id' => $userId, 'user' => $user['name']], affectedUserId: $userId);
    }

    /** @param array<string, mixed> $partner @param array<string, mixed> $actor */
    public function unlink(array $partner, array $actor, ?string $note = null): void
    {
        if ($partner['user_id'] === null) {
            throw new DomainRuleException('No login is linked to this partner.');
        }
        $userId = (int) $partner['user_id'];
        $this->db->transaction(function (Database $db) use ($partner, $userId, $actor, $note): void {
            $db->execute('UPDATE partners SET user_id = NULL, updated_by = ?, updated_at = ? WHERE id = ?', [(int) $actor['id'], now(), (int) $partner['id']]);
            $this->history($db, (int) $partner['id'], $userId, 'unlinked', $note, $actor);
        });
        App::flushAuthorization();
        App::activity()->log('partner.login_unlinked', "Unlinked login from partner {$partner['partner_code']}", 'partner', (int) $partner['id'],
            module: 'partners', oldValues: ['user_id' => $userId], newValues: ['user_id' => null, 'note' => $note], affectedUserId: $userId);
    }

    /**
     * Create an ERP login for the partner and link it. Only the limited
     * "Partner" role is offered, and only when the actor may assign roles.
     * @param array{email: string, password: string, assign_partner_role: bool} $data
     * @param array<string, mixed> $partner @param array<string, mixed> $actor
     */
    public function createLogin(array $partner, array $data, array $actor): int
    {
        $this->assertNotArchived($partner);
        if ($partner['user_id'] !== null) {
            throw new DomainRuleException('This partner already has a linked login.');
        }
        $roles = [];
        if ($data['assign_partner_role']) {
            $roleId = $this->db->scalar("SELECT id FROM roles WHERE slug = 'partner' AND is_active = 1");
            if ($roleId === null) {
                throw new DomainRuleException('The Partner role is missing or inactive.');
            }
            $roles[] = (int) $roleId;
        }
        $userId = (new UserService())->create([
            'name' => $partner['name'], 'email' => $data['email'], 'phone' => $partner['mobile'] ?? null,
            'password' => $data['password'], 'roles' => $roles,
        ], $actor);
        $this->db->transaction(function (Database $db) use ($partner, $userId, $actor, $roles): void {
            $db->execute('UPDATE partners SET user_id = ?, updated_by = ?, updated_at = ? WHERE id = ? AND user_id IS NULL', [$userId, (int) $actor['id'], now(), (int) $partner['id']]);
            $this->history($db, (int) $partner['id'], $userId, 'login_created', $roles === [] ? 'No role assigned' : 'Partner role assigned', $actor);
        });
        App::activity()->log('partner.login_created', "Created and linked a login for partner {$partner['partner_code']}", 'partner', (int) $partner['id'],
            module: 'partners', newValues: ['user_id' => $userId, 'email' => $data['email'], 'partner_role' => $roles !== []], affectedUserId: $userId);
        return $userId;
    }

    /** @param array<string, mixed> $partner @param array<string, mixed> $actor */
    public function suspendLinkedLogin(array $partner, string $reason, array $actor, bool $quiet = false): bool
    {
        if ($partner['user_id'] === null) {
            if ($quiet) {
                return false;
            }
            throw new DomainRuleException('No login is linked to this partner.');
        }
        $user = User::find((int) $partner['user_id']);
        if ($user === null || !App::gate()->allows($actor, 'users.suspend') || (int) $user['id'] === (int) $actor['id'] || !empty($user['suspended_at'])) {
            if ($quiet) {
                return false;
            }
            throw new DomainRuleException(!empty($user['suspended_at'] ?? null) ? 'The login is already suspended.' : 'You are not allowed to suspend this login.');
        }
        (new UserService())->suspend($user, null, $reason !== '' ? $reason : 'Partner access suspended', $actor);
        $this->history($this->db, (int) $partner['id'], (int) $user['id'], 'login_suspended', $reason, $actor);
        return true;
    }

    /** @param array<string, mixed> $partner @param array<string, mixed> $actor */
    public function reactivateLinkedLogin(array $partner, array $actor): void
    {
        $user = $partner['user_id'] !== null ? User::find((int) $partner['user_id']) : null;
        if ($user === null || empty($user['suspended_at'])) {
            throw new DomainRuleException('The linked login is not suspended.');
        }
        if (!App::gate()->allows($actor, 'users.suspend')) {
            throw new DomainRuleException('You are not allowed to reactivate logins.');
        }
        (new UserService())->unsuspend($user, $actor);
        $this->history($this->db, (int) $partner['id'], (int) $user['id'], 'login_reactivated', null, $actor);
    }

    /** @return list<array<string, mixed>> */
    public function linkHistory(int $partnerId): array
    {
        return $this->db->select(
            'SELECT h.*, u.name AS user_name, a.name AS actor_name FROM partner_account_links h JOIN users u ON u.id = h.user_id JOIN users a ON a.id = h.acted_by WHERE h.partner_id = ? ORDER BY h.id DESC',
            [$partnerId]
        );
    }

    /** Users that can be linked: not deleted and not linked to another partner. @return list<array<string, mixed>> */
    public function linkableUsers(): array
    {
        return $this->db->select('SELECT u.id, u.name, u.email FROM users u WHERE u.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM partners p WHERE p.user_id = u.id) ORDER BY u.name');
    }

    /** Partner linked to a login, if any. @return array<string, mixed>|null */
    public function forUser(int $userId): ?array
    {
        return $this->db->first('SELECT * FROM partners WHERE user_id = ?', [$userId]);
    }

    // ---------------------------------------------------------------- helpers

    public static function validatePan(?string $pan): bool
    {
        // 5 letters, 4 digits, 1 letter; 4th letter is the holder type (P, F, C, H, A, T, B, L, J, G).
        return $pan === null || $pan === '' || preg_match('/^[A-Z]{3}[PFCHATBLJG][A-Z][0-9]{4}[A-Z]$/', $pan) === 1;
    }

    /** Audit-safe view of a partner: PAN reduced to its last 4 characters. @param array<string, mixed> $p @return array<string, mixed> */
    public static function auditView(array $p): array
    {
        $out = array_intersect_key($p, array_flip(array_merge(self::PROFILE_FIELDS, ['partner_code', 'status'])));
        if (!empty($out['pan_number'])) {
            $out['pan_number'] = 'XXXXXX' . substr((string) $out['pan_number'], -4);
        }
        return $out;
    }

    /** @param array<string, mixed> $partner */
    public function assertNotArchived(array $partner): void
    {
        if ($partner['status'] === 'archived') {
            throw new DomainRuleException('Archived partners are read-only. Restore the partner first.');
        }
    }

    private function nextCode(): string
    {
        $max = 0;
        foreach ($this->db->select("SELECT partner_code FROM partners WHERE partner_code LIKE 'PTR-%'") as $r) {
            $max = max($max, (int) substr((string) $r['partner_code'], 4));
        }
        return 'PTR-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalise(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $v = trim($v);
                $data[$k] = $v === '' ? null : $v;
            }
        }
        if (isset($data['pan_number'])) {
            $data['pan_number'] = strtoupper((string) $data['pan_number']);
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower((string) $data['email']);
        }
        if (array_key_exists('country', $data) && $data['country'] === null) {
            $data['country'] = 'India';
        }
        return $data;
    }

    private function assertUniquePan(?string $pan, ?int $exceptId): void
    {
        if ($pan === null || $pan === '') {
            return;
        }
        if (!self::validatePan($pan)) {
            throw ValidationException::withMessage('pan_number', 'PAN must look like ABCPE1234F (5 letters, 4 digits, 1 letter).');
        }
        $other = $this->db->scalar('SELECT id FROM partners WHERE pan_number = ?' . ($exceptId ? ' AND id <> ' . (int) $exceptId : ''), [$pan]);
        if ($other !== null) {
            throw ValidationException::withMessage('pan_number', 'Another partner already uses this PAN.');
        }
    }

    /** @param array<string, mixed> $partner @param array<string, mixed> $actor */
    private function changeStatus(array $partner, string $status, ?string $reason, array $actor, string $action): void
    {
        if ($partner['status'] === $status) {
            return;
        }
        $this->db->update('partners', ['status' => $status, 'status_reason' => $reason !== null ? (mb_substr($reason, 0, 255) ?: null) : null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $partner['id']]);
        App::activity()->log($action, ucfirst(self::STATUSES[$status]) . " partner {$partner['partner_code']} {$partner['name']}", 'partner', (int) $partner['id'], module: 'partners',
            oldValues: ['status' => $partner['status']], newValues: ['status' => $status, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $actor */
    private function history(Database $db, int $partnerId, int $userId, string $action, ?string $note, array $actor): void
    {
        $db->insert('partner_account_links', ['partner_id' => $partnerId, 'user_id' => $userId, 'action' => $action, 'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'acted_by' => (int) $actor['id'], 'created_at' => now()]);
    }

    /** @param array{q?: string, status?: string} $filters @param array{0: string, 1: list<mixed>} $scope @return array{0: string, 1: list<mixed>} */
    private function where(array $filters, array $scope): array
    {
        [$where, $params] = $scope;
        $sql = [$where];
        $status = (string) ($filters['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $sql[] = 'p.status = ?';
            $params[] = $status;
        } else {
            $sql[] = "p.status <> 'archived'";
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
            $sql[] = "(LOWER(p.name) LIKE ? ESCAPE '!' OR LOWER(p.partner_code) LIKE ? ESCAPE '!' OR LOWER(COALESCE(p.email, '')) LIKE ? ESCAPE '!' OR COALESCE(p.mobile, '') LIKE ? ESCAPE '!' OR LOWER(COALESCE(p.city, '')) LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $sql), $params];
    }
}
