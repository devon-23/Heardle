<?php

declare(strict_types=1);

namespace HeardLive\Setlists;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use HeardLive\Support\Text;

/**
 * Picks the one setlist.fm setlist for a performance: same artist, same date (any day of a
 * multi-day event), and a location that agrees with the CSV. Never borrows another date.
 * See docs/BUILD_SPEC.md §6.3.
 */
final class SetlistMatcher
{
    private const MAX_DAYS = 4;

    public function __construct(private SetlistFmClient $client)
    {
    }

    /**
     * @param array{start_date: string, end_date: ?string, venue: ?string, city: ?string, region: ?string, nonstandard_type: ?string} $event
     * @return array{status: string, chosen: ?array<string, mixed>, candidates: list<array<string, mixed>>, note: ?string}
     */
    public function match(array $event, string $artistMbid): array
    {
        $candidates = [];
        foreach ($this->dates($event) as $date) {
            foreach ($this->client->setlistsOn($artistMbid, $date) as $setlist) {
                $summary = SetlistSummary::from($setlist);
                $summary['location_ok'] = $this->locationAgrees($event, $summary);
                $summary['venue_score'] = $this->venueScore($event['venue'], $summary['venue']);
                $candidates[] = $summary;
            }
        }

        if ($candidates === []) {
            return ['status' => 'missing', 'chosen' => null, 'candidates' => [], 'note' => 'no_setlist'];
        }

        usort($candidates, fn ($a, $b) => [$b['location_ok'], $b['venue_score']] <=> [$a['location_ok'], $a['venue_score']]);
        $plausible = array_values(array_filter($candidates, fn ($c) => $c['location_ok']));

        // Several setlists on the same day (e.g. a show plus a radio session): an exact venue match settles it.
        // Across festival days it doesn't — the stage name says nothing about which day you were there.
        $sameVenue = array_values(array_filter($plausible, fn ($c) => $c['venue_score'] === 2));
        $oneDay = count(array_unique(array_column($plausible, 'date'))) === 1;
        if (count($plausible) > 1 && $oneDay && count($sameVenue) === 1) {
            $plausible = $sameVenue;
        }

        if (count($plausible) !== 1) {
            $note = $plausible === [] ? 'location_mismatch' : 'several_setlists';
            return ['status' => 'ambiguous', 'chosen' => null, 'candidates' => $candidates, 'note' => $note];
        }

        $chosen = $plausible[0];
        if (!$chosen['has_sets']) {
            // Search results normally include the songs; fetch the full setlist if not.
            $full = $this->client->setlist($chosen['id']);
            if ($full !== null) {
                $chosen = array_merge($chosen, SetlistSummary::from($full));
            }
        }

        if ($event['nonstandard_type'] !== null) {
            return ['status' => 'ambiguous', 'chosen' => null, 'candidates' => $candidates, 'note' => 'special_show'];
        }
        if ($chosen['song_count'] === 0) {
            return ['status' => 'empty', 'chosen' => $chosen, 'candidates' => $candidates, 'note' => null];
        }

        return ['status' => 'matched', 'chosen' => $chosen, 'candidates' => $candidates, 'note' => null];
    }

    /** @return list<string> YYYY-MM-DD for each day of the event (capped) */
    private function dates(array $event): array
    {
        $start = new DateTimeImmutable($event['start_date']);
        if ($event['end_date'] === null) {
            return [$start->format('Y-m-d')];
        }
        $end = (new DateTimeImmutable($event['end_date']))->modify('+1 day');
        $dates = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $day) {
            $dates[] = $day->format('Y-m-d');
            if (count($dates) === self::MAX_DAYS) {
                break;
            }
        }

        return $dates;
    }

    /** False only when both sides name a city and they differ. */
    private function locationAgrees(array $event, array $summary): bool
    {
        if ($event['city'] === null || $summary['city'] === null) {
            return true;
        }

        return Text::looseKey($event['city']) === Text::looseKey($summary['city']);
    }

    /** 2 = same venue, 1 = one name contains the other, 0 = different or unknown. */
    private function venueScore(?string $csvVenue, ?string $setlistVenue): int
    {
        if ($csvVenue === null || $setlistVenue === null) {
            return 0;
        }
        $a = Text::looseKey($csvVenue);
        $b = Text::looseKey($setlistVenue);
        if ($a === $b) {
            return 2;
        }

        return ($a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a))) ? 1 : 0;
    }
}
