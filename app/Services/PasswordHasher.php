<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Wraps password_hash()/password_verify(). Uses Argon2id when PHP was built
 * with it, otherwise bcrypt (cost 12). Plaintext passwords are never stored.
 */
final class PasswordHasher
{
    private static ?string $dummy = null;

    public static function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, int> */
    private static function options(): array
    {
        return self::algorithm() === PASSWORD_BCRYPT ? ['cost' => 12] : [];
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm(), self::options());
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm(), self::options());
    }

    /** bcrypt only uses the first 72 bytes, so the policy limits length in that case. */
    public static function maxBytes(): int
    {
        return self::algorithm() === PASSWORD_BCRYPT ? 72 : 4096;
    }

    /** Spend comparable time when the user does not exist (prevents user enumeration by timing). */
    public static function dummyVerify(string $password): void
    {
        self::$dummy ??= self::hash(bin2hex(random_bytes(16)));
        password_verify($password, self::$dummy);
    }
}
