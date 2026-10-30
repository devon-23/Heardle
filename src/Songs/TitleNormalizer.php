<?php

declare(strict_types=1);

namespace HeardLive\Songs;

use Normalizer;

/**
 * Comparison keys for song titles (display always keeps the original). See docs/BUILD_SPEC.md §7.3.
 * Brackets are usually part of the real title ("I'm Not Okay (I Promise)"), so only plain version
 * labels like "(acoustic)" are set aside as a variant.
 */
final class TitleNormalizer
{
    private const VERSION_LABEL = '/\s*[(\[]\s*((?:acoustic|piano|stripped(?: down)?|live|demo|unplugged)(?: version)?)\s*[)\]]\s*$/i';

    private const ROMAN = ['i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10];

    /** The title without a trailing "(acoustic)"-style label, e.g. for searching. */
    public static function withoutVersionLabel(string $title): string
    {
        return trim((string) preg_replace(self::VERSION_LABEL, '', $title));
    }

    /** @return array{key: string, variant: ?string} */
    public static function normalize(string $title): array
    {
        $variant = null;
        if (preg_match(self::VERSION_LABEL, $title, $m)) {
            $variant = strtolower($m[1]);
            $title = (string) preg_replace(self::VERSION_LABEL, '', $title);
        }

        $key = Normalizer::normalize($title, Normalizer::FORM_KC) ?: $title;
        $key = mb_strtolower($key);
        $key = Normalizer::normalize($key, Normalizer::FORM_D) ?: $key;
        $key = (string) preg_replace('/\p{Mn}+/u', '', $key);
        $key = strtr($key, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '&' => ' and ']);
        $key = str_replace("'", '', $key);                       // don't → dont, not don t
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);
        $key = trim((string) preg_replace('/\s+/', ' ', $key));

        // "pt 2", "pt. ii", "part II" → "part 2"
        $key = (string) preg_replace_callback(
            '/\b(?:pt|part)\s+(\d+|i{1,3}|iv|vi{0,3}|ix|x)\b/',
            fn (array $m) => 'part ' . (self::ROMAN[$m[1]] ?? $m[1]),
            $key,
        );

        return ['key' => $key, 'variant' => $variant];
    }
}
