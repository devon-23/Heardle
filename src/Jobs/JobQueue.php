<?php

declare(strict_types=1);

namespace HeardLive\Jobs;

use PDO;
use Throwable;

/** A tiny job table the web app writes to and bin/worker.php works through. */
final class JobQueue
{
    public const ACTIVE = ['queued', 'running'];

    public function __construct(private PDO $pdo)
    {
    }

    /** Queues a job, or returns the one of this type that's already queued or running. */
    public function enqueue(string $type, array $payload = []): int
    {
        $existing = $this->pdo->prepare("SELECT id FROM jobs WHERE type = ? AND status IN ('queued', 'running') ORDER BY id LIMIT 1");
        $existing->execute([$type]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $now = gmdate('c');
        $this->pdo->prepare('INSERT INTO jobs (type, payload_json, created_at, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([$type, json_encode($payload), $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return ?array<string, mixed> the oldest queued job, now marked running */
    public function claimNext(): ?array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $job = $this->pdo->query("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 1")->fetch();
            if ($job !== false) {
                $this->pdo->prepare("UPDATE jobs SET status = 'running', error = NULL, updated_at = ? WHERE id = ?")
                    ->execute([gmdate('c'), $job['id']]);
                $job['status'] = 'running';
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $job === false ? null : $this->decode($job);
    }

    /** Jobs left running by a worker that stopped are queued again; the stages pick up where they were. */
    public function requeueInterrupted(): int
    {
        return $this->pdo->exec("UPDATE jobs SET status = 'queued' WHERE status = 'running'") ?: 0;
    }

    public function progress(int $id, string $stage, int $done, int $total, ?string $label): void
    {
        $this->pdo->prepare('UPDATE jobs SET stage = ?, done = ?, total = ?, current_label = ?, updated_at = ? WHERE id = ?')
            ->execute([$stage, $done, $total, $label, gmdate('c'), $id]);
        // Progress doubles as a check-in, so a long job doesn't look like a stopped worker.
        $this->heartbeat();
    }

    public function finish(int $id, array $result): void
    {
        $this->end($id, 'done', null, $result);
    }

    /** @param 'failed'|'paused' $status */
    public function fail(int $id, string $error, string $status = 'failed', array $result = []): void
    {
        $this->end($id, $status, $error, $result);
    }

    public function cancel(int $id): void
    {
        $this->pdo->prepare("UPDATE jobs SET status = 'canceled', current_label = NULL, updated_at = ?, finished_at = ?
                             WHERE id = ? AND status IN ('queued', 'running')")
            ->execute([gmdate('c'), gmdate('c'), $id]);
    }

    public function isCanceled(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT status FROM jobs WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() === 'canceled';
    }

    /** @return ?array<string, mixed> */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $job = $stmt->fetch();

        return $job === false ? null : $this->decode($job);
    }

    /** @return ?array<string, mixed> */
    public function latest(string $type): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE type = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$type]);
        $job = $stmt->fetch();

        return $job === false ? null : $this->decode($job);
    }

    public function heartbeat(): void
    {
        $this->pdo->prepare("INSERT INTO app_meta (key, value) VALUES ('worker_heartbeat', ?)
                             ON CONFLICT(key) DO UPDATE SET value = excluded.value")
            ->execute([(string) time()]);
    }

    /** Seconds since the worker last checked in, or null if it never has. */
    public function workerSilentFor(): ?int
    {
        $value = $this->pdo->query("SELECT value FROM app_meta WHERE key = 'worker_heartbeat'")->fetchColumn();

        return $value === false ? null : time() - (int) $value;
    }

    private function end(int $id, string $status, ?string $error, array $result): void
    {
        // A cancel that arrived while the job was finishing wins.
        $this->pdo->prepare("UPDATE jobs SET status = ?, error = ?, result_json = ?, current_label = NULL, updated_at = ?, finished_at = ?
                             WHERE id = ? AND status <> 'canceled'")
            ->execute([$status, $error, json_encode($result), gmdate('c'), gmdate('c'), $id]);
    }

    private function decode(array $job): array
    {
        $job['payload'] = json_decode($job['payload_json'], true) ?: [];
        $job['result'] = json_decode($job['result_json'], true) ?: [];
        foreach (['id', 'total', 'done'] as $key) {
            $job[$key] = (int) $job[$key];
        }

        return $job;
    }
}
