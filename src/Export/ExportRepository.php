<?php

declare(strict_types=1);

namespace HeardLive\Export;

use HeardLive\Matching\MatchPresenter;
use InvalidArgumentException;
use PDO;

/**
 * The playlist and the "couldn't get" list as CSV rows. See docs/BUILD_SPEC.md §9.1.
 *
 * The playlist is every song with a recording picked (automatic or yours), not excluded, one row per
 * Apple Music track: a cover matched to the original's recording and the original itself are one row.
 */
final class ExportRepository
{
    public const ORDERS = ['first', 'heard'];
    public const FORMATS = ['transfer', 'details'];

    // Songs that currently count, as in SongRepository.
    private const LIVE = 'JOIN song_performances sp ON sp.song_id = s.id
        JOIN performances p ON p.id = sp.performance_id AND p.attended = 1 AND p.setlist_status = \'matched\'
        JOIN events e ON e.id = p.event_id AND e.active = 1';

    /** Column names from Soundiiz's sample playlist file (verified 2026-10-30); unused ones left out. */
    public const TRANSFER_HEADER = ['title', 'artist', 'album', 'duration', 'url'];
    public const DETAILS_HEADER = [
        'Title', 'Artist', 'Album', 'Apple Music URL', 'Apple Music ID', 'Times Heard', 'First Heard', 'Last Heard', 'Performed By', 'Original Artist',
    ];
    public const MISSING_HEADER = ['Title', 'Performed By', 'Original Artist', 'Times Heard', 'First Heard', 'Last Heard', 'Why', 'Latest Setlist'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{track_id: int, track_name: string, artist_name: string, collection_name: ?string, view_url: ?string,
     *                    duration_ms: ?int, heard: int, first_heard: string, last_heard: string, performers: list<string>, originals: list<string>}>
     *   'first' orders by the show you first heard each one at, then setlist position; 'heard' puts the most-heard first
     */
    public function tracks(int $minHeard = 1, string $order = 'first'): array
    {
        $orderBy = match ($order) {
            'first' => 'first_key, r.track_name COLLATE NOCASE',
            'heard' => 'heard DESC, first_key',
            default => throw new InvalidArgumentException("Unknown order: $order"),
        };
        $stmt = $this->pdo->prepare(
            "SELECT r.track_id, r.track_name, r.artist_name, r.collection_name, r.view_url, r.duration_ms,
                    COUNT(DISTINCT p.id) AS heard, MIN(e.start_date) AS first_heard, MAX(e.start_date) AS last_heard,
                    MIN(e.start_date || printf('%04d', sp.position)) AS first_key,
                    json_group_array(DISTINCT a.name) AS performers,
                    json_group_array(DISTINCT s.original_artist_name) AS originals
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id " . self::LIVE . "
             JOIN song_matches m ON m.song_id = s.id AND m.status IN ('auto', 'chosen')
             JOIN recordings r ON r.id = m.recording_id
             WHERE s.excluded = 0
             GROUP BY r.track_id HAVING heard >= ?
             ORDER BY $orderBy"
        );
        $stmt->bindValue(1, max(1, $minHeard), PDO::PARAM_INT); // SQLite never finds a count >= a string
        $stmt->execute();

        return array_map(function (array $row): array {
            $names = fn (string $json) => array_values(array_filter(json_decode($json, true), fn ($n) => $n !== null));
            $row['performers'] = $names($row['performers']);
            $row['originals'] = $names($row['originals']);
            unset($row['first_key']);

            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * Songs you heard that aren't on the playlist (excluded songs aside), with the reason.
     *
     * @return list<array<string, mixed>>
     */
    public function missing(): array
    {
        return $this->pdo->query(
            "SELECT s.id, s.title_display, a.name AS performer, s.original_artist_name, m.status,
                    COUNT(DISTINCT p.id) AS heard, MIN(e.start_date) AS first_heard, MAX(e.start_date) AS last_heard,
                    substr(MAX(e.start_date || p.setlist_url), 11) AS setlist_url
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id " . self::LIVE . '
             LEFT JOIN song_matches m ON m.song_id = s.id
             WHERE s.excluded = 0 AND (m.song_id IS NULL OR m.status NOT IN (\'auto\', \'chosen\'))
             GROUP BY s.id ORDER BY heard DESC, a.name COLLATE NOCASE, s.title_display COLLATE NOCASE'
        )->fetchAll();
    }

    /**
     * @param list<array<string, mixed>> $tracks from tracks()
     * @return list<list<string|int|null>>
     */
    public static function rows(array $tracks, string $format): array
    {
        return array_map(fn (array $t) => match ($format) {
            'transfer' => [
                $t['track_name'], $t['artist_name'], $t['collection_name'],
                $t['duration_ms'] === null ? '' : (int) round($t['duration_ms'] / 1000) . 's',
                MatchPresenter::appleMusicUrl($t['view_url']),
            ],
            'details' => [
                $t['track_name'], $t['artist_name'], $t['collection_name'], MatchPresenter::appleMusicUrl($t['view_url']), $t['track_id'],
                $t['heard'], $t['first_heard'], $t['last_heard'], implode('; ', $t['performers']), implode('; ', $t['originals']),
            ],
            default => throw new InvalidArgumentException("Unknown format: $format"),
        }, $tracks);
    }

    /**
     * @param list<array<string, mixed>> $songs from missing()
     * @return list<list<string|int|null>>
     */
    public static function missingRows(array $songs): array
    {
        return array_map(fn (array $s) => [
            $s['title_display'], $s['performer'], $s['original_artist_name'], $s['heard'], $s['first_heard'], $s['last_heard'],
            match ($s['status']) {
                'unmatched' => 'Not found on Apple Music',
                'review' => 'Waiting for your review',
                null => 'Not searched yet',
                default => (string) $s['status'],
            },
            $s['setlist_url'],
        ], $songs);
    }

    /**
     * Splits the playlist into files of $size tracks (0 = one file), for transfer services that cap
     * how many songs one import takes.
     *
     * @template T
     * @param list<T> $tracks
     * @return list<list<T>>
     */
    public static function parts(array $tracks, int $size): array
    {
        return $tracks === [] ? [] : array_chunk($tracks, $size > 0 ? $size : count($tracks));
    }
}
