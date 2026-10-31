<?php

declare(strict_types=1);

namespace HeardLive\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($body, $status, ['Content-Type' => 'application/json']);
    }

    /** A file to save, e.g. a CSV export. $filename may contain any characters; it's encoded for the header. */
    public static function download(string $body, string $filename, string $type = 'text/csv; charset=utf-8'): self
    {
        // The plain filename= takes printable ASCII without " or \; filename*= carries the real name.
        $ascii = (string) preg_replace('/[^\x20-\x21\x23-\x5B\x5D-\x7E]/u', '_', $filename);

        return new self($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => "attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function redirect(string $location): self
    {
        return new self('', 303, ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
