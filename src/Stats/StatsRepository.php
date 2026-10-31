<?php

declare(strict_types=1);

namespace HeardLive\Stats;

use HeardLive\Songs\SongRepository;
use PDO;

/** Numbers for the stats page. See docs/BUILD_SPEC.md §9.2. Excluded songs never count. */
final class StatsRepository
{
    // Songs that currently count, as in SongRepository.
    private const LIVE = 'JOIN song_performances sp ON sp.song_id = s.id
        JOIN performances p ON p.id = sp.performance_id AND p.attended = 1 AND p.setlist_status = \'matched\'
        JOIN events e ON e.id = p.event_id AND e.active = 1';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{songs: int, hearings: int, artists: int, concerts: int, covers: int, opener_songs: int,
     *               playlist: int, playlist_ms: int, first_show: ?string, last_show: ?string}
     */
    public function summary(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(DISTINCT s.id) AS songs, COUNT(DISTINCT sp.id) AS hearings, COUNT(DISTINCT s.performer_artist_id) AS artists,
                    COUNT(DISTINCT e.id) AS concerts, COUNT(DISTINCT CASE WHEN s.original_key <> \'\' THEN s.id END) AS covers,
                    MIN(e.start_date) AS first_show, MAX(e.start_date) AS last_show
             FROM songs s ' . self::LIVE . ' WHERE s.excluded = 0'
        )->fetch();
        // Songs you only ever heard in supporting sets.
        $openers = $this->pdo->query(
            'SELECT COUNT(*) FROM (SELECT s.id FROM songs s ' . self::LIVE . ' WHERE s.excluded = 0 GROUP BY s.id HAVING MIN(' . SongRepository::SUPPORT_SET . ') = 1)'
        )->fetchColumn();
        $playlist = $this->pdo->query(
            "SELECT COUNT(*) AS tracks, COALESCE(SUM(duration_ms), 0) AS ms FROM (
                SELECT DISTINCT r.track_id, r.duration_ms FROM songs s " . self::LIVE . "
                JOIN song_matches m ON m.song_id = s.id AND m.status IN ('auto', 'chosen') JOIN recordings r ON r.id = m.recording_id
                WHERE s.excluded = 0)"
        )->fetch();

        return [
            'songs' => (int) $row['songs'], 'hearings' => (int) $row['hearings'], 'artists' => (int) $row['artists'],
            'concerts' => (int) $row['concerts'], 'covers' => (int) $row['covers'], 'opener_songs' => (int) $openers,
            'playlist' => (int) $playlist['tracks'], 'playlist_ms' => (int) $playlist['ms'],
            'first_show' => $row['first_show'], 'last_show' => $row['last_show'],
        ];
    }

    /**
     * @return list<array{id: int, title_display: string, performer: string, original_artist_name: ?string, heard: int,
     *                    shows: list<array{date: string, event: string}>}>
     */
    public function mostHeard(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title_display, a.name AS performer, s.original_artist_name, COUNT(DISTINCT p.id) AS heard, MAX(e.start_date) AS last_heard
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
             WHERE s.excluded = 0 GROUP BY s.id HAVING heard > 1 ORDER BY heard DESC, last_heard DESC, s.title_display LIMIT ?'
        );
        $stmt->execute([$limit]);
        $songs = $stmt->fetchAll();
        if ($songs === []) {
            return [];
        }

        $ids = array_map(fn ($s) => (int) $s['id'], $songs);
        $shows = $this->pdo->prepare(
            'SELECT DISTINCT s.id AS song_id, e.id AS event_id, e.start_date, COALESCE(e.name, e.venue) AS event, e.city
             FROM songs s ' . self::LIVE . ' WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY e.start_date'
        );
        $shows->execute($ids);
        $bySong = [];
        foreach ($shows->fetchAll() as $show) {
            $bySong[(int) $show['song_id']][] = $show;
        }
        foreach ($songs as &$song) {
            $song['shows'] = $bySong[(int) $song['id']] ?? [];
        }

        return $songs;
    }

    /** @return list<array{id: int, name: string, songs: int, sets: int, hearings: int}> performers who gave you the most different songs */
    public function topArtists(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.name, COUNT(DISTINCT s.id) AS songs, COUNT(DISTINCT p.id) AS sets, COUNT(DISTINCT sp.id) AS hearings
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
             WHERE s.excluded = 0 GROUP BY a.id ORDER BY songs DESC, sets DESC, a.name COLLATE NOCASE LIMIT ?'
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll();
    }

    /** @return list<array{id: int, name: string, songs: int, sets: int}> acts by songs heard in their supporting sets */
    public function openers(int $limit = 8): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.name, COUNT(DISTINCT s.id) AS songs, COUNT(DISTINCT p.id) AS sets
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
             WHERE s.excluded = 0 AND ' . SongRepository::SUPPORT_SET . ' GROUP BY a.id ORDER BY songs DESC, a.name COLLATE NOCASE LIMIT ?'
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll();
    }

    /** @return array{total: int, songs: list<array<string, mixed>>, originals: list<array{name: string, covers: int}>} */
    public function covers(int $limit = 12): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title_display, a.name AS performer, s.original_artist_name, COUNT(DISTINCT p.id) AS heard, MAX(e.start_date) AS last_heard
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . "
             WHERE s.excluded = 0 AND s.original_key <> '' GROUP BY s.id ORDER BY heard DESC, last_heard DESC, s.title_display LIMIT ?"
        );
        $stmt->execute([$limit]);
        $originals = $this->pdo->query(
            "SELECT s.original_artist_name AS name, COUNT(DISTINCT s.id) AS covers FROM songs s " . self::LIVE . "
             WHERE s.excluded = 0 AND s.original_key <> '' GROUP BY s.original_key HAVING covers > 1 ORDER BY covers DESC, name COLLATE NOCASE LIMIT 5"
        )->fetchAll();
        $total = (int) $this->pdo->query(
            "SELECT COUNT(DISTINCT s.id) FROM songs s " . self::LIVE . " WHERE s.excluded = 0 AND s.original_key <> ''"
        )->fetchColumn();

        return ['total' => $total, 'songs' => $stmt->fetchAll(), 'originals' => $originals];
    }

    /** @return list<array{id: int, start_date: string, event: string, city: ?string, songs: int, acts: int}> the shows you heard the most songs at */
    public function biggestNights(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.start_date, COALESCE(e.name, e.venue) AS event, e.city, COUNT(DISTINCT s.id) AS songs, COUNT(DISTINCT p.id) AS acts
             FROM songs s ' . self::LIVE . ' WHERE s.excluded = 0 GROUP BY e.id ORDER BY songs DESC, e.start_date DESC LIMIT ?'
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll();
    }

    /**
     * Songs heard once from an act you saw at least $minSets times: the ones you only caught on one night.
     * Not rarity (that needs the artist's setlist history, see §10), just your own history.
     *
     * @return array{total: int, songs: list<array<string, mixed>>}
     */
    public function caughtOnce(int $minSets = 3, int $limit = 12): array
    {
        $sql = 'SELECT s.id, s.title_display, a.name AS performer, MIN(e.start_date) AS heard_on, seen.sets
                FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
                JOIN (SELECT p2.artist_id, COUNT(DISTINCT p2.id) AS sets FROM performances p2
                      JOIN events e2 ON e2.id = p2.event_id AND e2.active = 1
                      WHERE p2.attended = 1 AND p2.setlist_status = \'matched\' GROUP BY p2.artist_id) seen ON seen.artist_id = s.performer_artist_id
                WHERE s.excluded = 0 AND seen.sets >= ? GROUP BY s.id HAVING COUNT(DISTINCT p.id) = 1';
        $total = $this->pdo->prepare("SELECT COUNT(*) FROM ($sql)");
        $total->bindValue(1, $minSets, PDO::PARAM_INT); // a string would never compare equal to a count
        $total->execute();
        $stmt = $this->pdo->prepare("$sql ORDER BY seen.sets DESC, heard_on, s.title_display LIMIT ?");
        $stmt->bindValue(1, $minSets, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => (int) $total->fetchColumn(), 'songs' => $stmt->fetchAll()];
    }

    /**
     * Setlist coverage of the sets you saw.
     *
     * @return array{sets: int, matched: int, by_status: array<string, int>, missing: list<array<string, mixed>>}
     */
    public function coverage(int $limit = 12): array
    {
        $byStatus = $this->pdo->query(
            'SELECT p.setlist_status, COUNT(*) FROM performances p JOIN events e ON e.id = p.event_id AND e.active = 1
             WHERE p.attended = 1 GROUP BY p.setlist_status'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $missing = $this->pdo->prepare(
            "SELECT p.id, a.name AS artist, e.id AS event_id, e.start_date, COALESCE(e.name, e.venue) AS event, p.setlist_status
             FROM performances p JOIN artists a ON a.id = p.artist_id JOIN events e ON e.id = p.event_id AND e.active = 1
             WHERE p.attended = 1 AND p.setlist_status <> 'matched' ORDER BY e.start_date DESC, p.position LIMIT ?"
        );
        $missing->execute([$limit]);
        $byStatus = array_map('intval', $byStatus);

        return ['sets' => array_sum($byStatus), 'matched' => $byStatus['matched'] ?? 0, 'by_status' => $byStatus, 'missing' => $missing->fetchAll()];
    }
}
