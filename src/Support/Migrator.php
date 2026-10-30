<?php

declare(strict_types=1);

namespace HeardLive\Support;

use PDO;
use Throwable;

/** Applies migrations/NNN_name.sql files in order, each in its own transaction. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @return list<string> names of migrations not yet applied */
    public function pending(): array
    {
        $this->ensureTable();
        $applied = $this->pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($this->available(), $applied));
    }

    /** @return list<string> names of migrations applied by this call */
    public function migrate(): array
    {
        $ran = [];
        foreach ($this->pending() as $name) {
            $sql = (string) file_get_contents($this->dir . '/' . $name);
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $insert = $this->pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)');
                $insert->execute([$name, gmdate('c')]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw new \RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
            }
            $ran[] = $name;
        }

        return $ran;
    }

    /** @return list<string> */
    private function available(): array
    {
        $files = glob($this->dir . '/[0-9][0-9][0-9]_*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_STRING);

        return $names;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    }
}
