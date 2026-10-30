<?php

declare(strict_types=1);

namespace HeardLive\Jobs;

use HeardLive\Setlists\ArtistResolver;
use HeardLive\Setlists\ScanRepository;
use HeardLive\Setlists\SetlistFmException;
use HeardLive\Setlists\SetlistMatcher;

/**
 * The scan pipeline. Each stage only touches items that still need work, so a scan
 * that stops part way (cancel, crash, rate budget) resumes cleanly next time.
 */
final class ScanJob
{
    public const TYPE = 'scan';

    public function __construct(
        private JobQueue $queue,
        private ScanRepository $repo,
        private ArtistResolver $resolver,
        private SetlistMatcher $matcher,
        private string $source,
    ) {
    }

    public function run(int $jobId): void
    {
        $this->repo->resetIfSourceChanged($this->source);
        $this->repo->markPerformancesOfMissingArtists();

        try {
            if (!$this->resolveArtists($jobId) || !$this->matchSetlists($jobId)) {
                return; // canceled
            }
        } catch (SetlistFmException $e) {
            $this->queue->fail($jobId, $e->getMessage(), $e->kind === 'budget' ? 'paused' : 'failed', $this->repo->coverage());
            return;
        }

        $this->queue->finish($jobId, $this->repo->coverage());
    }

    private function resolveArtists(int $jobId): bool
    {
        $artists = $this->repo->artistsToResolve();
        $total = count($artists);
        foreach ($artists as $i => $artist) {
            if ($this->queue->isCanceled($jobId)) {
                return false;
            }
            $this->queue->progress($jobId, 'artists', $i, $total, $artist['name']);
            try {
                $this->repo->saveArtist($artist['id'], $this->resolver->resolve($artist['name'], $artist['dates']));
            } catch (SetlistFmException $e) {
                if ($e->stopsTheScan()) {
                    throw $e;
                }
                // Leave the artist pending; the next scan retries it.
            }
        }
        $this->queue->progress($jobId, 'artists', $total, $total, null);

        return true;
    }

    private function matchSetlists(int $jobId): bool
    {
        $performances = $this->repo->performancesToMatch();
        $total = count($performances);
        foreach ($performances as $i => $p) {
            if ($this->queue->isCanceled($jobId)) {
                return false;
            }
            $label = $p['artist'] . ' · ' . (new \DateTimeImmutable($p['start_date']))->format('M j, Y');
            $this->queue->progress($jobId, 'setlists', $i, $total, $label);
            try {
                $this->repo->saveMatch((int) $p['id'], $this->matcher->match($p, (string) $p['mbid']));
            } catch (SetlistFmException $e) {
                if ($e->stopsTheScan()) {
                    throw $e;
                }
                $this->repo->markError((int) $p['id'], $e->getMessage());
            }
        }
        $this->queue->progress($jobId, 'setlists', $total, $total, null);

        return true;
    }
}
