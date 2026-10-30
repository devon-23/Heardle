<?php

declare(strict_types=1);

namespace HeardLive\Import;

use DateTimeImmutable;
use HeardLive\Support\Text;

/** Parses the "Export To Spreadsheet" CSV from concertarchives.org. See docs/BUILD_SPEC.md §5. */
final class ConcertArchivesCsv
{
    public const COLUMNS = [
        'Start Date', 'End Date', 'Nonstandard Concert Type', 'Status', 'Concert Name',
        'Bands Seen', 'Bands Not Seen', 'Venue', 'Location', 'URL',
    ];

    private const ARTIST_SEPARATOR = ' / ';

    public static function parseFile(string $path): ParseResult
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new CsvFormatError('The file could not be opened.');
        }
        try {
            return self::parseHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    public static function parseString(string $csv): ParseResult
    {
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $csv);
        rewind($handle);
        try {
            return self::parseHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function parseHandle($handle): ParseResult
    {
        $header = fgetcsv($handle, escape: '');
        if ($header === false || $header === [null]) {
            throw new CsvFormatError('The file is empty.');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $missing = array_values(array_diff(self::COLUMNS, $header));
        if ($missing !== []) {
            throw new CsvFormatError(
                'This doesn’t look like a Concert Archives export. Missing columns: ' . implode(', ', $missing) . '.',
                $missing,
            );
        }
        $index = array_flip($header);

        $events = [];
        $errors = [];
        $skipped = [];
        $seenUrls = [];
        $row = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $row++;
            if ($cells === [null]) {
                continue; // blank line
            }
            $get = fn (string $column) => trim((string) ($cells[$index[$column]] ?? ''));

            $status = $get('Status');
            if ($status !== 'Past') {
                $skipped[] = ['row' => $row, 'message' => sprintf('Status is "%s"; only past concerts are imported.', $status)];
                continue;
            }

            $problem = null;
            $url = $get('URL');
            $start = self::parseDate($get('Start Date'));
            $endRaw = $get('End Date');
            $end = $endRaw === '' ? null : self::parseDate($endRaw);
            $venue = $get('Venue');

            if ($url === '') {
                $problem = 'Missing URL.';
            } elseif (isset($seenUrls[$url])) {
                $problem = sprintf('Same URL as row %d.', $seenUrls[$url]);
            } elseif ($start === null) {
                $problem = sprintf('Start date "%s" isn’t a valid MM/DD/YYYY date.', $get('Start Date'));
            } elseif ($endRaw !== '' && $end === null) {
                $problem = sprintf('End date "%s" isn’t a valid MM/DD/YYYY date.', $endRaw);
            } elseif ($end !== null && $end < $start) {
                $problem = 'End date is before the start date.';
            }
            if ($problem !== null) {
                $errors[] = ['row' => $row, 'message' => $problem];
                continue;
            }
            $seenUrls[$url] = $row;

            $flags = [];
            [$seen, $seenDupes] = self::splitArtists($get('Bands Seen'));
            [$notSeenAll, $notSeenDupes] = self::splitArtists($get('Bands Not Seen'));

            $seenKeys = array_flip(array_map([Text::class, 'nameKey'], $seen));
            $notSeen = array_values(array_filter($notSeenAll, fn ($a) => !isset($seenKeys[Text::nameKey($a)])));

            if ($end !== null && $end !== $start) {
                $flags[] = ParsedEvent::FLAG_MULTI_DAY;
            } else {
                $end = null;
            }
            $type = $get('Nonstandard Concert Type');
            if ($type !== '') {
                $flags[] = ParsedEvent::FLAG_NONSTANDARD;
            }
            if (count($notSeen) < count($notSeenAll)) {
                $flags[] = ParsedEvent::FLAG_SEEN_AND_NOT_SEEN;
            }
            if ($seen === []) {
                $flags[] = ParsedEvent::FLAG_NOTHING_SEEN;
            }
            if ($seenDupes || $notSeenDupes) {
                $flags[] = ParsedEvent::FLAG_DUPLICATE_ARTIST;
            }
            if ($venue === '') {
                $flags[] = ParsedEvent::FLAG_NO_VENUE;
            }

            [$city, $region, $country] = self::splitLocation($get('Location'));
            $name = $get('Concert Name');

            $events[] = new ParsedEvent(
                row: $row,
                url: $url,
                startDate: $start,
                endDate: $end,
                nonstandardType: $type === '' ? null : $type,
                status: $status,
                name: $name === '' ? null : $name,
                seen: $seen,
                notSeen: $notSeen,
                venue: $venue === '' ? null : $venue,
                city: $city,
                region: $region,
                country: $country,
                locationRaw: $get('Location'),
                flags: $flags,
            );
        }

        $extra = array_values(array_filter(array_diff($header, self::COLUMNS), fn ($h) => $h !== ''));

        return new ParseResult($events, $errors, $skipped, $extra);
    }

    /** @return ?string YYYY-MM-DD, or null if not a real MM/DD/YYYY date */
    private static function parseDate(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!m/d/Y', $value);
        if ($date === false || $date->format('m/d/Y') !== $value) {
            return null;
        }
        return $date->format('Y-m-d');
    }

    /** @return array{0: list<string>, 1: bool} artists in order, and whether duplicates were dropped */
    private static function splitArtists(string $value): array
    {
        $artists = [];
        $keys = [];
        $dupes = false;
        foreach (explode(self::ARTIST_SEPARATOR, $value) as $artist) {
            $artist = trim($artist);
            if ($artist === '') {
                continue;
            }
            $key = Text::nameKey($artist);
            if (isset($keys[$key])) {
                $dupes = true;
                continue;
            }
            $keys[$key] = true;
            $artists[] = $artist;
        }

        return [$artists, $dupes];
    }

    /** "City, State, Country" or "City, Country" → [city, region, country]. */
    private static function splitLocation(string $value): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($p) => $p !== ''));

        return match (count($parts)) {
            0 => [null, null, null],
            1 => [$parts[0], null, null],
            2 => [$parts[0], null, $parts[1]],
            default => [$parts[0], implode(', ', array_slice($parts, 1, -1)), $parts[count($parts) - 1]],
        };
    }
}
