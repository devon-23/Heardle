<?php

declare(strict_types=1);

namespace HeardLive\Matching;

use HeardLive\Support\Text;

/**
 * Scores one iTunes result against a song and decides how sure a match is. See docs/BUILD_SPEC.md §8.2–8.3.
 * Only an exact artist + exact title + studio recording is "high" and gets accepted without review.
 */
final class CandidateScorer
{
    private const ARTIST_POINTS = ['exact' => 100, 'credited' => 90, 'partial' => 70, 'original' => 40, 'none' => 0];
    private const TITLE_POINTS = ['exact' => 50, 'qualified' => 35, 'fuzzy' => 20];
    private const KIND_POINTS = [
        'studio' => 30, 'live' => 15, 'acoustic' => 12, 'demo' => 8, 'other' => 6, 'remix' => 5,
        'instrumental' => 0, 'altered' => -20, 'karaoke' => -50,
    ];

    /** Artist matches that mean the recording is by the performer. */
    public const BY_PERFORMER = ['exact', 'credited', 'partial'];

    /**
     * @param array{performer: string, title_norm: string, original_artist_name: ?string} $song
     * @param array<string, mixed> $result one iTunes search result
     * @return ?array{kind: string, artist_match: string, title_match: string, score: int}
     *   null when the result is a different song
     */
    public static function score(array $song, array $result): ?array
    {
        $type = RecordingClassifier::classify((string) $result['trackName'], (string) ($result['collectionName'] ?? ''), $song['title_norm']);
        if ($type['title'] === 'none') {
            return null;
        }
        $artist = self::artistMatch((string) $result['artistName'], $song['performer'], $song['original_artist_name']);

        $score = self::ARTIST_POINTS[$artist] + self::TITLE_POINTS[$type['title']] + self::KIND_POINTS[$type['kind']];
        // Prefer the original album over compilations, singles and deluxe reissues of the same track.
        // Apple credits compilations to "Various Artists"; names like "Greatest Hits" catch the rest.
        $compilation = $type['compilation'] || preg_match('/^various artists$/i', (string) ($result['collectionArtistName'] ?? ''));
        $score -= ($compilation ? 10 : 0) + ($type['single'] ? 3 : 0) + ($type['deluxe'] ? 1 : 0);
        $score -= ($result['trackExplicitness'] ?? '') === 'cleaned' ? 2 : 0;

        return ['kind' => $type['kind'], 'artist_match' => $artist, 'title_match' => $type['title'], 'score' => $score];
    }

    /** @param array{kind: string, artist_match: string, title_match: string} $best */
    public static function confidence(?array $best): string
    {
        if ($best === null || !in_array($best['artist_match'], self::BY_PERFORMER, true)) {
            return 'none';
        }
        if (in_array($best['artist_match'], ['exact', 'credited'], true) && $best['title_match'] === 'exact' && $best['kind'] === 'studio') {
            return 'high';
        }

        return 'medium';
    }

    /**
     * exact: same artist. credited: the performer is one of several credited ("A & B", "A feat. B").
     * partial: the result is by part of the performer's name ("Kevin Devine" for "Kevin Devine & The Goddamn Band",
 *          "Dixie" for "Dixie D'Amelio").
     * original: the cover's original artist.
     */
    public static function artistMatch(string $candidate, string $performer, ?string $original = null): string
    {
        $candidateKey = self::key($candidate);
        $performerKey = self::key($performer);
        if ($candidateKey === $performerKey) {
            return 'exact';
        }
        if (in_array($performerKey, self::parts($candidate), true)) {
            return 'credited';
        }
        if (strlen($candidateKey) >= 4 && (in_array($candidateKey, self::parts($performer), true) || self::leadingWords($candidate, $performer))) {
            return 'partial';
        }
        if ($original !== null && ($candidateKey === self::key($original) || in_array(self::key($original), self::parts($candidate), true))) {
            return 'original';
        }

        return 'none';
    }

    /** "Dixie" for "Dixie D'Amelio": the candidate's name is the first word(s) of the performer's. */
    private static function leadingWords(string $candidate, string $performer): bool
    {
        $words = fn (string $n) => preg_split('/\s+/u', Text::nameKey($n)) ?: [];
        $c = $words($candidate);
        $p = $words($performer);

        return count($c) < count($p) && array_slice($p, 0, count($c)) === $c;
    }

    private static function key(string $name): string
    {
        return Text::looseKey((string) preg_replace('/^the\s+/i', '', trim($name)));
    }

    /** @return list<string> keys of each name in a credit like "A, B & C feat. D" */
    private static function parts(string $credit): array
    {
        $names = preg_split('/\s*(?:&|,|\+|\/|\bfeat\.?|\bft\.?|\bfeaturing\b|\bwith\b|\bx\b|\band\b|\bvs\.?)\s*/i', $credit) ?: [];

        return array_values(array_filter(array_map(self::key(...), $names), fn ($k) => $k !== ''));
    }
}
