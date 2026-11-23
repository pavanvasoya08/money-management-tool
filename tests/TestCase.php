<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Migrator;
use App\Core\Request;
use App\Models\User;
use App\Services\PasswordHasher;
use Database\Seeders\DatabaseSeeder;
use RuntimeException;
use Throwable;

/**
 * Minimal dependency-free test base (no PHPUnit required).
 * Every public method whose name starts with "test" is executed.
 */
abstract class TestCase
{
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    /** Recreate the SQLite test database from the real migrations and seeders. */
    protected function freshDatabase(): void
    {
        $file = (string) getenv('DB_DATABASE');
        App::resetServices();
        if (is_file($file)) {
            unlink($file);
        }
        (new Migrator(App::db(), App::basePath('database/migrations')))->migrate();
        (new DatabaseSeeder(App::db()))->run();
        App::session()->start(false);
        App::setRequest($this->request());
    }

    protected function request(string $method = 'GET', string $path = '/', array $post = [], string $ip = '10.0.0.1', string $ua = 'TestAgent/1.0'): Request
    {
        return new Request($method, $path, [], $post, ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua], []);
    }

    /** Create a user directly (bypassing services) with the given role slugs. */
    protected function makeUser(string $email, array $roles = [], string $password = 'Correct-Horse9!', string $status = 'active', string $name = 'Test User'): array
    {
        $id = User::create([
            'name' => $name, 'email' => $email, 'password_hash' => PasswordHasher::hash($password),
            'status' => $status, 'must_change_password' => 0, 'password_changed_at' => now(),
        ]);
        foreach ($roles as $slug) {
            $roleId = App::db()->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
            App::db()->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId, 'assigned_at' => now()]);
        }
        App::flushAuthorization();
        return User::find($id);
    }

    // ---------------- assertions ----------------
    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== true) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected true, got ' . var_export($value, true));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertions++;
        if ($value !== false) {
            throw new AssertionFailed($message !== '' ? $message : 'Expected false, got ' . var_export($value, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(($message !== '' ? $message . ' — ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            throw new AssertionFailed($message !== '' ? $message : 'Values should differ: ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed($message !== '' ? $message : "Expected to find [{$needle}]");
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed($message !== '' ? $message : "Did not expect to find [{$needle}]");
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message);
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $fn, ?string $messageContains = null): void
    {
        $this->assertions++;
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionFailed("Expected {$class}, got " . $e::class . ': ' . $e->getMessage());
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                throw new AssertionFailed("Exception message [{$e->getMessage()}] does not contain [{$messageContains}]");
            }
            return;
        }
        throw new AssertionFailed("Expected exception {$class} was not thrown");
    }
}

final class AssertionFailed extends RuntimeException
{
}
