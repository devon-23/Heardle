<?php

declare(strict_types=1);

namespace Tests\Unit\Export;

use HeardLive\Export\Csv;
use HeardLive\Export\ExportRepository;
use Tests\TestCase;

final class CsvTest extends TestCase
{
    public function testBuildsUtf8CsvWithBomAndQuoting(): void
    {
        $csv = Csv::build(['title', 'artist'], [['Mr. Brightside', 'The Killers'], ['Say "Hi", Again', 'Bjørk'], ['Back\\slash', null]]);

        $this->assertSame("\u{FEFF}title,artist\r\n\"Mr. Brightside\",\"The Killers\"\r\n\"Say \"\"Hi\"\", Again\",Bjørk\r\nBack\\slash,\r\n", $csv);
    }

    public function testSpreadsheetSafeModeDefusesFormulas(): void
    {
        $rows = [['=HYPERLINK("x")', '+44', '-', '@home', 'Plain']];

        $this->assertContains("'=HYPERLINK", Csv::build(['a', 'b', 'c', 'd', 'e'], $rows, spreadsheetSafe: true));
        $this->assertContains("'+44,'-,'@home,Plain", Csv::build(['a', 'b', 'c', 'd', 'e'], $rows, spreadsheetSafe: true));
        $this->assertContains('"=HYPERLINK(""x"")",+44,-,@home,Plain', Csv::build(['a', 'b', 'c', 'd', 'e'], $rows), 'transfer files keep titles exact');
    }

    public function testFilenameDropsCharactersFilesystemsReject(): void
    {
        $this->assertSame('Heard Live (1 of 7).csv', Csv::filename('Heard Live (1 of 7)'));
        $this->assertSame('ACDC Live.csv', Csv::filename("AC/DC: \"Live\"\n"));
        $this->assertSame('Heard Live.csv', Csv::filename('  /// '));
        $this->assertSame('Sigur Rós.csv', Csv::filename('Sigur Rós'));
    }

    public function testPartsSplitIntoFilesOfAGivenSize(): void
    {
        $this->assertSame([[1, 2], [3, 4], [5]], ExportRepository::parts([1, 2, 3, 4, 5], 2));
        $this->assertSame([[1, 2, 3]], ExportRepository::parts([1, 2, 3], 0), '0 means one file');
        $this->assertSame([], ExportRepository::parts([], 200));
    }

    public function testRowsUseTheRecordingAndTheLiveHistory(): void
    {
        $track = [
            'track_id' => 900001, 'track_name' => 'Neon Hours', 'artist_name' => 'The Midnight Arcade', 'collection_name' => 'Neon Hours',
            'view_url' => 'https://music.apple.com/us/album/neon-hours/1?i=900001&uo=4', 'duration_ms' => 201_600,
            'heard' => 3, 'first_heard' => '2024-05-01', 'last_heard' => '2026-04-18', 'performers' => ['The Midnight Arcade', 'Paper Satellites'], 'originals' => [],
        ];

        $this->assertSame([['Neon Hours', 'The Midnight Arcade', 'Neon Hours', '202s', 'https://music.apple.com/us/album/neon-hours/1?i=900001']],
            ExportRepository::rows([$track], 'transfer'));
        $this->assertSame([['Neon Hours', 'The Midnight Arcade', 'Neon Hours', 'https://music.apple.com/us/album/neon-hours/1?i=900001', 900001, 3,
            '2024-05-01', '2026-04-18', 'The Midnight Arcade; Paper Satellites', '']], ExportRepository::rows([$track], 'details'));
    }
}
