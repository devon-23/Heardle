<?php

declare(strict_types=1);

namespace HeardLive\Setlists;

/** Flattens a setlist.fm setlist object into what matching and the review screen need. */
final class SetlistSummary
{
    /**
     * @param array<string, mixed> $setlist
     * @return array{id: string, version_id: ?string, url: ?string, date: ?string, venue: ?string, city: ?string,
     *               state: ?string, country: ?string, tour: ?string, has_sets: bool, song_count: int, first_songs: list<string>}
     */
    public static function from(array $setlist): array
    {
        $venue = $setlist['venue'] ?? [];
        $city = $venue['city'] ?? [];
        $songs = self::songNames($setlist);

        return [
            'id' => (string) ($setlist['id'] ?? ''),
            'version_id' => isset($setlist['versionId']) ? (string) $setlist['versionId'] : null,
            'url' => isset($setlist['url']) ? (string) $setlist['url'] : null,
            'date' => self::isoDate($setlist['eventDate'] ?? null),
            'venue' => $venue['name'] ?? null,
            'city' => $city['name'] ?? null,
            'state' => $city['state'] ?? null,
            'country' => $city['country']['name'] ?? null,
            'tour' => $setlist['tour']['name'] ?? null,
            'has_sets' => self::sets($setlist) !== null,
            'song_count' => count($songs),
            'first_songs' => array_slice($songs, 0, 3),
        ];
    }

    /**
     * The sets of a setlist. JSON responses nest them as sets.set; null when absent from the payload.
     *
     * @return ?list<array<string, mixed>>
     */
    public static function sets(array $setlist): ?array
    {
        if (isset($setlist['sets']) && is_array($setlist['sets'])) {
            return array_values($setlist['sets']['set'] ?? []);
        }
        if (isset($setlist['set']) && is_array($setlist['set'])) {
            return array_values($setlist['set']);
        }
        return null;
    }

    /** @return list<string> performed songs (tape entries and blank names skipped) */
    public static function songNames(array $setlist): array
    {
        $names = [];
        foreach (self::sets($setlist) ?? [] as $set) {
            foreach ($set['song'] ?? [] as $song) {
                $name = trim((string) ($song['name'] ?? ''));
                if ($name !== '' && empty($song['tape'])) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    private static function isoDate(mixed $eventDate): ?string
    {
        $date = is_string($eventDate) ? \DateTimeImmutable::createFromFormat('!d-m-Y', $eventDate) : false;

        return $date === false ? null : $date->format('Y-m-d');
    }
}
