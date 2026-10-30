<?php

declare(strict_types=1);

namespace HeardLive\Support\Http;

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @throws NetworkError when no HTTP response was received
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
