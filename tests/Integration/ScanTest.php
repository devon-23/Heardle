<?php

declare(strict_types=1);

namespace Tests\Integration;

use HeardLive\Import\ConcertArchivesCsv;
use HeardLive\Import\Importer;
use HeardLive\Jobs\ScanJob;
use HeardLive\Services;
use HeardLive\Support\Config;
use PDO;
use Tests\TestCase;

/** Runs the full setlist scan over the sample CSV against the fixture setlist.fm responses. */
final class ScanTest extends TestCase
{
    private PDO $pdo;
    private Services $services;

    public function setUp(): void
    {
        $this->pdo = $this->migratedDb();
        $this->services = new Services(Config::fromArray(['DB_PATH' => ':memory:'], dirname(__DIR__, 2)), $this->pdo);
        $this->import(dirname(__DIR__) . '/fixtures/concert-archives/sample.csv');
    }

    private function import(string $file): void
    {
        (new Importer($this->pdo))->import(ConcertArchivesCsv::parseFile($file), basename($file));
    }

    private function scan(): array
    {
        $queue = $this->services->jobs();
        $id = $queue->enqueue(ScanJob::TYPE);
        $job = $queue->claimNext();
        $this->assertSame($id, $job['id']);
        $this->services->scanJob()->run($id);

        return $queue->find($id);
    }

    /** @return array<string, array<string, mixed>> "Artist @ YYYY-MM-DD" → performance row */
    private function performances(): array
    {
        $rows = $this->pdo->query(
            'SELECT a.name, e.start_date, p.* FROM performances p JOIN artists a ON a.id = p.artist_id JOIN events e ON e.id = p.event_id'
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['name'] . ' @ ' . $row['start_date']] = $row;
        }
        return $out;
    }

    private function artist(string $name): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artists WHERE name = ?');
        $stmt->execute([$name]);
        return $stmt->fetch();
    }

    public function testScanResolvesArtistsAndMatchesSetlists(): void
    {
        $job = $this->scan();

        $this->assertSame('done', $job['status']);
        $this->assertSame('setlists', $job['stage']);

        // Artists
        $this->assertSame('resolved', $this->artist('The Midnight Arcade')['resolution']);
        $satellites = $this->artist('Paper Satellites');
        $this->assertSame('resolved', $satellites['resolution'], 'the same-named artist with a setlist on your date wins');
        $this->assertSame('00000000-0000-4000-8000-000000000002', $satellites['mbid']);
        $this->assertSame('unresolved', $this->artist('Basement Lanterns')['resolution']);
        $this->assertSame('pending', $this->artist('Static Bloom')['resolution'], 'artists you didn’t see are never looked up');

        $p = $this->performances();
        // Harbor Pavilion: headliner and seen opener matched; the missed opener untouched.
        $this->assertSame('matched', $p['The Midnight Arcade @ 2026-04-18']['setlist_status']);
        $this->assertSame(5, (int) $p['The Midnight Arcade @ 2026-04-18']['setlist_song_count'], 'tape intro not counted');
        $this->assertSame('https://www.setlist.fm/setlist/fixture/1a2b3c01.html', $p['The Midnight Arcade @ 2026-04-18']['setlist_url']);
        $this->assertSame('matched', $p['Paper Satellites @ 2026-04-18']['setlist_status']);
        $this->assertSame('pending', $p['Low Tide Choir @ 2026-04-18']['setlist_status']);

        // No setlist that night: missing, never borrowed from another date.
        $this->assertSame('missing', $p['Paper Satellites @ 2026-03-02']['setlist_status']);
        $this->assertSame(null, $p['Paper Satellites @ 2026-03-02']['setlist_id']);
        $this->assertSame('artist_not_found', $p['Basement Lanterns @ 2026-03-02']['setlist_note']);

        // Two-day festival: one day only → matched; both days → you choose.
        $this->assertSame('matched', $p['The Midnight Arcade @ 2025-09-20']['setlist_status']);
        $this->assertSame('2025-09-21', $p['The Midnight Arcade @ 2025-09-20']['setlist_event_date']);
        $this->assertSame('ambiguous', $p['Quiet Engines @ 2025-09-20']['setlist_status']);
        $this->assertSame('several_setlists', $p['Quiet Engines @ 2025-09-20']['setlist_note']);
        $this->assertSame(2, count(json_decode($p['Quiet Engines @ 2025-09-20']['setlist_candidates_json'], true)));

        // VIP show: confirm even with one setlist.
        $this->assertSame('ambiguous', $p['Low Tide Choir @ 2025-07-04']['setlist_status']);
        $this->assertSame('special_show', $p['Low Tide Choir @ 2025-07-04']['setlist_note']);

        $this->assertSame(
            ['pending' => 0, 'matched' => 3, 'ambiguous' => 2, 'missing' => 2, 'empty' => 0, 'error' => 0, 'total' => 7,
             'artists_ambiguous' => 0, 'artists_not_found' => 1],
            $job['result'],
        );
    }

    public function testSecondScanDoesNoNewWork(): void
    {
        $this->scan();
        $before = (int) $this->pdo->query('SELECT SUM(requests) FROM api_usage')->fetchColumn();

        $this->assertSame('done', $this->scan()['status']);
        $after = (int) $this->pdo->query('SELECT SUM(requests) FROM api_usage')->fetchColumn();
        $this->assertSame($before, $after);
    }

    public function testUserChoicesAreKeptAndAffectStatus(): void
    {
        $this->scan();
        $repo = $this->services->scanRepository();
        $p = $this->performances();

        $repo->chooseSetlist((int) $p['Quiet Engines @ 2025-09-20']['id'], '1a2b3c05');
        $repo->chooseSetlist((int) $p['Low Tide Choir @ 2025-07-04']['id'], null);
        $repo->chooseArtist((int) $this->artist('Basement Lanterns')['id'], null);

        $this->scan();
        $p = $this->performances();
        $this->assertSame(['matched', 'user', '2025-09-21'], [
            $p['Quiet Engines @ 2025-09-20']['setlist_status'],
            $p['Quiet Engines @ 2025-09-20']['setlist_source'],
            $p['Quiet Engines @ 2025-09-20']['setlist_event_date'],
        ]);
        $this->assertSame(['missing', 'user_none'], [$p['Low Tide Choir @ 2025-07-04']['setlist_status'], $p['Low Tide Choir @ 2025-07-04']['setlist_note']]);
        $this->assertSame('user_none', $this->artist('Basement Lanterns')['resolution']);

        $this->assertThrows(\InvalidArgumentException::class, fn () => $repo->chooseSetlist((int) $p['Quiet Engines @ 2025-09-20']['id'], 'not-a-candidate'));
    }

    public function testNewlyTickedPerformerIsPickedUpByNextScan(): void
    {
        $this->scan();
        // You did catch Low Tide Choir at Harbor Pavilion after all — but there's no setlist for them that night.
        $this->pdo->exec("UPDATE performances SET attended = 1, attended_source = 'user'
                          WHERE artist_id = (SELECT id FROM artists WHERE name = 'Low Tide Choir')
                            AND event_id = (SELECT id FROM events WHERE start_date = '2026-04-18')");

        $this->scan();
        $this->assertSame('missing', $this->performances()['Low Tide Choir @ 2026-04-18']['setlist_status']);
    }

    public function testSwitchingDataSourceResetsAutomaticResults(): void
    {
        $this->scan();
        $repo = $this->services->scanRepository();
        $p = $this->performances();
        $repo->chooseSetlist((int) $p['Quiet Engines @ 2025-09-20']['id'], '1a2b3c04');

        $this->assertTrue($repo->resetIfSourceChanged('live'));
        $p = $this->performances();
        $this->assertSame('pending', $p['The Midnight Arcade @ 2026-04-18']['setlist_status']);
        $this->assertSame('matched', $p['Quiet Engines @ 2025-09-20']['setlist_status'], 'user choice kept');
        $this->assertSame('pending', $this->artist('The Midnight Arcade')['resolution']);
        $this->assertSame(false, $repo->resetIfSourceChanged('live'));
    }

    public function testCanceledScanStopsAndCanResume(): void
    {
        $queue = $this->services->jobs();
        $id = $queue->enqueue(ScanJob::TYPE);
        $queue->claimNext();
        $queue->cancel($id);
        $this->services->scanJob()->run($id);

        $this->assertSame('canceled', $queue->find($id)['status']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM artists WHERE resolution <> 'pending'")->fetchColumn());

        $this->assertSame('done', $this->scan()['status']);
    }

    public function testEnqueueReusesActiveJobAndRequeuesInterrupted(): void
    {
        $queue = $this->services->jobs();
        $first = $queue->enqueue(ScanJob::TYPE);
        $this->assertSame($first, $queue->enqueue(ScanJob::TYPE));

        $queue->claimNext();
        $this->assertSame(1, $queue->requeueInterrupted());
        $this->assertSame('queued', $queue->find($first)['status']);
    }
}
