<?php

declare(strict_types=1);

namespace HeardLive\Support;

use Normalizer;

final class Text
{
    /** Comparison key for names: NFKC, lowercase, straight quotes, single spaces. Never shown to users. */
    public static function nameKey(string $name): string
    {
        $name = Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name;
        $name = strtr($name, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower(trim($name));
    }

    /**
     * Looser key for matching names across sources: also treats "&" as "and" and ignores accents,
     * punctuation and spacing, so "half•alive" matches "half·alive", "Case Barge" matches "Case Bargé"
     * and "Judah & the Lion" matches "Judah and the Lion".
     */
    public static function looseKey(string $name): string
    {
        $key = str_replace('&', ' and ', self::nameKey($name));
        $key = Normalizer::normalize($key, Normalizer::FORM_D) ?: $key;
        $key = preg_replace('/\p{Mn}+/u', '', $key) ?? $key;

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $key);
    }
}
