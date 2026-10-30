<?php

declare(strict_types=1);

namespace HeardLive\Matching;

use HeardLive\Songs\TitleNormalizer;

/**
 * Reads an iTunes track's title and album to tell what kind of recording it is (studio, live,
 * remix…) and how its title compares with the song's. See docs/BUILD_SPEC.md §8.2.
 *
 * Labels are peeled off the end of the track title one at a time ("Song (feat. X) [Live]" →
 * "Song (feat. X)" → "Song") until what's left matches the song, so brackets that belong to the
 * song's real name ("The Craving (Jenna's Version)") are never treated as labels.
 */
final class RecordingClassifier
{
    // Labels that don't change what the recording is: credits, remasters, release formats.
    private const NEUTRAL = '/^(?:feat\.?|ft\.?|featuring|with|prod\.?|from\b|bonus|explicit|clean|edited|mono|stereo|\d{4}\b.*remaster|remaster'
        . '|(?:single|album|radio|lp|original|main|studio)(?: version| edit| mix)?$|\d{4}$)/i';

    // Checked in this order; the first that matches a label sets the kind.
    private const KINDS = [
        'karaoke' => '/karaoke|originally performed|in the style of|made famous|tribute/i',
        'instrumental' => '/instrumental/i',
        'altered' => '/sped[ -]?up|slowed|nightcore|reverb|8d audio/i',
        'remix' => '/\b(?:remix|rmx|mix|dub|vip|bootleg|rework|flip)\b/i',
        'live' => '/\blive\b|\bin concert\b|session/i',
        'acoustic' => '/acoustic|stripped|unplugged|piano/i',
        'demo' => '/\bdemo\b|voice memo/i',
    ];

    private const LIVE_ALBUM = '/[(\[]live[)\]]|\blive (?:at|in|from|on)\b|- live$|\bunplugged\b|\bin concert\b/i';
    private const KARAOKE_ALBUM = '/karaoke|lullaby|tribute to|in the style of|made famous|instrumental versions/i';
    private const COMPILATION = '/greatest hits|best of|\bhits\b|collection|anthology|essentials|the very best|now that\'?s|compilation/i';

    /**
     * @return array{kind: string, title: 'exact'|'qualified'|'fuzzy'|'none', compilation: bool, single: bool, deluxe: bool}
     *   title: exact = same song, at most neutral labels; qualified = same base title with an unknown label;
     *   fuzzy = similar titles only; none = a different song.
     */
    public static function classify(string $trackName, string $collectionName, string $songKey): array
    {
        $labels = [];
        $rest = self::unmask(trim($trackName), $songKey);
        $title = 'none';

        for ($i = 0; $i <= 5; $i++) {
            $normalized = TitleNormalizer::normalize($rest);
            if ($normalized['key'] === $songKey) {
                if ($normalized['variant'] !== null) {
                    $labels[] = $normalized['variant'];
                }
                $title = 'exact';
                break;
            }
            if (!preg_match('/^(.*\S)\s*(?:\(([^()]*)\)|\[([^\[\]]*)\]|\s[-–—]\s+([^-–—]+))$/u', $rest, $m)) {
                break;
            }
            $rest = $m[1];
            $labels[] = trim($m[2] !== '' ? $m[2] : ($m[3] ?? '') . ($m[4] ?? ''));
        }

        if ($title === 'none' && self::similar(TitleNormalizer::normalize($rest)['key'], $songKey)) {
            $title = 'fuzzy';
        }

        $kind = null;
        foreach ($labels as $label) {
            if (preg_match(self::NEUTRAL, $label)) {
                continue;
            }
            $labelKind = self::kindOf($label);
            if ($labelKind === null) {
                // e.g. "(Taylor's Version)" or "(2nd Movement)": probably this song, but not certainly.
                $title = $title === 'exact' ? 'qualified' : $title;
                continue;
            }
            $kind ??= $labelKind;
        }

        if ($kind === null && preg_match(self::KARAOKE_ALBUM, $collectionName)) {
            $kind = 'karaoke';
        }
        if ($kind === null && preg_match(self::LIVE_ALBUM, $collectionName)) {
            $kind = 'live';
        }

        return [
            'kind' => $kind ?? 'studio',
            'title' => $title,
            'compilation' => (bool) preg_match(self::COMPILATION, $collectionName),
            'single' => (bool) preg_match('/ - (?:single|ep)$/i', $collectionName),
            'deluxe' => (bool) preg_match('/deluxe|expanded|anniversary|edition|bonus/i', $collectionName),
        ];
    }

    /** Apple masks swear words ("F**k U"); read each masked word as the song-title word it fits ("Fuck U"). */
    private static function unmask(string $title, string $songKey): string
    {
        if (!str_contains($title, '*')) {
            return $title;
        }
        $words = explode(' ', $songKey);

        return (string) preg_replace_callback('/[\p{L}\p{N}]*\*+[\p{L}\p{N}*]*/u', function (array $m) use ($words): string {
            $pattern = '/^' . str_replace('\*', '[\p{L}\p{N}]', preg_quote(mb_strtolower($m[0]), '/')) . '$/u';
            foreach ($words as $word) {
                if (preg_match($pattern, $word)) {
                    return $word;
                }
            }
            return $m[0];
        }, $title);
    }

    private static function kindOf(string $label): ?string
    {
        foreach (self::KINDS as $kind => $pattern) {
            if (preg_match($pattern, $label)) {
                return $kind;
            }
        }
        // "Extended Version" is another take on the song; "(Taylor's Version)" may be the one you heard.
        if (preg_match('/version|edit/i', $label) && !preg_match('/[\'’]s\s+version/iu', $label)) {
            return 'other';
        }

        return null;
    }

    private static function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        similar_text($a, $b, $percent);

        return $percent >= 85;
    }
}
