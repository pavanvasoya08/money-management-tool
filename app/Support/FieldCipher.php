<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Env;
use App\Exceptions\DomainRuleException;

/**
 * Encrypts sensitive values at rest (bank account numbers) with AES-256-GCM and
 * the application key (APP_KEY in .env, created by `php bin/console key:generate`).
 * blindIndex() gives a keyed HMAC so equal values can be found (duplicate
 * detection) without decrypting. Keep APP_KEY secret and backed up: without it,
 * stored numbers cannot be decrypted.
 */
final class FieldCipher
{
    private const PREFIX = 'v1:';

    public static function configured(): bool
    {
        return self::keyOrNull() !== null;
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::subKey($key, 'enc'), OPENSSL_RAW_DATA, $iv, $tag, 'erp-field', 16);
        if ($cipher === false) {
            throw new DomainRuleException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new DomainRuleException('Unknown encrypted value format.');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new DomainRuleException('Corrupted encrypted value.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::subKey(self::key(), 'enc'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'erp-field');
        if ($plain === false) {
            throw new DomainRuleException('The value could not be decrypted. Was APP_KEY changed?');
        }
        return $plain;
    }

    /** Keyed hash for equality lookups (e.g. the same account number entered twice). */
    public static function blindIndex(string $plain, string $purpose): string
    {
        return hash_hmac('sha256', $purpose . '|' . $plain, self::subKey(self::key(), 'idx'));
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private static function subKey(string $key, string $label): string
    {
        return hash_hmac('sha256', $label, $key, true);
    }

    private static function key(): string
    {
        return self::keyOrNull() ?? throw new DomainRuleException('APP_KEY is not set. Run "php bin/console key:generate" on the server, then try again.');
    }

    private static function keyOrNull(): ?string
    {
        $value = (string) Env::get('APP_KEY', '');
        if (!str_starts_with($value, 'base64:')) {
            return null;
        }
        $key = base64_decode(substr($value, 7), true);
        return $key !== false && strlen($key) === 32 ? $key : null;
    }
}
