<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;
use App\Support\Locale;
use App\Support\Uuid;

/**
 * The platform master: add, edit, configure, activate / deactivate, archive / restore, logo,
 * capabilities and custom settings. Platforms are never deleted. Nothing here connects to a
 * marketplace, stores credentials or creates seller accounts, orders, fees or money movements.
 */
final class PlatformService
{
    public const STATUSES = ['under_configuration' => 'Under configuration', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];
    public const CONNECTION = ['not_connected' => 'Not connected', 'connected' => 'Connected', 'disconnected' => 'Disconnected', 'error' => 'Connection error'];
    /** Capability key => [label, phase that will implement it]. */
    public const CAPABILITIES = [
        'product_listing' => ['Product listing', 'Phase 8C'], 'sku_mapping' => ['SKU mapping', 'Phase 8C'], 'inventory_sync' => ['Inventory sync', 'Phase 8C'],
        'order_sync' => ['Order sync', 'Phase 8C / 9'], 'settlement_sync' => ['Settlement sync', 'Phase 10'], 'return_sync' => ['Return sync', 'Phase 10'],
        'api_integration' => ['API integration', 'Phase 8C'], 'webhooks' => ['Webhooks', 'Phase 8C'],
    ];
    /** Capabilities the ERP really implements and that may therefore be marked operational. None in Phase 8A. */
    public const IMPLEMENTED = [];
    public const PLATFORM_SUPPORT = ['unknown' => 'Not known', 'supported' => 'Offered', 'not_supported' => 'Not offered'];
    public const ERP_STATUS = ['not_available' => 'Not available', 'planned' => 'Planned', 'operational' => 'Operational'];
    private const FIELDS = ['name', 'platform_code', 'category_id', 'website_url', 'description', 'country', 'default_currency', 'display_order', 'internal_notes'];
    private const CONFIG_FIELDS = ['currencies', 'default_currency', 'languages', 'time_zone', 'date_format', 'number_format', 'reference_prefix', 'country'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function logos(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/platform-logos'), 2 * 1024 * 1024);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT p.*, c.name AS category_name, c.code AS category_code, cu.name AS created_by_name, uu.name AS updated_by_name, au.name AS archived_by_name
            FROM ecommerce_platforms p JOIN ecommerce_platform_categories c ON c.id = p.category_id LEFT JOIN users cu ON cu.id = p.created_by
            LEFT JOIN users uu ON uu.id = p.updated_by LEFT JOIN users au ON au.id = p.archived_by WHERE p.id = ?', [$id]);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return list<array<string, mixed>> */
    public function list(array $user, array $f = []): array
    {
        [$w, $p] = PlatformScope::sql($user, 'p.id');
        $sql = "SELECT p.*, c.name AS category_name,
                (SELECT COUNT(*) FROM ecommerce_platform_gst_mappings m WHERE m.platform_id = p.id AND m.status = 'active') AS gst_count,
                (SELECT COUNT(*) FROM ecommerce_platform_warehouse_mappings m WHERE m.platform_id = p.id AND m.status = 'active') AS warehouse_count,
                (SELECT COUNT(*) FROM ecommerce_platform_business_mappings m WHERE m.platform_id = p.id AND m.status = 'active') AS activity_count
            FROM ecommerce_platforms p JOIN ecommerce_platform_categories c ON c.id = p.category_id WHERE {$w}";
        $status = (string) ($f['status'] ?? '');
        if ($status !== '' && isset(self::STATUSES[$status])) {
            $sql .= ' AND p.status = ?';
            $p[] = $status;
        } elseif (($f['archived'] ?? '') !== 'with') {
            $sql .= " AND p.status <> 'archived'";
        }
        foreach (['category' => 'p.category_id', 'platform' => 'p.id'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $sql .= " AND {$col} = ?";
                $p[] = (int) $f[$k];
            }
        }
        if (($f['connection'] ?? '') !== '' && isset(self::CONNECTION[$f['connection']])) {
            $sql .= ' AND p.connection_status = ?';
            $p[] = $f['connection'];
        }
        if (($f['country'] ?? '') !== '') {
            $sql .= ' AND p.country = ?';
            $p[] = (string) $f['country'];
        }
        if (($f['gst'] ?? '') !== '') {
            $sql .= " AND p.id IN (SELECT platform_id FROM ecommerce_platform_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)";
            $p[] = (int) $f['gst'];
        }
        if (($f['from'] ?? '') !== '') {
            $sql .= ' AND p.created_at >= ?';
            $p[] = $f['from'] . ' 00:00:00';
        }
        if (($f['to'] ?? '') !== '') {
            $sql .= ' AND p.created_at <= ?';
            $p[] = $f['to'] . ' 23:59:59';
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.platform_code LIKE ? OR p.website_url LIKE ? OR p.description LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $rows = $this->db->select($sql . ' ORDER BY p.display_order, p.name', $p);
        foreach ($rows as &$r) {
            $r['readiness'] = $this->readiness($r);
        }
        return $rows;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function create(array $data, array $actor): int
    {
        $clean = $this->validate($data, null);
        $now = now();
        $id = $this->db->transaction(function () use ($clean, $actor, $now): int {
            if ($clean['platform_code'] === '') {
                $clean['platform_code'] = $this->codeFrom((string) $clean['name']);
            }
            $id = $this->db->insert('ecommerce_platforms', $clean + ['uuid' => Uuid::v4(), 'currencies' => $clean['default_currency'], 'business_currency' => 'INR',
                'status' => 'under_configuration', 'connection_status' => 'not_connected', 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'],
                'created_at' => $now, 'updated_at' => $now]);
            foreach (array_keys(self::CAPABILITIES) as $key) {
                $this->db->insert('ecommerce_platform_capabilities', ['platform_id' => $id, 'capability_key' => $key, 'platform_support' => 'unknown', 'erp_status' => 'not_available',
                    'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
            }
            PlatformHistory::write($this->db, $id, 'created', null, self::view($clean), null, $actor, to: 'under_configuration');
            return $id;
        });
        $p = $this->find($id);
        App::activity()->log('platforms.created', "Added platform {$p['platform_code']} {$p['name']}", 'platform', $id, module: 'platforms', newValues: self::view($p));
        return $id;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function update(array $p, array $data, array $actor): void
    {
        $this->assertWritable($p);
        $this->change($p, $this->validate($data, $p), $actor, 'updated', 'Updated platform');
    }

    /** General configuration (currencies, languages, time zone, formats, prefix). @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function configure(array $p, array $data, array $actor): void
    {
        $this->assertWritable($p);
        $d = [];
        foreach (self::CONFIG_FIELDS as $k) {
            $v = $data[$k] ?? '';
            $d[$k] = is_array($v) ? implode(',', array_map('strval', $v)) : trim((string) $v);
        }
        $d['currencies'] = implode(',', array_map('strtoupper', Locale::codes($d['currencies'])));
        $d['languages'] = implode(',', array_map('strtolower', Locale::codes($d['languages'])));
        $d['default_currency'] = strtoupper($d['default_currency']);
        $d['country'] = strtoupper($d['country']);
        $d['reference_prefix'] = strtoupper($d['reference_prefix']);
        $errors = [];
        if ($d['currencies'] === '' || array_diff(Locale::codes($d['currencies']), array_keys(Locale::CURRENCIES)) !== []) {
            $errors['currencies'] = 'Choose at least one supported currency.';
        } elseif (!in_array($d['default_currency'], Locale::codes($d['currencies']), true)) {
            $errors['default_currency'] = 'The default currency must be one of the supported currencies.';
        }
        if ($d['languages'] === '' || array_diff(Locale::codes($d['languages']), array_keys(Locale::LANGUAGES)) !== []) {
            $errors['languages'] = 'Choose at least one supported language.';
        }
        if (!in_array($d['time_zone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors['time_zone'] = 'Choose a valid time zone.';
        }
        if (!isset(Locale::DATE_FORMATS[$d['date_format']])) {
            $errors['date_format'] = 'Choose a date format.';
        }
        if (!isset(Locale::NUMBER_FORMATS[$d['number_format']])) {
            $errors['number_format'] = 'Choose a number format.';
        }
        if (!isset(Locale::COUNTRIES[$d['country']])) {
            $errors['country'] = 'Choose a country.';
        }
        if ($d['reference_prefix'] !== '' && preg_match('/^[A-Z0-9][A-Z0-9-]{0,11}$/', $d['reference_prefix']) !== 1) {
            $errors['reference_prefix'] = 'Use up to 12 capital letters, digits or dashes (e.g. AMZ).';
        } elseif ($d['reference_prefix'] !== '' && $this->db->scalar('SELECT 1 FROM ecommerce_platforms WHERE reference_prefix = ? AND id <> ?', [$d['reference_prefix'], $p['id']])) {
            $errors['reference_prefix'] = 'Another platform already uses this prefix.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $d['reference_prefix'] = $d['reference_prefix'] !== '' ? $d['reference_prefix'] : null;
        $this->change($p, $d, $actor, 'configuration_changed', 'Changed the configuration of platform');
    }

    /** Default business currency (business mappings tab). @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function setBusinessCurrency(array $p, string $currency, array $actor): void
    {
        $this->assertWritable($p);
        $currency = strtoupper(trim($currency));
        if (!isset(Locale::CURRENCIES[$currency])) {
            throw ValidationException::withMessage('business_currency', 'Choose a currency.');
        }
        $this->change($p, ['business_currency' => $currency], $actor, 'business_currency_changed', 'Changed the business currency of platform');
    }

    /** @param array<string, mixed> $p @return list<array<string, mixed>> */
    public function capabilities(array $p): array
    {
        $rows = [];
        foreach ($this->db->select('SELECT * FROM ecommerce_platform_capabilities WHERE platform_id = ?', [$p['id']]) as $r) {
            $rows[$r['capability_key']] = $r;
        }
        $out = [];
        foreach (self::CAPABILITIES as $key => [$label, $phase]) {
            $out[] = ($rows[$key] ?? ['capability_key' => $key, 'platform_support' => 'unknown', 'erp_status' => 'not_available', 'notes' => null])
                + ['label' => $label, 'phase' => $phase, 'implemented' => in_array($key, self::IMPLEMENTED, true)];
        }
        return $out;
    }

    /** @param array<string, mixed> $p @param array<string, array<string, string>> $input key => [platform_support, erp_status, notes] @param array<string, mixed> $actor */
    public function saveCapabilities(array $p, array $input, array $actor): void
    {
        $this->assertWritable($p);
        $old = [];
        $new = [];
        $current = [];
        foreach ($this->capabilities($p) as $c) {
            $current[$c['capability_key']] = $c;
        }
        foreach (self::CAPABILITIES as $key => [$label]) {
            $row = (array) ($input[$key] ?? []);
            $support = (string) ($row['platform_support'] ?? $current[$key]['platform_support']);
            $erp = (string) ($row['erp_status'] ?? $current[$key]['erp_status']);
            $notes = mb_substr(trim((string) ($row['notes'] ?? '')), 0, 255);
            if (!isset(self::PLATFORM_SUPPORT[$support]) || !isset(self::ERP_STATUS[$erp])) {
                throw ValidationException::withMessage("capabilities.{$key}", 'Choose a valid value.');
            }
            if ($erp === 'operational' && !in_array($key, self::IMPLEMENTED, true)) {
                throw ValidationException::withMessage("capabilities.{$key}", "{$label} is not implemented in the ERP yet, so it cannot be marked operational.");
            }
            $c = $current[$key];
            if ($support === $c['platform_support'] && $erp === $c['erp_status'] && $notes === (string) ($c['notes'] ?? '')) {
                continue;
            }
            $old[$key] = ['platform' => $c['platform_support'], 'erp' => $c['erp_status']];
            $new[$key] = ['platform' => $support, 'erp' => $erp] + ($notes !== '' ? ['notes' => $notes] : []);
            $values = ['platform_support' => $support, 'erp_status' => $erp, 'notes' => $notes !== '' ? $notes : null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
            if (isset($c['id'])) {
                $this->db->update('ecommerce_platform_capabilities', $values, ['id' => $c['id']]);
            } else {
                $this->db->insert('ecommerce_platform_capabilities', $values + ['platform_id' => (int) $p['id'], 'capability_key' => $key, 'created_by' => (int) $actor['id'], 'created_at' => now()]);
            }
        }
        if ($new === []) {
            return;
        }
        PlatformHistory::write($this->db, (int) $p['id'], 'capabilities_changed', $old, $new, null, $actor);
        App::activity()->log('platforms.capabilities_changed', "Changed capabilities of platform {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms', oldValues: $old, newValues: $new);
    }

    /** @param array<string, mixed> $p @return list<array<string, mixed>> */
    public function settings(array $p): array
    {
        return $this->db->select('SELECT s.*, u.name AS updated_by_name FROM ecommerce_platform_settings s LEFT JOIN users u ON u.id = s.updated_by WHERE s.platform_id = ? ORDER BY s.setting_key', [$p['id']]);
    }

    /** Add or change a platform-specific setting (key => value). An empty value removes it. @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveSetting(array $p, array $data, array $actor): void
    {
        $this->assertWritable($p);
        $key = strtolower(trim((string) ($data['setting_key'] ?? '')));
        $value = trim((string) ($data['value'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_.]{1,59}$/', $key) !== 1) {
            throw ValidationException::withMessage('setting_key', 'Use 2–60 lowercase letters, digits, dots or underscores (e.g. return_window_days).');
        }
        if (preg_match('/(secret|password|passwd|token|api_?key|private_?key)/', $key) === 1) {
            throw ValidationException::withMessage('setting_key', 'Credentials, passwords and API keys are never stored here. Secure credential storage comes with seller accounts (Phase 8B).');
        }
        if (mb_strlen($value) > 500) {
            throw ValidationException::withMessage('value', 'Use at most 500 characters.');
        }
        $existing = $this->db->first('SELECT * FROM ecommerce_platform_settings WHERE platform_id = ? AND setting_key = ?', [$p['id'], $key]);
        if ($existing === null && $value === '') {
            throw ValidationException::withMessage('value', 'Enter a value.');
        }
        $desc = mb_substr(trim((string) ($data['description'] ?? '')), 0, 255);
        if ($existing !== null && $value === '') {
            $this->db->delete('ecommerce_platform_settings', ['id' => $existing['id']]);
            $event = 'setting_removed';
        } elseif ($existing !== null) {
            $this->db->update('ecommerce_platform_settings', ['value' => $value, 'description' => $desc !== '' ? $desc : $existing['description'], 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $existing['id']]);
            $event = 'setting_changed';
        } else {
            $this->db->insert('ecommerce_platform_settings', ['platform_id' => (int) $p['id'], 'setting_key' => $key, 'value' => $value, 'description' => $desc !== '' ? $desc : null,
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            $event = 'setting_added';
        }
        $old = $existing !== null ? [$key => $existing['value']] : null;
        $new = $value !== '' ? [$key => $value] : null;
        PlatformHistory::write($this->db, (int) $p['id'], $event, $old, $new, null, $actor);
        App::activity()->log('platforms.' . $event, ucfirst(str_replace('_', ' ', $event)) . " \"{$key}\" on platform {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms', oldValues: $old, newValues: $new);
    }

    /** under_configuration / inactive → active, and active / under_configuration → inactive. @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function changeStatus(array $p, string $to, string $reason, array $actor): void
    {
        $from = (string) $p['status'];
        $allowed = ['active' => ['under_configuration', 'inactive'], 'inactive' => ['active', 'under_configuration'], 'under_configuration' => ['inactive']];
        if (!isset($allowed[$to]) || !in_array($from, $allowed[$to], true)) {
            throw new DomainRuleException('This status change is not possible.');
        }
        if ($to === 'inactive' && trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($to === 'active') {
            $missing = array_keys(array_filter($this->readiness($p)['required'], static fn (bool $ok): bool => !$ok));
            if ($missing !== []) {
                throw new DomainRuleException('Complete the configuration before activating: ' . implode(', ', $missing) . '.');
            }
        }
        $this->setStatus($p, $to, $reason, $actor, match ($to) { 'active' => 'activated', 'inactive' => 'deactivated', default => 'status_changed' });
    }

    /**
     * Things that will stay linked when the platform is archived (shown as a warning; archiving them needs confirmation).
     * Seller accounts (Phase 8B) and transactions (Phase 9+) will be added here as hard blockers.
     * @param array<string, mixed> $p @return array{blockers: list<string>, warnings: list<string>}
     */
    public function archiveCheck(array $p): array
    {
        $id = (int) $p['id'];
        $warnings = [];
        foreach (['ecommerce_platform_gst_mappings' => 'GST registration', 'ecommerce_platform_business_mappings' => 'business activity', 'ecommerce_platform_partner_mappings' => 'partner',
            'ecommerce_platform_warehouse_mappings' => 'warehouse'] as $table => $label) {
            $n = (int) $this->db->scalar("SELECT COUNT(*) FROM {$table} WHERE platform_id = ? AND status = 'active'", [$id]);
            if ($n > 0) {
                $warnings[] = "{$n} active {$label} mapping" . ($n === 1 ? '' : 's') . ' (kept, read-only while archived)';
            }
        }
        $prices = (int) $this->db->scalar("SELECT COUNT(*) FROM product_prices WHERE LOWER(sales_channel) = LOWER(?)", [$p['platform_code']]);
        if ($prices > 0) {
            $warnings[] = "{$prices} product price" . ($prices === 1 ? '' : 's') . " for channel {$p['platform_code']} in Product Management (kept as history)";
        }
        $docs = (int) $this->db->scalar("SELECT COUNT(*) FROM ecommerce_platform_documents WHERE platform_id = ? AND status = 'active'", [$id]);
        if ($docs > 0) {
            $warnings[] = "{$docs} document" . ($docs === 1 ? '' : 's') . ' (kept)';
        }
        return ['blockers' => [], 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function archive(array $p, string $reason, bool $confirmed, array $actor): void
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('This platform is already archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $check = $this->archiveCheck($p);
        if ($check['blockers'] !== []) {
            throw new DomainRuleException('This platform cannot be archived: ' . implode('; ', $check['blockers']) . '.');
        }
        if ($check['warnings'] !== [] && !$confirmed) {
            throw ValidationException::withMessage('confirm', 'This platform still has ' . implode('; ', $check['warnings']) . '. Tick the confirmation to archive it anyway.');
        }
        $this->db->transaction(function () use ($p, $reason, $actor, $check): void {
            $this->db->update('ecommerce_platforms', ['status' => 'archived', 'status_reason' => mb_substr($reason, 0, 255), 'archived_at' => now(), 'archived_by' => (int) $actor['id'],
                'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
            PlatformHistory::write($this->db, (int) $p['id'], 'archived', null, $check['warnings'] !== [] ? ['kept' => $check['warnings']] : null, $reason, $actor, (string) $p['status'], 'archived');
        });
        App::activity()->log('platforms.archived', "Archived platform {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms',
            oldValues: ['status' => $p['status']], newValues: ['status' => 'archived', 'reason' => $reason, 'kept' => $check['warnings']]);
    }

    /** Archived → inactive. @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function restore(array $p, array $actor): void
    {
        if ($p['status'] !== 'archived') {
            throw new DomainRuleException('Only archived platforms can be restored.');
        }
        $this->db->update('ecommerce_platforms', ['archived_at' => null, 'archived_by' => null], ['id' => $p['id']]);
        $this->setStatus($p, 'inactive', 'Restored from the archive', $actor, 'restored');
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $file @param array<string, mixed> $actor */
    public function setLogo(array $p, array $file, array $actor, bool $httpUpload = true): void
    {
        $this->assertWritable($p);
        $store = self::logos();
        $info = $store->validate($file, $httpUpload, 'logo', SecureFileStore::IMAGE_EXT);
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $this->db->update('ecommerce_platforms', ['logo_stored_name' => $saved['stored'], 'logo_mime' => $info['mime'], 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
        PlatformHistory::write($this->db, (int) $p['id'], $p['logo_stored_name'] ? 'logo_changed' : 'logo_added', null, ['file' => $info['original'], 'size' => $info['size']], null, $actor);
        App::activity()->log('platforms.logo_changed', "Changed the logo of platform {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms', newValues: ['file' => $info['original']]);
    }

    /** @param array<string, mixed> $p */
    public function logoResponse(array $p): Response
    {
        if (!$p['logo_stored_name']) {
            throw new HttpException(404, 'No logo.');
        }
        return self::logos()->response((string) $p['logo_stored_name'], (string) $p['logo_mime'], $p['platform_code'] . '.img', true);
    }

    /**
     * Configuration checklist. "required" items must be complete to activate; "recommended" ones feed the
     * configuration status shown on the dashboard. @param array<string, mixed> $p
     * @return array{required: array<string, bool>, recommended: array<string, bool>, percent: int, complete: bool}
     */
    public function readiness(array $p): array
    {
        $id = (int) $p['id'];
        $required = [
            'category' => (int) $p['category_id'] > 0,
            'country' => isset(Locale::COUNTRIES[(string) $p['country']]),
            'default currency' => in_array((string) $p['default_currency'], Locale::codes((string) $p['currencies']), true),
            'language' => Locale::codes((string) $p['languages']) !== [],
        ];
        $recommended = [
            'website' => (string) ($p['website_url'] ?? '') !== '' || in_array((string) ($p['category_code'] ?? ''), ['offline'], true),
            'GST registration' => isset($p['gst_count']) ? (int) $p['gst_count'] > 0 : (bool) $this->db->scalar("SELECT 1 FROM ecommerce_platform_gst_mappings WHERE platform_id = ? AND status = 'active'", [$id]),
            'warehouse' => isset($p['warehouse_count']) ? (int) $p['warehouse_count'] > 0 : (bool) $this->db->scalar("SELECT 1 FROM ecommerce_platform_warehouse_mappings WHERE platform_id = ? AND status = 'active'", [$id]),
            'reference prefix' => (string) ($p['reference_prefix'] ?? '') !== '',
        ];
        $all = $required + $recommended;
        $done = count(array_filter($all));
        return ['required' => $required, 'recommended' => $recommended, 'percent' => (int) round($done * 100 / count($all)), 'complete' => $done === count($all)];
    }

    /** @param array<string, mixed> $p */
    public function assertWritable(array $p): void
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('This platform is archived and read-only. Restore it to make changes.');
        }
    }

    /** @return list<array<string, mixed>> visible, non-archived platforms (for pickers). @param array<string, mixed> $user */
    public function options(array $user, bool $withArchived = false): array
    {
        [$w, $p] = PlatformScope::sql($user, 'id');
        return $this->db->select("SELECT id, platform_code, name, status FROM ecommerce_platforms WHERE {$w}" . ($withArchived ? '' : " AND status <> 'archived'") . ' ORDER BY display_order, name', $p);
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed> $p @param array<string, mixed> $clean @param array<string, mixed> $actor */
    private function change(array $p, array $clean, array $actor, string $event, string $text): void
    {
        $old = [];
        $new = [];
        foreach ($clean as $k => $v) {
            if ((string) ($p[$k] ?? '') !== (string) ($v ?? '')) {
                $old[$k] = $p[$k] ?? null;
                $new[$k] = $v;
            }
        }
        if ($new === []) {
            return;
        }
        $this->db->transaction(function () use ($p, $new, $old, $actor, $event): void {
            $this->db->update('ecommerce_platforms', $new + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
            PlatformHistory::write($this->db, (int) $p['id'], $event, self::view($old), self::view($new), null, $actor);
        });
        App::activity()->log('platforms.' . $event, "{$text} {$p['platform_code']}", 'platform', (int) $p['id'], module: 'platforms', oldValues: self::view($old), newValues: self::view($new));
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $actor */
    private function setStatus(array $p, string $to, string $reason, array $actor, string $event): void
    {
        $this->db->transaction(function () use ($p, $to, $reason, $actor, $event): void {
            $this->db->update('ecommerce_platforms', ['status' => $to, 'status_reason' => trim($reason) !== '' ? mb_substr($reason, 0, 255) : null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
            PlatformHistory::write($this->db, (int) $p['id'], $event, null, null, $reason, $actor, (string) $p['status'], $to);
        });
        App::activity()->log('platforms.' . $event, "Platform {$p['platform_code']}: {$p['status']} → {$to}", 'platform', (int) $p['id'], module: 'platforms',
            oldValues: ['status' => $p['status']], newValues: ['status' => $to, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $p @return array<string, mixed> */
    private function validate(array $data, ?array $p): array
    {
        $d = [];
        foreach (self::FIELDS as $k) {
            $d[$k] = is_string($data[$k] ?? null) ? trim($data[$k]) : ($data[$k] ?? ($p[$k] ?? ''));
        }
        $d['name'] = (string) preg_replace('/\s+/', ' ', (string) $d['name']);
        $d['platform_code'] = strtoupper((string) $d['platform_code']);
        $d['country'] = strtoupper((string) ($d['country'] ?: ($p['country'] ?? 'IN')));
        $d['default_currency'] = strtoupper((string) ($d['default_currency'] ?: ($p['default_currency'] ?? 'INR')));
        $d['display_order'] = (string) $d['display_order'] === '' ? 0 : $d['display_order'];
        $v = Validator::make($d, [
            'name' => 'required|string|min:2|max:150', 'platform_code' => 'nullable|regex:/^[A-Z0-9][A-Z0-9_-]{1,29}$/', 'category_id' => 'required|integer|exists:ecommerce_platform_categories,id',
            'website_url' => 'nullable|string|max:255', 'description' => 'nullable|string|max:5000', 'country' => 'required|regex:/^[A-Z]{2}$/', 'default_currency' => 'required|regex:/^[A-Z]{3}$/',
            'display_order' => 'integer|min:0|max:9999', 'internal_notes' => 'nullable|string|max:5000',
        ], ['category_id' => 'Category', 'platform_code' => 'Platform code', 'website_url' => 'Website URL']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if ($d['website_url'] !== '' && (preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', (string) $d['website_url']) !== 1 || filter_var($d['website_url'], FILTER_VALIDATE_URL) === false)) {
            throw ValidationException::withMessage('website_url', 'Enter a web address starting with http:// or https://.');
        }
        if (!isset(Locale::COUNTRIES[$d['country']])) {
            throw ValidationException::withMessage('country', 'Choose a country.');
        }
        if (!isset(Locale::CURRENCIES[$d['default_currency']])) {
            throw ValidationException::withMessage('default_currency', 'Choose a currency.');
        }
        if ($p !== null && $d['default_currency'] !== $p['default_currency'] && !in_array($d['default_currency'], Locale::codes((string) $p['currencies']), true)) {
            throw ValidationException::withMessage('default_currency', 'Add this currency to the supported currencies on the Configuration tab first.');
        }
        if ($this->db->scalar('SELECT 1 FROM ecommerce_platforms WHERE LOWER(name) = LOWER(?) AND id <> ?', [$d['name'], (int) ($p['id'] ?? 0)])) {
            throw ValidationException::withMessage('name', 'A platform with this name already exists.');
        }
        if ($d['platform_code'] !== '' && $this->db->scalar('SELECT 1 FROM ecommerce_platforms WHERE platform_code = ? AND id <> ?', [$d['platform_code'], (int) ($p['id'] ?? 0)])) {
            throw ValidationException::withMessage('platform_code', 'This platform code is already used.');
        }
        if ($p !== null && $d['platform_code'] === '') {
            $d['platform_code'] = (string) $p['platform_code'];
        }
        if ($p !== null && $d['platform_code'] !== $p['platform_code'] && $this->db->scalar('SELECT 1 FROM product_prices WHERE LOWER(sales_channel) = LOWER(?) LIMIT 1', [$p['platform_code']])) {
            throw ValidationException::withMessage('platform_code', 'Product prices already use this code as their sales channel, so it cannot change.');
        }
        if ($p === null || (int) $p['category_id'] !== (int) $d['category_id']) {
            if (!$this->db->scalar('SELECT is_active FROM ecommerce_platform_categories WHERE id = ?', [(int) $d['category_id']])) {
                throw ValidationException::withMessage('category_id', 'This category is inactive.');
            }
        }
        $d['category_id'] = (int) $d['category_id'];
        $d['display_order'] = (int) $d['display_order'];
        foreach (['website_url', 'description', 'internal_notes'] as $k) {
            $d[$k] = (string) $d[$k] !== '' ? $d[$k] : null;
        }
        return $d;
    }

    private function codeFrom(string $name): string
    {
        $base = substr((string) preg_replace('/[^A-Z0-9]+/', '', strtoupper($name)), 0, 20);
        $base = strlen($base) >= 2 ? $base : 'PLATFORM';
        $code = $base;
        for ($i = 2; $this->db->scalar('SELECT 1 FROM ecommerce_platforms WHERE platform_code = ?', [$code]); $i++) {
            $code = $base . '-' . $i;
        }
        return $code;
    }

    /** History / audit view of a set of values (notes are summarised). @param array<string, mixed> $v @return array<string, mixed> */
    public static function view(array $v): array
    {
        $out = [];
        foreach ($v as $k => $x) {
            if (in_array($k, ['uuid', 'logo_stored_name', 'logo_mime', 'created_at', 'updated_at', 'created_by', 'updated_by'], true)) {
                continue;
            }
            $out[$k] = $k === 'internal_notes' && $x !== null ? '(changed)' : $x;
        }
        return $out;
    }
}
