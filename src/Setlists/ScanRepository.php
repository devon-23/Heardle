<?php

declare(strict_types=1);

namespace HeardLive\Setlists;

use InvalidArgumentException;
use PDO;

/** Database side of the setlist scan: what still needs work, saving results, and user choices. */
final class ScanRepository
{
    private const MATCHABLE = "('resolved', 'user_chosen')";
    private const NOT_FOUND = "('unresolved', 'user_none')";

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{id: int, name: string, dates: list<string>}> artists you saw that still need a setlist.fm match */
    public function artistsToResolve(): array
    {
        $rows = $this->pdo->query(
            "SELECT a.id, a.name, GROUP_CONCAT(e.start_date) AS dates
             FROM artists a
             JOIN performances p ON p.artist_id = a.id AND p.attended = 1
             JOIN events e ON e.id = p.event_id AND e.active = 1
             WHERE a.resolution = 'pending'
             GROUP BY a.id ORDER BY a.name"
        )->fetchAll();

        return array_map(function (array $row): array {
            $dates = array_unique(explode(',', (string) $row['dates']));
            rsort($dates);
            return ['id' => (int) $row['id'], 'name' => $row['name'], 'dates' => array_values($dates)];
        }, $rows);
    }

    /** @param array{resolution: string, mbid: ?string, url: ?string, candidates: list<array>} $result */
    public function saveArtist(int $artistId, array $result): void
    {
        $this->pdo->prepare('UPDATE artists SET resolution = ?, mbid = ?, setlistfm_url = ?, candidates_json = ? WHERE id = ?')
            ->execute([$result['resolution'], $result['mbid'], $result['url'], json_encode($result['candidates']), $artistId]);
        $this->syncArtistPerformances($artistId, $result['resolution']);
    }

    /** @return list<array<string, mixed>> attended performances ready for setlist matching */
    public function performancesToMatch(): array
    {
        return $this->pdo->query(
            "SELECT p.id, a.name AS artist, a.mbid, e.start_date, e.end_date, e.venue, e.city, e.region, e.nonstandard_type
             FROM performances p
             JOIN artists a ON a.id = p.artist_id
             JOIN events e ON e.id = p.event_id AND e.active = 1
             WHERE p.attended = 1 AND p.setlist_status IN ('pending', 'error') AND a.resolution IN " . self::MATCHABLE . "
             ORDER BY e.start_date DESC, p.position"
        )->fetchAll();
    }

    /** @param array{status: string, chosen: ?array, candidates: list<array>, note: ?string} $result */
    public function saveMatch(int $performanceId, array $result, string $source = 'auto'): void
    {
        $chosen = $result['chosen'];
        $this->pdo->prepare(
            'UPDATE performances SET setlist_status = ?, setlist_source = ?, setlist_id = ?, setlist_version_id = ?,
                setlist_url = ?, setlist_event_date = ?, setlist_song_count = ?, setlist_candidates_json = ?, setlist_note = ?
             WHERE id = ?'
        )->execute([
            $result['status'],
            $source,
            $chosen['id'] ?? null,
            $chosen['version_id'] ?? null,
            $chosen['url'] ?? null,
            $chosen['date'] ?? null,
            $chosen['song_count'] ?? null,
            json_encode($result['candidates']),
            $result['note'],
            $performanceId,
        ]);
        // Songs from the previous setlist no longer apply; the next scan's songs stage refills them.
        $this->pdo->prepare('DELETE FROM song_performances WHERE performance_id = ?')->execute([$performanceId]);
        $this->pdo->prepare('UPDATE performances SET songs_setlist_id = NULL WHERE id = ?')->execute([$performanceId]);
    }

    public function markError(int $performanceId, string $message): void
    {
        $this->pdo->prepare("UPDATE performances SET setlist_status = 'error', setlist_note = ? WHERE id = ?")
            ->execute([$message, $performanceId]);
    }

    /** User picked one of the candidate setlists, or said none of them is right (null). */
    public function chooseSetlist(int $performanceId, ?string $setlistId): void
    {
        $stmt = $this->pdo->prepare('SELECT setlist_candidates_json FROM performances WHERE id = ?');
        $stmt->execute([$performanceId]);
        $json = $stmt->fetchColumn();
        if ($json === false) {
            throw new InvalidArgumentException('Unknown performance.');
        }
        $candidates = json_decode($json, true);

        if ($setlistId === null) {
            $this->saveMatch($performanceId, ['status' => 'missing', 'chosen' => null, 'candidates' => $candidates, 'note' => 'user_none'], 'user');
            return;
        }
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $setlistId) {
                $status = $candidate['song_count'] > 0 ? 'matched' : 'empty';
                $this->saveMatch($performanceId, ['status' => $status, 'chosen' => $candidate, 'candidates' => $candidates, 'note' => null], 'user');
                return;
            }
        }
        throw new InvalidArgumentException('That setlist isn’t one of the candidates for this performance.');
    }

    /** User picked which same-named artist they saw, or said it isn't on setlist.fm (null). */
    public function chooseArtist(int $artistId, ?string $mbid): void
    {
        $stmt = $this->pdo->prepare('SELECT candidates_json FROM artists WHERE id = ?');
        $stmt->execute([$artistId]);
        $json = $stmt->fetchColumn();
        if ($json === false) {
            throw new InvalidArgumentException('Unknown artist.');
        }

        if ($mbid === null) {
            $this->pdo->prepare("UPDATE artists SET resolution = 'user_none', mbid = NULL, setlistfm_url = NULL WHERE id = ?")->execute([$artistId]);
            $this->syncArtistPerformances($artistId, 'user_none');
            return;
        }
        foreach (json_decode($json, true) as $candidate) {
            if ($candidate['mbid'] === $mbid) {
                $this->pdo->prepare("UPDATE artists SET resolution = 'user_chosen', mbid = ?, setlistfm_url = ? WHERE id = ?")
                    ->execute([$mbid, $candidate['url'], $artistId]);
                $this->syncArtistPerformances($artistId, 'user_chosen', resetMatched: true);
                return;
            }
        }
        throw new InvalidArgumentException('That artist isn’t one of the candidates.');
    }

    /**
     * Results depend on where data came from, so switching between fixture and live mode
     * clears everything the scan found automatically. User choices are kept.
     */
    public function resetIfSourceChanged(string $source): bool
    {
        $stmt = $this->pdo->prepare("SELECT value FROM app_meta WHERE key = 'setlist_source'");
        $stmt->execute();
        $previous = $stmt->fetchColumn();
        $this->pdo->prepare("INSERT INTO app_meta (key, value) VALUES ('setlist_source', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
            ->execute([$source]);
        if ($previous === false || $previous === $source) {
            return false;
        }

        $this->pdo->exec("UPDATE artists SET resolution = 'pending', mbid = NULL, setlistfm_url = NULL, candidates_json = '[]'
                          WHERE resolution NOT IN ('user_chosen', 'user_none')");
        $this->pdo->exec(
            "UPDATE performances SET setlist_status = 'pending', setlist_id = NULL, setlist_version_id = NULL, setlist_url = NULL,
                setlist_event_date = NULL, setlist_song_count = NULL, setlist_candidates_json = '[]', setlist_note = NULL
             WHERE setlist_source = 'auto'"
        );

        return true;
    }

    /** @return array<string, int> counts for attended performances in active events, plus artists needing a choice */
    public function coverage(): array
    {
        $counts = ['pending' => 0, 'matched' => 0, 'ambiguous' => 0, 'missing' => 0, 'empty' => 0, 'error' => 0];
        $rows = $this->pdo->query(
            'SELECT p.setlist_status, COUNT(*) FROM performances p JOIN events e ON e.id = p.event_id AND e.active = 1
             WHERE p.attended = 1 GROUP BY p.setlist_status'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $status => $count) {
            $counts[$status] = (int) $count;
        }
        $counts['total'] = array_sum($counts);
        $counts['artists_ambiguous'] = (int) $this->pdo->query(
            "SELECT COUNT(DISTINCT a.id) FROM artists a JOIN performances p ON p.artist_id = a.id AND p.attended = 1
             JOIN events e ON e.id = p.event_id AND e.active = 1
             WHERE a.resolution = 'ambiguous' OR (a.resolution = 'unresolved' AND a.candidates_json <> '[]')"
        )->fetchColumn();
        $counts['artists_not_found'] = (int) $this->pdo->query(
            'SELECT COUNT(DISTINCT a.id) FROM artists a JOIN performances p ON p.artist_id = a.id AND p.attended = 1
             JOIN events e ON e.id = p.event_id AND e.active = 1 WHERE a.resolution IN ' . self::NOT_FOUND . "
               AND NOT (a.resolution = 'unresolved' AND a.candidates_json <> '[]')"
        )->fetchColumn();

        return $counts;
    }

    /** Performances ticked since the last scan whose artist is already known not to be on setlist.fm. */
    public function markPerformancesOfMissingArtists(): void
    {
        $this->pdo->exec(
            "UPDATE performances SET setlist_status = 'missing', setlist_note = 'artist_not_found'
             WHERE setlist_status = 'pending' AND setlist_source = 'auto'
               AND artist_id IN (SELECT id FROM artists WHERE resolution IN " . self::NOT_FOUND . ')'
        );
    }

    /** Keeps an artist's automatic performance statuses in line with whether the artist was found. */
    private function syncArtistPerformances(int $artistId, string $resolution, bool $resetMatched = false): void
    {
        if (in_array($resolution, ['unresolved', 'user_none'], true)) {
            $this->pdo->prepare(
                "UPDATE performances SET setlist_status = 'missing', setlist_note = 'artist_not_found'
                 WHERE artist_id = ? AND setlist_source = 'auto' AND setlist_status IN ('pending', 'error', 'missing')"
            )->execute([$artistId]);
            return;
        }

        $statuses = $resetMatched ? "('pending', 'error', 'missing', 'matched', 'ambiguous', 'empty')" : "('missing')";
        $this->pdo->prepare(
            "UPDATE performances SET setlist_status = 'pending', setlist_id = NULL, setlist_version_id = NULL, setlist_url = NULL,
                setlist_event_date = NULL, setlist_song_count = NULL, setlist_candidates_json = '[]', setlist_note = NULL
             WHERE artist_id = ? AND setlist_source = 'auto' AND setlist_status IN $statuses"
                . ($resetMatched ? '' : " AND setlist_note = 'artist_not_found'")
        )->execute([$artistId]);
    }
}
