<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    public array $cookies = [];

    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        $this->headers = ['Content-Type' => 'text/html; charset=UTF-8'] + $headers;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        // Only allow same-site relative redirects to prevent open redirects.
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            $url = '/';
        }
        return new self('', $status, ['Location' => $url]);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** @param array<string, mixed> $options */
    public function cookie(string $name, string $value, array $options): static
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    /** Flash a message for the next request (redirect responses). */
    public function with(string $type, string $message): static
    {
        App::session()->flash('toast', ['type' => $type, 'message' => $message]);
        return $this;
    }

    /** @param array<string, list<string>> $errors @param array<string, mixed> $input */
    public function withErrors(array $errors, array $input = []): static
    {
        App::session()->flash('errors', $errors);
        App::session()->flash('old', self::safeOld($input));
        return $this;
    }

    /** Never flash secrets back into the form. */
    public static function safeOld(array $input): array
    {
        return array_filter(
            $input,
            static fn (string $k): bool => !preg_match('/pass|token|secret|_csrf/i', $k),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value));
            }
            foreach ($this->cookies as $c) {
                setcookie($c['name'], $c['value'], $c['options']);
            }
        }
        echo $this->body;
    }
}
