<?php

declare(strict_types=1);

namespace HeardLive\Matching;

use HeardLive\Songs\TitleNormalizer;
use HeardLive\Support\Text;

/**
 * Finds Apple Music recordings for one song. See docs/BUILD_SPEC.md §8.2–8.3.
 *
 * Looks through the performer's catalog first (up to 200 songs per artist, fetched once per artist):
 * Apple's search ranks an artist's popular songs above an exact title, so album tracks are often
 * missing from search results. If that doesn't give a studio recording, it searches
 * "{performer} {title}"; if still nothing by the performer, the title alone (catches artists named
 * differently on Apple Music) and, for covers, the original artist's version, which is offered
 * but never picked automatically.
 */
final class RecordingMatcher
{
    public const KEEP = 5;

    /** @var array<string, list<array<string, mixed>>> performer → catalog songs, for this run */
    private array $catalogs = [];

    public function __construct(private ItunesClient $itunes)
    {
    }

    /**
     * @param array{performer: string, title_display: string, title_norm: string, original_artist_name: ?string, search_terms?: ?string} $song
     * @return array{confidence: string, status: string, pick: ?int,
     *               candidates: list<array{result: array<string, mixed>, kind: string, artist_match: string, title_match: string, score: int}>}
     *   pick: index into candidates
     */
    public function match(array $song): array
    {
        $title = TitleNormalizer::withoutVersionLabel($song['title_display']);
        $custom = trim((string) ($song['search_terms'] ?? ''));
        $searches = $custom !== '' ? [$custom] : array_filter([
            $song['performer'] . ' ' . $title,
            $title,
            $song['original_artist_name'] !== null ? $song['original_artist_name'] . ' ' . $title : null,
        ]);

        $scored = [];
        $add = function (array $results) use (&$scored, $song): void {
            foreach ($results as $result) {
                $id = (int) $result['trackId'];
                if (!isset($scored[$id]) && ($score = CandidateScorer::score($song, $result)) !== null) {
                    $scored[$id] = ['result' => $result] + $score;
                }
            }
        };

        if ($custom === '') {
            $add($this->catalog($song['performer']));
        }
        if (!self::foundStudio($scored)) {
            foreach ($searches as $term) {
                $add($this->itunes->search($term));
                if (self::foundByPerformer($scored)) {
                    break;
                }
            }
        }

        $candidates = array_values($scored);
        usort($candidates, fn (array $a, array $b) => [$b['score'], $a['result']['releaseDate'] ?? '9999', $a['result']['trackId']]
            <=> [$a['score'], $b['result']['releaseDate'] ?? '9999', $b['result']['trackId']]);
        // The same track on the same album often appears twice (explicit and clean editions): keep the better one.
        $seen = [];
        $candidates = array_values(array_filter($candidates, function (array $c) use (&$seen): bool {
            $key = implode('|', array_map(Text::looseKey(...), [$c['result']['artistName'], $c['result']['trackName'], $c['result']['collectionName'] ?? '']));
            return !isset($seen[$key]) && ($seen[$key] = true);
        }));
        $candidates = array_slice($candidates, 0, self::KEEP);

        $confidence = CandidateScorer::confidence($candidates[0] ?? null);
        $status = match ($confidence) {
            // Never auto-accept a search the user typed: they're looking at this song already.
            'high' => $custom !== '' ? 'review' : 'auto',
            'medium' => 'review',
            default => 'unmatched',
        };

        return ['confidence' => $confidence, 'status' => $status, 'pick' => $confidence === 'none' ? null : 0, 'candidates' => $candidates];
    }

    /** @return list<array<string, mixed>> songs by the artist(s) on Apple Music with the performer's name */
    private function catalog(string $performer): array
    {
        if (isset($this->catalogs[$performer])) {
            return $this->catalogs[$performer];
        }
        $artists = ['exact' => [], 'partial' => []];
        foreach ($this->itunes->searchArtists($performer) as $artist) {
            $match = CandidateScorer::artistMatch((string) $artist['artistName'], $performer);
            if (isset($artists[$match])) {
                $artists[$match][] = (int) $artist['artistId'];
            }
        }
        // Two artists can share a name; their songs only match on the title anyway.
        $ids = array_slice($artists['exact'] ?: $artists['partial'], 0, 2);
        $songs = [];
        foreach (array_unique($ids) as $id) {
            $catalog = $this->itunes->artistSongs($id);
            if (count($catalog) >= ItunesClient::CATALOG_LIMIT) {
                // Cut off: the newest 200 cover much of what the first list missed.
                $catalog = [...$catalog, ...$this->itunes->artistSongs($id, recent: true)];
            }
            $songs = [...$songs, ...$catalog];
        }

        return $this->catalogs[$performer] = $songs;
    }

    /** @param array<int, array{artist_match: string, title_match: string, kind: string}> $scored */
    private static function foundStudio(array $scored): bool
    {
        foreach ($scored as $candidate) {
            if (CandidateScorer::confidence($candidate) === 'high') {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{artist_match: string}> $scored */
    private static function foundByPerformer(array $scored): bool
    {
        foreach ($scored as $candidate) {
            if (in_array($candidate['artist_match'], CandidateScorer::BY_PERFORMER, true)) {
                return true;
            }
        }

        return false;
    }
}
