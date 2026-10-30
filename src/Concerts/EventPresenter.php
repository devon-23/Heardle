<?php

declare(strict_types=1);

namespace HeardLive\Concerts;

/** Display helpers for an event row from EventRepository. */
final class EventPresenter
{
    private const FESTIVAL_MIN_ACTS = 6;

    /** @param array<string, mixed> $event */
    public static function isFestival(array $event): bool
    {
        return $event['end_date'] !== null
            || stripos((string) $event['name'], 'festival') !== false
            || count($event['performances']) >= self::FESTIVAL_MIN_ACTS;
    }

    /** Festivals are titled by event name; other shows by their first-listed act. */
    public static function title(array $event): string
    {
        if (self::isFestival($event) && $event['name'] !== null) {
            return $event['name'];
        }
        return $event['performances'][0]['artist'] ?? $event['name'] ?? 'Untitled show';
    }

    /** The tour or event name when it isn't already the title. */
    public static function subtitle(array $event): ?string
    {
        $name = $event['name'];
        return $name !== null && $name !== self::title($event) ? $name : null;
    }

    public static function date(array $event): string
    {
        $start = new \DateTimeImmutable($event['start_date']);
        if ($event['end_date'] === null) {
            return strtoupper($start->format('D')) . ' ' . $start->format('m.d.Y');
        }
        $end = new \DateTimeImmutable($event['end_date']);

        return $start->format('m.d') . '–' . $end->format('m.d.Y');
    }

    /**
     * The setlist status shown beside an attended performer.
     *
     * @param array<string, mixed> $p
     * @return ?array{tone: string, text: string, url: ?string}
     */
    public static function setlistStatus(array $p): ?array
    {
        if (!$p['attended']) {
            return null;
        }
        if ($p['artist_resolution'] === 'ambiguous') {
            return ['tone' => 'warn', 'text' => 'which artist?', 'url' => null];
        }
        if ($p['artist_resolution'] === 'unresolved' && $p['artist_candidates'] !== []) {
            return ['tone' => 'warn', 'text' => 'did you mean…?', 'url' => null];
        }
        $songs = (int) $p['setlist_song_count'];

        return match ($p['setlist_status']) {
            'matched' => ['tone' => 'ok', 'text' => $songs . ($songs === 1 ? ' song' : ' songs'), 'url' => self::setlistFmUrl($p['setlist_url'])],
            'empty' => ['tone' => 'muted', 'text' => 'setlist has no songs', 'url' => self::setlistFmUrl($p['setlist_url'])],
            'ambiguous' => ['tone' => 'warn', 'text' => 'choose setlist', 'url' => null],
            'missing' => ['tone' => 'muted', 'text' => match ($p['setlist_note']) {
                'artist_not_found' => 'not on setlist.fm',
                'user_none' => 'no setlist',
                default => 'no setlist that night',
            }, 'url' => null],
            'error' => ['tone' => 'err', 'text' => 'lookup failed', 'url' => null],
            default => ['tone' => 'muted', 'text' => 'not scanned yet', 'url' => null],
        };
    }

    /** Only https links to setlist.fm are rendered; anything else from the API is dropped. */
    public static function setlistFmUrl(?string $url): ?string
    {
        return $url !== null && preg_match('#^https://(www\.)?setlist\.fm/#', $url) ? $url : null;
    }

    /** Why the scan needs a decision about this setlist. */
    public static function choiceReason(array $p): string
    {
        return match ($p['setlist_note']) {
            'special_show' => 'This was a special show, so check the setlist matches what you saw.',
            'location_mismatch' => 'setlist.fm has them somewhere else that day. Pick it only if it’s really your show.',
            default => 'There’s more than one setlist for them on these dates. Which one did you see?',
        };
    }

    public static function shortDate(?string $date): string
    {
        return $date === null ? '' : (new \DateTimeImmutable($date))->format('D M j');
    }

    public static function place(array $event): string
    {
        $where = array_filter([$event['city'], $event['region'] ?? $event['country']]);

        return implode(' · ', array_filter([$event['venue'], implode(', ', $where)]));
    }
}
