<?php

declare(strict_types=1);

namespace HeardLive\Support\Http;

final readonly class HttpResponse
{
    /** @param array<string, string> $headers lowercase names */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
