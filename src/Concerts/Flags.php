<?php

declare(strict_types=1);

namespace HeardLive\Concerts;

use HeardLive\Import\ParsedEvent;

/** User-facing wording for import flags. */
final class Flags
{
    /** @param array<string, mixed> $event */
    public static function label(string $flag, array $event = []): string
    {
        return match ($flag) {
            ParsedEvent::FLAG_MULTI_DAY => 'Multi-day: which day each act played isn’t recorded',
            ParsedEvent::FLAG_NONSTANDARD => ($event['nonstandard_type'] ?? 'Special show') . ': the setlist may differ from the tour',
            ParsedEvent::FLAG_SEEN_AND_NOT_SEEN => 'An artist was listed as both seen and not seen',
            ParsedEvent::FLAG_NOTHING_SEEN => 'No performers are marked as seen',
            ParsedEvent::FLAG_DUPLICATE_ARTIST => 'An artist was listed twice',
            ParsedEvent::FLAG_NO_VENUE => 'No venue listed',
            default => $flag,
        };
    }

    public static function short(string $flag): string
    {
        return match ($flag) {
            ParsedEvent::FLAG_MULTI_DAY => 'Multi-day',
            ParsedEvent::FLAG_NONSTANDARD => 'Special show',
            ParsedEvent::FLAG_SEEN_AND_NOT_SEEN => 'Seen and not seen',
            ParsedEvent::FLAG_NOTHING_SEEN => 'Nothing seen',
            ParsedEvent::FLAG_DUPLICATE_ARTIST => 'Duplicate artist',
            ParsedEvent::FLAG_NO_VENUE => 'No venue',
            default => $flag,
        };
    }
}
