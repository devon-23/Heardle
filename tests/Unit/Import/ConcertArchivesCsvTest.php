<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use HeardLive\Import\ConcertArchivesCsv;
use HeardLive\Import\CsvFormatError;
use HeardLive\Import\ParsedEvent;
use HeardLive\Import\ParseResult;
use Tests\TestCase;

final class ConcertArchivesCsvTest extends TestCase
{
    private const HEADER = "Start Date,End Date,Nonstandard Concert Type,Status,Concert Name,Bands Seen,Bands Not Seen,Venue,Location,URL\n";

    private function sample(): ParseResult
    {
        return ConcertArchivesCsv::parseFile(dirname(__DIR__, 2) . '/fixtures/concert-archives/sample.csv');
    }

    private function event(ParseResult $result, string $urlSuffix): ParsedEvent
    {
        foreach ($result->events as $event) {
            if (str_ends_with($event->url, $urlSuffix)) {
                return $event;
            }
        }
        $this->fail("No event ending in $urlSuffix");
    }

    public function testSplitsSeenAndNotSeenArtistsInOrder(): void
    {
        $event = $this->event($this->sample(), 'midnight-arcade-harbor-pavilion');

        $this->assertSame(['The Midnight Arcade', 'Paper Satellites'], $event->seen);
        $this->assertSame(['Low Tide Choir'], $event->notSeen);
        $this->assertSame('2026-04-18', $event->startDate);
        $this->assertSame(null, $event->endDate);
        $this->assertSame([], $event->flags);
        $this->assertSame(['Portland', 'Oregon', 'United States'], [$event->city, $event->region, $event->country]);
    }

    public function testEmptyQuotedFieldsBecomeNullOrEmpty(): void
    {
        $event = $this->event($this->sample(), 'paper-satellites-the-cellar');

        $this->assertSame(null, $event->name);
        $this->assertSame([], $event->notSeen);
    }

    public function testMultiDayFestivalKeepsQuotedCommaNamesAndFlagsConflicts(): void
    {
        $event = $this->event($this->sample(), 'riverbend-festival-2025');

        $this->assertSame('2025-09-21', $event->endDate);
        $this->assertSame(['Static Bloom', 'Fat, Evil Bees'], $event->notSeen, 'Quiet Engines is seen, so it is dropped from not seen');
        $this->assertSame([ParsedEvent::FLAG_MULTI_DAY, ParsedEvent::FLAG_SEEN_AND_NOT_SEEN], $event->flags);
    }

    public function testNonstandardTypeAndTwoPartLocation(): void
    {
        $event = $this->event($this->sample(), 'low-tide-choir-vip');

        $this->assertSame('VIP Show', $event->nonstandardType);
        $this->assertSame([ParsedEvent::FLAG_NONSTANDARD], $event->flags);
        $this->assertSame(['London', null, 'United Kingdom'], [$event->city, $event->region, $event->country]);
    }

    public function testSkipsNonPastAndReportsBadRows(): void
    {
        $result = $this->sample();

        $this->assertSame(4, count($result->events));
        $this->assertSame([['row' => 6, 'message' => 'Status is "Upcoming"; only past concerts are imported.']], $result->skipped);
        $this->assertSame(1, count($result->errors));
        $this->assertSame(7, $result->errors[0]['row']);
        $this->assertContains('13/45/2024', $result->errors[0]['message']);
    }

    public function testSummaryCounts(): void
    {
        $summary = $this->sample()->summary();

        $this->assertSame(4, $summary['events']);
        $this->assertSame(7, $summary['performances_seen']);
        $this->assertSame(3, $summary['performances_not_seen']);
        $this->assertSame(5, $summary['artists_seen']);
        $this->assertSame('2025-07-04', $summary['first_date']);
        $this->assertSame('2026-04-18', $summary['last_date']);
        $this->assertSame(2, $summary['flagged_events']);
    }

    public function testMissingColumnsAreAFormatError(): void
    {
        $this->assertThrows(
            CsvFormatError::class,
            fn () => ConcertArchivesCsv::parseString("Date,Artist\n01/01/2020,Someone\n"),
            'Missing columns: Start Date',
        );
    }

    public function testEmptyFileIsAFormatError(): void
    {
        $this->assertThrows(CsvFormatError::class, fn () => ConcertArchivesCsv::parseString(''), 'empty');
    }

    public function testByteOrderMarkAndExtraColumnsAreTolerated(): void
    {
        $csv = "\xEF\xBB\xBF" . rtrim(self::HEADER) . ",Rating\n"
            . "01/02/2020,,,Past,,A / B,\"\",V,\"X, Y\",https://e/1,5\n";

        $result = ConcertArchivesCsv::parseString($csv);
        $this->assertSame(1, count($result->events));
        $this->assertSame(['Rating'], $result->extraColumns);
    }

    public function testDuplicateUrlsAndDuplicateArtists(): void
    {
        $csv = self::HEADER
            . "01/02/2020,,,Past,,A / a / B,\"\",V,X,https://e/1\n"
            . "01/03/2020,,,Past,,C,\"\",V,X,https://e/1\n";

        $result = ConcertArchivesCsv::parseString($csv);
        $this->assertSame(['A', 'B'], $result->events[0]->seen);
        $this->assertSame([ParsedEvent::FLAG_DUPLICATE_ARTIST], $result->events[0]->flags);
        $this->assertSame([['row' => 3, 'message' => 'Same URL as row 2.']], $result->errors);
    }

    public function testEndDateEqualToStartIsNotMultiDayAndNothingSeenIsFlagged(): void
    {
        $csv = self::HEADER . "01/02/2020,01/02/2020,,Past,,,A,V,X,https://e/1\n";

        $event = ConcertArchivesCsv::parseString($csv)->events[0];
        $this->assertSame(null, $event->endDate);
        $this->assertSame([ParsedEvent::FLAG_NOTHING_SEEN], $event->flags);
    }

    public function testMissingVenueIsFlaggedNotRejected(): void
    {
        $csv = self::HEADER . "04/22/2017,,,Past,Some Fest,A / B,\"\",,\"X, Y, Z\",https://e/1\n";

        $result = ConcertArchivesCsv::parseString($csv);
        $this->assertSame([], $result->errors);
        $this->assertSame(null, $result->events[0]->venue);
        $this->assertSame([ParsedEvent::FLAG_NO_VENUE], $result->events[0]->flags);
    }

    public function testEndBeforeStartIsAnError(): void
    {
        $csv = self::HEADER . "01/05/2020,01/02/2020,,Past,,A,,V,X,https://e/1\n";

        $this->assertSame('End date is before the start date.', ConcertArchivesCsv::parseString($csv)->errors[0]['message']);
    }
}
