<?php

declare(strict_types=1);

namespace HeardLive\Songs;

use HeardLive\Setlists\SetlistSummary;
use HeardLive\Support\Text;

/** Turns one setlist.fm setlist into the songs performed, one entry per distinct song. See docs/BUILD_SPEC.md §7.1. */
final class SetlistSongs
{
    // A note that starts with one of these describes this song being cut short…
    private const PARTIAL = '/^(partial|snippet|shortened|excerpt|first verse(?: and chorus)?|(?:1st|first|second|2nd) half(?: only)?)\b/i';
    // …while "with snippet of X" or "'X' snippet" describes a different song, so it doesn't count.

    private const VARIANT = '/^(acoustic|stripped(?: down)?(?: version)?|piano(?: version)?|demo version|single version|unplugged)$/i';

    /**
     * @param array<string, mixed> $setlist
     * @return list<array{title: string, key: string, original_key: string, original_name: ?string, original_mbid: ?string,
     *                    set_index: int, position: int, encore: ?int, times_in_show: int, variant: ?string,
     *                    partial: bool, medley: bool, info: ?string, with_artist: ?string}>
     */
    public static function from(array $setlist): array
    {
        $songs = [];
        $position = 0;

        foreach (SetlistSummary::sets($setlist) ?? [] as $setIndex => $set) {
            $encore = isset($set['encore']) ? (int) $set['encore'] : null;
            foreach ($set['song'] ?? [] as $song) {
                $line = trim((string) ($song['name'] ?? ''));
                if ($line === '' || !empty($song['tape'])) {
                    continue;
                }
                $position++;

                // setlist.fm writes medleys as "Song A / Song B". Only a spaced slash splits:
                // "Needing/Getting" and "Unknown/Nth" are single songs.
                $parts = array_values(array_filter(array_map('trim', explode(' / ', $line)), fn ($p) => $p !== ''));
                $info = trim((string) ($song['info'] ?? ''));
                $medley = count($parts) > 1 || stripos($info, 'medley') !== false;
                $cover = $song['cover'] ?? null;
                $originalKey = self::originalKey($cover);

                foreach ($parts as $title) {
                    $normalized = TitleNormalizer::normalize($title);
                    if ($normalized['key'] === '') {
                        continue;
                    }
                    $identity = $normalized['key'] . "\0" . $originalKey;

                    if (isset($songs[$identity])) {
                        // Played twice (e.g. a reprise): one song at this show, played more than once.
                        $songs[$identity]['times_in_show']++;
                        continue;
                    }

                    $songs[$identity] = [
                        'title' => $title,
                        'key' => $normalized['key'],
                        'original_key' => $originalKey,
                        'original_name' => $cover['name'] ?? null,
                        'original_mbid' => $cover['mbid'] ?? null,
                        'set_index' => (int) $setIndex,
                        'position' => $position,
                        'encore' => $encore,
                        'times_in_show' => 1,
                        'variant' => $normalized['variant'] ?? self::variantFrom($info),
                        'partial' => self::isPartial($info),
                        'medley' => $medley,
                        'info' => $info === '' ? null : $info,
                        'with_artist' => $song['with']['name'] ?? null,
                    ];
                }
            }
        }

        return array_values($songs);
    }

    /** '' for the performer's own song; otherwise identifies the original artist of a cover. */
    public static function originalKey(?array $cover): string
    {
        if ($cover === null) {
            return '';
        }
        if (!empty($cover['mbid'])) {
            return 'mbid:' . $cover['mbid'];
        }
        $name = Text::looseKey((string) ($cover['name'] ?? ''));

        return $name === '' ? '' : 'name:' . $name;
    }

    private static function isPartial(string $info): bool
    {
        foreach (explode(';', $info) as $part) {
            if (preg_match(self::PARTIAL, trim($part))) {
                return true;
            }
        }
        return false;
    }

    private static function variantFrom(string $info): ?string
    {
        foreach (explode(';', $info) as $part) {
            if (preg_match(self::VARIANT, trim($part), $m)) {
                return strtolower($m[1]);
            }
        }
        return null;
    }
}
