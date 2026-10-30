<?php

declare(strict_types=1);

namespace HeardLive\Songs;

use HeardLive\Setlists\SetlistFmClient;
use HeardLive\Setlists\SetlistFmException;
use HeardLive\Setlists\SetlistSummary;

/** Pulls the songs out of a performance's matched setlist and saves them to the library. */
final class SongExtractor
{
    public function __construct(private SetlistFmClient $client, private SongRepository $songs)
    {
    }

    /** @param array<string, mixed> $performance a row from SongRepository::performancesToExtract() */
    public function extract(array $performance): int
    {
        $setlist = $this->findSetlist($performance);
        if ($setlist === null) {
            throw new SetlistFmException('temporary', "Setlist {$performance['setlist_id']} is no longer on setlist.fm.");
        }
        $entries = SetlistSongs::from($setlist);
        $this->songs->saveSongs((int) $performance['id'], (int) $performance['artist_id'], (string) $performance['setlist_id'], $entries);

        return count($entries);
    }

    /** @return ?array<string, mixed> */
    private function findSetlist(array $performance): ?array
    {
        // The scan already searched this artist on this date; while that response is cached it costs nothing.
        if ($performance['mbid'] !== null && $performance['setlist_event_date'] !== null) {
            foreach ($this->client->setlistsOn((string) $performance['mbid'], (string) $performance['setlist_event_date']) as $setlist) {
                if (($setlist['id'] ?? null) === $performance['setlist_id'] && SetlistSummary::sets($setlist) !== null) {
                    return $setlist;
                }
            }
        }

        return $this->client->setlist((string) $performance['setlist_id']);
    }
}
