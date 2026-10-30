<?php

declare(strict_types=1);

namespace HeardLive\Matching;

/** Display helpers for recordings and match states. */
final class MatchPresenter
{
    public const TAB_LABELS = [
        'review' => 'Needs review',
        'unmatched' => 'Unmatched',
        'covers' => 'Covers without recording',
        'auto' => 'Auto-matched',
        'chosen' => 'Confirmed',
        'excluded' => 'Excluded',
    ];

    private const KIND_LABELS = [
        'studio' => null, 'live' => 'live', 'acoustic' => 'acoustic', 'remix' => 'remix', 'demo' => 'demo',
        'instrumental' => 'instrumental', 'karaoke' => 'karaoke', 'altered' => 'sped up / slowed', 'other' => 'other version',
    ];

    /** Only links that really go to Apple Music are shown. */
    public static function appleMusicUrl(?string $url): ?string
    {
        if ($url === null || !preg_match('#^https://music\.apple\.com/#', $url)) {
            return null;
        }

        return (string) preg_replace('/[?&]uo=\d+$/', '', $url);
    }

    /** Apple's artwork URLs carry their size; ask for one sharp enough for a 2x screen. */
    public static function artwork(?string $url, int $size = 120): ?string
    {
        if ($url === null || !preg_match('#^https://[a-z0-9-]+\.mzstatic\.com/#', $url)) {
            return null;
        }

        return (string) preg_replace('#/\d+x\d+bb\.(jpg|png)$#', "/{$size}x{$size}bb.$1", $url);
    }

    /** @param array{kind: string, artist_match: string, title_match: string} $candidate @return list<string> */
    public static function badges(array $candidate, ?string $originalArtist): array
    {
        $badges = [];
        if (($kind = self::KIND_LABELS[$candidate['kind']] ?? null) !== null) {
            $badges[] = $kind;
        }
        $badges[] = match ($candidate['artist_match']) {
            'original' => 'original by ' . ($originalArtist ?? 'the original artist'),
            'partial' => 'similar artist name',
            'none' => 'different artist',
            default => null,
        };
        $badges[] = match ($candidate['title_match']) {
            'fuzzy' => 'similar title',
            'qualified' => 'extra label in title',
            default => null,
        };

        return array_values(array_filter($badges));
    }

    public static function year(?string $date): string
    {
        return $date === null ? '' : substr($date, 0, 4);
    }

    public static function statusText(?string $status): string
    {
        return match ($status) {
            'auto' => 'Matched automatically',
            'review' => 'Needs review',
            'unmatched' => 'No recording found',
            'chosen' => 'Confirmed',
            default => 'Not searched yet',
        };
    }
}
