<?php

declare(strict_types=1);

namespace HeardLive\Import;

use HeardLive\Support\Text;
use PDO;
use Throwable;

/**
 * Writes a parsed export to the database. Re-importing is an upsert keyed on the event URL:
 * attendance the user changed by hand is kept; everything else follows the latest CSV.
 */
final class Importer
{
    /** @var array<string, int> artist name key → id */
    private array $artistIds = [];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{import_id: int, created: int, updated: int, deactivated: int} */
    public function import(ParseResult $result, string $filename): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO imports (filename, imported_at, row_count, summary_json) VALUES (?, ?, ?, ?)');
            $stmt->execute([$filename, gmdate('c'), count($result->events), json_encode($result->summary())]);
            $importId = (int) $this->pdo->lastInsertId();

            $created = 0;
            $updated = 0;
            $eventIds = [];
            foreach ($result->events as $event) {
                [$eventId, $isNew] = $this->upsertEvent($importId, $event);
                $isNew ? $created++ : $updated++;
                $eventIds[] = $eventId;
                $this->syncPerformances($eventId, $event);
            }

            $deactivated = $this->deactivateMissing($eventIds);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['import_id' => $importId, 'created' => $created, 'updated' => $updated, 'deactivated' => $deactivated];
    }

    /** @return array{0: int, 1: bool} event id, whether it was newly created */
    private function upsertEvent(int $importId, ParsedEvent $event): array
    {
        $fields = [
            'import_id' => $importId,
            'start_date' => $event->startDate,
            'end_date' => $event->endDate,
            'name' => $event->name,
            'venue' => $event->venue,
            'city' => $event->city,
            'region' => $event->region,
            'country' => $event->country,
            'location_raw' => $event->locationRaw,
            'nonstandard_type' => $event->nonstandardType,
            'status' => $event->status,
            'flags_json' => json_encode($event->flags),
        ];

        $find = $this->pdo->prepare('SELECT id, flags_json FROM events WHERE ca_url = ?');
        $find->execute([$event->url]);
        $existing = $find->fetch();

        if ($existing === false) {
            $columns = array_keys($fields + ['ca_url' => null]);
            $sql = sprintf(
                'INSERT INTO events (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
            );
            $this->pdo->prepare($sql)->execute([...array_values($fields), $event->url]);

            return [(int) $this->pdo->lastInsertId(), true];
        }

        $sets = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($fields)));
        // New or changed flags mean the event needs another look.
        $resetReview = $existing['flags_json'] !== $fields['flags_json'] ? ', reviewed_at = NULL' : '';
        $this->pdo->prepare("UPDATE events SET $sets, active = 1$resetReview WHERE id = ?")
            ->execute([...array_values($fields), $existing['id']]);

        return [(int) $existing['id'], false];
    }

    private function syncPerformances(int $eventId, ParsedEvent $event): void
    {
        $listed = [];
        foreach ($event->seen as $artist) {
            $listed[] = [$artist, 'seen'];
        }
        foreach ($event->notSeen as $artist) {
            $listed[] = [$artist, 'not_seen'];
        }

        $find = $this->pdo->prepare('SELECT id, attended_source FROM performances WHERE event_id = ? AND artist_id = ?');
        $insert = $this->pdo->prepare(
            'INSERT INTO performances (event_id, artist_id, listed_as, position, attended) VALUES (?, ?, ?, ?, ?)'
        );
        $update = $this->pdo->prepare(
            "UPDATE performances SET listed_as = ?, position = ?,
                attended = CASE WHEN attended_source = 'user' THEN attended ELSE ? END
             WHERE id = ?"
        );

        $keepIds = [];
        foreach ($listed as $position => [$artist, $listedAs]) {
            $artistId = $this->artistId($artist);
            $attended = $listedAs === 'seen' ? 1 : 0;

            $find->execute([$eventId, $artistId]);
            $existing = $find->fetch();
            if ($existing === false) {
                $insert->execute([$eventId, $artistId, $listedAs, $position, $attended]);
                $keepIds[] = (int) $this->pdo->lastInsertId();
            } else {
                $update->execute([$listedAs, $position, $attended, $existing['id']]);
                $keepIds[] = (int) $existing['id'];
            }
        }

        // Artists no longer listed for this event in Concert Archives.
        $placeholders = implode(', ', array_fill(0, count($keepIds), '?')) ?: 'NULL';
        $this->pdo->prepare("DELETE FROM performances WHERE event_id = ? AND id NOT IN ($placeholders)")
            ->execute([$eventId, ...$keepIds]);
    }

    private function artistId(string $name): int
    {
        $key = Text::nameKey($name);
        if (isset($this->artistIds[$key])) {
            return $this->artistIds[$key];
        }

        $find = $this->pdo->prepare('SELECT id FROM artists WHERE name_norm = ?');
        $find->execute([$key]);
        $id = $find->fetchColumn();
        if ($id === false) {
            $this->pdo->prepare('INSERT INTO artists (name, name_norm) VALUES (?, ?)')->execute([$name, $key]);
            $id = $this->pdo->lastInsertId();
        }

        return $this->artistIds[$key] = (int) $id;
    }

    /** @param list<int> $keepIds */
    private function deactivateMissing(array $keepIds): int
    {
        $placeholders = implode(', ', array_fill(0, count($keepIds), '?')) ?: 'NULL';
        $stmt = $this->pdo->prepare("UPDATE events SET active = 0 WHERE active = 1 AND id NOT IN ($placeholders)");
        $stmt->execute($keepIds);

        return $stmt->rowCount();
    }
}
