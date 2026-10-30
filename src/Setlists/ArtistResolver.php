<?php

declare(strict_types=1);

namespace HeardLive\Setlists;

use HeardLive\Support\Text;

/** Finds the setlist.fm artist for a name from the CSV. See docs/BUILD_SPEC.md §6.2. */
final class ArtistResolver
{
    private const MAX_CANDIDATES = 5;
    private const DATES_TO_CHECK = 2;
    // How alike two names must be (similar_text percentage) to be offered as "did you mean".
    private const SUGGEST_SIMILARITY = 75;

    public function __construct(private SetlistFmClient $client)
    {
    }

    /**
     * @param list<string> $attendedDates YYYY-MM-DD, used to tell same-named artists apart
     * @return array{resolution: string, mbid: ?string, url: ?string, candidates: list<array<string, ?string>>}
     */
    public function resolve(string $name, array $attendedDates): array
    {
        $results = $this->client->searchArtists($name);
        $exact = self::exactMatches($name, $results);

        // setlist.fm's search is literal about "&" vs "and", so try the other spelling before giving up.
        $alternate = self::alternateSpelling($name);
        if ($exact === [] && $alternate !== null) {
            $results = [...$results, ...$this->client->searchArtists($alternate)];
            $exact = self::exactMatches($name, $results);
        }

        if ($exact === []) {
            // Offer only names close enough to be a spelling difference, e.g. "Jackson Brown" → "Jackson Browne".
            $similar = array_values(array_filter($results, fn (array $a) => self::similar($name, (string) ($a['name'] ?? ''))));
            return self::result('unresolved', null, array_slice($similar, 0, self::MAX_CANDIDATES));
        }
        if (count($exact) === 1) {
            return self::result('resolved', $exact[0], $exact);
        }

        // Several artists share the name: keep the one with a setlist on a date this user was there.
        $exact = array_slice($exact, 0, self::MAX_CANDIDATES);
        $withSetlist = [];
        foreach ($exact as $candidate) {
            foreach (array_slice($attendedDates, 0, self::DATES_TO_CHECK) as $date) {
                if ($this->client->setlistsOn((string) $candidate['mbid'], $date) !== []) {
                    $withSetlist[] = $candidate;
                    break;
                }
            }
        }

        return count($withSetlist) === 1
            ? self::result('resolved', $withSetlist[0], $exact)
            : self::result('ambiguous', null, $exact);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>> distinct artists whose name matches exactly (loosely)
     */
    private static function exactMatches(string $name, array $results): array
    {
        $key = Text::looseKey($name);
        $matches = [];
        foreach ($results as $artist) {
            if (Text::looseKey((string) ($artist['name'] ?? '')) === $key) {
                $matches[$artist['mbid'] ?? ''] = $artist;
            }
        }

        return array_values($matches);
    }

    private static function alternateSpelling(string $name): ?string
    {
        if (str_contains($name, '&')) {
            return (string) preg_replace('/\s*&\s*/', ' and ', $name);
        }
        if (preg_match('/\sand\s/i', $name)) {
            return (string) preg_replace('/\s+and\s+/i', ' & ', $name);
        }
        return null;
    }

    private static function similar(string $a, string $b): bool
    {
        $a = Text::looseKey($a);
        $b = Text::looseKey($b);
        if (strlen($a) < 4 || strlen($b) < 4) {
            return false;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        similar_text($a, $b, $percent);

        return $percent >= self::SUGGEST_SIMILARITY;
    }

    /**
     * @param ?array<string, mixed> $chosen
     * @param list<array<string, mixed>> $candidates
     */
    private static function result(string $resolution, ?array $chosen, array $candidates): array
    {
        return [
            'resolution' => $resolution,
            'mbid' => $chosen['mbid'] ?? null,
            'url' => $chosen['url'] ?? null,
            'candidates' => array_map(fn (array $a) => [
                'mbid' => $a['mbid'] ?? null,
                'name' => $a['name'] ?? null,
                'disambiguation' => ($a['disambiguation'] ?? '') !== '' ? $a['disambiguation'] : null,
                'url' => $a['url'] ?? null,
            ], $candidates),
        ];
    }
}
