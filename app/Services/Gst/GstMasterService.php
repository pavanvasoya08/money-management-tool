<?php

declare(strict_types=1);

namespace App\Services\Gst;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;

/**
 * Configurable GST master data: states/UTs, registration types, partner
 * relationship types and business activities. Codes are unique; rows in use are
 * deactivated rather than deleted, so existing registrations keep their values.
 */
final class GstMasterService
{
    /** master key => [table, label, editable columns] */
    public const MASTERS = [
        'states' => ['gst_states', 'States & union territories'],
        'types' => ['gst_registration_types', 'Registration types'],
        'relationships' => ['gst_partner_relationship_types', 'Partner relationship types'],
        'activities' => ['gst_business_activities', 'Business activities'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function states(bool $activeOnly = false, bool $registrationOnly = false): array
    {
        return $this->db->select('SELECT * FROM gst_states WHERE 1 = 1' . ($activeOnly ? ' AND is_active = 1' : '') . ($registrationOnly ? ' AND allows_registration = 1' : '') . ' ORDER BY sort_order, code');
    }

    /** @return list<array<string, mixed>> */
    public function types(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT * FROM gst_registration_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** @return list<array<string, mixed>> */
    public function relationships(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT * FROM gst_partner_relationship_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** @return list<array<string, mixed>> */
    public function activities(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT * FROM gst_business_activities' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** Usage counts so the UI can explain why a row cannot simply be removed. @return array<int, int> */
    public function usage(string $master): array
    {
        $sql = match ($master) {
            'states' => 'SELECT state_id AS id, COUNT(*) AS n FROM gst_registrations GROUP BY state_id',
            'types' => 'SELECT registration_type_id AS id, COUNT(*) AS n FROM gst_registrations GROUP BY registration_type_id',
            'relationships' => 'SELECT relationship_type_id AS id, COUNT(*) AS n FROM gst_registration_partner_mappings GROUP BY relationship_type_id',
            'activities' => 'SELECT business_activity_id AS id, COUNT(*) AS n FROM gst_registration_business_mappings GROUP BY business_activity_id',
            default => throw new DomainRuleException('Unknown master.'),
        };
        $out = [];
        foreach ($this->db->select($sql) as $r) {
            $out[(int) $r['id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Create or update a master row.
     * @param array<string, mixed> $data @param array<string, mixed> $actor
     */
    public function save(string $master, array $data, array $actor, ?int $id = null): int
    {
        [$table, $label] = self::MASTERS[$master] ?? throw new DomainRuleException('Unknown master.');
        $existing = $id !== null ? ($this->db->first("SELECT * FROM {$table} WHERE id = ?", [$id]) ?? throw new DomainRuleException('Not found.')) : null;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessage('name', 'Enter a name (up to 120 characters).');
        }
        $code = $master === 'states' ? trim((string) ($data['code'] ?? '')) : strtolower(trim((string) ($data['code'] ?? '')));
        if ($master === 'states' && preg_match('/^[0-9]{2}$/', $code) !== 1) {
            throw ValidationException::withMessage('code', 'A GST state code has exactly two digits, e.g. 24.');
        }
        if ($master !== 'states' && preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code) !== 1) {
            throw ValidationException::withMessage('code', 'Use 2–40 lowercase letters, digits or underscores, starting with a letter.');
        }
        if ($existing !== null && $code !== (string) $existing['code'] && $this->inUse($master, (int) $existing['id'])) {
            throw ValidationException::withMessage('code', 'The code of a value that is already in use cannot be changed.');
        }
        if ($this->db->scalar("SELECT 1 FROM {$table} WHERE code = ?" . ($id !== null ? ' AND id <> ?' : ''), $id !== null ? [$code, $id] : [$code])) {
            throw ValidationException::withMessage('code', "The code {$code} already exists in {$label}.");
        }
        $row = ['code' => $code, 'name' => $name, 'is_active' => !empty($data['is_active']) ? 1 : 0, 'sort_order' => max(0, min(100000, (int) ($data['sort_order'] ?? 0))), 'updated_at' => now()];
        $desc = trim((string) ($data['description'] ?? ''));
        match ($master) {
            'states' => $row += ['abbreviation' => strtoupper(mb_substr(trim((string) ($data['abbreviation'] ?? '')), 0, 4)) ?: null, 'is_union_territory' => !empty($data['is_union_territory']) ? 1 : 0,
                'allows_registration' => !empty($data['allows_registration']) ? 1 : 0, 'notes' => $desc !== '' ? mb_substr($desc, 0, 255) : null],
            'types' => $row += ['description' => $desc !== '' ? mb_substr($desc, 0, 255) : null, 'id_basis' => in_array($data['id_basis'] ?? '', ['pan', 'tan', 'other'], true) ? $data['id_basis'] : 'pan',
                'fourteenth_char' => preg_match('/^[A-Z0-9]$/', strtoupper(trim((string) ($data['fourteenth_char'] ?? '')))) === 1 ? strtoupper(trim((string) $data['fourteenth_char'])) : null,
                'has_validity_period' => !empty($data['has_validity_period']) ? 1 : 0],
            'relationships' => $row += ['description' => $desc !== '' ? mb_substr($desc, 0, 255) : null, 'counts_as_owner' => !empty($data['counts_as_owner']) ? 1 : 0],
            'activities' => $row += ['description' => $desc !== '' ? mb_substr($desc, 0, 255) : null, 'updated_by' => (int) $actor['id']],
        };
        if ($existing === null) {
            $row['created_at'] = now();
            if ($master === 'activities') {
                $row['created_by'] = (int) $actor['id'];
            }
            $id = $this->db->insert($table, $row);
            App::activity()->log('gst.master_created', "Added {$label}: {$name} ({$code})", $table, $id, module: 'gst_registrations', newValues: $row);
            return $id;
        }
        $this->db->update($table, $row, ['id' => $id]);
        App::activity()->log('gst.master_updated', "Updated {$label}: {$name} ({$code})", $table, $id, module: 'gst_registrations',
            oldValues: array_intersect_key($existing, $row), newValues: $row);
        return (int) $id;
    }

    private function inUse(string $master, int $id): bool
    {
        return ($this->usage($master)[$id] ?? 0) > 0;
    }
}
