<?php

declare(strict_types=1);

namespace Tests\Unit\Concerts;

use HeardLive\Concerts\EventRepository;
use HeardLive\Import\ConcertArchivesCsv;
use HeardLive\Import\Importer;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

final class EventRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EventRepository $repo;

    public function setUp(): void
    {
        $this->pdo = $this->migratedDb();
        $result = ConcertArchivesCsv::parseFile(dirname(__DIR__, 2) . '/fixtures/concert-archives/sample.csv');
        (new Importer($this->pdo))->import($result, 'sample.csv');
        $this->repo = new EventRepository($this->pdo);
    }

    private function eventId(string $urlSuffix): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM events WHERE ca_url LIKE ?');
        $stmt->execute(['%' . $urlSuffix]);

        return (int) $stmt->fetchColumn();
    }

    public function testStats(): void
    {
        $this->assertSame(
            ['events' => 4, 'attended' => 7, 'not_attended' => 3, 'artists' => 5, 'attention' => 2, 'unscanned' => 7],
            $this->repo->stats(),
        );
    }

    public function testFiltersAndCounts(): void
    {
        $this->assertSame(['all' => 4, 'attention' => 2, 'missing' => 0, 'unseen' => 2, 'festivals' => 1], $this->repo->filterCounts());

        $festivals = $this->repo->forReview('festivals');
        $this->assertSame('Riverbend Festival 2025', $festivals[0]['name']);

        $all = $this->repo->forReview('all');
        $this->assertSame('2026-04-18', $all[0]['start_date'], 'newest first');
        $this->assertSame(['The Midnight Arcade', 'Paper Satellites', 'Low Tide Choir'], array_column($all[0]['performances'], 'artist'));
        $this->assertSame([true, true, false], array_column($all[0]['performances'], 'attended'));
    }

    public function testUnknownFilterIsRejected(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->repo->forReview('everything'));
    }

    public function testSetAttendanceMarksChangesAsUserChoices(): void
    {
        $eventId = $this->eventId('midnight-arcade-harbor-pavilion');
        $performances = $this->repo->find($eventId)['performances'];
        [$head, $opener, $missed] = array_column($performances, 'id');

        // Saw the headliner and the second opener, not the first.
        $this->repo->setAttendance($eventId, [$head, $missed]);

        $after = $this->repo->find($eventId)['performances'];
        $this->assertSame([true, false, true], array_column($after, 'attended'));
        $this->assertSame(['csv', 'user', 'user'], array_column($after, 'attended_source'));
    }

    public function testSetAttendanceRejectsPerformancesFromOtherEvents(): void
    {
        $eventId = $this->eventId('midnight-arcade-harbor-pavilion');
        $otherId = $this->repo->find($this->eventId('paper-satellites-the-cellar'))['performances'][0]['id'];

        $this->assertThrows(InvalidArgumentException::class, fn () => $this->repo->setAttendance($eventId, [$otherId]));
    }

    public function testReviewingClearsAttention(): void
    {
        $this->repo->markReviewed($this->eventId('low-tide-choir-vip'));
        $this->assertSame(1, $this->repo->stats()['attention']);

        $this->assertSame(1, $this->repo->markFilterReviewed('attention'));
        $this->assertSame(0, $this->repo->stats()['attention']);
        $this->assertSame([], $this->repo->forReview('attention'));
    }
}
