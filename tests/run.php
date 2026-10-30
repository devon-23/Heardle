<?php

declare(strict_types=1);

// Zero-dependency test runner. Usage: php tests/run.php [filter]
// Finds tests/**/*Test.php, runs every public test* method, exits non-zero on failure.

$root = dirname(__DIR__);
require $root . '/src/autoload.php';
require __DIR__ . '/TestCase.php';

use Tests\AssertionFailed;
use Tests\TestCase;

$filter = $argv[1] ?? '';

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
$testFiles = [];
foreach ($files as $file) {
    if (str_ends_with($file->getFilename(), 'Test.php')) {
        $testFiles[] = $file->getPathname();
    }
}
sort($testFiles);
foreach ($testFiles as $file) {
    require_once $file;
}

$passed = 0;
$failures = [];

foreach (get_declared_classes() as $class) {
    if (!is_subclass_of($class, TestCase::class) || (new ReflectionClass($class))->isAbstract()) {
        continue;
    }
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $class . '::' . $method->getName();
        if (!str_starts_with($method->getName(), 'test') || ($filter !== '' && !str_contains($name, $filter))) {
            continue;
        }

        /** @var TestCase $test */
        $test = new $class();
        try {
            $test->setUp();
            $test->{$method->getName()}();
            $passed++;
            echo '.';
        } catch (AssertionFailed $e) {
            $failures[] = [$name, $e->getMessage()];
            echo 'F';
        } catch (Throwable $e) {
            $failures[] = [$name, $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()];
            echo 'E';
        } finally {
            $test->tearDown();
            foreach ($test->tempDirs as $dir) {
                removeDir($dir);
            }
        }
    }
}

echo "\n\n";
foreach ($failures as [$name, $message]) {
    echo "FAIL $name\n  " . str_replace("\n", "\n  ", $message) . "\n\n";
}
printf("%d passed, %d failed\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
