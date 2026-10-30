<?php

declare(strict_types=1);

namespace HeardLive\Support;

use Closure;
use HeardLive\Support\Http\HttpResponse;
use PDO;

/** Stores raw API responses for a limited time. */
final class ApiCache
{
    private Closure $now;

    /** @param ?Closure(): int $now unix seconds */
    public function __construct(private PDO $pdo, ?Closure $now = null)
    {
        $this->now = $now ?? fn (): int => time();
    }

    public function get(string $key, int $ttlSeconds): ?HttpResponse
    {
        $stmt = $this->pdo->prepare('SELECT status, body, fetched_at FROM api_cache WHERE cache_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['fetched_at'] + $ttlSeconds <= ($this->now)()) {
            return null;
        }

        return new HttpResponse((int) $row['status'], $row['body']);
    }

    public function put(string $key, string $provider, HttpResponse $response): void
    {
        $this->pdo->prepare(
            'INSERT INTO api_cache (cache_key, provider, status, body, fetched_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(cache_key) DO UPDATE SET status = excluded.status, body = excluded.body, fetched_at = excluded.fetched_at'
        )->execute([$key, $provider, $response->status, $response->body, ($this->now)()]);
    }

    /** Deletes expired entries for a provider. Returns how many were removed. */
    public function prune(string $provider, int $ttlSeconds): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM api_cache WHERE provider = ? AND fetched_at + ? <= ?');
        $stmt->execute([$provider, $ttlSeconds, ($this->now)()]);

        return $stmt->rowCount();
    }
}
