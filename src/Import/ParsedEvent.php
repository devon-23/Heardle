<?php

declare(strict_types=1);

namespace HeardLive\Import;

final readonly class ParsedEvent
{
    public const FLAG_MULTI_DAY = 'multi_day';
    public const FLAG_NONSTANDARD = 'nonstandard_type';
    public const FLAG_SEEN_AND_NOT_SEEN = 'seen_and_not_seen';
    public const FLAG_NOTHING_SEEN = 'nothing_seen';
    public const FLAG_DUPLICATE_ARTIST = 'duplicate_artist';
    public const FLAG_NO_VENUE = 'no_venue';

    /**
     * @param list<string> $seen      artists in "Bands Seen", CSV order, deduplicated
     * @param list<string> $notSeen   artists in "Bands Not Seen" that aren't also in $seen
     * @param list<string> $flags
     */
    public function __construct(
        public int $row,
        public string $url,
        public string $startDate,
        public ?string $endDate,
        public ?string $nonstandardType,
        public string $status,
        public ?string $name,
        public array $seen,
        public array $notSeen,
        public ?string $venue,
        public ?string $city,
        public ?string $region,
        public ?string $country,
        public string $locationRaw,
        public array $flags,
    ) {
    }
}
