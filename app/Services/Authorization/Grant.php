<?php

declare(strict_types=1);

namespace App\Services\Authorization;

/**
 * An effective permission for one user after merging all role grants and
 * individual overrides.
 *
 * $constraints (Phase 2) restrict the grant to specific GST registrations,
 * bank accounts, platforms or partners, e.g.
 *   ['gst_registration_ids' => [1, 2], 'bank_account_ids' => [3]]
 * null means "no restriction".
 */
final class Grant
{
    /** @param array<string, list<int>>|null $constraints */
    public function __construct(
        public readonly string $permission,
        public readonly DataScope $scope = DataScope::All,
        public readonly ?array $constraints = null,
        public readonly string $source = 'role',
    ) {
    }

    /** Merge two grants for the same permission: widest scope, union of constraints. */
    public function merge(self $other): self
    {
        $constraints = null;
        if ($this->constraints !== null && $other->constraints !== null) {
            $constraints = $this->constraints;
            foreach ($other->constraints as $key => $ids) {
                $constraints[$key] = array_values(array_unique(array_merge($constraints[$key] ?? [], $ids)));
            }
        }
        return new self($this->permission, DataScope::widest($this->scope, $other->scope), $constraints, $this->source);
    }
}
