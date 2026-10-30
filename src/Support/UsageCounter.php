<?php

declare(strict_types=1);

namespace HeardLive\Support;

use Closure;
use PDO;

/** Counts outgoing API requests per provider per UTC day. */
final class UsageCounter
{
    private Closure $today;

    /** @param ?Closure(): string $today YYYY-MM-DD */
    public function __construct(private PDO $pdo, ?Closure $today = null)
    {
        $this->today = $today ?? fn (): string => gmdate('Y-m-d');
    }

    public function record(string $provider): void
    {
        $this->pdo->prepare(
            'INSERT INTO api_usage (provider, day, requests) VALUES (?, ?, 1)
             ON CONFLICT(provider, day) DO UPDATE SET requests = requests + 1'
        )->execute([$provider, ($this->today)()]);
    }

    public function count(string $provider): int
    {
        $stmt = $this->pdo->prepare('SELECT requests FROM api_usage WHERE provider = ? AND day = ?');
        $stmt->execute([$provider, ($this->today)()]);

        return (int) $stmt->fetchColumn();
    }
}
