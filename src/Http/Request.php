<?php

declare(strict_types=1);

namespace HeardLive\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers lowercase names
     * @param array<string, array<string, mixed>> $files  $_FILES-shaped
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
        public array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: is_string($path) && $path !== '' ? $path : '/',
            query: $_GET,
            body: $_POST,
            headers: array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER),
            files: $_FILES,
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('accept'), 'application/json');
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
