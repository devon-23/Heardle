<?php

declare(strict_types=1);

namespace HeardLive\Support\Http;

use Closure;

/**
 * Serves saved JSON responses from disk instead of calling the network. A mapper turns each URL
 * into a fixture path; a missing file is answered with 404, like an empty search on the real API.
 */
final class FixtureHttpClient implements HttpClient
{
    /** @var list<string> every URL requested, for tests */
    public array $requests = [];

    /** @param Closure(string): ?string $mapper URL → path relative to $dir, or null for 404 */
    public function __construct(private string $dir, private Closure $mapper)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = $url;
        $relative = ($this->mapper)($url);
        $file = $relative === null ? null : $this->dir . '/' . $relative;

        if ($file === null || !is_file($file)) {
            return new HttpResponse(404, '{"code":404,"status":"Not Found","message":"not found"}');
        }

        return new HttpResponse(200, (string) file_get_contents($file), ['content-type' => 'application/json']);
    }
}
