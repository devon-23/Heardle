<?php

declare(strict_types=1);

namespace HeardLive\Songs;

use InvalidArgumentException;
use PDO;
use Throwable;

/** The deduplicated song library. A song is visible while at least one attended, matched performance has it. */
final class SongRepository
{
    public const SORTS = ['heard', 'title', 'recent', 'artist'];
    public const SHOWS = ['all', 'repeats', 'covers', 'openers', 'review', 'excluded'];
    public const PER_PAGE = 100;

    // Joins a song's performances that currently count: you were there and the setlist is the one you saw.
    private const LIVE = 'JOIN song_performances sp ON sp.song_id = s.id
        JOIN performances p ON p.id = sp.performance_id AND p.attended = 1 AND p.setlist_status = \'matched\'
        JOIN events e ON e.id = p.event_id AND e.active = 1';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> matched performances whose songs haven't been pulled from their current setlist */
    public function performancesToExtract(): array
    {
        return $this->pdo->query(
            "SELECT p.id, p.artist_id, p.setlist_id, p.setlist_event_date, a.mbid, a.name AS artist, e.start_date
             FROM performances p
             JOIN artists a ON a.id = p.artist_id
             JOIN events e ON e.id = p.event_id AND e.active = 1
             WHERE p.attended = 1 AND p.setlist_status = 'matched' AND p.setlist_id IS NOT NULL
               AND (p.songs_setlist_id IS NULL OR p.songs_setlist_id <> p.setlist_id)
             ORDER BY e.start_date DESC, p.position"
        )->fetchAll();
    }

    /** @param list<array<string, mixed>> $entries from SetlistSongs::from() */
    public function saveSongs(int $performanceId, int $performerId, string $setlistId, array $entries): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM song_performances WHERE performance_id = ?')->execute([$performanceId]);

            $alias = $this->pdo->prepare('SELECT to_title_norm FROM song_aliases WHERE performer_artist_id = ? AND original_key = ? AND from_title_norm = ?');
            $insertSong = $this->pdo->prepare(
                'INSERT INTO songs (performer_artist_id, title_norm, original_key, title_display, original_artist_name, original_artist_mbid)
                 VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING'
            );
            $findSong = $this->pdo->prepare('SELECT id FROM songs WHERE performer_artist_id = ? AND title_norm = ? AND original_key = ?');
            $insertPerformance = $this->pdo->prepare(
                'INSERT INTO song_performances (song_id, performance_id, set_index, position, encore, times_in_show, variant, partial, medley, info, with_artist)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (song_id, performance_id) DO UPDATE SET times_in_show = times_in_show + excluded.times_in_show'
            );

            foreach ($entries as $entry) {
                $alias->execute([$performerId, $entry['original_key'], $entry['key']]);
                $key = $alias->fetchColumn() ?: $entry['key'];

                $insertSong->execute([$performerId, $key, $entry['original_key'], $entry['title'], $entry['original_name'], $entry['original_mbid']]);
                $findSong->execute([$performerId, $key, $entry['original_key']]);
                $songId = (int) $findSong->fetchColumn();

                $insertPerformance->execute([
                    $songId, $performanceId, $entry['set_index'], $entry['position'], $entry['encore'], $entry['times_in_show'],
                    $entry['variant'], (int) $entry['partial'], (int) $entry['medley'], $entry['info'], $entry['with_artist'],
                ]);
            }

            $this->pdo->prepare('UPDATE performances SET songs_setlist_id = ? WHERE id = ?')->execute([$setlistId, $performanceId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{songs: int, hearings: int, artists: int, covers: int, review: int, excluded: int, unextracted: int} */
    public function stats(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(DISTINCT CASE WHEN s.excluded = 0 THEN s.id END) AS songs,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 THEN sp.id END) AS hearings,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 THEN s.performer_artist_id END) AS artists,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 AND s.original_key <> \'\' THEN s.id END) AS covers,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 AND (sp.medley = 1 OR sp.partial = 1) THEN s.id END) AS review,
                    COUNT(DISTINCT CASE WHEN s.excluded = 1 THEN s.id END) AS excluded
             FROM songs s ' . self::LIVE
        )->fetch();
        $row['unextracted'] = count($this->performancesToExtract());

        return array_map('intval', $row);
    }

    /** @return list<array<string, mixed>> the most-heard songs */
    public function mostHeard(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title_display, a.name AS performer, COUNT(DISTINCT p.id) AS heard
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
             WHERE s.excluded = 0 GROUP BY s.id HAVING heard > 1 ORDER BY heard DESC, s.title_display LIMIT ?'
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{q?: string, artist?: int, show?: string, sort?: string, page?: int} $filters
     * @return array{songs: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function search(array $filters): array
    {
        $where = [];
        $having = [];
        $params = [];

        $show = $filters['show'] ?? 'all';
        $where[] = $show === 'excluded' ? 's.excluded = 1' : 's.excluded = 0';
        if (($filters['q'] ?? '') !== '') {
            $where[] = "(s.title_display LIKE ? ESCAPE '\\' OR a.name LIKE ? ESCAPE '\\' OR s.original_artist_name LIKE ? ESCAPE '\\')";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['artist'])) {
            $where[] = 's.performer_artist_id = ?';
            $params[] = $filters['artist'];
        }
        match ($show) {
            'covers' => $where[] = "s.original_key <> ''",
            'repeats' => $having[] = 'heard > 1',
            'openers' => $having[] = 'MIN(p.position) > 0',
            'review' => $having[] = '(MAX(sp.medley) = 1 OR MAX(sp.partial) = 1)',
            'all', 'excluded' => null,
            default => throw new InvalidArgumentException("Unknown filter: $show"),
        };

        $order = match ($filters['sort'] ?? 'heard') {
            'heard' => 'heard DESC, last_heard DESC, s.title_display',
            'title' => 's.title_display COLLATE NOCASE',
            'recent' => 'last_heard DESC, s.title_display',
            'artist' => 'a.name COLLATE NOCASE, heard DESC, s.title_display',
            default => throw new InvalidArgumentException('Unknown sort'),
        };

        $sql = 'SELECT s.id, s.title_display, s.original_artist_name, s.original_key, s.excluded, a.id AS performer_id, a.name AS performer,
                       COUNT(DISTINCT p.id) AS heard, MIN(e.start_date) AS first_heard, MAX(e.start_date) AS last_heard,
                       MAX(sp.medley) AS medley, MAX(sp.partial) AS partial
                FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY s.id' . ($having === [] ? '' : ' HAVING ' . implode(' AND ', $having));

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM ($sql)");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        $stmt = $this->pdo->prepare("$sql ORDER BY $order LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);

        return ['songs' => $stmt->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return list<array{id: int, name: string, songs: int}> performers with at least one visible song */
    public function performers(): array
    {
        return $this->pdo->query(
            'SELECT a.id, a.name, COUNT(DISTINCT s.id) AS songs FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
             WHERE s.excluded = 0 GROUP BY a.id ORDER BY a.name COLLATE NOCASE'
        )->fetchAll();
    }

    /** @return ?array<string, mixed> the song with every performance that counts */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, a.name AS performer FROM songs s JOIN artists a ON a.id = s.performer_artist_id WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $song = $stmt->fetch();
        if ($song === false) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT sp.*, e.id AS event_id, e.start_date, e.name AS event_name, e.venue, e.city, e.region,
                    p.setlist_url, p.setlist_event_date, p.position AS lineup_position
             FROM songs s ' . self::LIVE . ' WHERE s.id = ? ORDER BY e.start_date DESC'
        );
        $stmt->execute([$id]);
        $song['performances'] = $stmt->fetchAll();

        return $song;
    }

    /** @return list<array{id: int, title_display: string}> other songs this could be merged into */
    public function mergeTargets(array $song): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT s.id, s.title_display FROM songs s ' . self::LIVE . '
             WHERE s.performer_artist_id = ? AND s.original_key = ? AND s.id <> ? ORDER BY s.title_display COLLATE NOCASE'
        );
        $stmt->execute([$song['performer_artist_id'], $song['original_key'], $song['id']]);

        return $stmt->fetchAll();
    }

    /** Deletes songs no setlist mentions any more. Excluded songs stay so the choice applies if they come back. */
    public function pruneOrphans(): int
    {
        return (int) $this->pdo->exec(
            'DELETE FROM songs WHERE excluded = 0 AND NOT EXISTS (SELECT 1 FROM song_performances sp WHERE sp.song_id = songs.id)'
        );
    }

    public function setExcluded(int $id, bool $excluded): void
    {
        $this->pdo->prepare('UPDATE songs SET excluded = ? WHERE id = ?')->execute([(int) $excluded, $id]);
    }

    /** "These are the same song": moves $fromId's performances into $intoId and remembers it for future scans. */
    public function merge(int $fromId, int $intoId): void
    {
        $songs = $this->pdo->prepare('SELECT id, performer_artist_id, original_key, title_norm FROM songs WHERE id IN (?, ?)');
        $songs->execute([$fromId, $intoId]);
        $byId = array_column($songs->fetchAll(), null, 'id');
        $from = $byId[$fromId] ?? null;
        $into = $byId[$intoId] ?? null;
        if ($from === null || $into === null || $fromId === $intoId
            || $from['performer_artist_id'] !== $into['performer_artist_id'] || $from['original_key'] !== $into['original_key']) {
            throw new InvalidArgumentException('Only songs by the same artist (and the same original, for covers) can be merged.');
        }

        $this->pdo->beginTransaction();
        try {
            // Both titles played at the same show: fold the counts together.
            $this->pdo->prepare(
                'UPDATE song_performances SET times_in_show = times_in_show + (
                    SELECT f.times_in_show FROM song_performances f WHERE f.song_id = ? AND f.performance_id = song_performances.performance_id)
                 WHERE song_id = ? AND performance_id IN (SELECT performance_id FROM song_performances WHERE song_id = ?)'
            )->execute([$fromId, $intoId, $fromId]);
            $this->pdo->prepare(
                'DELETE FROM song_performances WHERE song_id = ? AND performance_id IN (SELECT performance_id FROM song_performances WHERE song_id = ?)'
            )->execute([$fromId, $intoId]);
            $this->pdo->prepare('UPDATE song_performances SET song_id = ? WHERE song_id = ?')->execute([$intoId, $fromId]);

            $this->pdo->prepare('UPDATE song_aliases SET to_title_norm = ? WHERE performer_artist_id = ? AND original_key = ? AND to_title_norm = ?')
                ->execute([$into['title_norm'], $from['performer_artist_id'], $from['original_key'], $from['title_norm']]);
            $this->pdo->prepare(
                'INSERT INTO song_aliases (performer_artist_id, original_key, from_title_norm, to_title_norm) VALUES (?, ?, ?, ?)
                 ON CONFLICT (performer_artist_id, original_key, from_title_norm) DO UPDATE SET to_title_norm = excluded.to_title_norm'
            )->execute([$from['performer_artist_id'], $from['original_key'], $from['title_norm'], $into['title_norm']]);
            $this->pdo->prepare('DELETE FROM songs WHERE id = ?')->execute([$fromId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
