-- Concert history imported from a Concert Archives CSV export.

CREATE TABLE imports (
    id           INTEGER PRIMARY KEY,
    filename     TEXT NOT NULL,
    imported_at  TEXT NOT NULL,
    row_count    INTEGER NOT NULL,
    summary_json TEXT NOT NULL
);

CREATE TABLE events (
    id               INTEGER PRIMARY KEY,
    import_id        INTEGER NOT NULL REFERENCES imports(id),
    ca_url           TEXT NOT NULL UNIQUE,          -- stable key across re-imports
    start_date       TEXT NOT NULL,                 -- YYYY-MM-DD
    end_date         TEXT,                          -- set only for multi-day events
    name             TEXT,
    venue            TEXT,
    city             TEXT,
    region           TEXT,
    country          TEXT,
    location_raw     TEXT NOT NULL,
    nonstandard_type TEXT,
    status           TEXT NOT NULL,
    flags_json       TEXT NOT NULL DEFAULT '[]',    -- import-time flags, e.g. ["multi_day"]
    active           INTEGER NOT NULL DEFAULT 1,    -- 0 when missing from the latest import
    reviewed_at      TEXT
);

CREATE INDEX events_start_date ON events(start_date);

CREATE TABLE artists (
    id        INTEGER PRIMARY KEY,
    name      TEXT NOT NULL,
    name_norm TEXT NOT NULL UNIQUE
);

-- One artist at one event. "listed_as" is what the CSV said; "attended" is what counts.
CREATE TABLE performances (
    id              INTEGER PRIMARY KEY,
    event_id        INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    artist_id       INTEGER NOT NULL REFERENCES artists(id),
    listed_as       TEXT NOT NULL CHECK (listed_as IN ('seen', 'not_seen')),
    position        INTEGER NOT NULL,
    attended        INTEGER NOT NULL CHECK (attended IN (0, 1)),
    attended_source TEXT NOT NULL DEFAULT 'csv' CHECK (attended_source IN ('csv', 'user')),
    UNIQUE (event_id, artist_id)
);

CREATE INDEX performances_artist ON performances(artist_id);
