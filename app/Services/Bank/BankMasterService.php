<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Core\Validator;
use App\Exceptions\ValidationException;

/** Bank account types (Super Admin configurable). A type in use keeps its code; deactivate instead of deleting. */
final class BankMasterService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int, int> type id => accounts using it */
    public function usage(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT account_type_id, COUNT(*) AS n FROM bank_accounts GROUP BY account_type_id') as $r) {
            $out[(int) $r['account_type_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveType(array $data, array $actor, ?int $id = null): int
    {
        $data['code'] = strtolower(trim((string) ($data['code'] ?? '')));
        $v = Validator::make($data, ['code' => 'required|regex:/^[a-z][a-z0-9_]{1,39}$/', 'name' => 'required|string|min:2|max:120', 'description' => 'nullable|string|max:255', 'sort_order' => 'nullable|integer|min:0|max:100000'],
            ['code' => 'Code', 'name' => 'Name']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $c = $v->validated();
        if ($this->db->scalar('SELECT 1 FROM bank_account_types WHERE code = ?' . ($id ? ' AND id <> ' . $id : ''), [$c['code']])) {
            throw ValidationException::withMessage('code', 'This code is already used.');
        }
        $row = ['code' => $c['code'], 'name' => $c['name'], 'description' => ($c['description'] ?? '') ?: null, 'allows_overdraft' => !empty($data['allows_overdraft']) ? 1 : 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0, 'sort_order' => (int) ($c['sort_order'] ?? 0), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($id === null) {
            $id = $this->db->insert('bank_account_types', $row + ['created_by' => (int) $actor['id'], 'created_at' => now()]);
            App::activity()->log('bank.type_created', "Added bank account type {$c['name']}", 'bank_account_type', $id, module: 'bank_accounts', newValues: $row);
            return $id;
        }
        $old = $this->db->first('SELECT * FROM bank_account_types WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('code', 'Type not found.');
        if ($old['code'] !== $row['code'] && ($this->usage()[$id] ?? 0) > 0) {
            throw ValidationException::withMessage('code', 'This type is used by accounts, so its code cannot change. Rename it or deactivate it instead.');
        }
        $this->db->update('bank_account_types', $row, ['id' => $id]);
        App::activity()->log('bank.type_updated', "Updated bank account type {$row['name']}", 'bank_account_type', $id, module: 'bank_accounts',
            oldValues: array_intersect_key($old, $row), newValues: $row);
        return $id;
    }
}
