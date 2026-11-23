<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use RuntimeException;
use Tests\TestCase;

/**
 * Drives the real application over HTTP through PHP's built-in server.
 * Each Browser instance has its own cookie jar (= separate user/device).
 */
abstract class HttpTestCase extends TestCase
{
    protected const BASE = 'http://127.0.0.1:8765';
    /** @var resource|null */
    private static $server = null;

    public function setUp(): void
    {
        $this->freshDatabase();
        @unlink(App::basePath('storage/logs/mail.log'));
        self::startServer();
    }

    private static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('ext-curl is required for feature tests');
        }
        $root = App::basePath();
        $env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '1']);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:8765', '-t', $root . '/public', $root . '/server.php'],
            [1 => ['file', $root . '/tests/.tmp/server.log', 'a'], 2 => ['file', $root . '/tests/.tmp/server.log', 'a']],
            $pipes,
            $root,
            $env
        );
        register_shutdown_function(static function (): void {
            if (is_resource(self::$server)) {
                proc_terminate(self::$server);
            }
        });
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', 8765);
            if ($fp) {
                fclose($fp);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Built-in server did not start');
    }

    protected function browser(): Browser
    {
        return new Browser(self::BASE);
    }

    /** Sign in through the real login form. */
    protected function signIn(Browser $b, string $email, string $password = 'Correct-Horse9!', bool $remember = false): array
    {
        $b->get('/login');
        return $b->post('/login', ['email' => $email, 'password' => $password] + ($remember ? ['remember' => '1'] : []));
    }
}

final class Browser
{
    private string $jar;
    public string $lastBody = '';

    public function __construct(private readonly string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status: int, headers: string, body: string, location: string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST with the CSRF token from the last page (unless $csrf === false). */
    public function post(string $path, array $data = [], bool $csrf = true): array
    {
        if ($csrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrf();
        }
        return $this->request('POST', $path, $data);
    }

    /** Multipart POST with files: [field => [path, filename, mime]]. */
    public function upload(string $path, array $data, array $files): array
    {
        $data['_csrf'] ??= $this->csrf();
        foreach ($files as $field => [$file, $name, $mime]) {
            $data[$field] = new \CURLFile($file, $mime, $name);
        }
        return $this->request('POST', $path, $data, multipart: true);
    }

    public function csrf(): string
    {
        if (!preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $this->lastBody, $m)) {
            $this->get('/login');
            preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $this->lastBody, $m);
        }
        return $m[1] ?? '';
    }

    public function cookie(string $name): ?string
    {
        foreach (file($this->jar) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) === 7 && $parts[5] === $name) {
                return $parts[6];
            }
        }
        return null;
    }

    /** Copy only one cookie into a fresh browser (simulates a new device with a remember cookie). */
    public function withOnlyCookie(string $name, string $value): self
    {
        $b = new self($this->base);
        file_put_contents($b->jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\t{$name}\t{$value}\n");
        return $b;
    }

    private function request(string $method, string $path, array $data = [], bool $multipart = false): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_USERAGENT => 'FeatureTest/1.0',
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
        if ($status === 200) {
            $this->lastBody = $body;
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'location' => $m[1] ?? ''];
    }
}
