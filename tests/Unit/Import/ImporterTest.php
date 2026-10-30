<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use HeardLive\Import\ConcertArchivesCsv;
use HeardLive\Import\Importer;
use PDO;
use Tests\TestCase;

final class ImporterTest extends TestCase
{
    private const HEADER = "Start Date,End Date,Nonstandard Concert Type,Status,Concert Name,Bands Seen,Bands Not Seen,Venue,Location,URL\n";

    private PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = $this->migratedDb();
    }

    private function importCsv(string $csv): array
    {
        return (new Importer($this->pdo))->import(ConcertArchivesCsv::parseString(self::HEADER . $csv), 'test.csv');
    }

    private function attendance(string $url): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.name, p.listed_as, p.attended, p.attended_source FROM performances p
             JOIN artists a ON a.id = p.artist_id JOIN events e ON e.id = p.event_id
             WHERE e.ca_url = ? ORDER BY p.position'
        );
        $stmt->execute([$url]);

        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    public function testImportsSampleFixture(): void
    {
        $result = ConcertArchivesCsv::parseFile(dirname(__DIR__, 2) . '/fixtures/concert-archives/sample.csv');
        $outcome = (new Importer($this->pdo))->import($result, 'sample.csv');

        $this->assertSame(['import_id' => 1, 'created' => 4, 'updated' => 0, 'deactivated' => 0], $outcome);
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM events')->fetchColumn());
        $this->assertSame(10, (int) $this->pdo->query('SELECT COUNT(*) FROM performances')->fetchColumn());
        $this->assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM performances WHERE attended = 1')->fetchColumn());
        // The Midnight Arcade and Paper Satellites appear at several events but are one artist each.
        $this->assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM artists')->fetchColumn());
    }

    public function testSeenAreAttendedAndNotSeenAreNot(): void
    {
        $this->importCsv("01/02/2020,,,Past,,Head / Opener One,Opener Two,V,X,https://e/1\n");

        $this->assertSame([
            ['Head', 'seen', 1, 'csv'],
            ['Opener One', 'seen', 1, 'csv'],
            ['Opener Two', 'not_seen', 0, 'csv'],
        ], $this->attendance('https://e/1'));
    }

    public function testReimportIsAnUpsertThatKeepsUserChoices(): void
    {
        $this->importCsv("01/02/2020,,,Past,,Head / Opener One,Opener Two,V,X,https://e/1\n");
        // The user says they did see Opener Two after all.
        $this->pdo->exec("UPDATE performances SET attended = 1, attended_source = 'user'
                          WHERE artist_id = (SELECT id FROM artists WHERE name = 'Opener Two')");

        // Concert Archives now says Opener One wasn't seen, and still says Opener Two wasn't.
        $outcome = $this->importCsv("01/02/2020,,,Past,,Head,Opener One / Opener Two,V,X,https://e/1\n");

        $this->assertSame(0, $outcome['created']);
        $this->assertSame(1, $outcome['updated']);
        $this->assertSame([
            ['Head', 'seen', 1, 'csv'],
            ['Opener One', 'not_seen', 0, 'csv'],
            ['Opener Two', 'not_seen', 1, 'user'],
        ], $this->attendance('https://e/1'));
    }

    public function testRemovedArtistsAndEventsAreDroppedOrDeactivated(): void
    {
        $this->importCsv(
            "01/02/2020,,,Past,,A / B,\"\",V,X,https://e/1\n"
            . "01/03/2020,,,Past,,C,\"\",V,X,https://e/2\n"
        );
        $outcome = $this->importCsv("01/02/2020,,,Past,,A,\"\",V,X,https://e/1\n");

        $this->assertSame(1, $outcome['deactivated']);
        $this->assertSame([['A', 'seen', 1, 'csv']], $this->attendance('https://e/1'));
        $this->assertSame(0, (int) $this->pdo->query("SELECT active FROM events WHERE ca_url = 'https://e/2'")->fetchColumn());

        // Coming back in a later import reactivates it.
        $this->importCsv("01/03/2020,,,Past,,C,\"\",V,X,https://e/2\n");
        $this->assertSame(1, (int) $this->pdo->query("SELECT active FROM events WHERE ca_url = 'https://e/2'")->fetchColumn());
    }

    public function testChangedFlagsResetReview(): void
    {
        $this->importCsv("01/02/2020,,VIP Show,Past,,A,\"\",V,X,https://e/1\n");
        $this->pdo->exec("UPDATE events SET reviewed_at = '2026-01-01'");

        $this->importCsv("01/02/2020,,VIP Show,Past,,A,\"\",V,X,https://e/1\n");
        $this->assertSame('2026-01-01', $this->pdo->query('SELECT reviewed_at FROM events')->fetchColumn());

        $this->importCsv("01/02/2020,01/03/2020,VIP Show,Past,,A,\"\",V,X,https://e/1\n");
        $this->assertSame(null, $this->pdo->query('SELECT reviewed_at FROM events')->fetchColumn());
    }

    public function testArtistNamesMatchAcrossCaseAndQuotes(): void
    {
        $this->importCsv(
            "01/02/2020,,,Past,,Sophie’s Body,\"\",V,X,https://e/1\n"
            . "01/03/2020,,,Past,,sophie's body,\"\",V,X,https://e/2\n"
        );

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM artists')->fetchColumn());
    }
}
