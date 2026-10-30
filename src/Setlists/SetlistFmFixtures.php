<?php

declare(strict_types=1);

namespace HeardLive\Setlists;

use Closure;

/**
 * Where fixture mode looks for saved setlist.fm responses:
 *   artists/{artist-name-slug}.json
 *   setlists/{mbid}_{dd-MM-yyyy}.json   (page N > 1: _pN)
 *   setlist/{id}.json
 */
final class SetlistFmFixtures
{
    /** @return Closure(string): ?string */
    public static function mapper(): Closure
    {
        return static function (string $url): ?string {
            $path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = (int) ($query['p'] ?? 1);
            $suffix = $page > 1 ? "_p$page" : '';

            return match (true) {
                str_ends_with($path, '/search/artists') => 'artists/' . self::slug((string) ($query['artistName'] ?? '')) . "$suffix.json",
                str_ends_with($path, '/search/setlists') => sprintf('setlists/%s_%s%s.json', $query['artistMbid'] ?? '', $query['date'] ?? '', $suffix),
                (bool) preg_match('#/setlist/([^/]+)$#', $path, $m) => 'setlist/' . rawurldecode($m[1]) . '.json',
                default => null,
            };
        };
    }

    public static function slug(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }
}
