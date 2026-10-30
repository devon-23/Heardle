# Heard Live

Turns a Concert Archives export into a playlist of every song you've heard performed live: only the performers you actually saw, one entry per song, every show remembered.

The full plan is in [docs/BUILD_SPEC.md](docs/BUILD_SPEC.md).

## Status

Phase 3 — CSV import, concert review, the setlist scan (a background worker finds each attended performance's setlist on setlist.fm), and the song library: every unique song you heard, deduplicated, with the shows each came from. Exclude or merge songs from a song's page. Live API checks are in [docs/VERIFICATION.md](docs/VERIFICATION.md).

## Scanning with real setlist.fm data

1. Get a free API key from your setlist.fm account settings (non-commercial use).
2. In `.env`: `SETLISTFM_API_KEY=…` and `SETLISTFM_MODE=live`.
3. Restart `bin/dev`, open **Setlists**, start a scan.

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
