<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;

/**
 * Password strength rules, enforced server-side everywhere a password is set.
 */
final class PasswordPolicy
{
    private const COMMON = [
        'password', 'password1', 'password123', 'passw0rd', '123456', '12345678', '123456789', '1234567890',
        'qwerty', 'qwerty123', 'qwertyuiop', 'abc123', 'admin', 'admin123', 'administrator', 'welcome',
        'welcome1', 'welcome123', 'letmein', 'iloveyou', 'monkey', 'dragon', 'sunshine', 'india123',
        'changeme', 'default', 'secret', 'p@ssw0rd', 'p@ssword', 'superadmin', 'business', 'erp12345',
    ];

    public static function minLength(): int
    {
        return max(8, (int) App::settings()->get('password_min_length', Config::get('auth.password.min_length', 10)));
    }

    /**
     * @param array{name?: string|null, email?: string|null} $context
     * @return list<string> human-readable problems (empty = OK)
     */
    public static function validate(string $password, array $context = []): array
    {
        $errors = [];
        $min = self::minLength();
        $maxChars = (int) Config::get('auth.password.max_length', 128);

        if (mb_strlen($password) < $min) {
            $errors[] = "Use at least {$min} characters.";
        }
        if (mb_strlen($password) > $maxChars || strlen($password) > PasswordHasher::maxBytes()) {
            $errors[] = 'Password is too long.';
        }
        if (!preg_match('/\p{Lu}/u', $password)) {
            $errors[] = 'Include at least one uppercase letter.';
        }
        if (!preg_match('/\p{Ll}/u', $password)) {
            $errors[] = 'Include at least one lowercase letter.';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Include at least one number.';
        }
        if (!preg_match('/[^\p{L}\d]/u', $password)) {
            $errors[] = 'Include at least one symbol (e.g. ! @ # $ %).';
        }
        if (preg_match('/(.)\1{3,}/u', $password)) {
            $errors[] = 'Avoid repeating the same character 4 or more times.';
        }

        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || in_array(preg_replace('/[^a-z0-9@]/', '', $lower), self::COMMON, true)) {
            $errors[] = 'This password is too common.';
        }

        $personal = [];
        if (!empty($context['email'])) {
            $personal[] = mb_strtolower(explode('@', (string) $context['email'])[0]);
        }
        foreach (preg_split('/\s+/', mb_strtolower((string) ($context['name'] ?? ''))) ?: [] as $part) {
            $personal[] = $part;
        }
        foreach ($personal as $word) {
            if (mb_strlen($word) >= 4 && str_contains($lower, $word)) {
                $errors[] = 'Do not include your name or email address.';
                break;
            }
        }
        return $errors;
    }

    public static function description(): string
    {
        return 'At least ' . self::minLength() . ' characters with uppercase, lowercase, a number and a symbol. Avoid your name or email.';
    }
}
