<?php

declare(strict_types=1);

namespace Tests\Integration;

use HeardLive\Export\ExportRepository;
use HeardLive\Import\ConcertArchivesCsv;
use HeardLive\Import\Importer;
use HeardLive\Services;
use HeardLive\Stats\StatsRepository;
use HeardLive\Support\Config;
use PDO;
use Tests\TestCase;

/** The playlist export and the stats, over a fixture scan of the sample CSV. */
final class ExportTest extends TestCase
{
    private PDO $pdo;
    private ExportRepository $exports;
    private StatsRepository $stats;

    public function setUp(): void
    {
        $this->pdo = $this->migratedDb();
        $services = new Services(Config::fromArray(['DB_PATH' => ':memory:'], dirname(__DIR__, 2)), $this->pdo);
        (new Importer($this->pdo))->import(ConcertArchivesCsv::parseFile(dirname(__DIR__) . '/fixtures/concert-archives/sample.csv'), 'sample.csv');
        $id = $services->jobs()->enqueue('scan');
        $services->jobs()->claimNext();
        $services->scanJob()->run($id);
        $this->exports = new ExportRepository($this->pdo);
        $this->stats = new StatsRepository($this->pdo);
    }

    private function song(string $title): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM songs WHERE title_display = ?');
        $stmt->execute([$title]);

        return (int) $stmt->fetchColumn();
    }

    public function testPlaylistHasPickedRecordingsInTheOrderFirstHeard(): void
    {
        $tracks = $this->exports->tracks();

        $this->assertSame([900001, 900021, 900061], array_map(fn ($t) => (int) $t['track_id'], $tracks), 'auto matches only; review and unmatched wait');
        $this->assertSame('Night Drive (feat. Low Tide Choir)', $tracks[1]['track_name'], 'Apple Music’s title, so the transfer service finds it');
        $this->assertSame(2, (int) $tracks[0]['heard']);
        $this->assertSame(['2025-09-20', '2026-04-18'], [$tracks[0]['first_heard'], $tracks[0]['last_heard']]);
        $this->assertSame(['The Midnight Arcade'], $tracks[0]['performers']);

        $this->assertSame([900001, 900021], array_map(fn ($t) => (int) $t['track_id'], $this->exports->tracks(minHeard: 2)));
        $this->assertSame(900061, (int) $this->exports->tracks(order: 'heard')[2]['track_id']);
    }

    public function testSongsSharingARecordingAreOneTrack(): void
    {
        // A cover matched to the original's recording, say: two songs, one Apple Music track.
        $neonHours = (int) $this->pdo->query('SELECT recording_id FROM song_matches WHERE song_id = ' . $this->song('Neon Hours'))->fetchColumn();
        $this->pdo->prepare("UPDATE song_matches SET recording_id = ?, status = 'chosen' WHERE song_id = ?")->execute([$neonHours, $this->song('Orbit')]);

        $tracks = $this->exports->tracks();
        $this->assertSame([900001, 900021], array_map(fn ($t) => (int) $t['track_id'], $tracks));
        $this->assertSame(3, (int) $tracks[0]['heard'], 'every show either song was played at');
        $performers = $tracks[0]['performers'];
        sort($performers);
        $this->assertSame(['Paper Satellites', 'The Midnight Arcade'], $performers);
    }

    public function testExcludedAndUnfinishedSongsStayOffThePlaylist(): void
    {
        $this->pdo->exec('UPDATE songs SET excluded = 1 WHERE id = ' . $this->song('Orbit'));
        $this->pdo->exec('DELETE FROM song_matches WHERE song_id = ' . $this->song('Night Drive'));

        $this->assertSame([900001], array_map(fn ($t) => (int) $t['track_id'], $this->exports->tracks()));

        $why = array_column(ExportRepository::missingRows($this->exports->missing()), 6, 0);
        $this->assertSame('Not searched yet', $why['Night Drive']);
        $this->assertSame('Waiting for your review', $why['Static Love']);
        $this->assertSame('Not found on Apple Music', $why['Signal Fire']);
        $this->assertTrue(!isset($why['Orbit']), 'excluded songs were left off on purpose');
        $this->assertSame('https://www.setlist.fm/setlist/fixture/1a2b3c01.html', array_column($this->exports->missing(), 'setlist_url', 'title_display')['Heart of Glass']);
    }

    public function testStatsSummariseTheLibrary(): void
    {
        $summary = $this->stats->summary();
        $this->assertSame(
            ['songs' => 7, 'hearings' => 9, 'artists' => 2, 'concerts' => 2, 'covers' => 1, 'opener_songs' => 3, 'playlist' => 3],
            array_intersect_key($summary, array_flip(['songs', 'hearings', 'artists', 'concerts', 'covers', 'opener_songs', 'playlist'])),
        );
        $this->assertSame(['2025-09-20', '2026-04-18'], [$summary['first_show'], $summary['last_show']]);

        $most = $this->stats->mostHeard();
        $this->assertSame(['Neon Hours', 'Night Drive'], array_column($most, 'title_display'), 'only songs heard more than once');
        $this->assertSame(['2025-09-20', '2026-04-18'], array_column($most[0]['shows'], 'start_date'));

        $this->assertSame(['The Midnight Arcade' => 4, 'Paper Satellites' => 3], array_map('intval', array_column($this->stats->topArtists(), 'songs', 'name')));
        $this->assertSame(['Paper Satellites'], array_column($this->stats->openers(), 'name'), 'the shorter set that night');
        $this->assertSame(['Heart of Glass'], array_column($this->stats->covers()['songs'], 'title_display'));
        $this->assertSame([7, 2], array_map('intval', array_column($this->stats->biggestNights(), 'songs')));
        $this->assertSame(['Heart of Glass', 'Static Love'], array_column($this->stats->caughtOnce(2)['songs'], 'title_display'));
        $this->assertSame(0, $this->stats->caughtOnce(3)['total']);

        $coverage = $this->stats->coverage();
        $this->assertSame(['sets' => 7, 'matched' => 3], array_intersect_key($coverage, ['sets' => 0, 'matched' => 0]));
        $this->assertSame(4, count($coverage['missing']));
    }

    public function testExcludedSongsDoNotCount(): void
    {
        $this->pdo->exec('UPDATE songs SET excluded = 1 WHERE id = ' . $this->song('Neon Hours'));

        $this->assertSame(6, $this->stats->summary()['songs']);
        $this->assertSame(['Night Drive'], array_column($this->stats->mostHeard(), 'title_display'));
    }
}
