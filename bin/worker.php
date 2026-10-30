<?php

declare(strict_types=1);

// Background worker: runs queued jobs (setlist scans) one at a time. Started by bin/dev.
// Usage: php bin/worker.php [--once]   (--once: run whatever is queued, then exit)

use HeardLive\Jobs\ScanJob;
use HeardLive\Services;
use HeardLive\Support\Config;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

$services = new Services(Config::load($root));
$queue = $services->jobs();
$once = in_array('--once', $argv, true);

$requeued = $queue->requeueInterrupted();
fwrite(STDOUT, sprintf("Worker ready (%s mode)%s\n", $services->config->setlistFmMode, $requeued ? ", resuming $requeued job(s)" : ''));

while (true) {
    $queue->heartbeat();
    $job = $queue->claimNext();

    if ($job === null) {
        if ($once) {
            break;
        }
        sleep(1);
        continue;
    }

    fwrite(STDOUT, "Job {$job['id']} ({$job['type']}) started\n");
    try {
        match ($job['type']) {
            ScanJob::TYPE => $services->scanJob()->run($job['id']),
            default => $queue->fail($job['id'], "Unknown job type {$job['type']}"),
        };
    } catch (Throwable $e) {
        fwrite(STDERR, (string) $e . "\n");
        $queue->fail($job['id'], $services->config->isLocal() ? $e->getMessage() : 'The scan hit an unexpected error.');
    }
    $finished = $queue->find($job['id']);
    fwrite(STDOUT, "Job {$job['id']} {$finished['status']}\n");
}
