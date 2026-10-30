<?php

declare(strict_types=1);

namespace HeardLive\Support;

use Closure;
use PDO;
use Throwable;

/**
 * Spaces out requests per provider. Slots are reserved in SQLite so the web process
 * and the worker share one schedule.
 */
final class RateLimiter
{
    private Closure $now;
    private Closure $sleep;

    /**
     * @param ?Closure(): float $now   seconds, fractional
     * @param ?Closure(float): void $sleep
     */
    public function __construct(private PDO $pdo, ?Closure $now = null, ?Closure $sleep = null)
    {
        $this->now = $now ?? fn (): float => microtime(true);
        $this->sleep = $sleep ?? function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /** Waits until this caller's slot. Returns the seconds waited. */
    public function acquire(string $provider, float $interval): float
    {
        $now = ($this->now)();
        $slot = $this->reserve($provider, $now, $interval);
        $wait = $slot - $now;
        if ($wait > 0) {
            ($this->sleep)($wait);
        }

        return max(0.0, $wait);
    }

    /** Pushes the next slot back, e.g. after a 429 with Retry-After. */
    public function delayUntil(string $provider, float $timestamp): void
    {
        $this->pdo->prepare(
            'INSERT INTO rate_limits (provider, next_allowed_at) VALUES (?, ?)
             ON CONFLICT(provider) DO UPDATE SET next_allowed_at = MAX(next_allowed_at, excluded.next_allowed_at)'
        )->execute([$provider, $timestamp]);
    }

    private function reserve(string $provider, float $now, float $interval): float
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->pdo->prepare('SELECT next_allowed_at FROM rate_limits WHERE provider = ?');
            $stmt->execute([$provider]);
            $next = $stmt->fetchColumn();
            $slot = max($now, $next === false ? 0.0 : (float) $next);

            $this->pdo->prepare(
                'INSERT INTO rate_limits (provider, next_allowed_at) VALUES (?, ?)
                 ON CONFLICT(provider) DO UPDATE SET next_allowed_at = excluded.next_allowed_at'
            )->execute([$provider, $slot + $interval]);
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $slot;
    }
}
