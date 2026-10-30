-- Songs pulled from matched setlists, deduplicated. See docs/BUILD_SPEC.md §7.
--
-- A song is (performer, normalized title, original artist). original_key is '' for the
-- performer's own songs and identifies the original artist for covers, so covers stay separate.
-- Rows are never deleted by extraction: a song with no remaining performances is simply hidden,
-- which keeps the user's exclude flag if it comes back.

CREATE TABLE songs (
    id                   INTEGER PRIMARY KEY,
    performer_artist_id  INTEGER NOT NULL REFERENCES artists(id),
    title_norm           TEXT NOT NULL,
    original_key         TEXT NOT NULL DEFAULT '',
    title_display        TEXT NOT NULL,
    original_artist_name TEXT,
    original_artist_mbid TEXT,
    excluded             INTEGER NOT NULL DEFAULT 0,
    UNIQUE (performer_artist_id, title_norm, original_key)
);

-- One song at one performance.
CREATE TABLE song_performances (
    id             INTEGER PRIMARY KEY,
    song_id        INTEGER NOT NULL REFERENCES songs(id) ON DELETE CASCADE,
    performance_id INTEGER NOT NULL REFERENCES performances(id) ON DELETE CASCADE,
    set_index      INTEGER NOT NULL,
    position       INTEGER NOT NULL,     -- order within the whole setlist, from 1
    encore         INTEGER,              -- encore number, null for the main set
    times_in_show  INTEGER NOT NULL DEFAULT 1,
    variant        TEXT,                 -- e.g. "acoustic", from the title or setlist note
    partial        INTEGER NOT NULL DEFAULT 0,
    medley         INTEGER NOT NULL DEFAULT 0,
    info           TEXT,
    with_artist    TEXT,
    UNIQUE (song_id, performance_id)
);

CREATE INDEX song_performances_performance ON song_performances(performance_id);

-- "These two titles are the same song", recorded so rebuilds apply it again.
CREATE TABLE song_aliases (
    id                  INTEGER PRIMARY KEY,
    performer_artist_id INTEGER NOT NULL REFERENCES artists(id),
    original_key        TEXT NOT NULL DEFAULT '',
    from_title_norm     TEXT NOT NULL,
    to_title_norm       TEXT NOT NULL,
    UNIQUE (performer_artist_id, original_key, from_title_norm)
);

-- Which setlist a performance's songs were last extracted from.
ALTER TABLE performances ADD COLUMN songs_setlist_id TEXT;
