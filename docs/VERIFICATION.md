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

| Assumption | Where | How to check | Status |
|---|---|---|---|
| `search?term=…&entity=song&media=music&country=us&limit=50` needs no key and returns `{resultCount, results[]}` | `ItunesClient::search` | First live request | **Verified 2026-10-30** |
| Result fields: `trackId, artistId, collectionId, trackName, artistName, collectionName, trackViewUrl, artworkUrl100, releaseDate, trackExplicitness, trackTimeMillis` | `MatchRepository::saveRecording` | Inspect one response | **Verified 2026-10-30**; also `isStreamable`, `primaryGenreName`. No ISRC. |
| A search with no hits answers 200 with `resultCount: 0` (not 404) | `ItunesClient::search` (404 is treated as empty only for fixture mode) | Search nonsense | **Verified 2026-10-30** |
| `trackId` is the Apple Music catalog song ID | needed for future direct playlist creation | Open `music.apple.com/us/song/{trackId}` | **Verified 2026-10-30**: `music.apple.com/us/song/585128671` redirects to "Car Radio" by twenty one pilots; `trackViewUrl` carries the same ID as `?i=` |
| Search terms match loosely (not every word has to appear) | `RecordingMatcher` scores every result itself | "Judah and the Lion Mr Brightside" | **Observed 2026-10-30**: returns their song "Reputation"; they have no Mr. Brightside recording |
| Rate limit about 20/minute; what a throttled response looks like | `ITUNES_MIN_INTERVAL_MS` (3000); 403/429/503 are treated as "slow down": wait 60s, retry, then pause the scan | Watch a full live match | **Observed 2026-10-30**: 697 requests at one per 3 seconds over a full match of 1,423 songs, no 403/429/503. The real limit is still unknown; what a throttled answer looks like is untested. |
| Search ranks an artist's popular songs above an exact title, so album tracks go missing | `RecordingMatcher` looks through the artist's catalog first | "A Day To Remember Right Back at It Again" | **Observed 2026-10-30**: not in 50 results, but in `lookup?id={artistId}&entity=song` |
| `lookup` returns at most 200 songs; `sort=recent` returns a different 200 | `RecordingMatcher::catalog` fetches both when the first is full | blackbear's "idfc" | **Observed 2026-10-30**: missing from the default 200, present with `sort=recent` |
| Curly apostrophes in a term break search | `ItunesClient::term` straightens them | "Dixie D’amelio Be Happy" | **Observed 2026-10-30**: no hit with ’, found with ' |
| Swear words are masked in `trackName` ("F**k U") | `RecordingClassifier::unmask` | Frances Forever, The Front Bottoms | **Observed 2026-10-30** |
| Compilations carry `collectionArtistName: "Various Artists"` | `CandidateScorer` penalizes them | The Struts "Could Have Been Me" | **Observed 2026-10-30**; the field is absent on artists' own albums |
| Responses send `cache-control: max-age=86400` | Responses are kept until the data source changes (Apple asks heavy users to cache) | Response headers | **Observed 2026-10-30** |

## Transfer service (Phase 5)

- CSV columns TuneMyMusic / Soundiiz accept, and free-tier limits.
