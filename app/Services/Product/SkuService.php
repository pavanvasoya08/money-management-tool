<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;

/**
 * SKUs for products and variants.
 *
 * - Format: 2–80 upper-case letters, digits and - _ . / (typed SKUs are upper-cased).
 * - Uniqueness: product_sku_registry holds every SKU ever assigned — current and retired —
 *   under a unique index, so a SKU can never belong to two items, and a retired SKU is not
 *   handed out again (old orders and purchases keep pointing at it unambiguously).
 * - Generation: the Super Admin's pattern ({CAT}-{SEQ:5}, …). Each filled-in prefix has its
 *   own running number in product_sku_sequences; numbers already taken by a typed SKU are
 *   skipped. Nothing is hard-coded.
 */
final class SkuService
{
    public const FORMAT = '/^[A-Z0-9][A-Z0-9_.\/-]{1,79}$/';

    public function __construct(private readonly Database $db)
    {
    }

    public static function normalize(string $sku): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', '-', trim($sku))));
    }

    public static function formatError(string $sku): ?string
    {
        return preg_match(self::FORMAT, $sku) === 1 ? null : 'A SKU has 2–80 characters: letters, digits, hyphen, underscore, dot or slash, starting with a letter or digit.';
    }

    /** Who holds the SKU (current or retired), if anyone. @return array<string, mixed>|null */
    public function holder(string $sku): ?array
    {
        return $this->db->first('SELECT r.*, p.product_code, p.name AS product_name, v.name AS variant_name FROM product_sku_registry r JOIN products p ON p.id = r.product_id
            LEFT JOIN product_variants v ON v.id = r.variant_id WHERE r.sku = ?', [self::normalize($sku)]);
    }

    /** Throws when the SKU is badly formed or already used anywhere. */
    public function assertAvailable(string $sku, string $field = 'sku'): void
    {
        if (($err = self::formatError($sku)) !== null) {
            throw ValidationException::withMessage($field, $err);
        }
        $h = $this->holder($sku);
        if ($h !== null) {
            $who = $h['product_code'] . ' ' . $h['product_name'] . ($h['variant_name'] ? ' – ' . $h['variant_name'] : '');
            throw ValidationException::withMessage($field, (int) $h['is_current'] === 1
                ? "SKU {$sku} is already used by {$who}."
                : "SKU {$sku} was used before by {$who} and stays reserved so old records remain unambiguous.");
        }
    }

    /**
     * Fill a pattern and take the next free running number for it.
     * @param array<string, string> $tokens token (without braces) => value, e.g. CAT => ELEC
     */
    public function generate(string $pattern, array $tokens): string
    {
        if (preg_match('/\{SEQ(?::([1-9]))?\}/', $pattern, $m) !== 1) {
            throw new DomainRuleException('The SKU pattern needs a {SEQ} running number. A Super Admin can fix it in Products → Settings.');
        }
        $digits = (int) ($m[1] ?? 4);
        $filled = (string) preg_replace_callback('/\{([A-Z]+)\}/', static fn (array $t): string => $tokens[$t[1]] ?? '', $pattern);
        $prefixKey = mb_substr(str_replace($m[0], '{SEQ}', $filled), 0, 120);
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $n = $this->nextNumber($prefixKey);
            $sku = self::normalize(str_replace($m[0], str_pad((string) $n, $digits, '0', STR_PAD_LEFT), $filled));
            $sku = trim((string) preg_replace('/-{2,}/', '-', $sku), '-');
            if (self::formatError($sku) !== null) {
                throw new DomainRuleException("The SKU pattern produced \"{$sku}\", which is not a valid SKU. A Super Admin can fix the pattern in Products → Settings.");
            }
            if ($this->holder($sku) === null) {
                return $sku;
            }
        }
        throw new DomainRuleException('Could not find a free SKU for this pattern. Change the pattern or enter the SKU by hand.');
    }

    /** Pattern tokens for a product. @param array<string, mixed> $product @return array<string, string> */
    public function productTokens(array $product): array
    {
        $cat = $product['subcategory_id'] ?? null ?: ($product['category_id'] ?? null);
        $catCode = $cat ? (string) $this->db->scalar('SELECT code FROM product_categories WHERE id = ?', [$cat]) : '';
        $brand = !empty($product['brand_id']) ? (string) $this->db->scalar('SELECT code FROM product_brands WHERE id = ?', [$product['brand_id']]) : '';
        $type = !empty($product['product_type_id']) ? (string) $this->db->scalar('SELECT code FROM product_types WHERE id = ?', [$product['product_type_id']]) : '';
        $name = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($product['name'] ?? '')));
        return ['CAT' => self::clean($catCode) ?: 'GEN', 'BRAND' => self::clean($brand) ?: 'NB', 'TYPE' => strtoupper(substr(self::clean($type), 0, 4)) ?: 'PRD',
            'NAME' => substr($name, 0, 3) ?: 'ITM', 'YYYY' => date('Y'), 'YY' => date('y')];
    }

    /** Pattern tokens for a variant. @param array<string, mixed> $product @param list<string> $attributeValues */
    public function variantTokens(array $product, array $attributeValues): array
    {
        $attr = implode('-', array_filter(array_map(static fn (string $v): string => substr(self::clean($v), 0, 3), $attributeValues)));
        return ['PARENT' => (string) ($product['sku'] ?: $product['product_code']), 'ATTR' => $attr ?: 'VAR'] + $this->productTokens($product);
    }

    /** Record a SKU as current for a product or variant. */
    public function register(string $sku, int $productId, ?int $variantId, bool $generated, int $actorId): void
    {
        try {
            $this->db->insert('product_sku_registry', ['sku' => self::normalize($sku), 'product_id' => $productId, 'variant_id' => $variantId, 'is_current' => 1,
                'is_generated' => $generated ? 1 : 0, 'created_by' => $actorId, 'created_at' => now()]);
        } catch (\PDOException $e) {
            // The unique index caught a race with another save of the same SKU.
            throw ValidationException::withMessage('sku', 'SKU ' . self::normalize($sku) . ' was just taken by another record. Choose another one.');
        }
    }

    /** Mark the current SKU of a product (variant null) or variant as retired. */
    public function retire(int $productId, ?int $variantId): void
    {
        $this->db->execute('UPDATE product_sku_registry SET is_current = 0, retired_at = ? WHERE product_id = ? AND ' . ($variantId === null ? 'variant_id IS NULL' : 'variant_id = ?') . ' AND is_current = 1',
            array_merge([now(), $productId], $variantId === null ? [] : [$variantId]));
    }

    /** @return list<array<string, mixed>> all SKUs a product and its variants have had */
    public function historyFor(int $productId): array
    {
        return $this->db->select('SELECT r.*, v.name AS variant_name, u.name AS created_by_name FROM product_sku_registry r LEFT JOIN product_variants v ON v.id = r.variant_id
            LEFT JOIN users u ON u.id = r.created_by WHERE r.product_id = ? ORDER BY r.id DESC', [$productId]);
    }

    private static function clean(string $v): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v));
    }

    /** Atomically take the next number of a prefix (row lock through the UPDATE). */
    private function nextNumber(string $key): int
    {
        return $this->db->transaction(function (Database $db) use ($key): int {
            if ($db->execute('UPDATE product_sku_sequences SET last_value = last_value + 1, updated_at = ? WHERE scope_key = ?', [now(), $key]) === 0) {
                try {
                    $db->insert('product_sku_sequences', ['scope_key' => $key, 'last_value' => 1, 'updated_at' => now()]);
                } catch (\PDOException) {
                    $db->execute('UPDATE product_sku_sequences SET last_value = last_value + 1, updated_at = ? WHERE scope_key = ?', [now(), $key]);
                }
            }
            return (int) $db->scalar('SELECT last_value FROM product_sku_sequences WHERE scope_key = ?', [$key]);
        });
    }
}
