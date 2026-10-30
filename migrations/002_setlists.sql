-- setlist.fm artist resolution, per-performance setlist matching, caching and background jobs.

-- resolution: pending | resolved | ambiguous | unresolved | user_chosen | user_none
ALTER TABLE artists ADD COLUMN mbid TEXT;
ALTER TABLE artists ADD COLUMN setlistfm_url TEXT;
ALTER TABLE artists ADD COLUMN resolution TEXT NOT NULL DEFAULT 'pending';
ALTER TABLE artists ADD COLUMN candidates_json TEXT NOT NULL DEFAULT '[]';

-- setlist_status: pending | matched | ambiguous | missing | empty | error
-- setlist_source: auto (found by the scan) | user (picked on the review screen)
ALTER TABLE performances ADD COLUMN setlist_status TEXT NOT NULL DEFAULT 'pending';
ALTER TABLE performances ADD COLUMN setlist_source TEXT NOT NULL DEFAULT 'auto';
ALTER TABLE performances ADD COLUMN setlist_id TEXT;
ALTER TABLE performances ADD COLUMN setlist_version_id TEXT;
ALTER TABLE performances ADD COLUMN setlist_url TEXT;
ALTER TABLE performances ADD COLUMN setlist_event_date TEXT;
ALTER TABLE performances ADD COLUMN setlist_song_count INTEGER;
ALTER TABLE performances ADD COLUMN setlist_candidates_json TEXT NOT NULL DEFAULT '[]';
ALTER TABLE performances ADD COLUMN setlist_note TEXT;

CREATE INDEX performances_setlist_status ON performances(setlist_status);

-- Short-term cache of setlist.fm responses (their terms allow only short-term caching).
CREATE TABLE api_cache (
    cache_key     TEXT PRIMARY KEY,
    provider      TEXT NOT NULL,
    status        INTEGER NOT NULL,
    body          TEXT NOT NULL,
    fetched_at    INTEGER NOT NULL          -- unix seconds
);

-- Shared between the web process and the worker so both respect one rate limit.
CREATE TABLE rate_limits (
    provider        TEXT PRIMARY KEY,
    next_allowed_at REAL NOT NULL           -- unix seconds, fractional
);

CREATE TABLE api_usage (
    provider TEXT NOT NULL,
    day      TEXT NOT NULL,                 -- YYYY-MM-DD, UTC
    requests INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (provider, day)
);

-- status: queued | running | done | failed | canceled | paused
CREATE TABLE jobs (
    id            INTEGER PRIMARY KEY,
    type          TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'queued',
    stage         TEXT,
    total         INTEGER NOT NULL DEFAULT 0,
    done          INTEGER NOT NULL DEFAULT 0,
    current_label TEXT,
    error         TEXT,
    payload_json  TEXT NOT NULL DEFAULT '{}',
    result_json   TEXT NOT NULL DEFAULT '{}',
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    finished_at   TEXT
);

CREATE TABLE app_meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
