<?php

declare(strict_types=1);

namespace HeardLive\Import;

use HeardLive\Support\Text;

final readonly class ParseResult
{
    /**
     * @param list<ParsedEvent> $events
     * @param list<array{row: int, message: string}> $errors   rows that couldn't be imported
     * @param list<array{row: int, message: string}> $skipped  valid rows deliberately left out
     * @param list<string> $extraColumns columns we don't use (reported, not fatal)
     */
    public function __construct(
        public array $events,
        public array $errors,
        public array $skipped,
        public array $extraColumns,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $seen = 0;
        $notSeen = 0;
        $artists = [];
        $flagCounts = [];
        $dates = [];

        foreach ($this->events as $event) {
            $seen += count($event->seen);
            $notSeen += count($event->notSeen);
            foreach ($event->seen as $artist) {
                $artists[Text::nameKey($artist)] = true;
            }
            foreach ($event->flags as $flag) {
                $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            }
            $dates[] = $event->startDate;
        }
        sort($dates);
        ksort($flagCounts);

        return [
            'events' => count($this->events),
            'performances_seen' => $seen,
            'performances_not_seen' => $notSeen,
            'artists_seen' => count($artists),
            'first_date' => $dates[0] ?? null,
            'last_date' => $dates === [] ? null : $dates[count($dates) - 1],
            'flags' => $flagCounts,
            'flagged_events' => count(array_filter($this->events, fn (ParsedEvent $e) => $e->flags !== [])),
            'errors' => count($this->errors),
            'skipped' => count($this->skipped),
        ];
    }
}
