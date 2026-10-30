<?php

declare(strict_types=1);

namespace HeardLive\Jobs;

use HeardLive\Matching\ItunesException;
use HeardLive\Matching\MatchRepository;
use HeardLive\Matching\RecordingMatcher;
use HeardLive\Setlists\ArtistResolver;
use HeardLive\Setlists\ScanRepository;
use HeardLive\Setlists\SetlistFmException;
use HeardLive\Setlists\SetlistMatcher;
use HeardLive\Songs\SongExtractor;
use HeardLive\Songs\SongRepository;

/**
 * The scan pipeline: artists → setlists → songs → matches. Each stage only touches items that still
 * need work, so a scan that stops part way (cancel, crash, rate budget) resumes cleanly next time.
 */
final class ScanJob
{
    public const TYPE = 'scan';
    public const STAGES = ['artists', 'setlists', 'songs', 'matches'];

    public function __construct(
        private JobQueue $queue,
        private ScanRepository $repo,
        private ArtistResolver $resolver,
        private SetlistMatcher $matcher,
        private SongRepository $songs,
        private SongExtractor $extractor,
        private MatchRepository $matches,
        private RecordingMatcher $recordings,
        private string $source,
        private string $itunesSource,
    ) {
    }

    public function run(int $jobId): void
    {
        $this->repo->resetIfSourceChanged($this->source);
        $this->matches->resetIfSourceChanged($this->itunesSource);
        $this->repo->markPerformancesOfMissingArtists();

        try {
            if (!$this->resolveArtists($jobId) || !$this->matchSetlists($jobId) || !$this->extractSongs($jobId) || !$this->matchRecordings($jobId)) {
                return; // canceled
            }
        } catch (SetlistFmException $e) {
            $this->queue->fail($jobId, $e->getMessage(), $e->kind === 'budget' ? 'paused' : 'failed', $this->repo->coverage());
            return;
        } catch (ItunesException $e) {
            // Everything before this stage is saved; scanning again carries on matching.
            $this->queue->fail($jobId, $e->getMessage(), 'paused', $this->repo->coverage());
            return;
        }

        $this->queue->finish($jobId, $this->repo->coverage() + ['songs' => $this->songs->stats()['songs']] + $this->matches->counts());
    }

    private function resolveArtists(int $jobId): bool
    {
        return $this->each($jobId, 'artists', $this->repo->artistsToResolve(), fn (array $a) => $a['name'], function (array $artist): void {
            $this->repo->saveArtist($artist['id'], $this->resolver->resolve($artist['name'], $artist['dates']));
        });
    }

    private function matchSetlists(int $jobId): bool
    {
        return $this->each($jobId, 'setlists', $this->repo->performancesToMatch(), self::label(...), function (array $p): void {
            $this->repo->saveMatch((int) $p['id'], $this->matcher->match($p, (string) $p['mbid']));
        }, onError: fn (array $p, SetlistFmException $e) => $this->repo->markError((int) $p['id'], $e->getMessage()));
    }

    private function extractSongs(int $jobId): bool
    {
        // A failure leaves the performance unextracted, so the next scan retries it.
        $finished = $this->each($jobId, 'songs', $this->songs->performancesToExtract(), self::label(...), function (array $p): void {
            $this->extractor->extract($p);
        });
        $this->songs->pruneOrphans();

        return $finished;
    }

    private function matchRecordings(int $jobId): bool
    {
        $label = fn (array $song) => $song['title_display'] . ' · ' . $song['performer'];

        // A failed search leaves the song unmatched, so the next scan retries it.
        return $this->each($jobId, 'matches', $this->matches->songsToMatch(), $label, function (array $song): void {
            $this->matches->save((int) $song['id'], $this->recordings->match($song));
        });
    }

    /**
     * Runs $work over $items with progress and cancel checks. Errors that stop the scan are rethrown;
     * other API errors go to $onError (or are skipped) so one bad item doesn't end the run.
     *
     * @param list<array<string, mixed>> $items
     */
    private function each(int $jobId, string $stage, array $items, callable $label, callable $work, ?callable $onError = null): bool
    {
        $total = count($items);
        foreach ($items as $i => $item) {
            if ($this->queue->isCanceled($jobId)) {
                return false;
            }
            $this->queue->progress($jobId, $stage, $i, $total, $label($item));
            try {
                $work($item);
            } catch (SetlistFmException|ItunesException $e) {
                if ($e->stopsTheScan()) {
                    throw $e;
                }
                $onError && $onError($item, $e);
            }
        }
        $this->queue->progress($jobId, $stage, $total, $total, null);

        return true;
    }

    private static function label(array $performance): string
    {
        return $performance['artist'] . ' · ' . (new \DateTimeImmutable($performance['start_date']))->format('M j, Y');
    }
}
