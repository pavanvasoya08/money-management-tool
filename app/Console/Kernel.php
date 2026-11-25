<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\App;
use App\Core\Config;
use App\Core\Migrator;
use App\Models\Role;
use App\Models\User;
use App\Services\PasswordHasher;
use App\Services\PasswordPolicy;
use Database\Seeders\DatabaseSeeder;
use Throwable;

/**
 * CLI commands: migrations, seeding, Super Admin setup and environment checks.
 */
final class Kernel
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        $command = $args[0] ?? 'help';
        $options = $this->options(array_slice($args, 1));
        try {
            return match ($command) {
                'migrate' => isset($options['pretend']) ? $this->pretend() : $this->migrate(),
                'migrate:rollback' => $this->rollback((int) ($options['step'] ?? 1)),
                'migrate:status' => $this->status(),
                'migrate:fresh' => $this->fresh(isset($options['force'])),
                'db:seed' => $this->seed(),
                'admin:create' => $this->createAdmin($options),
                'app:check' => $this->check(),
                'key:generate' => $this->generateKey(isset($options['force'])),
                'help', '--help', '-h' => $this->help(),
                default => $this->fail("Unknown command [{$command}]. Run: php bin/console help"),
            };
        } catch (Throwable $e) {
            // The console is only usable with server access, so the underlying cause
            // (e.g. the PDO error) is safe to show here — it is never shown on web pages.
            $reason = $e->getPrevious()?->getMessage();
            return $this->fail($e->getMessage() . ($reason ? "\nReason: {$reason}" . $this->hint($reason) : ''));
        }
    }

    private function hint(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'could not find driver') => "\nHint: enable extension=pdo_mysql in php.ini (run \"php --ini\" to find it).",
            str_contains($reason, '2002'), str_contains($reason, 'refused') => "\nHint: the MySQL server is not running or DB_HOST/DB_PORT is wrong. Start MySQL in the XAMPP Control Panel.",
            str_contains($reason, '1045') => "\nHint: wrong DB_USERNAME/DB_PASSWORD. XAMPP default is root with an empty password.",
            str_contains($reason, '1049') => "\nHint: the database in DB_DATABASE does not exist. Create it in phpMyAdmin first.",
            default => '',
        };
    }

    private function migrator(): Migrator
    {
        return new Migrator(App::db(), App::basePath('database/migrations'), fn (string $l) => $this->line($l));
    }

    private function migrate(): int
    {
        $this->migrator()->migrate();
        return 0;
    }

    /** Print the MySQL DDL of pending migrations without running them. */
    private function pretend(): int
    {
        foreach ($this->migrator()->pretend('mysql') as $name => $queries) {
            $this->line("-- {$name}");
            foreach ($queries as $sql) {
                $this->line($sql . ';');
            }
            $this->line('');
        }
        return 0;
    }

    private function rollback(int $steps): int
    {
        $this->migrator()->rollback(max(1, $steps));
        return 0;
    }

    private function status(): int
    {
        foreach ($this->migrator()->status() as $row) {
            $this->line(($row['ran'] ? '[x] ' : '[ ] ') . $row['migration']);
        }
        return 0;
    }

    private function fresh(bool $force): int
    {
        if (Config::get('app.env') === 'production' && !$force) {
            return $this->fail('Refusing to drop all tables in production. Use --force if you are absolutely sure.');
        }
        $this->migrator()->fresh();
        return 0;
    }

    private function seed(): int
    {
        (new DatabaseSeeder(App::db(), fn (string $l) => $this->line($l)))->run();
        $this->line('Seeding complete.');
        return 0;
    }

    /**
     * Create a Super Admin. Interactive by default (password typed hidden).
     * Non-interactive: --name="..." --email=... --generate-password
     *
     * @param array<string, string|true> $o
     */
    private function createAdmin(array $o): int
    {
        $role = Role::findBySlug((string) Config::get('auth.super_admin_role', 'super-admin'));
        if ($role === null) {
            return $this->fail('Super Admin role not found. Run "php bin/console db:seed" first.');
        }

        $name = is_string($o['name'] ?? null) ? $o['name'] : $this->ask('Full name: ');
        $email = mb_strtolower(trim(is_string($o['email'] ?? null) ? $o['email'] : $this->ask('Email: ')));
        if (mb_strlen(trim($name)) < 2) {
            return $this->fail('Name is required.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail('A valid email address is required.');
        }
        if (User::findForAuth($email) !== null) {
            return $this->fail('A user with this email already exists.');
        }

        $generated = isset($o['generate-password']);
        if ($generated) {
            $password = $this->generatePassword();
        } else {
            $password = $this->askHidden('Password: ');
            if ($password !== $this->askHidden('Confirm password: ')) {
                return $this->fail('Passwords do not match.');
            }
        }
        $problems = PasswordPolicy::validate($password, ['name' => $name, 'email' => $email]);
        if ($problems !== []) {
            return $this->fail("Password does not meet the policy:\n - " . implode("\n - ", $problems));
        }

        $db = App::db();
        $id = $db->transaction(function () use ($name, $email, $password, $role, $generated, $db): int {
            $id = User::create([
                'name' => trim($name),
                'email' => $email,
                'password_hash' => PasswordHasher::hash($password),
                'status' => 'active',
                'must_change_password' => $generated ? 1 : 0,
                'password_changed_at' => now(),
            ]);
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $role['id'], 'assigned_by' => null, 'assigned_at' => now()]);
            return $id;
        });
        App::activity()->log('user.super_admin_created', "Super Admin {$email} created from the command line", 'user', $id, userId: null);

        $this->line("Super Admin created: {$email} (user #{$id})");
        if ($generated) {
            $this->line("Temporary password (shown once): {$password}");
            $this->line('The password must be changed at first sign-in.');
        }
        return 0;
    }

    /**
     * Write a new APP_KEY to .env. Refuses to replace an existing key (encrypted bank
     * account numbers would become unreadable) unless --force is given.
     */
    private function generateKey(bool $force): int
    {
        $file = App::basePath('.env');
        if (!is_file($file) || !is_writable($file)) {
            return $this->fail('.env not found or not writable. Copy .env.example to .env first.');
        }
        $env = (string) file_get_contents($file);
        if (\App\Support\FieldCipher::configured() && !$force) {
            return $this->fail('APP_KEY is already set. Replacing it makes stored bank account numbers unreadable. Use --force only on a fresh install.');
        }
        $line = 'APP_KEY=' . \App\Support\FieldCipher::generateKey();
        $env = preg_match('/^APP_KEY=.*$/m', $env) ? (string) preg_replace('/^APP_KEY=.*$/m', $line, $env) : rtrim($env) . "\n" . $line . "\n";
        file_put_contents($file, $env);
        $this->line('[OK] APP_KEY written to .env. Back it up securely: it is needed to read encrypted bank account numbers.');
        return 0;
    }

    private function check(): int
    {
        $ok = true;
        $report = function (string $label, bool $pass, string $detail = '') use (&$ok): void {
            $ok = $ok && $pass;
            $this->line(sprintf('%s %-34s %s', $pass ? '[OK]  ' : '[FAIL]', $label, $detail));
        };
        $report('PHP >= ' . Config::get('app.min_php'), version_compare(PHP_VERSION, (string) Config::get('app.min_php'), '>='), PHP_VERSION);
        foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'session', 'ctype', 'filter'] as $ext) {
            $report("ext-{$ext}", extension_loaded($ext));
        }
        $report('Argon2id hashing (optional)', true, defined('PASSWORD_ARGON2ID') ? 'available' : 'not available — bcrypt will be used');
        foreach (['storage/logs', 'storage/sessions', 'storage/cache', 'storage/uploads'] as $dir) {
            $report("{$dir} writable", is_writable(App::basePath($dir)));
        }
        $report('.env present', is_file(App::basePath('.env')));
        $report('APP_KEY set (bank account encryption)', \App\Support\FieldCipher::configured(), \App\Support\FieldCipher::configured() ? '' : 'run: php bin/console key:generate');
        $report('APP_DEBUG off in production', !(Config::get('app.env') === 'production' && Config::get('app.debug') === true));
        try {
            App::db()->scalar('SELECT 1');
            $report('Database connection', true, App::db()->driver());
        } catch (Throwable $e) {
            $report('Database connection', false, $e->getMessage());
        }
        return $ok ? 0 : 1;
    }

    private function help(): int
    {
        $this->line(<<<TXT
            Business ERP console

              migrate                 Run pending database migrations   [--pretend: print MySQL SQL only]
              key:generate            Create APP_KEY in .env (encrypts bank account numbers) [--force]
              migrate:status          Show migration status
              migrate:rollback        Roll back the last batch   [--step=N]
              migrate:fresh           Drop all tables and re-run migrations (blocked in production unless --force)
              db:seed                 Seed permissions, roles and default settings (safe to re-run)
              admin:create            Create a Super Admin (interactive)
                                      [--name="Full Name" --email=you@example.com --generate-password]
              app:check               Verify PHP version, extensions, permissions and DB connection
            TXT);
        return 0;
    }

    /** @param list<string> $args @return array<string, string|true> */
    private function options(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m)) {
                $out[$m[1]] = $m[2] ?? true;
            }
        }
        return $out;
    }

    private function generatePassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*-_=+?'];
        $chars = array_map(static fn (string $s): string => $s[random_int(0, strlen($s) - 1)], $sets);
        $all = implode('', $sets);
        for ($i = 0; $i < 14; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    private function ask(string $prompt): string
    {
        $this->write($prompt);
        return trim((string) fgets(STDIN));
    }

    private function askHidden(string $prompt): string
    {
        $this->write($prompt);
        $interactive = function_exists('posix_isatty') && posix_isatty(STDIN) && DIRECTORY_SEPARATOR === '/';
        if ($interactive) {
            shell_exec('stty -echo');
        }
        $value = rtrim((string) fgets(STDIN), "\r\n");
        if ($interactive) {
            shell_exec('stty echo');
            $this->line('');
        }
        return $value;
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
        return 1;
    }
}
