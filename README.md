# Heard Live

Turns a Concert Archives export into a playlist of every song you've heard performed live: only the performers you actually saw, one entry per song, every show remembered.

The full plan is in [docs/BUILD_SPEC.md](docs/BUILD_SPEC.md).

## Status

Phase 5 — CSV import, concert review, the setlist scan (a background worker finds each attended performance's setlist on setlist.fm), the song library (every unique song you heard, deduplicated, with the shows each came from), Apple Music matching through the iTunes Search API with keyboard review, **Export** (the playlist as CSV for Soundiiz or TuneMyMusic, split into parts for free-plan limits, plus a details spreadsheet and the list of songs that didn't make it) and **Stats**. Live API checks are in [docs/VERIFICATION.md](docs/VERIFICATION.md).

## Scanning with real setlist.fm data

1. Get a free API key from your setlist.fm account settings (non-commercial use).
2. In `.env`: `SETLISTFM_API_KEY=…` and `SETLISTFM_MODE=live`.
3. Restart `bin/dev`, open **Setlists**, start a scan.

For Apple Music matches, set `ITUNES_MODE=live` (no key needed). Apple allows about 20 searches a minute, so the first full match runs for a while in the background; it resumes where it stopped.

To build the playlist, open **Export**, download the parts, and import each into [Soundiiz](https://soundiiz.com) (free: 200 songs per playlist) or [TuneMyMusic](https://www.tunemymusic.com/transfer/csv-to-apple-music). The export page walks through both.

Switching between fixture and live mode clears results the scan found automatically; choices you made are kept.

To try things without touching your real data, run a second copy on its own database:

```bash
DB_PATH=storage/demo.sqlite PORT=8081 bin/dev
```

## Requirements

PHP 8.2+ with `pdo_sqlite`, `curl` and `mbstring`. No Composer packages.

## Run it

```bash
cp .env.example .env
bin/dev
```

Then open http://127.0.0.1:8080. `bin/dev` applies migrations and serves on localhost only. Set `PORT` to use a different port.

The app runs in fixture mode by default, so no API keys are needed until you switch `SETLISTFM_MODE` / `ITUNES_MODE` to `live`.

## Tests

```bash
php tests/run.php            # all tests
php tests/run.php Router     # only tests whose name contains "Router"
```

## Layout

```
public/       front controller and static assets
src/          app code (namespace HeardLive\)
views/        PHP templates; escape output with e()
migrations/   NNN_name.sql files, applied by bin/migrate.php
bin/          dev server and CLI scripts
tests/        Unit/ and Integration/ tests plus the runner
storage/      SQLite database and uploads (gitignored)
```

Your Concert Archives CSV and `.env` are gitignored. Keep them out of commits.
