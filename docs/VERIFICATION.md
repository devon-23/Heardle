# Live API verification

Things the code assumes about external APIs that haven't been checked against the real service yet. Each item says what the code does today and what to look for. Update this file as items are confirmed.

## setlist.fm (needed before trusting a live scan)

| Assumption | Where | How to check | Status |
|---|---|---|---|
| Base URL is `https://api.setlist.fm/rest/1.0` | `SetlistFmClient::BASE_URL` | First live request returns 200, not 404 | **Verified 2026-10-29** |
| An empty search answers **404**, not 200 with an empty list | `SetlistFmClient::request` treats 404 as "no results" | Search an artist with no setlist on a date | **Verified 2026-10-29**: body `{"code":404,"status":"Not Found","message":"not found",…}` |
| JSON setlists nest songs as `sets.set[].song[]` | `SetlistSummary::sets` (also accepts `set[]`) | Inspect one cached response in `api_cache` | **Verified 2026-10-29** |
| `search/setlists` results include the songs | `SetlistMatcher` fetches `setlist/{id}` only if they're missing | Check `api_usage`: few `setlist/` calls means yes | **Verified 2026-10-29** |
| Search page size is 20 | `setlistsOn` reads `itemsPerPage` from each response, so it adapts | — | Handled either way |
| Rate limit and daily quota | `SETLISTFM_MIN_INTERVAL_MS` (1000) and `SETLISTFM_DAILY_BUDGET` (1000) | Watch for 429s and any `retry-after` header during a full scan | **Observed 2026-10-29**: 507 requests at 1/second, no 429s or errors. The real limits are still unknown; 1/second is safe. |
| `artistName` search is fuzzy and returns `mbid`, `name`, `disambiguation`, `url` | `ArtistResolver` | Look at candidates saved for an ambiguous artist | **Verified 2026-10-29**: fuzzy (e.g. collaborations like "Switchfoot & Twenty One Pilots" come back too); names can differ in case ("twenty one pilots") |

Setlist objects from search contain `id, versionId, eventDate, lastUpdated, artist, venue, sets, info, url`; `tour` appears only when set.

Verified from the docs (2026-10-29): endpoint list, `x-api-key` header, `Accept: application/json`, date format `dd-MM-yyyy`, Song fields (`name`, `with`, `cover`, `info`, `tape`), Set fields (`name`, `encore`, `song`), Setlist fields (`id`, `versionId`, `eventDate`, `url`, …).

## iTunes Search API (Phase 4)

- `trackId` equals the Apple Music catalog song ID.
- Observed throttling behavior at ~20 calls/minute.

## Transfer service (Phase 5)

- CSV columns TuneMyMusic / Soundiiz accept, and free-tier limits.
