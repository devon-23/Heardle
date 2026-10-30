<?php

declare(strict_types=1);

namespace HeardLive\Matching;

use Closure;
use HeardLive\Setlists\SetlistFmFixtures;

/**
 * Where fixture mode looks for saved iTunes responses:
 *   search/{term-slug}.json    song searches
 *   artists/{name-slug}.json   artist searches
 *   lookup/{artistId}.json     an artist's songs
 */
final class ItunesFixtures
{
    /** @return Closure(string): ?string */
    public static function mapper(): Closure
    {
        return static function (string $url): ?string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $slug = SetlistFmFixtures::slug((string) ($query['term'] ?? ''));

            return match (true) {
                str_ends_with((string) parse_url($url, PHP_URL_PATH), '/lookup') => 'lookup/' . (int) ($query['id'] ?? 0) . '.json',
                ($query['entity'] ?? '') === 'musicArtist' => "artists/$slug.json",
                default => "search/$slug.json",
            };
        };
    }
}
