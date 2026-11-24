<?php

declare(strict_types=1);

namespace App\Services\Gst;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;

/**
 * Association foundation for FUTURE modules (bank accounts, e-commerce platforms,
 * seller accounts, warehouses, products, sales and purchase transactions).
 *
 * Phase 4 ships no such module and registers no link types, so nothing can be
 * linked yet and no fake records exist. A later module calls
 *
 *   GstLinkRegistry::register('bank_account', 'Bank accounts', fn (int $id): ?string => <label>);
 *
 * and then link()/unlink() to associate its records with an existing GST
 * registration, instead of storing GSTINs of its own. Transaction modules should
 * also reference gst_registrations.id directly; isReferenced() then prevents
 * archiving a registration that is in use (registrations are never deleted).
 */
final class GstLinkRegistry
{
    /** @var array<string, array{label: string, resolver: callable(int): ?string}> */
    private static array $types = [];
    /** @var array<string, string> table => column, for modules that reference gst_registrations.id directly */
    private static array $references = [];

    public static function register(string $type, string $label, callable $resolver): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $type) !== 1) {
            throw new \InvalidArgumentException('Invalid link type.');
        }
        self::$types[$type] = ['label' => $label, 'resolver' => $resolver];
    }

    /** Declare a table that references GST registrations (e.g. register('sales_orders', 'gst_registration_id')). */
    public static function registerReference(string $table, string $column = 'gst_registration_id'): void
    {
        self::$references[$table] = $column;
    }

    /** @return array<string, string> type => label */
    public static function types(): array
    {
        return array_map(static fn (array $t): string => $t['label'], self::$types);
    }

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $actor */
    public function link(int $regId, string $type, int $linkedId, ?string $relationship, array $actor): int
    {
        if (!isset(self::$types[$type])) {
            throw new DomainRuleException("Link type [{$type}] is not registered by any module.");
        }
        if (!$this->db->scalar('SELECT 1 FROM gst_registrations WHERE id = ? AND is_archived = 0', [$regId])) {
            throw new DomainRuleException('GST registration not found or archived.');
        }
        $id = $this->db->insert('gst_registration_links', ['gst_registration_id' => $regId, 'linked_type' => $type, 'linked_id' => $linkedId, 'relationship' => $relationship,
            'effective_from' => null, 'effective_to' => null, 'status' => 'active', 'created_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        App::activity()->log('gst.link_added', "Linked {$type} #{$linkedId} to GST registration #{$regId}", 'gst_registration', $regId, module: 'gst_registrations',
            newValues: ['entity' => 'link', 'type' => $type, 'linked_id' => $linkedId, 'relationship' => $relationship]);
        return $id;
    }

    /** @return list<array<string, mixed>> active links with labels from the owning module */
    public function linksFor(int $regId): array
    {
        $out = [];
        foreach ($this->db->select("SELECT * FROM gst_registration_links WHERE gst_registration_id = ? AND status = 'active' ORDER BY linked_type, id", [$regId]) as $l) {
            $t = self::$types[$l['linked_type']] ?? null;
            $out[] = $l + ['type_label' => $t['label'] ?? $l['linked_type'], 'label' => $t !== null ? (($t['resolver'])((int) $l['linked_id']) ?? '#' . $l['linked_id']) : '#' . $l['linked_id']];
        }
        return $out;
    }

    /** Whether any business record or transaction refers to the registration. */
    public function isReferenced(int $regId): bool
    {
        if ($this->db->scalar("SELECT 1 FROM gst_registration_links WHERE gst_registration_id = ? AND status = 'active'", [$regId])) {
            return true;
        }
        foreach (self::$references as $table => $column) {
            if ($this->db->scalar('SELECT 1 FROM ' . $this->db->identifier($table) . ' WHERE ' . $this->db->identifier($column) . ' = ? LIMIT 1', [$regId])) {
                return true;
            }
        }
        return false;
    }
}
