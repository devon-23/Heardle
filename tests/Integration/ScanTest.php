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
        $this->assertSame('matches', $job['stage']);

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
             'artists_ambiguous' => 0, 'artists_not_found' => 1, 'songs' => 7],
            array_diff_key($job['result'], array_flip(['review', 'unmatched', 'covers', 'auto', 'chosen', 'excluded', 'unsearched'])),
        );
    }

    /** @return array<string, array<string, mixed>> "Performer — Title" → library row */
    private function library(array $filters = []): array
    {
        $rows = $this->services->songs()->search($filters)['songs'];
        $out = [];
        foreach ($rows as $row) {
            $out[$row['performer'] . ' — ' . $row['title_display']] = $row;
        }
        return $out;
    }

    public function testSongsAreExtractedOncePerSongWithEveryShowRemembered(): void
    {
        $this->scan();
        $songs = $this->library();

        $this->assertSame(7, count($songs));
        $this->assertSame(2, (int) $songs['The Midnight Arcade — Neon Hours']['heard'], 'heard at two shows, listed once');
        $this->assertSame(['2025-09-20', '2026-04-18'], [
            $songs['The Midnight Arcade — Neon Hours']['first_heard'],
            $songs['The Midnight Arcade — Neon Hours']['last_heard'],
        ]);
        $this->assertSame('Blondie', $songs['The Midnight Arcade — Heart of Glass']['original_artist_name']);
        $this->assertTrue(!isset($songs['The Midnight Arcade — Intro']), 'tape intro skipped');

        $neon = $this->services->songs()->find((int) $songs['The Midnight Arcade — Neon Hours']['id']);
        $this->assertSame(2, count($neon['performances']));
        $this->assertSame(2, (int) $neon['performances'][0]['times_in_show'], 'reprise at Harbor Pavilion');
        $this->assertSame('https://www.setlist.fm/setlist/fixture/1a2b3c01.html', $neon['performances'][0]['setlist_url']);

        // Openers filter: songs only heard from acts listed after the first.
        $this->assertSame(['Paper Satellites'], array_values(array_unique(array_column($this->library(['show' => 'openers']), 'performer'))));
        $this->assertSame(['Heart of Glass'], array_column($this->library(['show' => 'covers']), 'title_display'));
        $this->assertSame(2, count($this->library(['show' => 'repeats'])));
    }

    public function testUntickingAPerformerHidesItsSongsAndChoosingASetlistAddsThem(): void
    {
        $this->scan();
        $this->pdo->exec("UPDATE performances SET attended = 0, attended_source = 'user'
                          WHERE artist_id = (SELECT id FROM artists WHERE name = 'Paper Satellites')");
        $this->assertSame(4, count($this->library()));

        $p = $this->performances();
        $this->services->scanRepository()->chooseSetlist((int) $p['Quiet Engines @ 2025-09-20']['id'], '1a2b3c05');
        $this->scan();
        $songs = $this->library();
        $this->assertTrue(isset($songs['Quiet Engines — Hum'], $songs['Quiet Engines — Overdrive']));
        $this->assertTrue(!isset($songs['Quiet Engines — Idle']), 'only the chosen day’s songs');

        // Changing your mind about the setlist swaps the songs, and the dropped song's row is cleaned up.
        $this->services->scanRepository()->chooseSetlist((int) $p['Quiet Engines @ 2025-09-20']['id'], '1a2b3c04');
        $this->scan();
        $songs = $this->library();
        $this->assertTrue(isset($songs['Quiet Engines — Idle']) && !isset($songs['Quiet Engines — Overdrive']));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM songs WHERE title_display = 'Overdrive'")->fetchColumn());
    }

    public function testExcludeAndMergeSurviveRescans(): void
    {
        $this->scan();
        $repo = $this->services->songs();
        $songs = $this->library();

        $repo->setExcluded((int) $songs['Paper Satellites — Orbit']['id'], true);
        $repo->merge((int) $songs['The Midnight Arcade — Night Drive']['id'], (int) $songs['The Midnight Arcade — Static Love']['id']);

        // Re-extract everything from scratch.
        $this->pdo->exec('UPDATE performances SET songs_setlist_id = NULL');
        $this->scan();

        $songs = $this->library();
        $this->assertTrue(!isset($songs['Paper Satellites — Orbit']));
        $this->assertSame(1, count($this->library(['show' => 'excluded'])));
        $this->assertTrue(!isset($songs['The Midnight Arcade — Night Drive']), 'merged title folded in again');
        $this->assertSame(2, (int) $songs['The Midnight Arcade — Static Love']['heard']);

        $this->assertThrows(\InvalidArgumentException::class, fn () => $repo->merge(
            (int) $songs['The Midnight Arcade — Static Love']['id'],
            (int) $songs['Paper Satellites — Signal Fire']['id'],
        ));
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

    /** @return array<string, array<string, mixed>> song title → status, picked track and candidate track ids */
    private function matches(): array
    {
        $rows = $this->pdo->query(
            'SELECT s.title_display, m.status, m.confidence, r.track_id,
                    (SELECT group_concat(r2.track_id) FROM (SELECT recording_id FROM song_candidates c WHERE c.song_id = s.id ORDER BY c.rank) c
                     JOIN recordings r2 ON r2.id = c.recording_id) AS candidates
             FROM songs s LEFT JOIN song_matches m ON m.song_id = s.id LEFT JOIN recordings r ON r.id = m.recording_id'
        )->fetchAll();

        return array_column($rows, null, 'title_display');
    }

    public function testScanMatchesSongsToRecordings(): void
    {
        $job = $this->scan();
        $this->assertSame(['review' => 2, 'unmatched' => 1, 'covers' => 1, 'auto' => 3],
            array_intersect_key($job['result'], array_flip(['review', 'unmatched', 'covers', 'auto'])));

        $m = $this->matches();
        $this->assertSame('auto', $m['Neon Hours']['status']);
        $this->assertSame(900001, (int) $m['Neon Hours']['track_id'], 'the original album beats the earlier single');
        $this->assertSame('900001,900002,900003,900004', $m['Neon Hours']['candidates']);
        $this->assertSame(900021, (int) $m['Night Drive']['track_id'], 'the studio album beats the greatest-hits copy');
        $this->assertSame('900021,900022', $m['Night Drive']['candidates'], 'the clean edition of the same album track is folded in');

        $this->assertSame('review', $m['Static Love']['status'], 'only a live version: never accepted automatically');
        $this->assertSame(900011, (int) $m['Static Love']['track_id']);
        $this->assertSame('review', $m['Low Earth']['status'], 'a similar title needs a look');

        $this->assertSame('unmatched', $m['Heart of Glass']['status']);
        $this->assertSame(null, $m['Heart of Glass']['track_id'], 'the original artist’s version is offered, not picked');
        $this->assertSame('900031,900033,900032', $m['Heart of Glass']['candidates'], 'searched by title, then the original artist; no duplicates');
        $this->assertSame('auto', $m['Orbit']['status'], 'found in the artist’s catalog though search misses it');
        $this->assertSame('900061,900062', $m['Orbit']['candidates'], 'the same-named tribute band isn’t looked up');
        $this->assertSame('unmatched', $m['Signal Fire']['status']);
    }

    public function testChosenRecordingsSurviveRescansAndSourceSwitches(): void
    {
        $this->scan();
        $matches = $this->services->matches();
        $staticLove = (int) $this->pdo->query("SELECT id FROM songs WHERE title_display = 'Static Love'")->fetchColumn();
        $acoustic = (int) $this->pdo->query('SELECT id FROM recordings WHERE track_id = 900012')->fetchColumn();
        $matches->choose($staticLove, $acoustic);

        $this->scan();
        $matches->resetIfSourceChanged('live');
        $m = $this->matches();
        $this->assertSame('chosen', $m['Static Love']['status']);
        $this->assertSame(900012, (int) $m['Static Love']['track_id']);
        $this->assertSame(null, $m['Neon Hours']['status'], 'automatic matches are cleared when the data source changes');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM api_cache WHERE provider = 'itunes'")->fetchColumn());
    }

    public function testExcludedSongsAreNotSearched(): void
    {
        $this->scan();
        $this->pdo->exec('DELETE FROM song_matches');
        $this->pdo->exec("UPDATE songs SET excluded = 1 WHERE title_display = 'Signal Fire'");

        $this->scan();
        $this->assertSame(null, $this->matches()['Signal Fire']['status']);
        $this->assertSame('auto', $this->matches()['Neon Hours']['status']);
    }
}
