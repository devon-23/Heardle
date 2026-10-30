<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use HeardLive\Support\Db;
use HeardLive\Support\Migrator;
use RuntimeException;
use Tests\TestCase;

final class MigratorTest extends TestCase
{
    public function testAppliesMigrationsInOrderOnce(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/002_add_b.sql", 'CREATE TABLE b (id INTEGER PRIMARY KEY, a_id INTEGER REFERENCES a(id));');
        file_put_contents("$dir/001_add_a.sql", 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        file_put_contents("$dir/notes.txt", 'ignored');

        $pdo = Db::connect(':memory:');
        $migrator = new Migrator($pdo, $dir);

        $this->assertSame(['001_add_a.sql', '002_add_b.sql'], $migrator->pending());
        $this->assertSame(['001_add_a.sql', '002_add_b.sql'], $migrator->migrate());
        $this->assertSame([], $migrator->migrate());
        $this->assertSame([], $migrator->pending());
    }

    public function testFailedMigrationRollsBackAndIsNotRecorded(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/001_ok.sql", 'CREATE TABLE ok (id INTEGER);');
        file_put_contents("$dir/002_broken.sql", 'CREATE TABLE half (id INTEGER); THIS IS NOT SQL;');

        $pdo = Db::connect(':memory:');
        $migrator = new Migrator($pdo, $dir);

        $this->assertThrows(RuntimeException::class, fn () => $migrator->migrate(), '002_broken.sql');
        $this->assertSame(['002_broken.sql'], $migrator->pending());

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['ok', 'schema_migrations'], $tables);
    }

    public function testFileDatabaseUsesWalAndForeignKeys(): void
    {
        $pdo = Db::connect($this->tempDir() . '/nested/app.sqlite');

        $this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }
}
