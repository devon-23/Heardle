<?php

declare(strict_types=1);

namespace HeardLive\Concerts;

use InvalidArgumentException;
use PDO;

/** Reads and updates imported events for the concert review screen. */
final class EventRepository
{
    public const FILTERS = ['all', 'attention', 'missing', 'unseen', 'festivals'];

    // A show counts as festival-like if it spans days, says so, or lists this many acts.
    private const FESTIVAL_MIN_ACTS = 6;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{events: int, attended: int, not_attended: int, artists: int, attention: int, unscanned: int} */
    public function stats(): array
    {
        $row = $this->pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM events WHERE active = 1) AS events,
                (SELECT COUNT(*) FROM performances p JOIN events e ON e.id = p.event_id WHERE e.active = 1 AND p.attended = 1) AS attended,
                (SELECT COUNT(*) FROM performances p JOIN events e ON e.id = p.event_id WHERE e.active = 1 AND p.attended = 0) AS not_attended,
                (SELECT COUNT(DISTINCT p.artist_id) FROM performances p JOIN events e ON e.id = p.event_id WHERE e.active = 1 AND p.attended = 1) AS artists,
                (SELECT COUNT(*) FROM events e WHERE e.active = 1 AND " . $this->attentionSql() . ") AS attention,
                (SELECT COUNT(*) FROM performances p JOIN events e ON e.id = p.event_id
                  WHERE e.active = 1 AND p.attended = 1 AND p.setlist_status = 'pending') AS unscanned"
        )->fetch();

        return array_map('intval', $row);
    }

    public function hasEvents(): bool
    {
        return (bool) $this->pdo->query('SELECT 1 FROM events WHERE active = 1 LIMIT 1')->fetchColumn();
    }

    /** @return list<array<string, mixed>> events (newest first), each with a 'performances' list */
    public function forReview(string $filter): array
    {
        $where = 'e.active = 1 AND ' . $this->filterSql($filter);
        $events = $this->pdo->query(
            "SELECT e.* FROM events e WHERE $where ORDER BY e.start_date DESC, e.id DESC"
        )->fetchAll();

        return $this->withPerformances($events);
    }

    /** @return ?array<string, mixed> */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id = ? AND active = 1');
        $stmt->execute([$id]);
        $event = $stmt->fetch();

        return $event === false ? null : $this->withPerformances([$event])[0];
    }

    /**
     * Sets exactly which performances at an event were attended. Anything that changes is marked as the user's choice.
     *
     * @param list<int> $attendedIds
     */
    public function setAttendance(int $eventId, array $attendedIds): void
    {
        $stmt = $this->pdo->prepare('SELECT id, attended FROM performances WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $current = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $unknown = array_diff($attendedIds, array_keys($current));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Performance ' . reset($unknown) . ' is not part of this event.');
        }

        $want = array_flip($attendedIds);
        $update = $this->pdo->prepare("UPDATE performances SET attended = ?, attended_source = 'user' WHERE id = ?");
        foreach ($current as $id => $attended) {
            $new = isset($want[$id]) ? 1 : 0;
            if ($new !== (int) $attended) {
                $update->execute([$new, $id]);
            }
        }
    }

    public function markReviewed(int $eventId): void
    {
        $this->pdo->prepare('UPDATE events SET reviewed_at = ? WHERE id = ?')->execute([gmdate('c'), $eventId]);
    }

    /** Marks every event in a filter as reviewed. Returns how many changed. */
    public function markFilterReviewed(string $filter): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE events SET reviewed_at = ? WHERE id IN (SELECT e.id FROM events e WHERE e.active = 1 AND e.reviewed_at IS NULL AND '
            . $this->filterSql($filter) . ')'
        );
        $stmt->execute([gmdate('c')]);

        return $stmt->rowCount();
    }

    /** @return array<string, int> filter → number of events */
    public function filterCounts(): array
    {
        $counts = [];
        foreach (self::FILTERS as $filter) {
            $counts[$filter] = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM events e WHERE e.active = 1 AND ' . $this->filterSql($filter)
            )->fetchColumn();
        }

        return $counts;
    }

    private function filterSql(string $filter): string
    {
        return match ($filter) {
            'all' => '1',
            'attention' => $this->attentionSql(),
            'festivals' => "(e.end_date IS NOT NULL OR e.name LIKE '%festival%'"
                . ' OR (SELECT COUNT(*) FROM performances p WHERE p.event_id = e.id) >= ' . self::FESTIVAL_MIN_ACTS . ')',
            'unseen' => 'EXISTS (SELECT 1 FROM performances p WHERE p.event_id = e.id AND p.attended = 0)',
            'missing' => "EXISTS (SELECT 1 FROM performances p WHERE p.event_id = e.id AND p.attended = 1
                                  AND p.setlist_status IN ('missing', 'empty', 'error'))",
            default => throw new InvalidArgumentException("Unknown filter: $filter"),
        };
    }

    /** Unreviewed import flags, or a setlist or artist the scan couldn't decide on. */
    private function attentionSql(): string
    {
        return "((e.flags_json <> '[]' AND e.reviewed_at IS NULL)
                 OR EXISTS (SELECT 1 FROM performances p JOIN artists a ON a.id = p.artist_id
                            WHERE p.event_id = e.id AND p.attended = 1
                              AND (p.setlist_status = 'ambiguous' OR a.resolution = 'ambiguous'
                                   OR (a.resolution = 'unresolved' AND a.candidates_json <> '[]'))))";
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function withPerformances(array $events): array
    {
        if ($events === []) {
            return [];
        }
        $ids = array_column($events, 'id');
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.event_id, p.listed_as, p.position, p.attended, p.attended_source,
                    p.setlist_status, p.setlist_source, p.setlist_url, p.setlist_song_count, p.setlist_event_date,
                    p.setlist_note, p.setlist_candidates_json,
                    a.id AS artist_id, a.name AS artist, a.resolution AS artist_resolution,
                    a.candidates_json AS artist_candidates_json, a.setlistfm_url AS artist_url
             FROM performances p JOIN artists a ON a.id = p.artist_id
             WHERE p.event_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')
             ORDER BY p.position'
        );
        $stmt->execute($ids);

        $byEvent = [];
        foreach ($stmt->fetchAll() as $performance) {
            $performance['attended'] = (bool) $performance['attended'];
            $performance['setlist_candidates'] = json_decode($performance['setlist_candidates_json'], true) ?: [];
            $performance['artist_candidates'] = json_decode($performance['artist_candidates_json'], true) ?: [];
            unset($performance['setlist_candidates_json'], $performance['artist_candidates_json']);
            $byEvent[$performance['event_id']][] = $performance;
        }

        foreach ($events as &$event) {
            $event['flags'] = json_decode($event['flags_json'], true);
            $event['performances'] = $byEvent[$event['id']] ?? [];
            $event['flags_pending'] = $event['flags'] !== [] && $event['reviewed_at'] === null;
            $event['choices'] = array_values(array_filter(
                $event['performances'],
                fn ($p) => $p['attended'] && (
                    $p['setlist_status'] === 'ambiguous'
                    || $p['artist_resolution'] === 'ambiguous'
                    || ($p['artist_resolution'] === 'unresolved' && $p['artist_candidates'] !== [])
                ),
            ));
            $event['needs_attention'] = $event['flags_pending'] || $event['choices'] !== [];
        }

        return $events;
    }
}
