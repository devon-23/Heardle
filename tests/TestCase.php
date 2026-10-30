<?php

declare(strict_types=1);

namespace Tests;

use Throwable;

final class AssertionFailed extends \Exception
{
}

/** Base class for tests run by tests/run.php. Public test* methods are run; setUp/tearDown wrap each. */
abstract class TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? "$message\n" : '')
                . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            $this->fail($message);
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $this->fail($message !== '' ? $message : "Expected to find \"$needle\"");
        }
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $fn, string $messageContains = ''): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                $this->fail("Expected $class, got " . $e::class . ': ' . $e->getMessage());
            }
            if ($messageContains !== '' && !str_contains($e->getMessage(), $messageContains)) {
                $this->fail("Exception message \"{$e->getMessage()}\" doesn't contain \"$messageContains\"");
            }
            return;
        }
        $this->fail("Expected $class to be thrown");
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    /** An in-memory database with every migration applied. */
    protected function migratedDb(): \PDO
    {
        $pdo = \HeardLive\Support\Db::connect(':memory:');
        (new \HeardLive\Support\Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();

        return $pdo;
    }

    /** Creates a fresh temporary directory, removed by the runner after the test. */
    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/heardlive-test-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    /** @var list<string> */
    public array $tempDirs = [];
}
