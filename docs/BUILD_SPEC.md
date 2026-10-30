# Heard Live — Build Specification (v1)

Working title. A personal web app that turns a Concert Archives export into a reviewed, deduplicated list of every song you've heard performed live, matched to Apple Music recordings and exported for playlist creation.

Status legend used throughout:
- **[Verified]** — confirmed in official documentation (links in §15).
- **[Unverified]** — believed true but not yet confirmed or tested; must be checked in the phase noted.
- **[Decision]** — agreed during planning.

---

## 1. Decisions summary

| Area | Decision |
|---|---|
| Import | [Decision] Upload the Concert Archives CSV export. No profile-URL import (no public API; scraping ruled out). |
| Attendance | [Decision] `Bands Seen` is the source of truth. `Bands Not Seen` is excluded by default but visible and toggleable. Review screen is pre-filled; only flagged items need input. |
| Setlists | setlist.fm API (free, non-commercial). |
| Streaming | [Decision] Apple Music, free-first: match via the iTunes Search API, export a CSV for a playlist-transfer service. Direct playlist creation via Apple Music API is deferred until an Apple Developer Program membership exists. Spotify is out of scope (Development Mode requires the owner to have Premium). |
| Playlist privacy | [Decision] Private by default (applies once direct creation exists). |
| Covers | [Decision] A cover is a separate song from the original. |
| Song defaults | [Decision] Tape/intro entries excluded; medley parts counted separately when listed separately; a song repeated within one show counts once for that show. |
| Stack | [Decision] PHP 8 + SQLite + vanilla JS/CSS, running locally. No framework, no Composer dependencies in v1. |
| setlist.fm data retention | [Decision] Setlist contents are cached with an expiry; durable tables hold IDs and user decisions. Derived tables are rebuildable. Ask setlist.fm whether a personal tool may retain data longer. |
| Users | Single user, local machine. No accounts. |

---

## 2. Product overview and user flow

1. **Landing** — what the app does; "Import your concerts" call to action.
2. **Import** — upload the Concert Archives CSV. Show a parse summary (events, performances seen/not seen, flagged rows) before committing.
3. **Scan** — background job: resolve artists → find setlists → extract songs → match recordings. Live progress per stage, with counts and the item currently being processed.
4. **Concert review** — every event as a "ticket stub" card. Performers pre-checked from `Bands Seen`. Only flagged events demand attention (see §6.4). Bulk "looks right" per filter.
5. **Song library** — every unique song, filterable by artist, event, year, opener/headliner-ish, cover. Each song shows its contributing performances with links to the source setlists.
6. **Match review** — high-confidence matches approved in bulk; user works through needs-review, unmatched, and covers-without-recording queues.
7. **Export** — choose included songs, name the list, download CSV. Instructions for importing into a transfer service.
8. **Stats** — summary dashboard (§9).

Every screen after Import is reachable from a persistent nav; the user can revisit review screens and re-export at any time.

---

## 3. Scope

### v1 (this spec)
Import, scan, concert review, song library, match review, CSV export, v1 stats, fixture mode for all external APIs.

### Next
- Re-upload CSV: only new or changed events are processed (keyed on event URL).

### Phase 2
- Rarest song heard live (on-demand, per artist — §10).
- Timeline of songs by date first heard.

### When an Apple Developer membership exists
- Direct Apple Music playlist creation (§8.4).

### Maybe
- Read-only share page. Spotify. Setlist.fm `attended` import as a second import path.

---

## 4. Architecture

```
Browser (vanilla JS)  ──HTTP──▶  PHP app (php -S 127.0.0.1:8080, public/index.php router)
                                   │
                                   ├─ SQLite (storage/app.sqlite)
                                   │
CLI worker (php bin/worker.php) ───┤  polls `jobs` table, runs pipeline stages,
                                   │  rate-limited HTTP clients
                                   ├─ setlist.fm API  (live | fixture)
                                   └─ iTunes Search API (live | fixture)
```

- **Why a separate worker:** a full scan takes tens of minutes because of rate limits. Running it inside a web request would block the single-threaded dev server and die if the tab closes. The worker is a loop that claims the next job, processes items, and writes progress to the `jobs` row after each item.
- **Progress:** the Scan screen polls `GET /api/jobs/{id}` every 2 seconds. No WebSockets/SSE needed.
- **Resumability:** each pipeline stage is idempotent and works off item status (`pending → done | error`). Killing the worker and restarting resumes where it stopped.
- **Dev convenience:** `bin/dev` starts the PHP server and the worker together.

### Directory layout

```
public/            index.php (router), assets/ (css, js, fonts if self-hosted)
src/
  Http/            controllers, JSON responses, CSRF
  Import/          ConcertArchivesCsv parser
  Setlists/        SetlistFmClient, ArtistResolver, SetlistMatcher
  Songs/           Extractor, TitleNormalizer, SongIdentity
  Matching/        ItunesClient, CandidateScorer, RecordingClassifier
  Export/          CsvExporter
  Stats/
  Jobs/            Queue, Worker, stages
  Support/         Env, Db, RateLimiter, HttpClient, Clock
views/             PHP templates (server-rendered shells; JS enhances)
migrations/        numbered .sql files
bin/               dev, worker.php, migrate.php, purge-setlist-cache.php
tests/             run.php (minimal runner), unit/, integration/, fixtures/
storage/           app.sqlite, uploads/ (gitignored)
docs/
```

### Configuration (`.env`, gitignored; `.env.example` committed)

```
APP_ENV=local
SETLISTFM_API_KEY=
SETLISTFM_MODE=fixture        # fixture | live
ITUNES_MODE=fixture           # fixture | live
ITUNES_COUNTRY=us
SETLIST_CACHE_TTL_HOURS=24
```

Fixture mode is the default so the app runs end to end with no credentials.

---

## 5. Import: Concert Archives CSV

### 5.1 Observed format (from a real export, 2026-10-29)

Header:
```
Start Date,End Date,Nonstandard Concert Type,Status,Concert Name,Bands Seen,Bands Not Seen,Venue,Location,URL
```

| Column | Observed values | Handling |
|---|---|---|
| Start Date | `MM/DD/YYYY` | Required. Parse strictly. |
| End Date | Empty, or `MM/DD/YYYY` for multi-day events | If present, event spans a range (§6.4). |
| Nonstandard Concert Type | Empty, `VIP Show`, `Private Event` | Non-empty → flag event for review. |
| Status | `Past` | Import `Past` only; report others as skipped. |
| Concert Name | Text, empty, or `""` | Optional display name. |
| Bands Seen | `A / B / C` | Split on `" / "` (space-slash-space). Order preserved. |
| Bands Not Seen | Same format, often `""` | Same split. |
| Venue | Text | Used for setlist matching. |
| Location | `City, State, Country` | Split from the right: last part = country, first part = city. Don't assume three parts. |
| URL | Unique per event | Stable event key for re-import. |

Parser rules:
- Read with a real CSV parser (`fgetcsv`), UTF-8 with optional BOM.
- Validate the header exactly; on mismatch, show which columns are missing/unexpected rather than guessing.
- Trim artist names; drop empties. Keep names with commas intact (e.g. `Fat, Evil Children` arrives quoted).
- The CSV doesn't mark headliners. Treat the first `Bands Seen` artist as the probable headliner for display only; never use it for inclusion logic.
- Uploaded file: max 5 MB, `.csv` only, stored under `storage/uploads/` (gitignored).

### 5.2 Import preview
Before committing, show: event count, performances seen / not seen, unique artists, date range, flagged events (multi-day, nonstandard type, non-Past status), and any rows that failed to parse with the reason.

---

## 6. Attendance and setlist retrieval

### 6.1 Three distinct states per performance
A **performance** is one artist at one event. Track separately:
- **Listed** — appears in `Bands Seen` or `Bands Not Seen`.
- **Attended** — user saw it. Initially = in `Bands Seen`; user can override.
- **Setlist status** — `pending | matched | ambiguous | missing | empty | error`.

Only performances that are **attended** contribute songs.

### 6.2 Artist resolution (CSV name → setlist.fm artist)
1. `GET /1.0/search/artists?artistName=…` [Verified endpoint].
2. Exact normalized-name match with a single result → resolved.
3. Multiple exact matches (common for small bands) → disambiguate by checking which candidate has a setlist on one of the user's event dates. Still ambiguous → flag for user choice in Concert review.
4. No match → `unresolved`; performance setlist status becomes `missing` with reason "artist not on setlist.fm".
5. Resolution is stored per artist and reused across events. User choices are durable.

### 6.3 Setlist matching (performance → one setlist)
1. `GET /1.0/search/setlists?artistMbid={mbid}&date={dd-MM-yyyy}` [Verified params; date format `dd-MM-yyyy`].
2. Score each result: city match (+), venue name similarity (+), country match (+). Venue/city disagreement with the CSV is a red flag even with one result.
3. Outcomes:
   - One plausible result → `matched`.
   - Several plausible results, or one result with location mismatch → `ambiguous`, user picks in Concert review.
   - None → `missing`. Never substitute another date's setlist.
   - Matched but zero songs → `empty`.
4. Fetch the full setlist via `GET /1.0/setlist/{setlistId}` only if the search result lacks sets [Unverified whether search results include full sets — check in Phase 2].
5. Store `setlist_id`, `versionId`, and the attribution `url` [Verified fields].

### 6.4 Flagged event types
| Case | Behavior |
|---|---|
| Multi-day festival (has End Date) | Query each date in the range. One hit → matched. Hits on several days or none → flag. |
| Single-day festival | Same as a normal event; `Bands Not Seen` matters most here. |
| `VIP Show` / `Private Event` | Search normally but always mark `ambiguous` so the user confirms the setlist reflects what they saw. |
| Co-headliners | No special handling; each is a performance. |
| Canceled performer | User unchecks attendance. If setlist.fm has no setlist, it's simply `missing`. |
| Artist in both Seen and Not Seen | Import as Seen, flag. |

### 6.5 Concert review screen
- Filter chips: All · Needs attention · Missing setlists · Festivals · Has unseen performers.
- Each event card: date, venue, city, performers with attendance checkbox, setlist status badge, link to the setlist.fm page.
- Ambiguous setlists show candidates side by side (venue, city, song count, first few songs, link).
- "Mark all in this filter as reviewed."
- Changing attendance or setlist choice re-runs song extraction for that performance only.

### 6.6 setlist.fm rules to follow [Verified in API terms]
- Non-commercial use only. Don't share the API key.
- Short-term caching only → `setlist_cache` with TTL (default 24h). Durable tables keep IDs and user decisions.
- Display attribution: link to each setlist's `url` wherever its data is shown; don't `nofollow` those links.
- Avoid unreasonable load. Rate limits aren't documented → default to **1 request/second**, exponential backoff on 429/5xx (1s, 2s, 4s… max 60s, max 5 retries), and a configurable daily budget. Record observed limits in Phase 2 and update this spec.
- Send `Accept: application/json` and `x-api-key` [Verified].

---

## 7. Songs and deduplication

### 7.1 Extraction
For each attended performance with a matched setlist, walk `sets[].song[]` [Verified structure: set has `name`, `encore`, `song[]`; song has `name`, `with`, `cover`, `info`, `tape`]:
- Skip `tape: true`.
- Skip entries with an empty name.
- Record set index, position, encore number, `info`, `with` artist.
- Repeated song in one setlist → one song-performance row, with `times_in_show` incremented.
- Medley: setlist.fm writes medleys as one entry, "Song A / Song B". An entry with a **spaced** " / " is split into its songs, each flagged "from a medley" (changed from flag-only after the real data showed ~50 such entries that could never match a single track). Unspaced slashes stay whole, since titles like "Needing/Getting" and "Unknown/Nth" are single songs. An `info` mentioning "medley" also sets the flag.
- Partial songs (`info` such as "snippet", "partial") count, with a `partial` flag shown in the library. User can exclude.

### 7.2 Song identity
A song's identity key:

```
(performer_artist_id, normalized_title, original_artist_id_or_null)
```

- `performer_artist_id` — the setlist's artist.
- `original_artist_id` — from the song's `cover` field when present; null when the performer is the original artist.
- Because covers are separate songs [Decision], a cover's key differs from the original's key even with the same title.
- Same title by different artists → different songs automatically.
- Same performer, same title, genuinely different songs (rare) → user can split in the library.

### 7.3 Title normalization (for keys only; display keeps the original)
- Unicode NFKC, lowercase, trim.
- Unify curly quotes/apostrophes, `&` → `and`, collapse whitespace.
- Strip surrounding quotes.
- Remove trailing version qualifiers into a separate `variant` attribute: `(acoustic)`, `(piano version)`, `(live)`, `(reprise)`. Variant doesn't change identity — the song is still the same song. Exception: `reprise` keeps identity separate (often a distinct track).
- Normalize part markers: `pt.`, `part`, roman numerals → `part N`.
- Strip trailing punctuation.

### 7.4 User corrections
- **Merge**: two library entries are the same song (spelling variants setlist.fm didn't unify). Stored as an alias row so future rebuilds apply it.
- **Split**: one entry is really two songs (stored similarly).
- **Exclude**: song stays in history but not in export.

All corrections are durable and survive cache purges and rebuilds.

---

## 8. Recording matching (Apple Music via iTunes Search API)

### 8.1 API facts [Verified]
- `https://itunes.apple.com/search?term=…&country=us&media=music&entity=song&limit=…` — no API key; `limit` 1–200.
- `https://itunes.apple.com/lookup?id=…` — lookup by iTunes ID.
- About **20 calls per minute** (subject to change). Apple asks large consumers to cache responses.
- Results include `trackId`, `artistId`, `collectionId`, `trackName`, `artistName`, `collectionName`, `trackTimeMillis`, `trackViewUrl`, `artworkUrl100`, `releaseDate`, `trackExplicitness`.
- No ISRC in results.
- Promotional content (artwork, previews) is for promoting store content; previews need "provided courtesy of iTunes" attribution and must not be downloaded or cached. **v1 shows artwork linked to the Apple Music page and doesn't play previews.**
- [Unverified] `trackId` equals the Apple Music catalog song ID. Confirm in Phase 4 by opening a few `trackViewUrl`s; needed for future direct playlist creation.

### 8.2 Search and scoring
For each song:
1. Choose the target artist: the performer. (For covers, the performer first; see §8.3.)
2. Query `term="{artist} {title}"`, `entity=song`, `limit=25`. Cache by normalized query string (no TTL needed for iTunes responses in v1; manual refresh available).
3. Score each candidate:
   - Artist match: exact normalized match is required for "high" confidence; "feat."/"&" variants allowed.
   - Title similarity on normalized titles (exact > prefix/suffix qualifier only > fuzzy).
   - Recording type from title/collection keywords: `live`, `acoustic`, `remix`, `demo`, `instrumental`, `karaoke`, `sped up`, `slowed`, `version`, `remaster`, `re-recorded`, `(taylor's version)`-style. Studio preferred; remaster counts as studio.
   - Penalize compilations and karaoke/tribute artists.
   - Among equal studio candidates, prefer the earliest `releaseDate` original album over singles/deluxe duplicates, unless only the single exists.
4. Confidence:
   - **High** — exact artist + exact normalized title + studio. Auto-accepted, reviewable in bulk.
   - **Medium** — anything weaker that still matched artist. Goes to review.
   - **None** — no candidate by the performer. Goes to the unmatched queue.

### 8.3 Covers [Decision]
For a cover (song has `original_artist_id`):
1. Performer's studio recording of that title.
2. Performer's live recording.
3. Otherwise → unmatched queue labeled "no recording by {performer}", with a one-click "use {original artist}'s version" action.

If two songs end up with the same `trackId`, export lists it once; the library still shows both songs.

### 8.4 Match review screen
- Tabs with counts: Auto-matched · Needs review · Unmatched · Covers without recording · Excluded.
- Auto-matched: compact grid of artwork + title, filterable; "approve all" button.
- Needs review / unmatched: one row per song with current pick, up to 5 alternatives, recording-type badge, "search again with custom terms", exclude, and contributing concerts.
- Keyboard: `j/k` move, `1–5` pick alternative, `x` exclude, `enter` accept.
- Every decision is saved immediately.

### 8.5 Deferred: direct Apple Music playlist creation
Requires an Apple Developer Program membership (paid). From the docs [Verified]:
- `POST https://api.music.apple.com/v1/me/library/playlists` with `name`, `description`, `isPublic`.
- `POST /v1/me/library/playlists/{id}/tracks` with items of type `songs` (catalog IDs) accepted.
- Requires a developer token (JWT signed with a MusicKit private key) and a Music User Token obtained by the user approving access in MusicKit JS.
- `GET /v1/catalog/{storefront}/songs?filter[isrc]=…` (max 25) can improve matching later.
[Unverified]: rate and track limits, MusicKit JS on `localhost`, whether a subscription is required for library writes. Verify when this phase starts.

---

## 9. Export and stats

### 9.1 CSV export
Columns: `Title, Artist, Album, Apple Music URL, Apple Music ID, Times Heard, First Heard, Last Heard, Performed By, Original Artist`.
- Only songs with a selected recording, not excluded, deduplicated by `trackId`.
- Second export: the unmatched list (what you heard but couldn't get).
- [Unverified] Exact column names TuneMyMusic / Soundiiz expect, and their free-tier track limits. Phase 5 tests a real import and adjusts the column order or adds a preset.

### 9.2 v1 stats
- Unique songs heard live, concerts represented, artists seen.
- Most-heard songs (with the list of shows).
- Songs heard from opening acts (non-first performers in `Bands Seen`; labeled "probable" since the CSV doesn't mark headliners).
- Artists contributing the most songs.
- Covers you've heard.
- Concerts/performances with missing setlists (coverage %).

---

## 10. Phase 2: rarity (design notes, not v1)
- On demand, one artist at a time, from that artist's song detail page.
- Page `GET /1.0/artist/{mbid}/setlists` (page size [Unverified]; the search/setlists docs example shows 20), count occurrences of each normalized title, show "played at N of M setlists on setlist.fm."
- Show progress, cap pages per run, respect the daily budget, cache with the same TTL rules.
- Cheaper variant: "rarest that year" using `search/setlists?artistMbid&year`.
- Always caveat that setlist.fm coverage is incomplete, especially for small artists and older shows.

---

## 11. Data model (SQLite)

```
imports            id, filename, imported_at, row_count, summary_json
events             id, import_id, ca_url UNIQUE, start_date, end_date, name, venue,
                   city, region, country, nonstandard_type, needs_review, reviewed_at
artists            id, name, name_norm, mbid NULL, setlistfm_url, resolution
                   (pending|resolved|ambiguous|unresolved|user_chosen), itunes_artist_id NULL
performances       id, event_id, artist_id, listed_as (seen|not_seen), position,
                   attended BOOL, attended_source (csv|user),
                   setlist_status, setlist_id NULL, setlist_version_id, setlist_url,
                   setlist_event_date, candidates_json, notes, UNIQUE(event_id, artist_id)
setlist_cache      setlist_id PK, json, fetched_at                 -- expires per TTL
songs              id, performer_artist_id, original_artist_id NULL, title_display,
                   title_norm, excluded BOOL, UNIQUE(performer_artist_id, title_norm, original_artist_id)
song_performances  id, song_id, performance_id, set_index, position, encore,
                   times_in_show, variant, partial BOOL, info, with_artist_name
song_aliases       id, performer_artist_id, from_title_norm, to_title_norm, kind (merge|split)
recordings         id, song_id, provider ('itunes'), track_id, track_name, artist_name,
                   collection_name, release_date, duration_ms, artwork_url, view_url,
                   kind (studio|live|acoustic|remix|other), score
song_matches       song_id PK, selected_recording_id NULL, status
                   (auto|needs_review|user_selected|unmatched|use_original|excluded),
                   confidence, decided_at
api_cache          key PK, provider, response_json, fetched_at
jobs               id, type, status (queued|running|done|failed|canceled), stage,
                   total, done, current_label, error, payload_json, created_at, updated_at
```

- **Durable:** events, artists, performances (IDs + user choices), song_aliases, song_matches (decisions), recordings (iTunes data).
- **Derived (rebuildable from cache + decisions):** songs, song_performances. `bin/purge-setlist-cache.php` clears `setlist_cache` and derived tables if strict retention is needed.
- Foreign keys on, WAL mode on, migrations in `migrations/NNN_name.sql` applied by `bin/migrate.php`.

---

## 12. Error handling and edge cases
- External calls go through one `HttpClient` with timeout (10s), retries/backoff, and a per-provider `RateLimiter` backed by SQLite so the worker and web process share it.
- 401/403 from setlist.fm → stop the job, show "check your API key."
- 429 → back off and continue; job shows "waiting for rate limit."
- Network outage → item marked `error`, job continues; "retry failed items" button.
- Malformed API response → item `error` with the raw snippet logged (not shown in UI).
- Re-import of the same CSV → upsert by `ca_url`; user decisions preserved; removed events marked inactive, not deleted.
- Never invent songs, never borrow another date's setlist, never auto-accept a medium-confidence match.

---

## 13. UI and design

**Direction:** dark, typographic, concert-ticket inspired. Music artwork carries the color; the chrome stays quiet.

- **Palette (CSS custom properties on `:root`):**
  - `--bg #0d0d0f`, `--surface #17171b`, `--surface-2 #212127`, `--line #2c2c33`
  - `--text #f2efe9` (warm off-white), `--muted #9a978f`
  - `--accent #ffb020` (stage-light amber), used sparingly: primary actions, progress, focus.
  - Status: `--ok #4cc38a`, `--warn #f5a524`, `--err #f2555a`, `--info #6aa8ff`.
  - No gradients except a subtle artwork-derived blur behind the Stats hero.
- **Type:** display font `Archivo` (expanded/condensed widths) for headings and big numbers, `IBM Plex Sans` body, `IBM Plex Mono` for dates, counts and setlist positions. Loaded from Google Fonts.
- **Signature elements:**
  - Event cards styled as ticket stubs: perforated edge, date in mono, venue in condensed caps.
  - Scan screen: four stacked stage meters (Artists, Setlists, Songs, Matches) filling left to right, with the current item scrolling like a marquee.
  - Song rows: 48px artwork, title, artist, small "heard ×N" pill.
- **Imagery:** album artwork from iTunes results, linked to Apple Music. No concert photos (no legitimate source).
- **Layout:** responsive down to 375px; 16px side gutter; no horizontal page scroll. Tables become stacked rows on mobile.
- **Accessibility:** AA contrast, visible focus rings, all actions keyboard-reachable, status conveyed by text not only color, `prefers-reduced-motion` respected.

---

## 14. Security and privacy
- Server binds to `127.0.0.1` only.
- Secrets only in `.env` (gitignored). `.env.example` has empty values. Never send keys to the browser.
- Personal data (`*Concert Archives Export*.csv`, `storage/`) gitignored.
- CSRF token on all state-changing requests; prepared statements only; escape all output in templates and use `textContent` in JS.
- Upload validation: size, extension, header check; stored with a generated filename.
- No streaming passwords, ever. Future Apple Music auth uses MusicKit's own consent flow.

---

## 15. Testing

### Tooling
Zero-dependency runner: `php tests/run.php` discovers `tests/**/*Test.php`, runs public `test*` methods, reports pass/fail. (Swap in PHPUnit later if Composer is added.)

### Unit tests
- CSV parser: header validation, quoted commas, empty `""` fields, multi-day dates, nonstandard types, `Location` splitting.
- Title normalizer and variant extraction.
- Song identity: covers separate, same title different artists, merge/split aliases.
- Setlist matcher scoring: single hit, multiple hits, location mismatch, multi-day.
- iTunes candidate scorer and recording classifier.
- Export dedup by `trackId`.

### Integration (fixture mode)
Synthetic fixtures (not copied from real setlists) in `tests/fixtures/`:
- A headliner + seen opener + unseen opener event → only the first two contribute songs.
- One song across four nights of the same tour → one library entry, four performances.
- A cover of a song whose original artist was also seen → two library entries.
- A festival with a missing setlist and an ambiguous one.
- A tape intro and a medley line.

### Acceptance criteria with the real CSV (live mode)
- Import shows 105 events, 274 seen performances, 87 not seen.
- Glass Animals 06/17/2025: Sofia Isella contributes no songs.
- The four Clancy Tour: Breach nights resolve to four different setlists.
- Sing Out Loud 2023 and 2024 are flagged as multi-day.
- The VIP Show and Private Event are flagged.
- Every song in the library links to at least one setlist.fm source.
- Exported CSV imports into the chosen transfer service.

### Live API verification checklist (record results in `docs/VERIFICATION.md`)
- setlist.fm observed rate limit and daily quota; whether search results include full sets.
- iTunes `trackId` ↔ Apple Music ID equivalence; observed throttling behavior.
- Transfer-service CSV format and free limits.

---

## 16. Build phases (each ends runnable and testable)

| Phase | Deliverable | Done when |
|---|---|---|
| 0. Skeleton | Router, env loader, SQLite + migrations, design tokens, landing page, test runner, `bin/dev` | `bin/dev` serves the landing page; `php tests/run.php` passes |
| 1. Import + concert review (no APIs) | CSV parser, preview, events/performances, review screen with attendance toggles | Real CSV imports with correct counts; toggles persist |
| 2. Setlists | setlist.fm client (fixture + live), job queue + worker, artist resolution, setlist matching, scan screen, ambiguous/missing handling | Fixture scan completes; live scan of the real CSV produces a coverage report |
| 3. Songs | Extraction, normalization, identity, library screen, merge/split/exclude, basic stats | Integration fixtures pass; library shows sources |
| 4. Matching | iTunes client (fixture + live), scorer, match review screen | High-confidence auto-accept works; review queue is keyboard-driven |
| 5. Export + stats | CSV export, unmatched export, stats dashboard, real transfer-service test | Playlist exists in Apple Music via transfer service |
| Next | Incremental re-import | Re-uploading adds only new events, keeps decisions |

---

## 17. Local setup

Requirements: PHP 8.2+ with `pdo_sqlite`, `curl`, `mbstring` (this machine: PHP 8.5.7, all present).

```bash
cp .env.example .env
php bin/migrate.php
bin/dev            # server on http://127.0.0.1:8080 + worker
php tests/run.php
```

Live mode: add `SETLISTFM_API_KEY` and set `SETLISTFM_MODE=live`, `ITUNES_MODE=live`.

## 18. Credentials

| Service | Needed for | How to get | Cost |
|---|---|---|---|
| setlist.fm API key | Phase 2 live mode | Log in to setlist.fm → account settings → API, apply for a key (non-commercial) | Free |
| iTunes Search API | Phase 4 live mode | Nothing — no key | Free |
| Apple Developer Program (MusicKit key) | Deferred direct playlist creation | developer.apple.com → enroll; create a Media ID and private key | Paid (annual) |

## 19. Deployment
None for v1; the app runs locally. Revisit if direct Apple Music creation (needs a registered web origin for the developer token) or a share page is added.

---

## 20. Sources
- setlist.fm API docs: https://api.setlist.fm/docs/1.0/index.html (Song, Set, Setlist types; search/setlists)
- setlist.fm terms (API section): https://www.setlist.fm/help/terms
- iTunes Search API: https://performance-partners.apple.com/search-api
- Apple Music API — create library playlist: https://developer.apple.com/documentation/applemusicapi/create-a-new-library-playlist
- Apple Music API — add tracks to library playlist: https://developer.apple.com/documentation/applemusicapi/add-tracks-to-a-library-playlist
- Apple Music API — songs by ISRC: https://developer.apple.com/documentation/applemusicapi/get-multiple-catalog-songs-by-isrc
- MusicKit: https://developer.apple.com/musickit/
- Spotify Feb 2026 Development Mode changes: https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide
- Concert Archives export FAQ: https://concertarchives.uservoice.com/knowledgebase/articles/1908544-faq-can-i-export-my-data-for-safe-keeping
- Concert Archives data requests: https://concertarchives.org/data-requests
