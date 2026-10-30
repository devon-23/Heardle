<?php

declare(strict_types=1);

namespace HeardLive\Matching;

use InvalidArgumentException;
use PDO;
use Throwable;

/** Which Apple Music recording each song uses, and the queues for reviewing them. See docs/BUILD_SPEC.md §8.4. */
final class MatchRepository
{
    public const TABS = ['review', 'unmatched', 'covers', 'auto', 'chosen', 'excluded'];
    public const PER_PAGE = ['review' => 40, 'unmatched' => 40, 'covers' => 40, 'auto' => 120, 'chosen' => 120, 'excluded' => 40];

    // Songs that currently count, as in SongRepository.
    private const LIVE = 'JOIN song_performances sp ON sp.song_id = s.id
        JOIN performances p ON p.id = sp.performance_id AND p.attended = 1 AND p.setlist_status = \'matched\'
        JOIN events e ON e.id = p.event_id AND e.active = 1';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> songs on the playlist that haven't been searched for yet */
    public function songsToMatch(): array
    {
        return $this->pdo->query(
            'SELECT s.id, s.title_display, s.title_norm, s.original_artist_name, a.name AS performer
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id
             WHERE s.excluded = 0 AND NOT EXISTS (SELECT 1 FROM song_matches m WHERE m.song_id = s.id)
               AND s.id IN (SELECT s.id FROM songs s ' . self::LIVE . ')
             ORDER BY a.name COLLATE NOCASE, s.title_display COLLATE NOCASE'
        )->fetchAll();
    }

    /** @return ?array<string, mixed> the song as RecordingMatcher wants it, with any search the user typed */
    public function songForSearch(int $songId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.title_display, s.title_norm, s.original_artist_name, a.name AS performer, m.search_terms
             FROM songs s JOIN artists a ON a.id = s.performer_artist_id LEFT JOIN song_matches m ON m.song_id = s.id WHERE s.id = ?'
        );
        $stmt->execute([$songId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Stores a search result. A recording the user chose is kept unless $terms is given (a new search they asked for).
     *
     * @param array{confidence: string, status: string, pick: ?int, candidates: list<array<string, mixed>>} $match
     */
    public function save(int $songId, array $match, ?string $terms = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $recordingIds = array_map(fn (array $c) => $this->saveRecording($c['result']), $match['candidates']);

            $this->pdo->prepare('DELETE FROM song_candidates WHERE song_id = ?')->execute([$songId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO song_candidates (song_id, recording_id, rank, score, kind, artist_match, title_match) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($match['candidates'] as $rank => $candidate) {
                $insert->execute([$songId, $recordingIds[$rank], $rank + 1, $candidate['score'], $candidate['kind'], $candidate['artist_match'], $candidate['title_match']]);
            }

            $pick = $match['pick'] === null ? null : $recordingIds[$match['pick']];
            $keepChoice = $terms === null ? " WHERE song_matches.status <> 'chosen'" : '';
            $this->pdo->prepare(
                "INSERT INTO song_matches (song_id, recording_id, status, confidence, search_terms, matched_at, decided_at)
                 VALUES (?, ?, ?, ?, ?, ?, NULL)
                 ON CONFLICT (song_id) DO UPDATE SET recording_id = excluded.recording_id, status = excluded.status,
                    confidence = excluded.confidence, search_terms = excluded.search_terms, matched_at = excluded.matched_at, decided_at = NULL
                 $keepChoice"
            )->execute([$songId, $pick, $match['status'], $match['confidence'], $terms === '' ? null : $terms, gmdate('c')]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string, int> songs per review tab, plus 'unsearched' */
    public function counts(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(DISTINCT CASE WHEN s.excluded = 0 AND m.status = 'review' THEN s.id END) AS review,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 AND m.status = 'unmatched' AND s.original_key = '' THEN s.id END) AS unmatched,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 AND m.status = 'unmatched' AND s.original_key <> '' THEN s.id END) AS covers,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 AND m.status = 'auto' THEN s.id END) AS auto,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 AND m.status = 'chosen' THEN s.id END) AS chosen,
                    COUNT(DISTINCT CASE WHEN s.excluded = 1 THEN s.id END) AS excluded,
                    COUNT(DISTINCT CASE WHEN s.excluded = 0 AND m.song_id IS NULL THEN s.id END) AS unsearched
             FROM songs s " . self::LIVE . ' LEFT JOIN song_matches m ON m.song_id = s.id'
        )->fetch();

        return array_map('intval', $row);
    }

    /**
     * @return array{songs: list<array<string, mixed>>, total: int, pages: int, page: int}
     *   each song has 'pick' (recording or null) and 'candidates' (with recordings)
     */
    public function tab(string $tab, int $page = 1, string $q = ''): array
    {
        $where = match ($tab) {
            'review' => "s.excluded = 0 AND m.status = 'review'",
            'unmatched' => "s.excluded = 0 AND m.status = 'unmatched' AND s.original_key = ''",
            'covers' => "s.excluded = 0 AND m.status = 'unmatched' AND s.original_key <> ''",
            'auto' => "s.excluded = 0 AND m.status = 'auto'",
            'chosen' => "s.excluded = 0 AND m.status = 'chosen'",
            'excluded' => 's.excluded = 1',
            default => throw new InvalidArgumentException("Unknown tab: $tab"),
        };
        $params = [];
        if ($q !== '') {
            $where .= " AND (s.title_display LIKE ? ESCAPE '\\' OR a.name LIKE ? ESCAPE '\\')";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like);
        }

        $sql = 'SELECT s.id, s.title_display, s.original_artist_name, s.original_key, s.excluded, a.name AS performer,
                       m.status, m.confidence, m.recording_id, m.search_terms,
                       COUNT(DISTINCT p.id) AS heard, MAX(e.start_date) AS last_heard
                FROM songs s JOIN artists a ON a.id = s.performer_artist_id ' . self::LIVE . '
                LEFT JOIN song_matches m ON m.song_id = s.id
                WHERE ' . $where . ' GROUP BY s.id';

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ($sql)");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = self::PER_PAGE[$tab];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        // Most-heard first: those matter most on the playlist.
        $stmt = $this->pdo->prepare("$sql ORDER BY heard DESC, a.name COLLATE NOCASE, s.title_display COLLATE NOCASE LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $stmt->execute($params);
        $songs = $stmt->fetchAll();

        return ['songs' => $this->withRecordings($songs), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** @return ?array{status: string, confidence: string, search_terms: ?string, pick: ?array, candidates: list<array>} */
    public function forSong(int $songId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ? AS id, m.status, m.confidence, m.recording_id, m.search_terms FROM song_matches m WHERE m.song_id = ?');
        $stmt->execute([$songId, $songId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->withRecordings([$row])[0];
    }

    /** The user picks one of the song's candidates. */
    public function choose(int $songId, int $recordingId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM song_candidates WHERE song_id = ? AND recording_id = ?');
        $stmt->execute([$songId, $recordingId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('That recording isn’t one of this song’s options.');
        }
        $this->pdo->prepare("UPDATE song_matches SET recording_id = ?, status = 'chosen', decided_at = ? WHERE song_id = ?")
            ->execute([$recordingId, gmdate('c'), $songId]);
    }

    /** The user confirms the current pick. */
    public function accept(int $songId): void
    {
        $stmt = $this->pdo->prepare("UPDATE song_matches SET status = 'chosen', decided_at = ? WHERE song_id = ? AND recording_id IS NOT NULL");
        $stmt->execute([gmdate('c'), $songId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('This song has no recording to accept yet.');
        }
    }

    /** Confirms every automatic match. Returns how many. */
    public function approveAll(): int
    {
        $stmt = $this->pdo->prepare("UPDATE song_matches SET status = 'chosen', decided_at = ? WHERE status = 'auto'");
        $stmt->execute([gmdate('c')]);

        return $stmt->rowCount();
    }

    /** Sends an automatic match back to review. */
    public function reopen(int $songId): void
    {
        $this->pdo->prepare("UPDATE song_matches SET status = CASE WHEN recording_id IS NULL THEN 'unmatched' ELSE 'review' END, decided_at = NULL WHERE song_id = ?")
            ->execute([$songId]);
    }

    /** Clears automatic results when switching between fixture and live data; the user's choices stay. */
    public function resetIfSourceChanged(string $source): void
    {
        $previous = $this->pdo->query("SELECT value FROM app_meta WHERE key = 'itunes_source'")->fetchColumn();
        if ($previous !== false && $previous !== $source) {
            $this->pdo->exec("DELETE FROM song_candidates WHERE song_id IN (SELECT song_id FROM song_matches WHERE status <> 'chosen')");
            $this->pdo->exec("DELETE FROM song_matches WHERE status <> 'chosen'");
            // Saved responses are keyed by URL, so fixture answers would otherwise stand in for real ones.
            $this->pdo->prepare('DELETE FROM api_cache WHERE provider = ?')->execute([ItunesClient::PROVIDER]);
        }
        $this->pdo->prepare("INSERT INTO app_meta (key, value) VALUES ('itunes_source', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
            ->execute([$source]);
    }

    /** @param array<string, mixed> $result one iTunes search result */
    private function saveRecording(array $result): int
    {
        $this->pdo->prepare(
            'INSERT INTO recordings (track_id, track_name, artist_name, itunes_artist_id, collection_name, release_date, duration_ms, artwork_url, view_url, explicit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (track_id) DO UPDATE SET track_name = excluded.track_name, artist_name = excluded.artist_name,
                itunes_artist_id = excluded.itunes_artist_id, collection_name = excluded.collection_name, release_date = excluded.release_date,
                duration_ms = excluded.duration_ms, artwork_url = excluded.artwork_url, view_url = excluded.view_url, explicit = excluded.explicit'
        )->execute([
            (int) $result['trackId'], $result['trackName'], $result['artistName'], $result['artistId'] ?? null,
            $result['collectionName'] ?? null, isset($result['releaseDate']) ? substr((string) $result['releaseDate'], 0, 10) : null,
            $result['trackTimeMillis'] ?? null, $result['artworkUrl100'] ?? null, $result['trackViewUrl'] ?? null,
            (int) (($result['trackExplicitness'] ?? '') === 'explicit'),
        ]);
        $stmt = $this->pdo->prepare('SELECT id FROM recordings WHERE track_id = ?');
        $stmt->execute([(int) $result['trackId']]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string, mixed>> $songs rows with id and recording_id
     * @return list<array<string, mixed>> the same rows with 'pick' and 'candidates'
     */
    private function withRecordings(array $songs): array
    {
        if ($songs === []) {
            return [];
        }
        $ids = array_map(fn ($s) => (int) $s['id'], $songs);
        $stmt = $this->pdo->prepare(
            'SELECT c.song_id, c.rank, c.kind, c.artist_match, c.title_match, r.* FROM song_candidates c JOIN recordings r ON r.id = c.recording_id
             WHERE c.song_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY c.song_id, c.rank'
        );
        $stmt->execute($ids);
        $bySong = [];
        foreach ($stmt->fetchAll() as $row) {
            $bySong[(int) $row['song_id']][] = $row;
        }

        foreach ($songs as &$song) {
            $song['candidates'] = $bySong[(int) $song['id']] ?? [];
            $song['pick'] = null;
            foreach ($song['candidates'] as $candidate) {
                if ($song['recording_id'] !== null && (int) $candidate['id'] === (int) $song['recording_id']) {
                    $song['pick'] = $candidate;
                }
            }
        }

        return $songs;
    }
}
